<?php

namespace PnShop\Shipping;

use Brick\Money\Money;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Customer\PostalAddress;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Payment\Gateways\CashOnDelivery;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\PaymentService;
use PnShop\Payment\PaymentState;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderAddress;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\OrderWorkflow;
use PnShop\Settings\Settings;
use PnShop\Shipping\Contracts\ProvidesShippingLabels;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\Models\ShipmentLine;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Waybills from the order's carrier (ProvidesShippingLabels): booked when staff create a
 * shipment, or later for a shipment recorded without one.
 *
 * For cash-on-delivery orders the courier collects what is still due, minus what parcels
 * still out for collection will bring in, so a split order is never charged twice. Staff
 * can change the amount (e.g. when a refused parcel is sent again).
 *
 * Booking runs under a lock per order: two staff members (or two tabs) cannot book the
 * same parcel twice.
 */
class Waybills
{
    /** Where waybill PDFs are kept, on the private "local" disk. */
    public const DIRECTORY = 'pnshop/waybills';

    public function __construct(private PaymentService $payments, private OrderWorkflow $workflow) {}

    /**
     * The order's carrier, when it issues waybills.
     */
    public function carrier(Order $order): ?ProvidesShippingLabels
    {
        $carrier = $order->shippingMethod?->carrierInstance();

        return $carrier instanceof ProvidesShippingLabels ? $carrier : null;
    }

    /**
     * What the courier collects with the next parcel: the amount still due on a
     * cash-on-delivery order minus what parcels still out for collection will bring in;
     * null when nothing is.
     */
    public function cashOnDelivery(Order $order): ?Money
    {
        if (! CashOnDelivery::collects($order->paymentMethod)) {
            return null;
        }

        $left = $this->payments->amountDue($order)->minus($this->outForCollection($order));

        return $left->isPositive() ? $left : null;
    }

    /**
     * The most a waybill may collect: what is still due on the order (null when the order
     * is not paid cash on delivery).
     */
    public function maximumCashOnDelivery(Order $order): ?Money
    {
        return CashOnDelivery::collects($order->paymentMethod) ? $this->payments->amountDue($order) : null;
    }

    /**
     * Cash that waybills ask couriers to collect and that is not recorded as paid yet.
     */
    private function outForCollection(Order $order): Money
    {
        $asked = Money::ofMinor((int) $order->shipments()->sum('cash_on_delivery'), $order->currency);
        $collected = $order->payments()->where('gateway', CashOnDelivery::CODE)->whereIn('status', [PaymentState::Paid, PaymentState::PartiallyRefunded, PaymentState::Refunded])->get()
            ->reduce(fn (Money $sum, Payment $payment) => $sum->plus($payment->amount), Money::zero($order->currency));

        $out = $asked->minus($collected);

        return $out->isPositive() ? $out : Money::zero($order->currency);
    }

    /**
     * Run $callback while holding the order's waybill lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws OrderException when another waybill of the order is being booked.
     */
    public function exclusively(Order $order, Closure $callback): mixed
    {
        try {
            return Cache::lock('pnshop.waybills.order.'.$order->id, 60)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new OrderException(__('Another parcel of this order is being booked. Try again in a moment.'));
        }
    }

    /**
     * Book the parcel with the carrier.
     *
     * @param  array<int, int>  $quantities  order item id => quantity in the parcel
     *
     * @throws OrderException when the carrier does not issue waybills or refuses.
     */
    public function book(Order $order, array $quantities, ?StockLocation $location = null, ?Money $cashOnDelivery = null): ShippingLabel
    {
        $order->loadMissing(['items', 'addresses', 'shippingMethod']);
        $carrier = $this->carrier($order);
        $method = $order->shippingMethod;

        if ($carrier === null || $method === null) {
            throw new OrderException(__('The carrier of this order cannot create waybills.'));
        }

        $address = $order->addresses->firstWhere('type', OrderAddress::SHIPPING);

        if ($address === null) {
            throw new OrderException(__('This order has no shipping address.'));
        }

        $items = $order->items->filter(fn (OrderItem $item) => ($quantities[$item->id] ?? 0) > 0);
        $weights = ProductVariant::withTrashed()->whereKey($items->pluck('product_variant_id')->filter()->all())->pluck('weight', 'id');

        try {
            return $carrier->shippingLabel(new ShippingLabelRequest(
                $order->number ?? (string) $order->id,
                $address->toPostalAddress(),
                $this->senderAddress($location),
                array_values($items->map(fn (OrderItem $item) => [
                    'title' => trim($item->product_title.($item->variant_label ? " ({$item->variant_label})" : '')),
                    'quantity' => (int) $quantities[$item->id],
                    'weight' => (int) ($weights[$item->product_variant_id] ?? 0),
                ])->all()),
                $cashOnDelivery,
                $order->email,
                $order,
            ), $method);
        } catch (RuntimeException $e) {
            throw new OrderException(__('The carrier did not create the waybill: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Keep the waybill with the shipment (and its number as the tracking number, when the
     * shipment has none).
     */
    public function attach(Shipment $shipment, ShippingLabel $label, ?Money $cashOnDelivery = null): Shipment
    {
        $path = null;

        if ($label->pdf !== null && $label->pdf !== '') {
            $path = self::DIRECTORY.'/'.$shipment->order_id.'-'.$shipment->id.'-'.preg_replace('/[^A-Za-z0-9_-]/', '', $label->trackingNumber).'.pdf';
            Storage::disk('local')->put($path, $label->pdf);
        }

        $method = $shipment->shipping_method_id !== null ? $shipment->order?->shippingMethod : null;

        $shipment->forceFill([
            'label_url' => $label->labelUrl,
            'label_path' => $path,
            'cash_on_delivery' => $cashOnDelivery?->getMinorAmount()->toInt(),
            ...($shipment->tracking_number === null ? [
                'tracking_number' => $label->trackingNumber,
                'tracking_url' => $method?->carrierInstance()?->trackingUrl($label->trackingNumber, $method),
            ] : []),
        ])->save();

        return $shipment;
    }

    /**
     * Whether staff can create a waybill for a shipment recorded without one.
     */
    public function canCreateFor(Shipment $shipment): bool
    {
        return ! $shipment->hasWaybill() && $shipment->tracking_number === null && $shipment->order !== null && $this->carrier($shipment->order) !== null;
    }

    /**
     * Create the waybill of a shipment recorded without one (its lines, from its location).
     * Without $cashOnDelivery, the courier collects what cashOnDelivery() suggests.
     *
     * @throws OrderException
     */
    public function createFor(Shipment $shipment, ?Model $actor = null, ?Money $cashOnDelivery = null, bool $suggested = true): Shipment
    {
        $order = $shipment->order()->firstOrFail();

        return $this->exclusively($order, function () use ($shipment, $order, $actor, $cashOnDelivery, $suggested) {
            $shipment->refresh();

            if ($shipment->hasWaybill() || $shipment->tracking_number !== null) {
                throw new OrderException(__('This shipment already has a waybill.'));
            }

            $cash = $this->checkedCash($order, $suggested ? $this->cashOnDelivery($order) : $cashOnDelivery);
            $quantities = $shipment->lines()->get()->mapWithKeys(fn (ShipmentLine $line) => [$line->order_item_id => $line->quantity])->all();
            $label = $this->book($order, $quantities, $shipment->location, $cash);

            $this->attach($shipment, $label, $cash);
            $this->workflow->addNote($order, __('Waybill :number created.', ['number' => $label->trackingNumber]), $actor);

            return $shipment;
        });
    }

    /**
     * The amount staff chose to collect, checked: never more than is due.
     *
     * @throws OrderException
     */
    public function checkedCash(Order $order, ?Money $cash): ?Money
    {
        if ($cash === null || ! $cash->isPositive()) {
            return null;
        }

        $maximum = $this->maximumCashOnDelivery($order);

        if ($maximum === null || $cash->isGreaterThan($maximum)) {
            throw new OrderException(__('The courier can collect at most :amount on this order.', ['amount' => ($maximum ?? Money::zero($order->currency))->formatToLocale(app()->getLocale())]));
        }

        return $cash;
    }

    /**
     * The waybill for download: the stored PDF, or a redirect to the carrier's copy.
     */
    public function download(Shipment $shipment): ?Response
    {
        if ($shipment->label_path !== null && Storage::disk('local')->exists($shipment->label_path)) {
            $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $shipment->tracking_number) ?: (string) $shipment->id;

            return Storage::disk('local')->download($shipment->label_path, 'waybill-'.$name.'.pdf', ['Content-Type' => 'application/pdf']);
        }

        return $shipment->label_url !== null ? redirect()->away($shipment->label_url) : null;
    }

    private function senderAddress(?StockLocation $location): ?PostalAddress
    {
        $location ??= StockLocation::default();

        if ($location->address === null) {
            return null;
        }

        $settings = app(Settings::class);

        return PostalAddress::fromArray([
            'company' => (string) $settings->get('store.name'),
            'line1' => $location->address,
            'city' => $location->city,
            'postcode' => $location->postcode,
            'country_code' => $location->country_code,
            'phone' => $settings->get('store.phone'),
        ]);
    }
}
