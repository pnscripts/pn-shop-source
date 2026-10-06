<?php

namespace Tests\Feature\Extensions;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PnShop\Catalog\Models\Product;
use PnShop\Extension\ExtensionManager;
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentGatewayManager;
use PnShop\Payment\PaymentState;
use PnShop\Payment\RefundService;
use PnShop\Payment\Testing\PaymentGatewayContractTests;
use PnShop\Plugins\Stripe\WebhookSignature;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Settings\Settings;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The Stripe plugin against a faked Stripe API.
 */
class StripePluginTest extends AdminTestCase
{
    use PaymentGatewayContractTests;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pnshop.extensions.path' => base_path('extensions'),
            'pnshop.extensions.cache' => sys_get_temp_dir().'/pnshop-plugins-'.bin2hex(random_bytes(4)).'.php',
        ]);

        $manager = app(ExtensionManager::class);
        $manager->install('pnshop/stripe');
        $manager->enable('pnshop/stripe');

        app(Settings::class)->set('plugin.pnshop_stripe', ['secret_key' => 'sk_test_fake', 'webhook_secret' => 'whsec_fake']);

        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink((string) config('pnshop.extensions.cache'));

        parent::tearDown();
    }

    protected function gateway(): PaymentGateway
    {
        return app(PaymentGatewayManager::class)->get('stripe');
    }

    public function test_checkout_redirects_to_stripe_with_the_order_total(): void
    {
        $order = $this->checkout();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer sk_test_fake')
            && preg_match('/^pnshop-payment-'.$order->payments()->sole()->id.'-[a-z0-9]{20}$/', $request->header('Idempotency-Key')[0] ?? '') === 1
            && $request['line_items'][0]['price_data']['unit_amount'] === 2400
            && $request['line_items'][0]['price_data']['currency'] === 'usd'
            && $request['client_reference_id'] === $order->number
            && $request['managed_payments'] === ['enabled' => 'false']);

        $this->assertSame('cs_test_1', $order->payments()->sole()->reference);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
    }

    public function test_returning_from_stripe_confirms_the_payment_from_the_api(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response($this->stripeSession($payment->id))]);

        $this->get('/stripe/return/'.$payment->id.'?session_id=cs_test_1')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PaymentState::Paid, $payment->fresh()->status);
        $this->assertSame('pi_1', $payment->fresh()->reference);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);

        // A forged session id in the URL changes nothing.
        $this->get('/stripe/return/'.$payment->id.'?session_id=cs_other')->assertRedirect();
    }

    public function test_guessing_payment_ids_never_yields_a_signed_order_link(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();

        // A stranger (new session) without the Checkout Session id.
        $this->flushSession();
        $location = $this->get('/stripe/return/'.$payment->id)->assertRedirect()->headers->get('Location');

        $this->assertStringNotContainsString('signature=', (string) $location);
        $this->get((string) $location)->assertForbidden();

        $this->get('/stripe/return/'.$payment->id.'?session_id=cs_wrong')->assertRedirect(route('orders.show', $order));
        $this->get('/stripe/return/999999')->assertRedirect(route('home'));
    }

    public function test_the_signed_webhook_confirms_the_payment_once(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession($payment->id)]]);

        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
        $this->assertSame(PaymentState::Pending, $payment->fresh()->status);

        foreach ([1, 2] as $delivery) {
            $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::header((string) $payload, 'whsec_fake', time()), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        }

        $this->assertSame(PaymentState::Paid, $payment->fresh()->status);
        $this->assertSame(1, $payment->transactions()->where('type', 'webhook')->count());
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_the_customer_is_thanked_when_the_webhook_confirmed_first(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession($payment->id)]]);
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::header((string) $payload, 'whsec_fake', time()), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        $this->assertSame('pi_1', $payment->fresh()->reference);

        // A forged session id still gets the plain order page.
        $this->get('/stripe/return/'.$payment->id.'?session_id=cs_other')->assertRedirect(route('orders.show', $order));

        $location = $this->get('/stripe/return/'.$payment->id.'?session_id=cs_test_1')
            ->assertRedirect()
            ->assertSessionHas('success')
            ->headers->get('Location');

        $this->assertStringContainsString('signature=', (string) $location);
        // Stripe is not asked again, and the payment is recorded once.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/checkout/sessions/cs_test_1'));
        $this->assertSame(1, $payment->transactions()->where('outcome', 'paid')->count());
    }

    public function test_cancelling_expires_the_session_and_a_late_payment_is_flagged(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1/expire' => Http::response(['id' => 'cs_test_1', 'status' => 'expired'])]);

        app(OrderWorkflow::class)->transition($order, OrderStatus::Cancelled);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions/cs_test_1/expire'));

        // The customer had already paid on Stripe's page: the webhook still arrives.
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession($payment->id)]]);
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::header((string) $payload, 'whsec_fake', time()), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::header((string) $payload, 'whsec_fake', time()), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(1, $payment->transactions()->where('type', 'late_payment')->count());
        $this->assertDatabaseHas('order_history', ['order_id' => $order->id, 'field' => 'note']);
    }

    public function test_a_payment_that_does_not_match_the_order_is_refused(): void
    {
        $payment = $this->checkout()->payments()->sole();
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [...$this->stripeSession($payment->id), 'amount_total' => 100]]]);

        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::header((string) $payload, 'whsec_fake', time()), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $this->assertSame(PaymentState::Failed, $payment->fresh()->status);
    }

    public function test_old_webhook_signatures_are_refused(): void
    {
        $this->assertFalse(WebhookSignature::valid('{}', WebhookSignature::header('{}', 'whsec_fake', time() - 600), 'whsec_fake'));
        $this->assertTrue(WebhookSignature::valid('{}', WebhookSignature::header('{}', 'whsec_fake', time()), 'whsec_fake'));
        $this->assertFalse(WebhookSignature::valid('{}', WebhookSignature::header('{}', 'whsec_fake', time()), ''));
    }

    public function test_refunds_go_to_stripe(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response($this->stripeSession($payment->id))]);
        $this->get('/stripe/return/'.$payment->id.'?session_id=cs_test_1');

        app(RefundService::class)->refund($order->fresh(), [$order->items->sole()->id => 1]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.stripe.com/v1/refunds' && $request['payment_intent'] === 'pi_1' && $request['amount'] === 1200
            && $request->hasHeader('Idempotency-Key', 'pnshop-refund-pi_1-0-1200'));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
    }

    public function test_without_a_key_stripe_is_not_offered(): void
    {
        DB::table('settings')->where('namespace', 'plugin.pnshop_stripe')->delete();
        app(Settings::class)->flush();
        PaymentMethod::factory()->create(['gateway' => 'stripe', 'name' => 'Card']);
        $this->post(route('cart.store'), ['product_id' => Product::factory()->active()->create(['stock' => 5])->id, 'quantity' => 1]);

        $this->get(route('checkout.create'))->assertInertia(fn ($page) => $page->where('paymentMethods', []));
    }

    private function checkout(): Order
    {
        $method = PaymentMethod::query()->firstOrCreate(['gateway' => 'stripe'], ['name' => 'Card', 'is_active' => true]);
        $product = Product::factory()->active()->create(['price' => '12.00', 'sale_price' => null, 'stock' => 5]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $this->withHeader('X-Inertia', 'true')
            ->post(route('checkout.store'), $this->checkoutData($method->id))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_1');

        // The browser comes back from Stripe with a normal request.
        $this->flushHeaders();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeSession(int $paymentId): array
    {
        return [
            'id' => 'cs_test_1',
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 2400,
            'currency' => 'usd',
            'payment_intent' => 'pi_1',
            'metadata' => ['payment_id' => (string) $paymentId],
        ];
    }
}
