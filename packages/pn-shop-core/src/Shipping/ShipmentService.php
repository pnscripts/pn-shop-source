<?php

namespace PnShop\Shipping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\OrderStockStatus;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\StockAllocations;
use PnShop\Shipping\Events\ShipmentCreated;
use PnShop\Shipping\Models\Shipment;

/**
 * Records parcels: the shipped lines leave the shelf of the stock location they ship
 * from, and the order becomes partially shipped or shipped.
 */
class ShipmentService
{
    public function __construct(
        private StockAllocations $allocations,
        private OrderWorkflow $workflow,
    ) {}

    /**
     * Without a location, the parcel leaves from the location holding the units; when they
     * are held at several, choose one. With a location and no quantities, everything held
     * there ships.
     *
     * @param  array<int, int>  $quantities  order item id => quantity to ship; empty ships everything left (at $location)
     *
     * @throws OrderException when the order is cancelled, a quantity is more than is left to ship, the units are
     *                        held at several locations and none was chosen, or the location does not have them.
     */
    public function ship(Order $order, array $quantities = [], ?string $trackingNumber = null, ?string $note = null, ?Model $actor = null, ?StockLocation $location = null): Shipment
    {
        $shipment = DB::transaction(function () use ($order, $quantities, $trackingNumber, $note, $actor, $location) {
            $order = Order::query()->with(['items', 'shippingMethod'])->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::Cancelled) {
                throw new OrderException(__('A cancelled order cannot be shipped.'));
            }

            if ($quantities === [] && $location !== null) {
                $quantities = $this->allocations->heldByLocation($order)[$location->id] ?? [];

                if ($quantities === []) {
                    throw new OrderException(__('Nothing on this order is waiting at :location.', ['location' => $location->name]));
                }
            }

            $lines = $this->linesToShip($order, $quantities);

            if ($lines === []) {
                throw new OrderException(__('Nothing is left to ship on this order.'));
            }

            $location ??= $this->locationFor($order, $lines);

            $method = $order->shippingMethod;
            $trackingNumber = $trackingNumber !== null && trim($trackingNumber) !== '' ? trim($trackingNumber) : null;

            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'shipping_method_id' => $method?->id,
                'stock_location_id' => $location->id,
                'carrier_name' => $method->name ?? $order->shipping_method_name,
                'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingNumber !== null && $method !== null ? $method->carrierInstance()?->trackingUrl($trackingNumber, $method) : null,
                'note' => $note,
                'shipped_at' => now(),
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
            ]);

            foreach ($lines as [$item, $quantity]) {
                $shipment->lines()->create(['order_item_id' => $item->id, 'quantity' => $quantity]);

                if ($item->product_variant_id !== null) {
                    // Orders placed before reservations already took their stock.
                    $this->allocations->ship($item, ProductVariant::withTrashed()->find($item->product_variant_id), $quantity, $location, $order, reserved: $order->stock_status === OrderStockStatus::Reserved);
                }

                $item->increment('quantity_fulfilled', $quantity);
            }

            return $shipment;
        });

        $order->refresh()->load('items');
        $complete = $order->items->every(fn (OrderItem $item) => $item->quantityToShip() === 0);

        $note = $shipment->tracking_number === null ? null : __('Tracking number: :number', ['number' => $shipment->tracking_number]);

        $this->workflow->transition($order, $complete ? FulfillmentStatus::Fulfilled : FulfillmentStatus::PartiallyFulfilled, $actor, is_string($note) ? $note : null);

        ShipmentCreated::dispatch($shipment);

        return $shipment;
    }

    /**
     * @param  array<int, int>  $quantities
     * @return list<array{OrderItem, int}>
     */
    private function linesToShip(Order $order, array $quantities): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            $left = $item->quantityToShip();
            $quantity = $quantities === [] ? $left : (int) ($quantities[$item->id] ?? 0);

            if ($quantity > $left) {
                throw new OrderException(__('Only :left of :product are left to ship.', ['left' => $left, 'product' => (string) $item->product_title]));
            }

            if ($quantity > 0) {
                $lines[] = [$item, $quantity];
            }
        }

        return $lines;
    }

    /**
     * The one location holding all of these units.
     *
     * @param  list<array{OrderItem, int}>  $lines
     *
     * @throws OrderException when they are held at several locations.
     */
    private function locationFor(Order $order, array $lines): StockLocation
    {
        $held = $this->allocations->heldByLocation($order);

        foreach ($held as $locationId => $items) {
            $covers = collect($lines)->every(fn (array $line) => $line[0]->product_variant_id === null || ($items[$line[0]->id] ?? 0) >= $line[1]);

            if ($covers) {
                return StockLocation::query()->findOrFail($locationId);
            }
        }

        if (count($held) <= 1) {
            return StockLocation::query()->whereKey(array_key_first($held))->first() ?? StockLocation::default();
        }

        throw new OrderException(__('These items are waiting at several locations. Choose the location this parcel leaves from.'));
    }
}
