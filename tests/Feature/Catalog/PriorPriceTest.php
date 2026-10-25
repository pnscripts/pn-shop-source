<?php

namespace Tests\Feature\Catalog;

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Pricing\Contracts\PriorPrices;
use PnShop\Catalog\Pricing\PriceQuote;
use PnShop\Money\MoneyDecorators;
use PnShop\Money\MoneyPresenter;
use Tests\TestCase;

/**
 * The "was" price shown struck through next to a reduced price, and the money text
 * decorators plugins use.
 */
class PriorPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_regular_price_is_the_prior_price_by_default(): void
    {
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => '15.00', 'stock' => 5]);

        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.sale_price.amount', '15.00')
            ->where('products.data.0.prior_price.amount', '20.00'));

        $this->get(route('shop.show', $product->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.prior_price.amount', '20.00'));
    }

    public function test_no_prior_price_without_a_reduction(): void
    {
        Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 5]);

        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page->where('products.data.0.prior_price', null));
    }

    public function test_a_plugin_can_supply_the_prior_price_or_hide_the_reduction(): void
    {
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => '15.00', 'stock' => 5]);

        $this->app->instance(PriorPrices::class, new class implements PriorPrices
        {
            public function priorPrice(PriceQuote $quote): ?Money
            {
                return Money::of('17.00', $quote->unit->getCurrency());
            }
        });

        $this->get(route('shop.show', $product->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.prior_price.amount', '17.00'));

        // A "was" price not above what the customer pays is never shown.
        $this->app->instance(PriorPrices::class, new class implements PriorPrices
        {
            public function priorPrice(PriceQuote $quote): ?Money
            {
                return $quote->unit;
            }
        });

        $this->get(route('shop.show', $product->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.sale_price.amount', '15.00')
            ->where('product.variants.0.prior_price', null));
    }

    public function test_money_decorators_change_the_formatted_text(): void
    {
        app(MoneyDecorators::class)->add(fn (string $text, Money $money) => $text.' ['.$money->getCurrency()->getCurrencyCode().']');

        $this->assertSame('$1.50 [USD]', MoneyPresenter::present(Money::of('1.50', 'USD'), 'en')['formatted'] ?? null);
    }
}
