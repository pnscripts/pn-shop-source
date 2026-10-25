<?php

namespace Tests\Feature\Extensions;

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Settings\Settings;

/**
 * The 30-day prior price plugin (extensions/pnshop/prior-price).
 */
class PriorPricePluginTest extends ShippedPluginTestCase
{
    private Product $product;

    private ProductVariant $variant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function startOn(string $date, string $price, ?string $sale = null): void
    {
        Carbon::setTestNow($date);
        $this->product = Product::factory()->active()->create(['price' => $price, 'sale_price' => $sale, 'stock' => 9]);
        $this->variant = $this->product->defaultVariant();
        $this->enablePlugin('pnshop/prior-price');
    }

    private function change(string $date, array $attributes): void
    {
        Carbon::setTestNow($date);
        $this->variant->refresh()->update($attributes);
    }

    private function assertShown(?string $sale, ?string $prior): void
    {
        $this->get(route('shop.show', $this->product->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.sale_price.amount', $sale)
            ->where($prior === null ? 'product.variants.0.prior_price' : 'product.variants.0.prior_price.amount', $prior)
            ->where('priorPrice.days', 30));
    }

    public function test_the_prior_price_is_the_lowest_of_the_30_days_before_the_sale(): void
    {
        $this->startOn('2026-09-01 10:00', '100.00');
        $this->change('2026-09-02 10:00', ['price' => '90.00']);
        $this->change('2026-09-10 10:00', ['price' => '100.00']);
        $this->change('2026-09-20 10:00', ['sale_price' => '80.00']);

        $this->assertShown('80.00', '90.00');

        // Lowered again during the sale: still compared with before the first reduction.
        $this->change('2026-09-25 10:00', ['sale_price' => '70.00']);
        $this->assertShown('70.00', '90.00');

        // Counted as a new reduction when back-to-back reductions are not one sale.
        app(Settings::class)->set('plugin.pnshop_prior_price', ['progressive' => false]);
        $this->assertShown('70.00', '80.00');
    }

    public function test_older_prices_than_the_window_do_not_count(): void
    {
        $this->startOn('2026-07-01 10:00', '100.00');
        $this->change('2026-07-02 10:00', ['price' => '50.00']);
        $this->change('2026-07-03 10:00', ['price' => '100.00']);
        $this->change('2026-09-01 10:00', ['sale_price' => '80.00']);

        $this->assertShown('80.00', '100.00');
    }

    public function test_no_reduction_is_announced_when_the_price_was_lower_recently(): void
    {
        $this->startOn('2026-09-01 10:00', '100.00');
        $this->change('2026-09-05 10:00', ['price' => '75.00']);
        $this->change('2026-09-06 10:00', ['price' => '100.00']);
        $this->change('2026-09-10 10:00', ['sale_price' => '80.00']);

        // The customer pays the sale price, but it is not shown as a reduction.
        $this->assertShown('80.00', null);
        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page->where('products.data.0.prior_price', null));
    }

    public function test_sales_older_than_the_history_follow_the_setting(): void
    {
        $this->startOn('2026-09-01 10:00', '100.00', '80.00');

        $this->assertShown('80.00', null);

        app(Settings::class)->set('plugin.pnshop_prior_price', ['without_history' => 'regular']);
        $this->assertShown('80.00', '100.00');
    }

    public function test_the_storefront_note_is_translated(): void
    {
        $this->startOn('2026-09-01 10:00', '100.00');

        $this->get('/bg/shop/'.$this->product->slug)->assertInertia(fn (Assert $page) => $page->where('translations', fn ($translations) => collect($translations)->get('Struck-through price: the lowest price of the :days days before the reduction.') === 'Зачертаната цена е най-ниската цена през последните :days дни преди намалението.'));
    }
}
