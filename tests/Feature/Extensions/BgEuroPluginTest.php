<?php

namespace Tests\Feature\Extensions;

use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Channel\Models\Channel;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Currency;
use PnShop\Plugins\BgEuro\EuroCheck;
use PnShop\Plugins\BgEuro\Lev;
use PnShop\Settings\Settings;

/**
 * The euro prices plugin for Bulgaria (extensions/pnshop/bg-euro).
 */
class BgEuroPluginTest extends ShippedPluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePlugin('pnshop/bg-euro');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function sellInEuro(): void
    {
        Currency::query()->update(['is_default' => false]);
        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'exchange_rate' => '1', 'is_active' => true])->forceFill(['is_default' => true])->save();
        app(Localization::class)->flush();
    }

    public function test_conversion_uses_the_full_rate_and_rounds_on_the_third_decimal(): void
    {
        $this->assertSame('19.56', (string) Lev::fromEuro(Money::of('10.00', 'EUR'))->getAmount());
        $this->assertSame('1.96', (string) Lev::fromEuro(Money::of('1.00', 'EUR'))->getAmount());
        $this->assertSame('0.02', (string) Lev::fromEuro(Money::of('0.01', 'EUR'))->getAmount());
        $this->assertSame('10.00', (string) Lev::toEuro('19.56')->getAmount());
        $this->assertSame('0.51', (string) Lev::toEuro('1.00')->getAmount());
        // 2.50 / 1.95583 = 1.27823... → 1.28; 0.97 / 1.95583 = 0.49595... → 0.50
        $this->assertSame('1.28', (string) Lev::toEuro('2.50')->getAmount());
        $this->assertSame('0.50', (string) Lev::toEuro('0.97')->getAmount());
    }

    public function test_the_check_fails_while_the_shop_sells_in_another_currency(): void
    {
        // Tests run in USD.
        $this->assertFalse(app(EuroCheck::class)->passes());

        $this->sellInEuro();
        $this->assertTrue(app(EuroCheck::class)->passes());

        Channel::query()->update(['currency' => 'BGN']);
        $this->assertFalse(app(EuroCheck::class)->passes());
    }

    public function test_an_active_lev_must_use_the_fixed_rate(): void
    {
        $this->sellInEuro();
        Currency::query()->create(['code' => 'BGN', 'name' => 'Lev', 'exchange_rate' => '1.96', 'is_active' => true]);

        $this->assertFalse(app(EuroCheck::class)->passes());

        Currency::query()->where('code', 'BGN')->update(['exchange_rate' => '1.95583']);
        $this->assertTrue(app(EuroCheck::class)->passes());
    }

    public function test_prices_in_leva_are_shown_next_to_the_euro_only_when_switched_on(): void
    {
        $this->sellInEuro();
        Product::factory()->active()->create(['price' => '10.00', 'sale_price' => null, 'stock' => 3]);

        $this->get('/bg/shop')->assertInertia(fn (Assert $page) => $page->where('products.data.0.price.formatted', fn (string $text) => ! str_contains($text, 'лв')));

        app(Settings::class)->set('plugin.pnshop_bg_euro', ['show_leva' => true]);
        $this->get('/bg/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price.amount', '10.00')
            ->where('products.data.0.price.formatted', function (string $text) {
                // ICU writes non-breaking spaces between the amount and the sign.
                $text = (string) preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $text);

                return str_contains($text, '10,00') && str_contains($text, '€') && str_contains($text, '(19,56 лв.)');
            }));

        // It stops after the chosen date.
        Carbon::setTestNow('2026-12-01');
        app(Settings::class)->set('plugin.pnshop_bg_euro', ['show_leva' => true, 'show_leva_until' => '2026-11-30']);
        $this->get('/bg/shop')->assertInertia(fn (Assert $page) => $page->where('products.data.0.price.formatted', fn (string $text) => ! str_contains($text, 'лв')));
    }
}
