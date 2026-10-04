<?php

namespace Tests\Feature\Inventory;

use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Filament\Resources\Products\Pages\EditProduct;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\Filament\Resources\StockLocations\Pages\ManageStockLocations;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\Models\StockMovement;
use PnShop\Inventory\StockMovementReason;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Stock kept at several locations: what the shop sells, counting and moving stock in the
 * admin and the Admin API.
 */
class StockLocationsTest extends AdminTestCase
{
    private const API = '/api/admin/v1';

    private StockLocation $main;

    private StockLocation $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = StockLocation::default();
        $this->shop = StockLocation::query()->create(['code' => 'sofia', 'name' => 'Sofia shop', 'city' => 'Sofia', 'country_code' => 'BG']);
    }

    private function variant(int $mainStock = 5): ProductVariant
    {
        $product = Product::factory()->active()->create(['stock' => $mainStock]);

        return $product->defaultVariant();
    }

    /** @return array{Authorization: string} */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->withPermissions(['catalog.inventory.manage', 'catalog.products.view'])->create(), 'erp', ['catalog.inventory.manage', 'catalog.products.view'])->plainTextToken];
    }

    public function test_the_shop_sells_the_stock_of_active_locations_that_sell_online(): void
    {
        $variant = $this->variant(5);
        $inventory = app(InventoryService::class);
        $inventory->setOnHand($variant, 3, location: $this->shop);

        $this->assertSame(8, $variant->fresh()->available());

        $this->shop->update(['sells_online' => false]);
        $this->assertSame(5, $variant->fresh()->available(), 'Stock kept for the physical shop is not sold online.');
        $this->assertSame(3, $inventory->availableAt($variant->fresh(), $this->shop));

        $this->shop->update(['sells_online' => true, 'is_active' => false]);
        $this->assertSame(5, $variant->fresh()->available());
    }

    public function test_transfers_move_available_stock_and_are_recorded_at_both_locations(): void
    {
        $variant = $this->variant(5);
        $inventory = app(InventoryService::class);
        $inventory->reserve($variant, 2);

        $inventory->transfer($variant, $this->main, $this->shop, 3, note: 'Shop restock');

        $this->assertSame(2, $inventory->onHandAt($variant->fresh(), $this->main));
        $this->assertSame(3, $inventory->onHandAt($variant->fresh(), $this->shop));

        $moves = StockMovement::query()->where('reason', StockMovementReason::Transfer)->orderBy('id')->get();
        $this->assertSame([[$this->main->id, -3], [$this->shop->id, 3]], $moves->map(fn (StockMovement $move) => [$move->stock_location_id, $move->quantity])->all());

        // The two reserved units stay.
        $this->expectException(InsufficientStock::class);
        $inventory->transfer($variant->fresh(), $this->main, $this->shop, 1);
    }

    public function test_staff_manage_locations_and_stock_per_location(): void
    {
        $this->actingAsAdministrator();
        $product = Product::factory()->active()->create(['stock' => 4]);
        $variant = $product->defaultVariant();

        Livewire::test(ManageStockLocations::class)
            ->callAction('create', data: ['name' => 'Plovdiv', 'code' => 'plovdiv', 'is_active' => true, 'sells_online' => false])
            ->assertHasNoActionErrors()
            ->assertTableActionHidden('delete', $this->main)
            ->callTableAction('makeDefault', $this->shop);

        $this->assertTrue($this->shop->fresh()->is_default);
        $this->assertFalse($this->main->fresh()->is_default);

        $plovdiv = StockLocation::query()->where('code', 'plovdiv')->sole();
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('stockByLocation', data: ["location_{$this->main->id}" => 4, "location_{$this->shop->id}" => 6, "location_{$plovdiv->id}" => 2, 'note' => 'Count'])
            ->assertHasNoActionErrors()
            ->callAction('transferStock', data: ['from' => $this->shop->id, 'to' => $plovdiv->id, 'quantity' => 1])
            ->assertHasNoActionErrors();

        $inventory = app(InventoryService::class);
        $this->assertSame([4, 5, 3], [$inventory->onHandAt($variant->fresh(), $this->main), $inventory->onHandAt($variant->fresh(), $this->shop), $inventory->onHandAt($variant->fresh(), $plovdiv)]);

        // A location holding stock cannot be deleted.
        Livewire::test(ManageStockLocations::class)->assertTableActionHidden('delete', $plovdiv);
    }

    public function test_the_product_form_stock_field_edits_the_default_location(): void
    {
        $this->actingAsAdministrator();
        $product = Product::factory()->active()->create(['stock' => 4]);
        app(InventoryService::class)->setOnHand($product->defaultVariant(), 7, location: $this->shop);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet(['stock' => 4])
            ->fillForm(['stock' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        $inventory = app(InventoryService::class);
        $this->assertSame(9, $inventory->onHandAt($product->defaultVariant()->fresh()));
        $this->assertSame(7, $inventory->onHandAt($product->defaultVariant()->fresh(), $this->shop));
    }

    public function test_the_admin_api_manages_locations_and_stock_per_location(): void
    {
        $variant = $this->variant(5);
        $headers = $this->headers();

        $this->withHeaders($headers)->getJson(self::API.'/stock-locations')
            ->assertOk()
            ->assertJsonPath('data.0.code', $this->main->code)
            ->assertJsonPath('data.1.code', 'sofia');

        $id = $this->withHeaders($headers)->postJson(self::API.'/stock-locations', ['code' => 'varna', 'name' => 'Varna', 'sells_online' => false])
            ->assertCreated()
            ->json('data.id');
        $this->withHeaders($headers)->patchJson(self::API.'/stock-locations/'.$this->main->id, ['is_active' => false])->assertUnprocessable();

        $this->withHeaders($headers)->postJson(self::API."/variants/{$variant->id}/stock", ['on_hand' => 4, 'location' => 'sofia'])
            ->assertOk()
            ->assertJsonPath('data.on_hand', 9)
            ->assertJsonPath('data.available', 9);

        $this->withHeaders($headers)->postJson(self::API."/variants/{$variant->id}/stock/transfers", ['from' => 'sofia', 'to' => 'varna', 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.available', 6)
            ->assertJsonFragment(['location' => 'varna', 'on_hand' => 3, 'reserved' => 0, 'available' => 3]);

        $this->withHeaders($headers)->postJson(self::API."/variants/{$variant->id}/stock/transfers", ['from' => 'sofia', 'to' => 'varna', 'quantity' => 2])
            ->assertUnprocessable();

        // Still holds stock.
        $this->withHeaders($headers)->deleteJson(self::API."/stock-locations/{$id}")->assertForbidden();
    }
}
