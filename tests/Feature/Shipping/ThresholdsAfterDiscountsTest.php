<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Promotion\Models\Promotion;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use Tests\TestCase;

/**
 * Free-shipping minimums and group minimum orders compare with the subtotal before
 * discounts, or after them with the setting "Thresholds use the subtotal after discounts".
 */
class ThresholdsAfterDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentMethod $payment;

    private ShippingMethod $free;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payment = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery']);
        $this->free = ShippingMethod::factory()->for(ShippingZone::factory()->create(['countries' => ['BG']]), 'zone')
            ->create(['name' => 'Free over 100', 'carrier' => 'free_shipping', 'settings' => ['min_subtotal' => '100']]);

        // 120.00 at half price: 120.00 before discounts, 60.00 after.
        Promotion::factory()->create(['name' => 'Half price', 'actions' => [['type' => 'percent_off', 'data' => ['percent' => 50]]], 'conditions' => []]);
        $this->product = Product::factory()->active()->create(['price' => '120.00', 'sale_price' => null, 'stock' => 10]);
    }

    private function addToCart(): void
    {
        $this->post(route('cart.store'), ['product_id' => $this->product->id, 'quantity' => 1])->assertSessionHasNoErrors();
    }

    private function afterDiscounts(bool $on): void
    {
        app(Settings::class)->set('sales', ['thresholds_after_discounts' => $on]);
    }

    public function test_free_shipping_uses_the_subtotal_before_discounts_by_default(): void
    {
        $this->addToCart();
        $this->postJson(route('checkout.quote'), $this->checkoutData($this->payment->id)['shipping'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Free over 100']);

        $this->post(route('checkout.store'), $this->checkoutData($this->payment->id, ['shipping_method_id' => $this->free->id]))->assertSessionMissing('error');

        $this->assertSame('60.00', (string) Order::query()->sole()->total->getAmount());
    }

    public function test_free_shipping_can_use_the_subtotal_after_discounts(): void
    {
        $this->addToCart();
        $this->afterDiscounts(true);

        $this->post(route('checkout.store'), $this->checkoutData($this->payment->id, ['shipping_method_id' => $this->free->id]))->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());

        $this->postJson(route('checkout.quote'), $this->checkoutData($this->payment->id)['shipping'])
            ->assertOk()
            ->assertJsonMissing(['name' => 'Free over 100']);
    }

    public function test_a_group_minimum_order_can_use_the_subtotal_after_discounts(): void
    {
        $group = CustomerGroup::query()->create(['code' => 'trade', 'name' => 'Trade', 'min_order_total' => '100.00']);
        $customer = User::factory()->create();
        $customer->forceFill(['customer_group_id' => $group->id])->save();
        $this->actingAs($customer);
        $this->addToCart();

        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.minimum_order', null));

        $this->afterDiscounts(true);

        $this->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->where('cart.minimum_order.amount', '100.00'));
        $this->post(route('checkout.store'), $this->checkoutData($this->payment->id, ['shipping_method_id' => $this->free->id]))->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());
    }
}
