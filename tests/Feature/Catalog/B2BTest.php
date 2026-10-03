<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Payment\Gateways\Invoice;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentService;
use PnShop\Sales\Models\Order;
use PnShop\Sales\States\OrderStatus;
use PnShop\Settings\Settings;
use PnShop\Tax\Models\TaxClass;
use PnShop\Tax\Models\TaxZone;
use Tests\TestCase;

/**
 * Customer group options for business customers: net prices, a minimum order, payment
 * methods for some groups only, paying by invoice, and prices for signed-in customers only.
 */
class B2BTest extends TestCase
{
    use RefreshDatabase;

    private Product $lamp;

    private CustomerGroup $wholesale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lamp = Product::factory()->active()->create(['title' => 'Brass Lamp', 'price' => '120.00', 'sale_price' => null, 'stock' => 50]);
        $this->wholesale = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale']);
    }

    private function wholesaleCustomer(): User
    {
        $customer = User::factory()->create();
        $customer->forceFill(['customer_group_id' => $this->wholesale->id])->save();

        return $customer;
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        $response = $this->get(route('shop.index'));
        $response->assertOk();

        return collect($response->viewData('page')['props']['products']['data'])->firstWhere('id', $this->lamp->id);
    }

    public function test_a_group_sees_prices_without_tax_and_pays_the_same(): void
    {
        $standard = TaxClass::query()->create(['name' => 'Standard', 'is_default' => true]);
        TaxZone::query()->create(['name' => 'Bulgaria', 'countries' => ['BG']])
            ->rates()->create(['tax_class_id' => $standard->id, 'name' => 'VAT 20%', 'rate' => 20]);
        app(Settings::class)->set('tax', ['store_country' => 'BG']);
        $this->wholesale->update(['prices_include_tax' => false]);

        // Retail customers see the price as entered (with tax).
        $this->actingAs(User::factory()->create());
        $this->assertSame('120.00', $this->card()['price']['amount']);
        $this->assertTrue($this->card()['price_includes_tax']);

        $this->actingAs($this->wholesaleCustomer());
        $card = $this->card();
        $this->assertSame('100.00', $card['price']['amount']);
        $this->assertFalse($card['price_includes_tax']);

        $this->get(route('shop.show', $this->lamp->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.price.amount', '100.00')
            ->where('product.price_includes_tax', false));

        // Checkout charges the same: 120.00 with 20.00 VAT included.
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.totals.subtotal.amount', '120.00'));
    }

    public function test_a_group_minimum_order_blocks_checkout_until_it_is_met(): void
    {
        $this->wholesale->update(['min_order_total' => '300.00']);
        $this->actingAs($this->wholesaleCustomer());
        $method = PaymentMethod::factory()->create(['gateway' => 'bank_transfer']);

        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 2]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.minimum_order.amount', '300.00'));

        $this->post(route('checkout.store'), $this->checkoutData($method->id))->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());

        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.minimum_order', null));
        $this->post(route('checkout.store'), $this->checkoutData($method->id))->assertSessionMissing('error');
        $this->assertSame(1, Order::query()->count());

        // Other customers have no minimum.
        $this->assertNull(CustomerGroup::query()->where('is_default', true)->first()?->minimumOrderShortfall(Money::of(1, 'USD')));
    }

    public function test_payment_methods_can_be_limited_to_customer_groups(): void
    {
        $method = PaymentMethod::factory()->create(['gateway' => 'bank_transfer', 'customer_group_ids' => [$this->wholesale->id]]);
        $payments = app(PaymentService::class);
        $total = Money::of(100, 'USD');

        $this->assertTrue($payments->accepts($method, new PaymentContext($total, 'BG', $this->wholesaleCustomer())));
        $this->assertFalse($payments->accepts($method, new PaymentContext($total, 'BG', User::factory()->create())));
        $this->assertFalse($payments->accepts($method, new PaymentContext($total, 'BG', null)));

        // A retail customer cannot use it at checkout.
        $this->actingAs(User::factory()->create());
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post(route('checkout.store'), $this->checkoutData($method->id))->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_invoice_orders_are_for_customers_and_are_not_cancelled_unpaid(): void
    {
        $method = PaymentMethod::factory()->create(['gateway' => Invoice::CODE, 'settings' => ['terms_days' => 14, 'instructions' => 'Pay :amount within :days days, quoting :order.']]);
        $payments = app(PaymentService::class);

        $this->assertFalse($payments->accepts($method, new PaymentContext(Money::of(100, 'USD'), 'BG', null)), 'Guests cannot pay by invoice.');

        $this->actingAs($this->wholesaleCustomer());
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post(route('checkout.store'), $this->checkoutData($method->id))->assertSessionMissing('error');
        $order = Order::query()->sole();

        $this->assertStringContainsString('within 14 days', (string) $payments->instructions($order));

        $this->travel(30)->days();
        $this->artisan('pnshop:orders:cancel-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_prices_can_be_shown_to_signed_in_customers_only(): void
    {
        app(Settings::class)->set('customers', ['show_prices_to_guests' => false]);

        $this->assertNull($this->card()['price']);
        $this->get(route('shop.show', $this->lamp->slug))->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.price', null)
            ->where('product.variants.0.sale_price', null)
            ->where('product.prices_visible', false));
        $this->getJson('/api/store/v1/products/'.$this->lamp->slug)->assertOk()->assertJsonPath('data.variants.0.price', null);

        // Guests cannot fill a cart.
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.items', []));

        // The API request switched the default guard: sign in on the storefront's.
        $this->actingAs(User::factory()->create(), 'web');
        $this->assertSame('120.00', $this->card()['price']['amount']);
        $this->post(route('cart.store'), ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->has('cart.items', 1));
    }
}
