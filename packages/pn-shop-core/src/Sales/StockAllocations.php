<?php

namespace PnShop\Sales;

use Illuminate\Database\Eloquent\Collection;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Channel\Channels;
use PnShop\Channel\Models\Channel;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\StockMovementReason;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\Models\OrderItemAllocation;

/**
 * Which stock locations hold an order line's units, and every stock move that follows
 * from it: reserving at checkout, shipping, cancelling, reopening and refunding.
 *
 * Units are taken from the active locations that sell online: first those in the
 * customer's country, then the default location, then by position. One location serves
 * the whole line when it can; otherwise the line is split. What no location has (sold on
 * backorder) goes to the first one.
 *
 * Orders placed before 1.4 have no allocations: theirs are created at the default
 * location the first time they are needed.
 */
final class StockAllocations
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * Where to take units from.
     *
     * @return list<array{0: StockLocation, 1: int}>
     */
    public function plan(ProductVariant $variant, int $quantity, ?string $countryCode = null, ?StockLocation $only = null, ?Channel $channel = null): array
    {
        $candidates = $only !== null ? [$only] : $this->candidates($countryCode, $channel);

        if (! $variant->track_inventory) {
            return [[$candidates[0], $quantity]];
        }

        foreach ($candidates as $location) {
            if ($this->inventory->availableAt($variant, $location) >= $quantity) {
                return [[$location, $quantity]];
            }
        }

        $plan = [];
        $left = $quantity;

        foreach ($candidates as $location) {
            $take = min($left, (int) $this->inventory->availableAt($variant, $location));

            if ($take > 0) {
                $plan[$location->id] = [$location, $take];
                $left -= $take;
            }

            if ($left === 0) {
                break;
            }
        }

        if ($left > 0) {
            $first = $candidates[0];
            $plan[$first->id] = [$first, ($plan[$first->id][1] ?? 0) + $left];
        }

        return array_values($plan);
    }

    /**
     * Reserve a new line's units (checkout).
     *
     * @throws InsufficientStock
     */
    public function reserve(OrderItem $item, ProductVariant $variant, int $quantity, ?string $countryCode = null, ?StockLocation $only = null): void
    {
        foreach ($this->plan($variant, $quantity, $countryCode, $only) as [$location, $take]) {
            $this->inventory->reserve($variant, $take, $location);
            $this->add($item, $location, $take);
        }
    }

    /**
     * The line's allocations; created at the default location for older orders.
     *
     * @return Collection<int, OrderItemAllocation>
     */
    public function for(OrderItem $item): Collection
    {
        $allocations = $item->allocations()->orderBy('id')->get();

        if ($allocations->isEmpty() && $item->quantityKept() > 0) {
            $item->allocations()->create([
                'stock_location_id' => StockLocation::default()->id,
                'quantity' => $item->quantityKept(),
                'quantity_shipped' => min($item->quantity_fulfilled, $item->quantityKept()),
            ]);

            $allocations = $item->allocations()->orderBy('id')->get();
        }

        return $allocations;
    }

    /**
     * Units waiting to ship, per location: [location id => [order item id => units]].
     *
     * @return array<int, array<int, int>>
     */
    public function heldByLocation(Order $order): array
    {
        $held = [];

        foreach ($order->items as $item) {
            if ($item->product_variant_id === null || $item->quantityToShip() === 0) {
                continue;
            }

            foreach ($this->for($item) as $allocation) {
                if ($allocation->held() > 0) {
                    $location = $allocation->stock_location_id ?? StockLocation::default()->id;
                    $held[$location][$item->id] = ($held[$location][$item->id] ?? 0) + $allocation->held();
                }
            }
        }

        return $held;
    }

    /**
     * Ship units from a location: they leave its shelf. Units held elsewhere move their
     * reservation here first, if the location has them.
     *
     * @param  bool  $reserved  whether the order's units are reserved (else they already left the shelf)
     *
     * @throws OrderException when the location does not have the units.
     */
    public function ship(OrderItem $item, ?ProductVariant $variant, int $quantity, StockLocation $location, Order $order, bool $reserved): void
    {
        $allocations = $this->for($item);
        $here = $allocations->first(fn (OrderItemAllocation $allocation) => $allocation->stock_location_id === $location->id);
        $fromHere = min($quantity, $here?->held() ?? 0);
        $moved = $quantity - $fromHere;

        if ($moved > 0 && $reserved && $variant !== null && $variant->track_inventory && ! $variant->allow_backorder
            && (int) $this->inventory->availableAt($variant, $location) < $moved) {
            throw new OrderException(__('Only :count of :product are available at :location.', [
                'count' => (int) $this->inventory->availableAt($variant, $location),
                'product' => (string) $item->product_title,
                'location' => $location->name,
            ]));
        }

        // Take the rest from the other locations' held units: their reservation is let go.
        $left = $moved;

        foreach ($allocations as $allocation) {
            if ($left === 0) {
                break;
            }

            if ($allocation->stock_location_id === $location->id || $allocation->held() === 0) {
                continue;
            }

            $take = min($left, $allocation->held());

            if ($reserved && $variant !== null) {
                $this->inventory->release($variant, $take, $allocation->location ?? StockLocation::default());
            }

            $this->shrink($allocation, $take);
            $left -= $take;
        }

        if ($moved > 0) {
            $here = $this->add($item, $location, $moved);

            if ($reserved && $variant !== null) {
                $this->inventory->reserve($variant, $moved, $location);
            }
        }

        if ($reserved && $variant !== null) {
            $this->inventory->commit($variant, $quantity, StockMovementReason::OrderFulfilled, $order, $location);
        }

        $here?->increment('quantity_shipped', $quantity);
    }

    /**
     * All held units leave the shelf where they are (the order is marked fulfilled).
     */
    public function fulfilAll(OrderItem $item, ProductVariant $variant, Order $order): void
    {
        foreach ($this->for($item) as $allocation) {
            if ($allocation->held() > 0) {
                $this->inventory->commit($variant, $allocation->held(), StockMovementReason::OrderFulfilled, $order, $allocation->location ?? StockLocation::default());
                $allocation->update(['quantity_shipped' => $allocation->quantity]);
            }
        }
    }

    /**
     * The order is cancelled: held units are let go, shipped ones come back to where
     * they left from.
     */
    public function releaseAll(OrderItem $item, ProductVariant $variant, Order $order, bool $reserved): void
    {
        foreach ($this->for($item) as $allocation) {
            $location = $allocation->location ?? StockLocation::default();

            if ($reserved && $allocation->held() > 0) {
                $this->inventory->release($variant, $allocation->held(), $location);
            }

            if ($allocation->quantity_shipped > 0) {
                $this->inventory->adjust($variant, $allocation->quantity_shipped, StockMovementReason::OrderCancelled, $order, location: $location);
            }

            $allocation->update(['quantity_shipped' => 0]);
        }
    }

    /**
     * A cancelled order is reopened: its units are reserved again, or taken off the
     * shelf when it had already shipped, wherever they are now.
     *
     * @throws InsufficientStock
     */
    public function reopen(OrderItem $item, ProductVariant $variant, Order $order, bool $shipped): void
    {
        $item->allocations()->delete();
        $kept = $item->quantityKept();

        if ($kept === 0) {
            return;
        }

        foreach ($this->plan($variant, $kept, $order->shippingAddress?->country_code, channel: $order->channel) as [$location, $take]) {
            if ($shipped) {
                $this->inventory->adjust($variant, -$take, StockMovementReason::OrderReopened, $order, location: $location);
            } else {
                $this->inventory->reserve($variant, $take, $location);
            }

            $this->add($item, $location, $take, $shipped ? $take : 0);
        }
    }

    /**
     * Units refunded before they shipped: they will never ship.
     */
    public function cancel(OrderItem $item, ?ProductVariant $variant, int $quantity, bool $reserved): void
    {
        $left = $quantity;

        foreach ($this->for($item)->reverse() as $allocation) {
            if ($left === 0) {
                break;
            }

            $take = min($left, $allocation->held());

            if ($take === 0) {
                continue;
            }

            if ($reserved && $variant !== null) {
                $this->inventory->release($variant, $take, $allocation->location ?? StockLocation::default());
            }

            $this->shrink($allocation, $take);
            $left -= $take;
        }
    }

    /**
     * Where returned units of the line go back to: the location they shipped from.
     */
    public function returnLocation(OrderItem $item): StockLocation
    {
        $allocation = $item->allocations()->where('quantity_shipped', '>', 0)->orderBy('id')->first();

        return $allocation->location ?? StockLocation::default();
    }

    /**
     * @return non-empty-list<StockLocation>
     */
    private function candidates(?string $countryCode, ?Channel $channel = null): array
    {
        $country = $countryCode === null ? null : strtoupper($countryCode);
        $channels = app(Channels::class);

        // The channel's own locations (the order's, or the storefront's at checkout).
        $locations = StockLocation::query()->sellingOnline()->ordered()->get()
            ->filter(fn (StockLocation $location) => $channels->allows('stock_location_ids', $location->id, $channel))
            ->sortBy(fn (StockLocation $location) => [
                $country !== null && $location->country_code === $country ? 0 : 1,
                $location->is_default ? 0 : 1,
                $location->position,
                $location->id,
            ])
            ->values()
            ->all();

        return $locations === [] ? [StockLocation::default()] : array_values($locations);
    }

    private function add(OrderItem $item, StockLocation $location, int $quantity, int $shipped = 0): OrderItemAllocation
    {
        $allocation = $item->allocations()->firstOrNew(['stock_location_id' => $location->id], ['quantity' => 0, 'quantity_shipped' => 0]);
        $allocation->quantity += $quantity;
        $allocation->quantity_shipped += $shipped;
        $allocation->save();

        return $allocation;
    }

    private function shrink(OrderItemAllocation $allocation, int $quantity): void
    {
        $allocation->quantity -= $quantity;

        if ($allocation->quantity <= 0 && $allocation->quantity_shipped === 0) {
            $allocation->delete();
        } else {
            $allocation->save();
        }
    }
}
