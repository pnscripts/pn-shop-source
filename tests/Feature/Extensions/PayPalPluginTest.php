<?php

namespace Tests\Feature\Extensions;

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PnShop\Catalog\Models\Product;
use PnShop\Extension\ExtensionManager;
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentGatewayManager;
use PnShop\Payment\PaymentState;
use PnShop\Payment\RefundService;
use PnShop\Payment\Testing\PaymentGatewayContractTests;
use PnShop\Plugins\PayPal\PayPalClient;
use PnShop\Plugins\PayPal\WebhookSignature;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Settings\Settings;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The PayPal plugin against a faked PayPal API, with webhooks signed by a test certificate.
 */
class PayPalPluginTest extends AdminTestCase
{
    use PaymentGatewayContractTests;

    private const API = 'https://api-m.sandbox.paypal.com';

    private const ORDER_ID = '5O190127TN364715T';

    private const CAPTURE_ID = '3C679366HH908993F';

    private const CERT_URL = 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77';

    /** @var \OpenSSLAsymmetricKey */
    private $key;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pnshop.extensions.path' => base_path('extensions'),
            'pnshop.extensions.cache' => sys_get_temp_dir().'/pnshop-plugins-'.bin2hex(random_bytes(4)).'.php',
        ]);

        $manager = app(ExtensionManager::class);
        $manager->install('pnshop/paypal');
        $manager->enable('pnshop/paypal');

        app(Settings::class)->set('plugin.pnshop_paypal', ['environment' => 'sandbox', 'client_id' => 'test-client', 'client_secret' => 'test-secret', 'webhook_id' => 'WH-TEST']);

        // PayPal's signing certificate, made up for the test.
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'messageverificationcerts.paypal.com'], $this->key), null, $this->key, 1);
        openssl_x509_export($certificate, $pem);

        Http::preventStrayRequests();
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'A21-test', 'token_type' => 'Bearer', 'expires_in' => 32400]),
            self::API.'/v2/checkout/orders' => Http::response(['id' => self::ORDER_ID, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                ['rel' => 'self', 'href' => self::API.'/v2/checkout/orders/'.self::ORDER_ID],
                ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER_ID],
            ]], 201),
            self::API.'/v2/payments/captures/'.self::CAPTURE_ID.'/refund' => Http::response(['id' => '1JU08902781691411', 'status' => 'COMPLETED'], 201),
            self::CERT_URL => Http::response($pem),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink((string) config('pnshop.extensions.cache'));

        parent::tearDown();
    }

    protected function gateway(): PaymentGateway
    {
        return app(PaymentGatewayManager::class)->get('paypal');
    }

    public function test_checkout_sends_the_customer_to_paypal_with_the_order_total(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/v2/checkout/orders'
            && $request->hasHeader('Authorization', 'Bearer A21-test')
            && $request->hasHeader('PayPal-Request-Id', 'pnshop-payment-'.$payment->id)
            && $request['intent'] === 'CAPTURE'
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '24.00']
            && $request['purchase_units'][0]['custom_id'] === (string) $payment->id
            && $request['purchase_units'][0]['invoice_id'] === $order->number.'-'.$payment->id
            && str_ends_with($request['payment_source']['paypal']['experience_context']['return_url'], '/paypal/return/'.$payment->id));

        $this->assertSame(self::ORDER_ID, $payment->reference);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
    }

    public function test_returning_from_paypal_captures_the_payment(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $this->fakeCapture($payment);

        $this->get('/paypal/return/'.$payment->id.'?token='.self::ORDER_ID.'&PayerID=PAYER1')
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/v2/checkout/orders/'.self::ORDER_ID.'/capture'
            && $request->hasHeader('PayPal-Request-Id', 'pnshop-capture-'.$payment->id));
        $this->assertSame(PaymentState::Paid, $payment->fresh()->status);
        $this->assertSame(self::CAPTURE_ID, $payment->fresh()->reference);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);

        // The token fetched at checkout is reused: only the capture was sent since.
        Http::assertSentCount(1);
    }

    public function test_guessing_payment_ids_never_yields_a_signed_order_link(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();

        $this->flushSession();
        $location = $this->get('/paypal/return/'.$payment->id)->assertRedirect()->headers->get('Location');

        $this->assertStringNotContainsString('signature=', (string) $location);
        $this->get('/paypal/return/'.$payment->id.'?token=WRONG123456')->assertRedirect(route('orders.show', $order));
        $this->get('/paypal/return/999999')->assertRedirect(route('home'));
        $this->assertSame(PaymentState::Pending, $payment->fresh()->status);
    }

    public function test_the_signed_approval_webhook_captures_once(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $this->fakeCapture($payment);
        $body = json_encode(['id' => 'WH-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => [
            'id' => self::ORDER_ID, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => (string) $payment->id]],
        ]]);

        $this->webhook((string) $body, forged: true)->assertStatus(400);
        $this->webhook((string) $body, certUrl: 'https://evil.example/cert.pem')->assertStatus(400);
        $this->assertSame(PaymentState::Pending, $payment->fresh()->status);

        $this->webhook((string) $body)->assertOk();
        $this->webhook((string) $body)->assertOk();

        $this->assertSame(PaymentState::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        // The certificate once, one capture: the second delivery finds the payment paid.
        Http::assertSentCount(2);
    }

    public function test_a_cancelled_order_is_never_captured(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $this->fakeCapture($payment);

        app(OrderWorkflow::class)->transition($order, OrderStatus::Cancelled);
        $this->get('/paypal/return/'.$payment->id.'?token='.self::ORDER_ID)->assertRedirect();

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/capture'));
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_capture_that_does_not_match_the_order_is_refused(): void
    {
        $payment = $this->checkout()->payments()->sole();
        $this->fakeCapture($payment, value: '1.00');

        $this->get('/paypal/return/'.$payment->id.'?token='.self::ORDER_ID);

        $this->assertSame(PaymentState::Failed, $payment->fresh()->status);
    }

    public function test_a_pending_capture_is_paid_when_paypal_completes_it(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $this->fakeCapture($payment, status: 'PENDING');

        $this->get('/paypal/return/'.$payment->id.'?token='.self::ORDER_ID)->assertSessionHas('error');
        $this->assertSame(PaymentState::Pending, $payment->fresh()->status);

        $body = json_encode(['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            ...$this->capture('COMPLETED', '24.00'),
            'custom_id' => (string) $payment->id,
            'supplementary_data' => ['related_ids' => ['order_id' => self::ORDER_ID]],
        ]]);
        $this->webhook((string) $body)->assertOk();

        $this->assertSame(PaymentState::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_refunds_go_to_paypal(): void
    {
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $this->fakeCapture($payment);
        $this->get('/paypal/return/'.$payment->id.'?token='.self::ORDER_ID);

        app(RefundService::class)->refund($order->fresh(), [$order->items->sole()->id => 1]);

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/v2/payments/captures/'.self::CAPTURE_ID.'/refund'
            && $request['amount'] === ['currency_code' => 'USD', 'value' => '12.00']
            && str_starts_with($request->header('PayPal-Request-Id')[0] ?? '', 'pnshop-refund-'.$payment->id.'-'));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
    }

    public function test_paypal_is_offered_only_when_configured_and_in_its_currencies(): void
    {
        $method = PaymentMethod::factory()->create(['gateway' => 'paypal', 'name' => 'PayPal']);
        $gateway = $this->gateway();

        $this->assertTrue($gateway->isAvailable(new PaymentContext(Money::of(10, 'EUR'), 'BG'), $method));
        $this->assertFalse($gateway->isAvailable(new PaymentContext(Money::of(10, 'BGN'), 'BG'), $method));

        DB::table('settings')->where('namespace', 'plugin.pnshop_paypal')->delete();
        app(Settings::class)->flush();
        $this->assertFalse($gateway->isAvailable(new PaymentContext(Money::of(10, 'EUR'), 'BG'), $method));
    }

    public function test_developers_can_point_the_plugin_at_another_api(): void
    {
        config(['services.paypal.api_url' => 'http://127.0.0.1:8125/']);

        $this->assertSame('http://127.0.0.1:8125', app(PayPalClient::class)->base());

        config(['services.paypal.api_url' => null]);
        $this->assertSame(self::API, app(PayPalClient::class)->base());
    }

    private function checkout(): Order
    {
        $method = PaymentMethod::query()->firstOrCreate(['gateway' => 'paypal'], ['name' => 'PayPal', 'is_active' => true]);
        $product = Product::factory()->active()->create(['price' => '12.00', 'sale_price' => null, 'stock' => 5]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $this->withHeader('X-Inertia', 'true')
            ->post(route('checkout.store'), $this->checkoutData($method->id))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER_ID);

        // The browser comes back from PayPal with a normal request.
        $this->flushHeaders();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function fakeCapture(Payment $payment, string $status = 'COMPLETED', string $value = '24.00'): void
    {
        Http::fake([self::API.'/v2/checkout/orders/'.self::ORDER_ID.'/capture' => Http::response([
            'id' => self::ORDER_ID,
            'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'APPROVED',
            'purchase_units' => [['custom_id' => (string) $payment->id, 'payments' => ['captures' => [$this->capture($status, $value)]]]],
        ], 201)]);
    }

    /** @return array<string, mixed> */
    private function capture(string $status, string $value): array
    {
        return ['id' => self::CAPTURE_ID, 'status' => $status, 'amount' => ['currency_code' => 'USD', 'value' => $value]];
    }

    private function webhook(string $body, bool $forged = false, string $certUrl = self::CERT_URL): TestResponse
    {
        $id = 'b2384410-f8d2-11ef-a14b-'.bin2hex(random_bytes(6));
        $time = now()->toIso8601ZuluString();
        openssl_sign($id.'|'.$time.'|WH-TEST|'.WebhookSignature::crc32($forged ? $body.' ' : $body), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/paypal/webhook', [], [], [], [
            'HTTP_PAYPAL_TRANSMISSION_ID' => $id,
            'HTTP_PAYPAL_TRANSMISSION_TIME' => $time,
            'HTTP_PAYPAL_TRANSMISSION_SIG' => base64_encode($signature),
            'HTTP_PAYPAL_CERT_URL' => $certUrl,
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
