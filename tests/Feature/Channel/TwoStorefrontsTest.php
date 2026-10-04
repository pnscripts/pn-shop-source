<?php

namespace Tests\Feature\Channel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PnShop\Catalog\Models\Product;
use PnShop\Channel\Models\Channel;
use PnShop\Localization\Models\Currency;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Models\Order;
use PnShop\Theme\ThemeManager;
use PnShop\Theme\ThemeManifest;
use Tests\TestCase;

/**
 * The 1.5 exit criteria: two storefronts on their own domains, one installation, each with
 * its own theme, currency and part of the catalog, taking orders independently.
 */
class TwoStorefrontsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'http://shop.example.test';

    private const EU = 'http://eu.example.test';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pnshop-themes-'.bin2hex(random_bytes(4));
        File::copyDirectory(base_path('tests/Fixtures/themes'), $this->root.'/themes');
        File::ensureDirectoryExists($this->root.'/public');

        config(['pnshop.themes.path' => $this->root.'/themes']);
        $this->app->usePublicPath($this->root.'/public');
        $this->app->forgetInstance(ThemeManager::class);

        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'exchange_rate' => '0.9', 'is_active' => true]);

        Channel::query()->where('is_default', true)->sole()->update(['hostname' => 'shop.example.test', 'name' => 'Shop']);
        $eu = Channel::query()->create([
            'code' => 'eu',
            'name' => 'Europe',
            'hostname' => 'eu.example.test',
            'currency' => 'EUR',
            'settings' => ['store' => ['name' => 'PN Europe'], 'appearance' => ['theme' => 'acme/child']],
        ]);

        $themes = app(ThemeManager::class);
        $themes->publish($themes->find('acme/child'));

        // The main shop: the core's prebuilt default storefront (a fixture bundle here).
        File::copyDirectory(base_path('tests/Fixtures/themes/acme/child/dist'), $this->root.'/core/dist');
        $themes->publish(new ThemeManifest(ThemeManifest::DEFAULT, 'PN Shop Default', '1.5.0', $this->root.'/core', true));
        $this->app->forgetInstance(ThemeManager::class);

        $this->withVite();

        // The lamp is sold everywhere, the mug in the main shop only, the scarf in Europe only.
        $this->lamp = Product::factory()->active()->create(['title' => 'Lamp', 'price' => '100.00', 'sale_price' => null, 'stock' => 10]);
        $this->mug = Product::factory()->active()->create(['title' => 'Mug', 'price' => '10.00', 'sale_price' => null, 'stock' => 10]);
        $this->scarf = Product::factory()->active()->create(['title' => 'Scarf', 'price' => '30.00', 'sale_price' => null, 'stock' => 10]);
        $this->mug->channels()->sync([Channel::query()->where('is_default', true)->value('id')]);
        $this->scarf->channels()->sync([$eu->id]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private Product $lamp;

    private Product $mug;

    private Product $scarf;

    /** @return list<string> */
    private function titles(string $root): array
    {
        return collect($this->get($root.'/shop')->assertOk()->viewData('page')['props']['products']['data'])->pluck('title')->sort()->values()->all();
    }

    public function test_two_storefronts_with_their_own_theme_currency_and_catalog_take_orders_independently(): void
    {
        // Theme.
        $main = (string) $this->get(self::SHOP.'/')->assertOk()->getContent();
        $this->assertStringContainsString('/vendor/pnshop/build/assets/app-child.js', $main);
        $this->assertStringNotContainsString('/themes/acme/child/build/', $main);
        $this->assertStringContainsString('/themes/acme/child/build/assets/app-child.js', (string) $this->get(self::EU.'/')->assertOk()->getContent());
        // (The fixture bundles only hold the home page.)
        $this->withoutVite();

        // Catalog.
        $this->assertSame(['Lamp', 'Mug'], $this->titles(self::SHOP));
        $this->assertSame(['Lamp', 'Scarf'], $this->titles(self::EU));

        // Orders, each in its currency, at the same time.
        $method = PaymentMethod::factory()->create(['gateway' => 'bank_transfer']);
        $this->post(self::SHOP.'/cart', ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post(self::SHOP.'/cart', ['product_id' => $this->mug->id, 'quantity' => 1]);
        $this->post(self::EU.'/cart', ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post(self::EU.'/cart', ['product_id' => $this->scarf->id, 'quantity' => 1]);
        // Not sold in Europe: refused.
        $this->post(self::EU.'/cart', ['product_id' => $this->mug->id, 'quantity' => 1]);

        $this->post(self::EU.'/checkout', $this->checkoutData($method->id))->assertSessionMissing('error')->assertSessionHasNoErrors();
        $this->post(self::SHOP.'/checkout', $this->checkoutData($method->id))->assertSessionMissing('error')->assertSessionHasNoErrors();

        $orders = Order::query()->with(['items', 'channel'])->get()->keyBy(fn (Order $order) => $order->channel->code);

        $this->assertSame('USD', $orders['default']->currency);
        $this->assertSame('110.00', (string) $orders['default']->total->getAmount());
        $this->assertEqualsCanonicalizing(['Lamp', 'Mug'], $orders['default']->items->pluck('product_title')->all());

        $this->assertSame('EUR', $orders['eu']->currency);
        $this->assertSame('117.00', (string) $orders['eu']->total->getAmount(), '90 + 27 euros.');
        $this->assertEqualsCanonicalizing(['Lamp', 'Scarf'], $orders['eu']->items->pluck('product_title')->all());

        // Stock is shared: one lamp left each storefront.
        $this->assertSame(8, $this->lamp->defaultVariant()->fresh()->available());
    }
}
