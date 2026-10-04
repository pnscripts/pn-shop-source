<?php

namespace Tests\Feature\Inventory;

use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLevel;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\Models\StockMovement;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Filament\Resources\Orders\Pages\ViewOrder;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItemAllocation;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\StockAllocations;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ShipmentService;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Orders served from several stock locations: where units are reserved, shipping from
 * each location, cancelling and reopening, and a ledger that balances per location.
 */
class MultiLocationOrdersTest extends AdminTestCase
{
    private StockLocation $main;

    private StockLocation $sofia;

    private Product $lamp;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = StockLocation::default();
        $this->sofia = StockLocation::query()->create(['code' => 'sofia', 'name' => 'Sofia shop', 'country_code' => 'BG']);

        // 3 in the main warehouse, 4 in the Sofia shop.
        $this->lamp = Product::factory()->active()->create(['stock' => 3]);
        $this->variant = $this->lamp->defaultVariant();
        app(InventoryService::class)->setOnHand($this->variant, 4, location: $this->sofia);
    }

    private function order(int $quantity, string $country = 'BG'): Order
    {
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => $quantity]);
        $this->post(route('checkout.store'), $this->checkoutData(PaymentMethod::factory()->create(['gateway' => 'bank_transfer'])->id, ['shipping' => ['country_code' => $country]]))
            ->assertSessionMissing('error');

        return Order::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, int> location code => units */
    private function allocations(Order $order): array
    {
        return OrderItemAllocation::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->with('location')
            ->get()
            ->mapWithKeys(fn (OrderItemAllocation $allocation) => [$allocation->location->code => $allocation->quantity])
            ->sortKeys()
            ->all();
    }

    /** @return array{int, int} [on hand, reserved] */
    private function level(StockLocation $location): array
    {
        $level = StockLevel::query()->where(['product_variant_id' => $this->variant->id, 'stock_location_id' => $location->id])->first();

        return [(int) $level?->on_hand, (int) $level?->reserved];
    }

    private function assertLedgerBalances(): void
    {
        foreach ([$this->main, $this->sofia] as $location) {
            $moved = (int) StockMovement::query()->where(['product_variant_id' => $this->variant->id, 'stock_location_id' => $location->id])->sum('quantity');
            $this->assertSame($this->level($location)[0], $moved, "The ledger of {$location->code} adds up to what is on hand.");
        }
    }

    public function test_an_order_split_across_two_locations_ships_from_each(): void
    {
        $order = $this->order(5);

        // The customer's country first: all of Sofia's 4, the last one from the main warehouse.
        $this->assertSame(['default' => 1, 'sofia' => 4], $this->allocations($order));
        $this->assertSame([3, 1], $this->level($this->main));
        $this->assertSame([4, 4], $this->level($this->sofia));

        $shipments = app(ShipmentService::class);

        try {
            $shipments->ship($order);
            $this->fail('A parcel cannot leave from two locations.');
        } catch (OrderException $e) {
            $this->assertStringContainsString('several locations', $e->getMessage());
        }

        $first = $shipments->ship($order, [], 'TRACK-1', location: $this->sofia);
        $this->assertSame($this->sofia->id, $first->stock_location_id);
        $this->assertSame(4, (int) $first->lines()->sum('quantity'));
        $this->assertSame(FulfillmentStatus::PartiallyFulfilled, $order->refresh()->fulfillment_status);

        $shipments->ship($order->refresh());
        $this->assertSame(FulfillmentStatus::Fulfilled, $order->refresh()->fulfillment_status);
        $this->assertSame($this->main->id, $order->shipments()->reorder()->orderByDesc('id')->first()->stock_location_id);

        $this->assertSame([2, 0], $this->level($this->main));
        $this->assertSame([0, 0], $this->level($this->sofia));
        $this->assertLedgerBalances();

        // Cancelled after shipping: the units come back where they left from.
        app(OrderWorkflow::class)->transition($order->refresh(), OrderStatus::Cancelled);
        $this->assertSame([3, 0], $this->level($this->main));
        $this->assertSame([4, 0], $this->level($this->sofia));
        $this->assertLedgerBalances();
    }

    public function test_one_location_serves_the_whole_line_when_it_can(): void
    {
        // Abroad: the default location first, and it has all three.
        $order = $this->order(3, 'DE');
        $this->assertSame(['default' => 3], $this->allocations($order));

        // Not enough there for the next order: Sofia has 4.
        $next = $this->order(2, 'DE');
        $this->assertSame(['sofia' => 2], $this->allocations($next));
    }

    public function test_cancelling_and_reopening_reserve_again_where_stock_is(): void
    {
        $order = $this->order(5);
        $workflow = app(OrderWorkflow::class);

        $workflow->transition($order, OrderStatus::Cancelled);
        $this->assertSame([3, 0], $this->level($this->main));
        $this->assertSame([4, 0], $this->level($this->sofia));

        // Meanwhile Sofia sells two over the counter.
        app(InventoryService::class)->setOnHand($this->variant->fresh(), 2, location: $this->sofia);

        $workflow->transition($order->refresh(), OrderStatus::Pending);
        $this->assertSame(['default' => 3, 'sofia' => 2], $this->allocations($order));
        $this->assertSame([3, 3], $this->level($this->main));
        $this->assertSame([2, 2], $this->level($this->sofia));
        $this->assertLedgerBalances();
    }

    public function test_shipping_from_another_location_moves_the_reservation(): void
    {
        $order = $this->order(2);
        $this->assertSame(['sofia' => 2], $this->allocations($order));

        // Without quantities a location ships what waits there; here staff pick the items.
        app(ShipmentService::class)->ship($order, [$order->items()->sole()->id => 2], location: $this->main);

        $this->assertSame([1, 0], $this->level($this->main));
        $this->assertSame([4, 0], $this->level($this->sofia));
        $this->assertSame(['default' => 2], $this->allocations($order));
        $this->assertLedgerBalances();

        // The main warehouse cannot ship what it does not have.
        $big = $this->order(3);
        $this->expectException(OrderException::class);
        app(ShipmentService::class)->ship($big, [$big->items()->sole()->id => 3], location: $this->main);
    }

    public function test_orders_from_before_locations_ship_from_the_default_location(): void
    {
        $order = $this->order(2, 'DE');
        $this->assertSame(['default' => 2], $this->allocations($order));

        // As placed by 1.3: no allocation rows.
        OrderItemAllocation::query()->delete();

        app(ShipmentService::class)->ship($order);

        $this->assertSame([1, 0], $this->level($this->main));
        $this->assertSame(['default' => 2], $this->allocations($order));
        $this->assertLedgerBalances();
    }

    public function test_staff_choose_the_location_in_the_admin_and_the_api(): void
    {
        $order = $this->order(5);
        $this->actingAsAdministrator();
        $item = $order->items()->sole();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('createShipment')
            ->assertActionDataSet(['location' => $this->sofia->id, "quantities.{$item->id}" => 4])
            ->setActionData(['location' => $this->main->id])
            ->assertActionDataSet(["quantities.{$item->id}" => 1])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([2, 0], $this->level($this->main));

        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'erp', ['*'])->plainTextToken];
        $detail = $this->withHeaders($headers)->postJson("/api/admin/v1/orders/{$order->id}/shipments", ['location' => 'sofia'])
            ->assertCreated()
            ->assertJsonPath('data.fulfillment_status', 'fulfilled')
            ->json('data');

        $this->assertEqualsCanonicalizing(['default', 'sofia'], array_column($detail['shipments'], 'location'));
        $this->assertSame(['default' => 1, 'sofia' => 4], collect($detail['allocations'][$item->id])->sortBy('location')->pluck('quantity_shipped', 'location')->all());

        $this->assertLedgerBalances();
    }

    public function test_units_refunded_before_shipping_stop_being_held(): void
    {
        $order = $this->order(5);
        $item = $order->items()->sole();

        // What RefundService does for 3 units that will never ship.
        app(StockAllocations::class)->cancel($item, $this->variant->fresh(), 3, reserved: true);

        $this->assertSame(['sofia' => 2], $this->allocations($order));
        $this->assertSame([3, 0], $this->level($this->main));
        $this->assertSame([4, 2], $this->level($this->sofia));
    }

    public function test_pickup_reserves_at_its_location_and_shows_availability(): void
    {
        $zone = ShippingZone::factory()->create();
        $pickup = ShippingMethod::factory()->for($zone, 'zone')
            ->create(['name' => 'Pick up in Sofia', 'carrier' => 'pickup', 'settings' => ['cost' => '0', 'stock_location' => 'sofia']]);
        ShippingMethod::factory()->for($zone, 'zone')->create(['name' => 'Courier', 'settings' => ['cost' => '5.00']]);

        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 3]);
        $options = collect($this->postJson(route('checkout.quote'), ['country_code' => 'DE'])->assertOk()->json('options'))->keyBy('name');

        $this->assertSame(['location' => 'Sofia shop', 'address' => '', 'in_stock' => true], $options['Pick up in Sofia']['pickup']);
        $this->assertNull($options['Courier']['pickup']);

        // Abroad, a courier order would come from the default location; pickup is served from Sofia.
        $this->post(route('checkout.store'), $this->checkoutData(PaymentMethod::factory()->create(['gateway' => 'bank_transfer'])->id, ['shipping' => ['country_code' => 'DE'], 'shipping_method_id' => $pickup->id]))
            ->assertSessionMissing('error');
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(['sofia' => 3], $this->allocations($order));

        // Sofia has one left: not enough for two more.
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 2]);
        $this->assertFalse(collect($this->postJson(route('checkout.quote'), ['country_code' => 'BG'])->json('options'))->firstWhere('name', 'Pick up in Sofia')['pickup']['in_stock']);
        $this->post(route('checkout.store'), $this->checkoutData(PaymentMethod::query()->firstOrFail()->id, ['shipping_method_id' => $pickup->id]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'pickup at Sofia shop'));
    }
}
