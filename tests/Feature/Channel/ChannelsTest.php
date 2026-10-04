<?php

namespace Tests\Feature\Channel;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Models\Product;
use PnShop\Channel\Channels;
use PnShop\Channel\Filament\Resources\Channels\Pages\CreateChannel;
use PnShop\Channel\Models\Channel;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Filament\Resources\Orders\Pages\ListOrders;
use PnShop\Sales\Filament\Widgets\StoreStatsOverview;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Notifications\OrderConfirmation;
use PnShop\Settings\Settings;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Storefronts on their own domain or path: which one answers, what it overrides, and what
 * records which channel.
 */
class ChannelsTest extends AdminTestCase
{
    private Channel $main;

    private Channel $wholesale;

    private Channel $trade;

    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->set('store', ['name' => 'PN Demo']);
        $this->main = Channel::query()->where('is_default', true)->sole();
        $this->wholesale = Channel::query()->create(['code' => 'wholesale', 'name' => 'Wholesale', 'hostname' => 'Wholesale.Example.test', 'settings' => ['store' => ['name' => 'PN Wholesale']]]);
        $this->trade = Channel::query()->create(['code' => 'trade', 'name' => 'Trade', 'path' => '/trade/', 'locales' => ['bg', 'en'], 'default_locale' => 'bg']);
    }

    public function test_the_existing_store_is_the_default_channel(): void
    {
        $this->assertSame('default', $this->main->code);
        $this->assertSame('wholesale.example.test', $this->wholesale->hostname, 'Domains are stored lower case.');
        $this->assertSame('trade', $this->trade->path);

        $this->get('/shop')->assertOk()->assertInertia(fn (Assert $page) => $page->where('name', 'PN Demo'));
    }

    public function test_a_channel_on_its_own_domain_overrides_store_details(): void
    {
        $this->get('http://wholesale.example.test/shop')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('name', 'PN Wholesale')
            ->where('ziggy.url', 'http://wholesale.example.test'));

        // Outside the request, the shop's own settings are back.
        $this->assertSame('PN Demo', app(Settings::class)->get('store.name'));
        $this->get('http://localhost/shop')->assertInertia(fn (Assert $page) => $page->where('name', 'PN Demo'));
    }

    public function test_a_channel_on_a_path_keeps_its_prefix_and_its_languages(): void
    {
        // Bulgarian first: its unprefixed pages are in Bulgarian, English is under /trade/en.
        $this->get('/trade/shop')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('shop/index')
            ->where('localization.locale', 'bg')
            ->where('ziggy.url', 'http://localhost/trade'));

        $this->get('/trade/en/shop')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('localization.locale', 'en')
            ->where('ziggy.url', 'http://localhost/trade/en'));

        $this->get('/trade')->assertOk()->assertInertia(fn (Assert $page) => $page->component('home'));

        // The main store is unchanged.
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('localization.locale', 'en')->where('ziggy.url', 'http://localhost'));
    }

    public function test_carts_and_orders_belong_to_their_channel(): void
    {
        $product = Product::factory()->active()->create(['stock' => 10]);

        $this->post('/trade/cart', ['product_id' => $product->id, 'quantity' => 2]);
        $this->get('/trade/cart')->assertInertia(fn (Assert $page) => $page->where('cart.total_quantity', 2));

        // The main store's cart is another one.
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page->where('cart.total_quantity', 0));

        $this->post('/trade/checkout', $this->checkoutData(PaymentMethod::factory()->create(['gateway' => 'bank_transfer'])->id))->assertSessionMissing('error');
        $order = Order::query()->sole();
        $this->assertSame($this->trade->id, $order->channel_id);
    }

    public function test_order_emails_link_to_the_order_s_channel(): void
    {
        $product = Product::factory()->active()->create(['stock' => 10]);
        $this->post('http://wholesale.example.test/cart', ['product_id' => $product->id, 'quantity' => 1]);
        $this->post('http://wholesale.example.test/checkout', $this->checkoutData(PaymentMethod::factory()->create(['gateway' => 'bank_transfer'])->id))->assertSessionMissing('error');
        $order = Order::query()->sole();
        $this->assertSame($this->wholesale->id, $order->channel_id);

        // As the mail channel sends it (a queued job: no request, no channel).
        $notification = new OrderConfirmation($order);
        $notifiable = new AnonymousNotifiable;
        event(new NotificationSending($notifiable, $notification, 'mail'));
        $mail = $notification->toMail($notifiable);
        event(new NotificationSent($notifiable, $notification, 'mail'));

        $this->assertStringStartsWith('http://wholesale.example.test/', (string) $mail->actionUrl);
        $this->assertStringContainsString('PN Wholesale', (string) $mail->salutation);
        $this->assertFalse(app(Channels::class)->isActive(), 'Back to no channel afterwards.');
    }

    public function test_staff_add_channels_without_hiding_pages(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateChannel::class)
            ->fillForm(['name' => 'Outlet', 'code' => 'outlet', 'path' => 'shop'])
            ->call('create')
            ->assertHasFormErrors(['path']);

        Livewire::test(CreateChannel::class)
            ->fillForm(['name' => 'Outlet', 'code' => 'outlet', 'hostname' => 'outlet.example.test', 'currency' => 'USD', 'settings' => ['store' => ['name' => 'PN Outlet']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $outlet = Channel::query()->where('code', 'outlet')->sole();
        $this->assertSame('PN Outlet', $outlet->setting('store.name'));
        $this->assertContains('outlet.example.test', app(Channels::class)->hostnames());
    }

    public function test_staff_see_orders_and_sales_per_channel(): void
    {
        // Placed today: 20.00 in the main store, 40.00 on the trade channel.
        Order::factory()->create(['channel_id' => $this->main->id, 'currency' => 'USD', 'total' => '20.00']);
        Order::factory()->create(['channel_id' => $this->trade->id, 'currency' => 'USD', 'total' => '40.00']);

        $main = Order::query()->where('channel_id', $this->main->id)->sole();
        $trade = Order::query()->where('channel_id', $this->trade->id)->sole();
        $this->actingAsAdministrator();

        Livewire::test(StoreStatsOverview::class, ['pageFilters' => ['channel_id' => $this->trade->id]])
            ->assertSee('$40.00')
            ->assertDontSee('$60.00');
        Livewire::test(StoreStatsOverview::class)->assertSee('$60.00');

        Livewire::test(ListOrders::class)
            ->filterTable('channel_id', $this->trade->id)
            ->assertCanSeeTableRecords([$trade])
            ->assertCanNotSeeTableRecords([$main]);

        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'erp', ['*'])->plainTextToken];
        $this->withHeaders($headers)->getJson('http://localhost/api/admin/v1/orders?filter[channel]=trade')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'trade');
    }
}
