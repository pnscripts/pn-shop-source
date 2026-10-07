<?php

namespace Tests\Feature\Extensions;

use PHPUnit\Framework\Attributes\Group;
use PnShop\Catalog\Models\Product;
use PnShop\Extension\ExtensionManager;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\PaymentResult;
use PnShop\Payment\PaymentService;
use PnShop\Payment\PaymentState;
use PnShop\Payment\RefundService;
use PnShop\Plugins\Stripe\StripeClient;
use PnShop\Plugins\Stripe\WebhookSignature;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Settings\Settings;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The Stripe plugin against Stripe's real API in test mode. Not part of the normal suite or
 * CI (group "stripe-live", excluded in phpunit.xml); run it with a test-mode secret key:
 *
 *     STRIPE_TEST_SECRET_KEY=sk_test_… ./vendor/bin/phpunit --group stripe-live
 *
 * Live keys are refused. Paying on Stripe's hosted page needs a browser, so the paid return
 * is covered by the manual run in docs/development/stripe-end-to-end.md; these tests cover
 * everything the API can drive: the Checkout Session Stripe creates for an order, returns
 * before payment, expiry on cancellation, and refunds of a real card payment.
 */
#[Group('stripe-live')]
class StripeLiveTest extends AdminTestCase
{
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $key = (string) getenv('STRIPE_TEST_SECRET_KEY');

        if (preg_match('/^(sk|rk)_test_[A-Za-z0-9]+$/', $key) !== 1) {
            $this->markTestSkipped('Set STRIPE_TEST_SECRET_KEY to a Stripe test-mode secret key (sk_test_…); live keys are refused.');
        }

        config([
            'pnshop.extensions.path' => base_path('extensions'),
            'pnshop.extensions.cache' => sys_get_temp_dir().'/pnshop-plugins-'.bin2hex(random_bytes(4)).'.php',
        ]);

        $manager = app(ExtensionManager::class);
        $manager->install('pnshop/stripe');
        $manager->enable('pnshop/stripe');

        app(Settings::class)->set('plugin.pnshop_stripe', ['secret_key' => $key, 'webhook_secret' => 'whsec_live_test']);
        $this->stripe = app(StripeClient::class);
    }

    protected function tearDown(): void
    {
        @unlink((string) config('pnshop.extensions.cache'));

        parent::tearDown();
    }

    public function test_checkout_creates_a_stripe_checkout_session_for_the_order_total(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();

        $this->assertMatchesRegularExpression('/^cs_test_/', (string) $payment->reference);

        $session = $this->stripe->get('checkout/sessions/'.$payment->reference);
        $this->assertSame(2400, $session['amount_total']);
        $this->assertSame('usd', $session['currency']);
        $this->assertSame('open', $session['status']);
        $this->assertSame('unpaid', $session['payment_status']);
        $this->assertSame((string) $payment->id, $session['metadata']['payment_id']);
        $this->assertSame($order->number, $session['client_reference_id']);
        $this->assertFalse($session['livemode']);
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $session['url']);
    }

    public function test_returning_before_paying_leaves_the_order_unpaid(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();

        $this->get('/stripe/return/'.$payment->id.'?session_id='.$payment->reference)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(PaymentState::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_cancelling_the_order_expires_the_session_on_stripe(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();

        app(OrderWorkflow::class)->transition($order, OrderStatus::Cancelled);

        $this->assertSame('expired', $this->stripe->get('checkout/sessions/'.$payment->reference)['status']);

        // A return after that changes nothing on the closed payment.
        $this->get('/stripe/return/'.$payment->id.'?session_id='.$payment->reference)->assertRedirect();
        $this->assertNotSame(PaymentState::Paid, $payment->fresh()->status);
    }

    public function test_refunds_of_a_card_payment_reach_stripe(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $intent = $this->paidCardPayment($payment);

        app(RefundService::class)->refund($order->fresh(), [$order->items->sole()->id => 1]);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);

        app(RefundService::class)->refund($order->fresh(), [$order->items->sole()->id => 1]);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);

        $refunds = $this->stripe->get('refunds', ['payment_intent' => $intent])['data'];
        $this->assertSame([1200, 1200], array_column($refunds, 'amount'));
        $this->assertSame(['succeeded', 'succeeded'], array_column($refunds, 'status'));
        $this->assertSame(2400, $this->stripe->get('payment_intents/'.$intent, ['expand' => ['latest_charge']])['latest_charge']['amount_refunded']);
    }

    public function test_a_refund_stripe_fails_later_is_undone_by_the_webhook(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        // Stripe's test card whose refunds succeed first and then fail.
        $this->paidCardPayment($payment, 'pm_card_refundFail');

        app(RefundService::class)->refund($order->fresh(), [$order->items->sole()->id => 1]);
        $refund = Refund::query()->sole();
        $this->assertSame(Refund::COMPLETED, $refund->status);

        $object = null;
        for ($i = 0; $i < 30; $i++) {
            $object = $this->stripe->get('refunds/'.$refund->reference);

            if ($object['status'] === 'failed') {
                break;
            }

            sleep(2);
        }

        if (($object['status'] ?? null) !== 'failed') {
            $this->markTestIncomplete('Stripe did not fail the test refund within a minute.');
        }

        // Stripe's event for it, signed with the shop's webhook secret.
        $payload = (string) json_encode(['type' => 'charge.refund.updated', 'data' => ['object' => $object]]);
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::header($payload, 'whsec_live_test', time()), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $this->assertSame(Refund::FAILED, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertTrue($payment->fresh()->refunded_amount->isZero());
    }

    /**
     * Pays the order's amount with Stripe's test card (a PaymentIntent confirmed through the
     * API, as Stripe Checkout would) and records it the way the return visit does.
     */
    private function paidCardPayment(Payment $payment, string $card = 'pm_card_visa'): string
    {
        $intent = $this->stripe->post('payment_intents', [
            'amount' => $payment->amount->getMinorAmount()->toInt(),
            'currency' => strtolower($payment->currency),
            'payment_method' => $card,
            'confirm' => 'true',
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'metadata' => ['payment_id' => (string) $payment->id],
        ]);
        $this->assertSame('succeeded', $intent['status']);

        app(PaymentService::class)->apply($payment, PaymentResult::paid((string) $intent['id']), 'test');
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);

        return (string) $intent['id'];
    }

    private function checkout(): Order
    {
        $method = PaymentMethod::query()->firstOrCreate(['gateway' => 'stripe'], ['name' => 'Card', 'is_active' => true]);
        $product = Product::factory()->active()->create(['price' => '12.00', 'sale_price' => null, 'stock' => 5]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $this->withHeader('X-Inertia', 'true')
            ->post(route('checkout.store'), $this->checkoutData($method->id))
            ->assertStatus(409);
        $this->flushHeaders();

        return Order::query()->latest('id')->firstOrFail();
    }
}
