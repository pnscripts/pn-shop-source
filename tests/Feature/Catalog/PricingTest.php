<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Invoices\InvoiceService;
use PnShop\Sales\Models\Order;
use Tests\TestCase;

/**
 * One variant, three customers: a guest and a retail customer pay the public price (less a
 * quantity tier for everyone), a wholesale customer pays the wholesale price list's price,
 * on every surface: listing, product page, cart, order, invoice and the Store API.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    private Product $lamp;

    private CustomerGroup $wholesale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lamp = Product::factory()->active()->create(['title' => 'Brass Lamp', 'price' => '100.00', 'sale_price' => null, 'stock' => 50]);
        $variant = $this->lamp->defaultVariant();
        $this->wholesale = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale']);

        // Everyone: 90.00 each from 10 units.
        PriceList::query()->create(['name' => 'Volume', 'currency' => 'USD', 'is_active' => true])
            ->entries()->create(['product_variant_id' => $variant->id, 'min_quantity' => 10, 'price' => '90.00']);

        // Wholesale: 80.00 each.
        PriceList::query()->create(['name' => 'Wholesale', 'customer_group_id' => $this->wholesale->id, 'currency' => 'USD', 'is_active' => true])
            ->entries()->create(['product_variant_id' => $variant->id, 'min_quantity' => 1, 'price' => '80.00']);

        // Ignored: inactive, expired, another group.
        PriceList::query()->create(['name' => 'Off', 'currency' => 'USD', 'is_active' => false])
            ->entries()->create(['product_variant_id' => $variant->id, 'price' => '1.00']);
        PriceList::query()->create(['name' => 'Expired', 'currency' => 'USD', 'is_active' => true, 'ends_at' => now()->subDay()])
            ->entries()->create(['product_variant_id' => $variant->id, 'price' => '2.00']);
    }

    private function wholesaleCustomer(): User
    {
        $customer = User::factory()->create();
        $customer->forceFill(['customer_group_id' => $this->wholesale->id])->save();

        return $customer;
    }

    /** @return array{0: string|null, 1: string|null} [card price, card sale price] */
    private function card(): array
    {
        $card = collect($this->get(route('shop.index'))->viewData('page')['props']['products']['data'])->firstWhere('id', $this->lamp->id);

        return [$card['price']['amount'] ?? null, $card['sale_price']['amount'] ?? null];
    }

    public function test_guests_and_retail_customers_pay_the_public_price_and_volume_tiers(): void
    {
        foreach ([null, User::factory()->create()] as $customer) {
            if ($customer !== null) {
                $this->actingAs($customer);
            }

            $this->assertSame(['100.00', null], $this->card());

            $this->get(route('shop.show', $this->lamp->slug))->assertInertia(fn (Assert $page) => $page
                ->where('product.variants.0.sale_price', null)
                ->where('product.variants.0.tiers.0.min_quantity', 10)
                ->where('product.variants.0.tiers.0.price.amount', '90.00'));
        }

        // In the cart, ten units cost 90.00 each.
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 10]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.sale_price.amount', '90.00')
            ->where('cart.totals.subtotal.amount', '900.00'));
    }

    public function test_wholesale_customers_pay_their_price_list_everywhere(): void
    {
        $customer = $this->wholesaleCustomer();
        $this->actingAs($customer);

        $this->assertSame(['100.00', '80.00'], $this->card());

        $this->get(route('shop.show', $this->lamp->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.sale_price.amount', '80.00')
            ->where('product.variants.0.tiers', []));

        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 2]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.totals.subtotal.amount', '160.00'));

        $this->post(route('checkout.store'), $this->checkoutData(PaymentMethod::factory()->create(['gateway' => 'bank_transfer'])->id))->assertSessionMissing('error');
        $order = Order::query()->sole();
        $item = $order->items->sole();

        $this->assertSame('100.00', (string) $item->price->getAmount());
        $this->assertSame('80.00', (string) $item->unitPrice()->getAmount());
        $this->assertSame('160.00', (string) $order->itemsTotal()->getAmount());

        $invoice = app(InvoiceService::class)->issue($order);
        $this->assertSame(8000, $invoice->lines[0]['unit']);
    }

    public function test_the_store_api_prices_for_the_token_owner(): void
    {
        $token = $this->wholesaleCustomer()->createToken('app', ['store'])->plainTextToken;

        $this->getJson('/api/store/v1/products/'.$this->lamp->slug)->assertOk()->assertJsonPath('data.variants.0.sale_price', null);

        $this->withToken($token)->getJson('/api/store/v1/products/'.$this->lamp->slug)
            ->assertOk()
            ->assertJsonPath('data.variants.0.sale_price.amount', '80.00');
    }

    public function test_price_lists_do_not_add_queries_per_product(): void
    {
        $this->actingAs($this->wholesaleCustomer());
        // Each page is loaded twice and the second load counted, so caches shared by all
        // requests (settings, translations) do not count.
        $count = function (): int {
            $this->get(route('shop.index'))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('shop.index'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        Product::factory()->active()->count(2)->create();
        $few = $count();

        Product::factory()->active()->count(9)->create();
        $many = $count();

        $this->assertSame($few, $many, "{$few} queries with 3 products, {$many} with 12.");
    }
}
