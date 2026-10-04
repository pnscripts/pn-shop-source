<?php

namespace Tests\Feature\Channel;

use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Filament\Resources\Products\Pages\EditProduct;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Channel\Models\Channel;
use PnShop\Cms\Models\Page;
use PnShop\Cms\PageStatus;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItemAllocation;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use Tests\Feature\Admin\AdminTestCase;

/**
 * What each channel shows and uses: products, categories and pages limited to channels,
 * and a channel's own stock locations, payment and shipping methods.
 */
class ChannelCatalogTest extends AdminTestCase
{
    private const MAIN = 'http://localhost';

    private const OUTLET = 'http://outlet.example.test';

    private Channel $main;

    private Channel $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Channel::query()->where('is_default', true)->sole();
        $this->outlet = Channel::query()->create(['code' => 'outlet', 'name' => 'Outlet', 'hostname' => 'outlet.example.test']);
    }

    /** @return list<int> */
    private function listed(string $root): array
    {
        return collect($this->get($root.'/shop')->assertOk()->viewData('page')['props']['products']['data'])->pluck('id')->all();
    }

    public function test_products_categories_and_pages_can_be_limited_to_channels(): void
    {
        $everywhere = Product::factory()->active()->create(['stock' => 5]);
        $outletOnly = Product::factory()->active()->create(['stock' => 5]);
        $outletOnly->channels()->sync([$this->outlet->id]);

        $this->assertEqualsCanonicalizing([$everywhere->id], $this->listed(self::MAIN));
        $this->assertEqualsCanonicalizing([$everywhere->id, $outletOnly->id], $this->listed(self::OUTLET));

        $this->get(self::MAIN.'/shop/'.$outletOnly->slug)->assertNotFound();
        $this->get(self::OUTLET.'/shop/'.$outletOnly->slug)->assertOk();
        $this->getJson(self::MAIN.'/api/store/v1/products/'.$outletOnly->slug)->assertNotFound();
        $this->getJson(self::OUTLET.'/api/store/v1/products/'.$outletOnly->slug)->assertOk();

        // It cannot be bought in the main store.
        $this->post(self::MAIN.'/cart', ['product_id' => $outletOnly->id, 'quantity' => 1]);
        $this->get(self::MAIN.'/cart')->assertInertia(fn (Assert $page) => $page->where('cart.total_quantity', 0));

        $clearance = Category::factory()->create(['is_active' => true]);
        $clearance->channels()->sync([$this->outlet->id]);
        $this->assertTrue(Category::query()->active()->whereKey($clearance->id)->exists(), 'Outside a storefront request every category counts.');
        $categories = fn (string $root) => collect($this->get($root.'/shop')->viewData('page')['props']['categories'])->pluck('id')->all();
        $this->assertNotContains($clearance->id, $categories(self::MAIN));
        $this->assertContains($clearance->id, $categories(self::OUTLET));

        $page = Page::factory()->create(['status' => PageStatus::Published, 'slug' => 'outlet-rules', 'is_home' => false]);
        $page->channels()->sync([$this->outlet->id]);
        $this->get(self::MAIN.'/outlet-rules')->assertNotFound();
        $this->get(self::OUTLET.'/outlet-rules')->assertOk();
    }

    public function test_a_channel_uses_its_own_stock_locations_payment_and_shipping_methods(): void
    {
        // Each channel sells its own stock: the main store its warehouse, the outlet its store.
        $outletStore = StockLocation::query()->create(['code' => 'outlet-store', 'name' => 'Outlet store']);
        $this->outlet->update(['stock_location_ids' => [$outletStore->id]]);
        $this->main->update(['stock_location_ids' => [StockLocation::default()->id]]);

        $lamp = Product::factory()->active()->create(['stock' => 5]);
        app(InventoryService::class)->setOnHand($lamp->defaultVariant(), 2, location: $outletStore);

        $zone = ShippingZone::factory()->create();
        $courier = ShippingMethod::factory()->for($zone, 'zone')->create(['name' => 'Courier', 'carrier' => 'flat_rate', 'settings' => ['cost' => '5.00']]);
        $van = ShippingMethod::factory()->for($zone, 'zone')->create(['name' => 'Outlet van', 'carrier' => 'flat_rate', 'settings' => ['cost' => '1.00']]);
        $card = PaymentMethod::factory()->create(['gateway' => 'bank_transfer', 'name' => 'Transfer']);
        $cash = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery', 'name' => 'Cash']);
        $this->outlet->update(['shipping_method_ids' => [$van->id], 'payment_method_ids' => [$cash->id]]);

        // Main store: 5 from the main warehouse; the outlet: its own 2.
        $this->get(self::MAIN.'/shop/'.$lamp->slug)->assertInertia(fn (Assert $page) => $page->where('product.variants.0.stock', 5));
        $this->get(self::OUTLET.'/shop/'.$lamp->slug)->assertInertia(fn (Assert $page) => $page->where('product.variants.0.stock', 2));

        $this->post(self::OUTLET.'/cart', ['product_id' => $lamp->id, 'quantity' => 2]);
        $options = collect($this->postJson(self::OUTLET.'/checkout/quote', ['country_code' => 'BG'])->json('options'))->pluck('name')->all();
        $this->assertSame(['Outlet van'], $options);

        // The main store's payment method is not offered by the outlet.
        $this->post(self::OUTLET.'/checkout', $this->checkoutData($card->id, ['shipping_method_id' => $van->id]))->assertSessionHas('error');
        $this->post(self::OUTLET.'/checkout', $this->checkoutData($cash->id, ['shipping_method_id' => $van->id]))->assertSessionMissing('error');

        $order = Order::query()->sole();
        $this->assertSame($outletStore->id, OrderItemAllocation::query()->sole()->stock_location_id);
        $this->assertSame(['Courier', 'Outlet van'], collect($this->postJson(self::MAIN.'/checkout/quote', ['country_code' => 'BG'])->json('options'))->pluck('name')->sort()->values()->all());
        $this->assertSame($this->outlet->id, $order->channel_id);
        $this->assertSame($courier->id, ShippingMethod::query()->where('name', 'Courier')->value('id'));
    }

    public function test_staff_limit_products_to_channels_in_the_admin_and_the_api(): void
    {
        $this->actingAsAdministrator();
        $product = Product::factory()->active()->create(['stock' => 1]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['channels' => [$this->outlet->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->outlet->id], $product->channels()->pluck('channels.id')->all());

        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'erp', ['*'])->plainTextToken];
        $this->withHeaders($headers)->patchJson("/api/admin/v1/products/{$product->id}", ['channel_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.channel_ids', []);
    }
}
