<?php

namespace PnShop\Returns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Customer\Models\User;
use PnShop\Customer\PostalAddress;
use PnShop\Foundation\NumberSequence;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\StockMovementReason;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\RefundService;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\Models\ReturnRequestLine;
use PnShop\Returns\Notifications\ReturnLabelIssued;
use PnShop\Returns\Notifications\ReturnUpdated;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderAddress;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\StockAllocations;
use PnShop\Settings\Settings;
use PnShop\Shipping\Contracts\ProvidesReturnLabels;
use PnShop\Shipping\ReturnLabelRequest;

/**
 * Return requests (RMA): customers ask to send shipped units back within the return
 * window; staff approve or reject, record what arrived (optionally back into stock),
 * refund it through RefundService and close the request. Every step is noted in the
 * order history, and the customer gets an email (setting notifications.returns).
 */
class ReturnService
{
    public function __construct(
        private Settings $settings,
        private OrderWorkflow $workflow,
        private InventoryService $inventory,
        private RefundService $refunds,
    ) {}

    /**
     * Whether the order can get a return request now, and how many units of each line.
     *
     * @return array{allowed: bool, reason: string|null, deadline: Carbon|null, items: array<int, int>}
     */
    public function eligibility(Order $order): array
    {
        $order->loadMissing(['items', 'shipments']);
        $deny = fn (string $reason, ?Carbon $deadline = null) => ['allowed' => false, 'reason' => $reason, 'deadline' => $deadline, 'items' => []];

        if (! $this->settings->get('returns.enabled')) {
            return $deny(__('Returns are not accepted online. Please contact us.'));
        }

        if ($order->status === OrderStatus::Cancelled || ! in_array($order->fulfillment_status, [FulfillmentStatus::PartiallyFulfilled, FulfillmentStatus::Fulfilled], true)) {
            return $deny(__('Items can be returned once they have shipped.'));
        }

        $shippedAt = $order->shipments->max('shipped_at') ?? $order->created_at;
        $deadline = Carbon::parse($shippedAt)->addDays((int) $this->settings->get('returns.window_days'))->endOfDay();

        if ($deadline->isPast()) {
            return $deny(__('The return period for this order has ended.'), $deadline);
        }

        $pending = $this->unitsInOpenReturns($order);
        $items = [];

        foreach ($order->items as $item) {
            $left = $item->quantity_fulfilled - $item->quantity_refunded - ($pending[$item->id] ?? 0);

            if ($left > 0) {
                $items[$item->id] = $left;
            }
        }

        return $items === []
            ? $deny(__('Everything from this order is already in a return request or refunded.'), $deadline)
            : ['allowed' => true, 'reason' => null, 'deadline' => $deadline, 'items' => $items];
    }

    /**
     * @param  array<int, int>  $quantities  order item id => units to return
     *
     * @throws OrderException when the order or the quantities are not returnable
     */
    public function request(Order $order, array $quantities, ReturnReason $reason, ?string $note = null, ?User $customer = null): ReturnRequest
    {
        $quantities = array_filter(array_map('intval', $quantities), fn (int $quantity) => $quantity > 0);

        $return = DB::transaction(function () use ($order, $quantities, $reason, $note, $customer) {
            // One request at a time per order, so two submissions cannot claim the same units.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $eligibility = $this->eligibility($order);

            if (! $eligibility['allowed']) {
                throw new OrderException((string) $eligibility['reason']);
            }

            if ($quantities === []) {
                throw new OrderException(__('Choose what you want to return.'));
            }

            foreach ($quantities as $itemId => $quantity) {
                if ($quantity > ($eligibility['items'][$itemId] ?? 0)) {
                    throw new OrderException(__('You can return at most :count of that item.', ['count' => $eligibility['items'][$itemId] ?? 0]));
                }
            }

            $prefix = (string) $this->settings->get('returns.number_prefix');

            $return = ReturnRequest::query()->create([
                'number' => $prefix.str_pad((string) NumberSequence::next('return'), 6, '0', STR_PAD_LEFT),
                'order_id' => $order->id,
                // The order's customer, also when someone else opened a shared order link.
                'user_id' => $order->user_id,
                'status' => ReturnStatus::Requested,
                'reason' => $reason,
                'customer_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            foreach ($quantities as $itemId => $quantity) {
                $return->lines()->create(['order_item_id' => $itemId, 'quantity' => $quantity]);
            }

            $this->workflow->addNote($order, __('Return :number requested.', ['number' => $return->number]), $customer);

            return $return;
        });

        $this->notify($return);

        return $return;
    }

    public function approve(ReturnRequest $return, ?string $note = null, ?Model $actor = null): ReturnRequest
    {
        return $this->move($return, ReturnStatus::Approved, $note, $actor, ['approved_at' => now()]);
    }

    public function reject(ReturnRequest $return, ?string $note = null, ?Model $actor = null): ReturnRequest
    {
        return $this->move($return, ReturnStatus::Rejected, $note, $actor, ['closed_at' => now()]);
    }

    /**
     * Record what came back, and put it back on the shelf when it can be sold again.
     *
     * @param  array<int, int>  $received  return line id => units received (default: as requested)
     */
    public function receive(ReturnRequest $return, array $received = [], bool $restock = true, ?Model $actor = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $received, $restock, $actor) {
            // Under the return's lock and before any stock moves: a second "receive" (double
            // click, two staff) fails here instead of putting the goods on the shelf twice.
            ReturnRequest::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            $return->refresh()->load('lines.orderItem');
            $this->assertCanMove($return, ReturnStatus::Received);

            foreach ($return->lines as $line) {
                $quantity = array_key_exists($line->id, $received) ? (int) $received[$line->id] : $line->quantity;

                if ($quantity < 0 || $quantity > $line->quantity) {
                    throw new OrderException(__('At most :count of that item was to be returned.', ['count' => $line->quantity]));
                }

                $line->update(['quantity_received' => $quantity]);
                $variant = $line->orderItem?->product_variant_id ? ProductVariant::withTrashed()->find($line->orderItem->product_variant_id) : null;

                if ($restock && $quantity > 0 && $variant !== null) {
                    // Back to the location the units shipped from.
                    $this->inventory->adjust($variant, $quantity, StockMovementReason::Return, $return, $actor instanceof AdminUser ? $actor : null, $return->number, app(StockAllocations::class)->returnLocation($line->orderItem));
                }
            }

            return $this->move($return, ReturnStatus::Received, null, $actor, ['received_at' => now(), 'restocked' => $restock]);
        });
    }

    /**
     * Refund the received units (their paid price, tax included where it was added).
     */
    /**
     * @param  string  $destination  Refund::TO_ORIGINAL or Refund::TO_STORE_CREDIT
     */
    public function refund(ReturnRequest $return, ?Model $actor = null, string $destination = Refund::TO_ORIGINAL): ReturnRequest
    {
        // A double click must not refund twice: the status is re-read under the lock.
        $lock = Cache::lock('pnshop:return:'.$return->id, 120);

        if (! $lock->block(15)) {
            throw new OrderException(__('This return is being refunded already.'));
        }

        try {
            $return->refresh();

            return $this->refundLocked($return, $actor, $destination);
        } finally {
            $lock->release();
        }
    }

    private function refundLocked(ReturnRequest $return, ?Model $actor, string $destination = Refund::TO_ORIGINAL): ReturnRequest
    {
        $return->load(['lines', 'order']);

        $quantities = $return->lines->mapWithKeys(fn (ReturnRequestLine $line) => [$line->order_item_id => $line->quantity_received])->filter()->all();

        if ($quantities === []) {
            throw new OrderException(__('Nothing was received back to refund.'));
        }

        $this->assertCanMove($return, ReturnStatus::Refunded);

        $refund = $this->refunds->refund($return->order, $quantities, restock: false, reason: __('Return :number', ['number' => $return->number]), actor: $actor, destination: $destination);
        $return->forceFill(['refund_id' => $refund->id]);

        return $this->move($return, ReturnStatus::Refunded, null, $actor);
    }

    /**
     * Received units of the return, by order item id (what a refund or an exchange pays back).
     *
     * @return array<int, int>
     */
    public function receivedQuantities(ReturnRequest $return): array
    {
        return $return->lines()->get()->mapWithKeys(fn (ReturnRequestLine $line) => [$line->order_item_id => $line->quantity_received])->filter()->all();
    }

    /**
     * Whether the order's carrier can issue a return label for this return now.
     */
    public function canCreateLabel(ReturnRequest $return): bool
    {
        return $return->status === ReturnStatus::Approved
            && $return->return_label_url === null
            && $this->labelCarrier($return) !== null;
    }

    /**
     * Ask the order's carrier for a return label and send it to the customer.
     *
     * @throws OrderException when the carrier cannot or does not issue one.
     */
    public function createLabel(ReturnRequest $return, ?Model $actor = null): ReturnRequest
    {
        if ($return->status !== ReturnStatus::Approved) {
            throw new OrderException(__('Return labels are created for approved returns.'));
        }

        $order = $return->order()->with(['shippingMethod', 'addresses', 'items.allocations.location'])->firstOrFail();
        $carrier = $this->labelCarrier($return);
        $method = $order->shippingMethod;

        if ($carrier === null || $method === null) {
            throw new OrderException(__('The carrier of this order cannot issue return labels.'));
        }

        $shipping = $order->addresses->firstWhere('type', OrderAddress::SHIPPING);
        $return->loadMissing('lines.orderItem');

        try {
            $label = $carrier->returnLabel(new ReturnLabelRequest(
                $return->number,
                $shipping?->toPostalAddress() ?? PostalAddress::fromArray([]),
                $this->returnAddress($order),
                array_values($return->lines->map(fn (ReturnRequestLine $line) => [
                    'title' => (string) $line->orderItem?->product_title,
                    'quantity' => $line->quantity,
                    'weight' => (int) ProductVariant::withTrashed()->whereKey($line->orderItem?->product_variant_id)->value('weight'),
                ])->all()),
            ), $method);
        } catch (\RuntimeException $e) {
            throw new OrderException(__('The carrier did not issue a label: :message', ['message' => $e->getMessage()]));
        }

        $return->forceFill([
            'return_label_url' => $label->labelUrl,
            'return_tracking_number' => $label->trackingNumber,
            'return_carrier' => $method->name,
        ])->save();

        $this->workflow->addNote($order, __('Return :number: return label created.', ['number' => $return->number]), $actor);

        Notification::route('mail', $order->email)->notify((new ReturnLabelIssued($return))->locale($order->locale));

        return $return;
    }

    private function labelCarrier(ReturnRequest $return): ?ProvidesReturnLabels
    {
        $carrier = $return->order?->shippingMethod?->carrierInstance();

        return $carrier instanceof ProvidesReturnLabels ? $carrier : null;
    }

    /** Where returns go: the stock location the items shipped from, with the store's name. */
    private function returnAddress(Order $order): ?PostalAddress
    {
        $location = $order->items->flatMap(fn ($item) => $item->allocations)->first(fn ($allocation) => $allocation->quantity_shipped > 0)?->location;

        if ($location === null || $location->address === null) {
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

    /**
     * @throws OrderException when the return cannot be exchanged now (it must have been received).
     */
    public function assertCanExchange(ReturnRequest $return): void
    {
        $this->assertCanMove($return, ReturnStatus::Exchanged);
    }

    /**
     * Mark the return as exchanged for a new order (see PnShop\Credit\Exchanges).
     */
    public function markExchanged(ReturnRequest $return, Order $exchange, ?Model $actor = null): ReturnRequest
    {
        return $this->move($return, ReturnStatus::Exchanged, __('Exchanged for order :number.', ['number' => $exchange->number]), $actor, ['exchange_order_id' => $exchange->id]);
    }

    public function close(ReturnRequest $return, ?string $note = null, ?Model $actor = null): ReturnRequest
    {
        return $this->move($return, ReturnStatus::Closed, $note, $actor, ['closed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function move(ReturnRequest $return, ReturnStatus $to, ?string $note, ?Model $actor, array $attributes = []): ReturnRequest
    {
        $this->assertCanMove($return, $to);

        $return->forceFill([...$attributes, 'status' => $to, ...($note !== null && trim($note) !== '' ? ['staff_note' => trim($note)] : [])])->save();

        $this->workflow->addNote($return->order()->firstOrFail(), __('Return :number: :status.', ['number' => $return->number, 'status' => __($to->label())]), $actor);
        $this->notify($return);

        return $return;
    }

    private function assertCanMove(ReturnRequest $return, ReturnStatus $to): void
    {
        if (! $return->status->canTransitionTo($to)) {
            throw new OrderException(__('A return that is :from cannot become :to.', ['from' => __($return->status->label()), 'to' => __($to->label())]));
        }
    }

    /**
     * @return array<int, int> order item id => units in open return requests
     */
    private function unitsInOpenReturns(Order $order): array
    {
        return ReturnRequestLine::query()
            ->whereHas('returnRequest', fn ($query) => $query->where('order_id', $order->id)->whereIn('status', array_map(fn (ReturnStatus $status) => $status->value, array_filter(ReturnStatus::cases(), fn (ReturnStatus $status) => $status->isOpen()))))
            ->selectRaw('order_item_id, SUM(quantity) as units')
            ->groupBy('order_item_id')
            ->pluck('units', 'order_item_id')
            ->map(fn (mixed $units) => (int) $units)
            ->all();
    }

    private function notify(ReturnRequest $return): void
    {
        $order = $return->order()->firstOrFail();

        // A refunded return is announced by the refund email itself.
        if ($return->status !== ReturnStatus::Refunded && $order->email !== '' && $this->settings->get('notifications.returns')) {
            Notification::route('mail', $order->email)->notify(new ReturnUpdated($return->refresh()));
        }
    }
}
