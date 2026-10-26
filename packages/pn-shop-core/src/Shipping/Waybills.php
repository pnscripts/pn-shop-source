<?php

namespace PnShop\Shipping;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Customer\PostalAddress;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Payment\Gateways\CashOnDelivery;
use PnShop\Payment\PaymentService;
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
 * For cash-on-delivery orders the courier collects what is still due, minus what earlier
 * parcels of the order already collect, so a split order is never charged twice.
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
     * cash-on-delivery order minus what earlier parcels collect; null when nothing is.
     */
    public function cashOnDelivery(Order $order): ?Money
    {
        if (! CashOnDelivery::collects($order->paymentMethod)) {
            return null;
        }

        $collected = (int) $order->shipments()->sum('cash_on_delivery');
        $left = $this->payments->amountDue($order)->minus(Money::ofMinor($collected, $order->currency));

        return $left->isPositive() ? $left : null;
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
        return ! $shipment->hasWaybill() && $shipment->order !== null && $this->carrier($shipment->order) !== null;
    }

    /**
     * Create the waybill of a shipment recorded without one (its lines, from its location).
     *
     * @throws OrderException
     */
    public function createFor(Shipment $shipment, ?Model $actor = null): Shipment
    {
        if ($shipment->hasWaybill()) {
            throw new OrderException(__('This shipment already has a waybill.'));
        }

        $order = $shipment->order()->firstOrFail();
        $cash = $this->cashOnDelivery($order);
        $quantities = $shipment->lines()->get()->mapWithKeys(fn (ShipmentLine $line) => [$line->order_item_id => $line->quantity])->all();
        $label = $this->book($order, $quantities, $shipment->location, $cash);

        $this->attach($shipment, $label, $cash);
        $this->workflow->addNote($order, __('Waybill :number created.', ['number' => $label->trackingNumber]), $actor);

        return $shipment;
    }

    /**
     * The waybill for download: the stored PDF, or a redirect to the carrier's copy.
     */
    public function download(Shipment $shipment): ?Response
    {
        if ($shipment->label_path !== null && Storage::disk('local')->exists($shipment->label_path)) {
            return Storage::disk('local')->download($shipment->label_path, 'waybill-'.($shipment->tracking_number ?? $shipment->id).'.pdf', ['Content-Type' => 'application/pdf']);
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
