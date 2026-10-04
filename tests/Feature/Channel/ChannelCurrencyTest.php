<?php

namespace Tests\Feature\Channel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Channel\Models\Channel;
use PnShop\Localization\Models\Currency;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Promotion\Models\Promotion;
use PnShop\Sales\Models\Order;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use Tests\TestCase;

/**
 * A channel selling in another currency: catalog prices, shipping costs, promotion amounts
 * and order limits are entered in the default currency (USD here) and converted with the
 * exchange rate; a price list in the channel's currency sets exact prices.
 */
class ChannelCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const EU = 'http://eu.example.test';

    private Product $lamp;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'exchange_rate' => '0.9', 'is_active' => true]);
        Channel::query()->create(['code' => 'eu', 'name' => 'Europe', 'hostname' => 'eu.example.test', 'currency' => 'EUR']);

        $this->lamp = Product::factory()->active()->create(['price' => '100.00', 'sale_price' => null, 'stock' => 20]);
    }

    private function card(string $root): array
    {
        $response = $this->get($root.'/shop')->assertOk();

        return collect($response->viewData('page')['props']['products']['data'])->firstWhere('id', $this->lamp->id);
    }

    public function test_catalog_prices_are_converted_or_taken_from_a_price_list_in_the_channel_s_currency(): void
    {
        $this->assertSame(['100.00', 'USD'], [$this->card('http://localhost')['price']['amount'], $this->card('http://localhost')['price']['currency']]);
        $this->assertSame(['90.00', 'EUR'], [$this->card(self::EU)['price']['amount'], $this->card(self::EU)['price']['currency']]);

        $this->get(self::EU.'/shop')->assertInertia(fn (Assert $page) => $page->where('localization.currency', 'EUR'));

        // A euro price list for everyone: 85.00 on the European channel, nothing changes in USD.
        PriceList::query()->create(['name' => 'Euro prices', 'currency' => 'EUR', 'is_active' => true])
            ->entries()->create(['product_variant_id' => $this->lamp->defaultVariant()->id, 'price' => '85.00']);

        $this->assertSame('85.00', $this->card(self::EU)['sale_price']['amount']);
        $this->assertNull($this->card('http://localhost')['sale_price']);
    }

    public function test_an_order_is_placed_and_charged_in_the_channel_s_currency(): void
    {
        ShippingMethod::factory()->for(ShippingZone::factory()->create(), 'zone')->create(['name' => 'Courier', 'carrier' => 'flat_rate', 'settings' => ['cost' => '10.00']]);
        Promotion::factory()->create(['actions' => [['type' => 'fixed_off', 'data' => ['amount' => '5']]], 'conditions' => [['type' => 'subtotal', 'data' => ['min' => '150']]]]);
        // 95 USD minimum = 85.50 EUR: a 90 EUR order may use it.
        $method = PaymentMethod::factory()->create(['gateway' => 'bank_transfer', 'min_total' => '95.00']);

        $this->post(self::EU.'/cart', ['product_id' => $this->lamp->id, 'quantity' => 2]);
        $quote = $this->postJson(self::EU.'/checkout/quote', ['country_code' => 'BG'])->assertOk()->json();

        $this->assertSame(['9.00', 'EUR'], [$quote['options'][0]['price']['amount'], $quote['options'][0]['price']['currency']]);
        // 180 EUR is over the 150 USD (135 EUR) minimum: 5 USD off = 4.50 EUR.
        $this->assertSame('-4.50', collect($quote['totals']['lines'])->first(fn (array $line) => str_starts_with($line['code'], 'discount:'))['amount']['amount'] ?? null);

        $this->post(self::EU.'/checkout', $this->checkoutData($method->id, ['shipping_method_id' => $quote['options'][0]['id']]))->assertSessionMissing('error');

        $order = Order::query()->sole();
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('90.00', (string) $order->items->sole()->price->getAmount());
        $this->assertSame('EUR', $order->items->sole()->price->getCurrency()->getCurrencyCode());
        $this->assertSame('184.50', (string) $order->total->getAmount(), '180 + 9 shipping - 4.50 off.');
    }
}
