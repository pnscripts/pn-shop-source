<?php

namespace PnShop\Sales;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\OrderStockStatus;
use PnShop\Sales\Events\OrderReopening;
use PnShop\Sales\Events\OrderStateChanged;
use PnShop\Sales\Exceptions\InvalidOrderTransition;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderHistory;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderState;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;

/**
 * The only way to change an order's status, payment or fulfillment state.
 *
 * Every change is checked against the state machine, recorded in the order history
 * with its actor, moves stock where needed and dispatches OrderStateChanged after commit:
 *
 * - cancelling releases reserved stock, or puts shipped stock back on the shelf;
 * - reopening a cancelled order reserves the stock again (or takes it, when it had shipped);
 * - marking the order shipped takes the reserved stock off the shelf;
 * - a payment on a pending order moves it to processing.
 */
class OrderWorkflow
{
    public function __construct(private StockAllocations $allocations) {}

    /**
     * @param  (Closure(Order): bool)|null  $when  checked against the locked order first; when it
     *                                             returns false nothing changes (for jobs that select orders
     *                                             before locking them, such as the unpaid-order cancellation)
     *
     * @throws OrderException when the change is not allowed or stock is no longer available.
     */
    public function transition(Order $order, OrderState $to, ?Model $actor = null, ?string $note = null, ?Closure $when = null): Order
    {
        /** @var list<array{OrderState, OrderState}> $changes */
        $changes = [];

        DB::transaction(function () use ($order, $to, $actor, $note, $when, &$changes) {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            if ($when !== null && ! $when($locked)) {
                return;
            }

            $changes[] = $this->apply($locked, $to, $actor, $note);

            if ($to === PaymentStatus::Paid && $locked->status === OrderStatus::Pending) {
                $changes[] = $this->apply($locked, OrderStatus::Processing, $actor, null);
            }
        });

        $order->refresh();

        foreach (array_filter($changes) as [$from, $state]) {
            OrderStateChanged::dispatch($order, $from, $state, $state === $to ? $note : null, $actor);
        }

        return $order;
    }

    public function addNote(Order $order, string $note, ?Model $actor = null): OrderHistory
    {
        return $this->record($order, OrderHistory::NOTE, null, null, $note, $actor);
    }

    /**
     * The first history entry, written by checkout.
     */
    public function recordPlaced(Order $order, ?Model $actor = null): void
    {
        $this->record($order, OrderStatus::field(), null, $order->status->value, 'Order placed', $actor);
    }

    /**
     * @return array{OrderState, OrderState}|null the change made, or null when the order already was in that state
     */
    private function apply(Order $order, OrderState $to, ?Model $actor, ?string $note): ?array
    {
        $field = $to::field();
        /** @var OrderState $from */
        $from = $order->{$field};

        if ($from === $to && ! $to->canTransitionTo($to)) {
            return null;
        }

        if (! $from->canTransitionTo($to)) {
            throw InvalidOrderTransition::between($from, $to);
        }

        if ($order->status === OrderStatus::Cancelled && $to instanceof FulfillmentStatus) {
            throw InvalidOrderTransition::cancelled();
        }

        // Listeners (coupon usage) can still refuse a reopening, inside this transaction.
        if ($order->status === OrderStatus::Cancelled && $to === OrderStatus::Pending) {
            OrderReopening::dispatch($order);
        }

        $this->moveStock($order, $this->stockStatusAfter($order, $to));

        $order->{$field} = $to;
        $order->save();

        $this->record($order, $field, $from->value, $to->value, $note, $actor);

        return [$from, $to];
    }

    private function stockStatusAfter(Order $order, OrderState $to): OrderStockStatus
    {
        return match (true) {
            $to === OrderStatus::Cancelled => OrderStockStatus::Released,
            $order->status === OrderStatus::Cancelled && $to instanceof OrderStatus => in_array($order->fulfillment_status, [FulfillmentStatus::Fulfilled, FulfillmentStatus::Returned], true)
                ? OrderStockStatus::Fulfilled
                : OrderStockStatus::Reserved,
            $to === FulfillmentStatus::Fulfilled => OrderStockStatus::Fulfilled,
            default => $order->stock_status,
        };
    }

    private function moveStock(Order $order, OrderStockStatus $target): void
    {
        if ($target === $order->stock_status) {
            return;
        }

        foreach ($order->items->whereNotNull('product_variant_id')->sortBy('product_variant_id') as $item) {
            $this->moveItemStock($order, $item, $order->stock_status, $target);
        }

        $order->stock_status = $target;
    }

    /**
     * Move one line's stock. `quantity_fulfilled` says how much of the line already left
     * the shelf (through shipments), so partial shipments are never taken twice.
     */
    private function moveItemStock(Order $order, OrderItem $item, OrderStockStatus $from, OrderStockStatus $to): void
    {
        $variant = ProductVariant::withTrashed()->find($item->product_variant_id);

        if ($variant === null) {
            return;
        }

        $kept = $item->quantityKept();

        // Stock moves at the locations the line's units are held at (StockAllocations).
        try {
            switch ([$from, $to]) {
                case [OrderStockStatus::Reserved, OrderStockStatus::Fulfilled]:
                    $this->allocations->fulfilAll($item, $variant, $order);
                    $item->quantity_fulfilled = $kept;
                    break;
                case [OrderStockStatus::Reserved, OrderStockStatus::Released]:
                    $this->allocations->releaseAll($item, $variant, $order, reserved: true);
                    $item->quantity_fulfilled = 0;
                    break;
                case [OrderStockStatus::Fulfilled, OrderStockStatus::Released]:
                    $this->allocations->releaseAll($item, $variant, $order, reserved: false);
                    $item->quantity_fulfilled = 0;
                    break;
                case [OrderStockStatus::Released, OrderStockStatus::Reserved]:
                    $this->allocations->reopen($item, $variant, $order, shipped: false);
                    break;
                case [OrderStockStatus::Released, OrderStockStatus::Fulfilled]:
                    $this->allocations->reopen($item, $variant, $order, shipped: true);
                    $item->quantity_fulfilled = $kept;
                    break;
            }
        } catch (InsufficientStock) {
            throw new OrderException(__('Not enough stock to reopen this order (:product).', ['product' => (string) $item->product_title]));
        }

        $item->save();
    }

    private function record(Order $order, string $field, ?string $from, ?string $to, ?string $note, ?Model $actor): OrderHistory
    {
        return OrderHistory::query()->create([
            'order_id' => $order->id,
            'field' => $field,
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
        ]);
    }
}
