<?php

namespace Tests\Feature\Credit;

use App\Models\User;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Gateways\StoreCreditGateway;
use PnShop\Credit\Models\GiftCard;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentState;
use PnShop\Payment\RefundService;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use Tests\TestCase;

/**
 * Paying with gift cards and store credit: in the cart, at checkout (with another method
 * for the rest, or alone), and when the order is cancelled or refunded.
 */
class SpendingTest extends TestCase
{
    use RefreshDatabase;

    private Product $lamp;

    private PaymentMethod $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lamp = Product::factory()->active()->create(['price' => '100.00', 'sale_price' => null, 'stock' => 10]);
        $this->transfer = PaymentMethod::factory()->create(['gateway' => 'bank_transfer']);
    }

    /** @return array{0: GiftCard, 1: string} */
    private function giftCard(string $amount, string $currency = 'USD'): array
    {
        return app(Balances::class)->issueGiftCard(Money::of($amount, $currency));
    }

    private function order(): Order
    {
        return Order::query()->with('payments')->latest('id')->firstOrFail();
    }

    public function test_a_gift_card_pays_part_of_the_order_and_the_payment_method_the_rest(): void
    {
        [$card, $code] = $this->giftCard('30');
        $this->post('/cart', ['product_id' => $this->lamp->id, 'quantity' => 1]);

        $this->post('/cart/gift-cards', ['gift_card' => 'WRONG-CODE'])->assertSessionHasErrors('gift_card');
        [, $euros] = $this->giftCard('10', 'EUR');
        $this->post('/cart/gift-cards', ['gift_card' => $euros])->assertSessionHasErrors('gift_card');

        $this->post('/cart/gift-cards', ['gift_card' => strtolower($code)])->assertSessionHasNoErrors();
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.gift_cards.0.applied.amount', '30.00')
            ->where('cart.amount_due.amount', '70.00'));

        $this->post('/checkout', $this->checkoutData($this->transfer->id))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $order = $this->order();

        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status, 'Still 70.00 to pay.');
        $this->assertSame([[StoreCreditGateway::CODE, '30.00', 'paid'], ['bank_transfer', '70.00', 'pending']], $order->payments->sortBy('id')
            ->map(fn (Payment $payment) => [$payment->gateway, (string) $payment->amount->getAmount(), $payment->status->value])->values()->all());
        $this->assertSame(0, $card->refresh()->balance);
        $this->assertSame([3000, -3000], $card->transactions()->orderBy('id')->pluck('amount')->all());

        // Staff mark the order paid (the transfer arrived): its payments follow.
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        $this->assertSame('70.00', (string) $order->payments()->where('gateway', 'bank_transfer')->sole()->amount->getAmount());
    }

    public function test_balances_can_pay_the_whole_order_without_a_payment_method(): void
    {
        [$card, $code] = $this->giftCard('60');
        $customer = User::factory()->create();
        app(Balances::class)->change(app(Balances::class)->creditAccount($customer, 'USD'), Money::of(50, 'USD'), BalanceReason::Adjustment);
        $this->actingAs($customer);

        $this->post('/cart', ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post('/cart/gift-cards', ['gift_card' => $code]);
        $this->put('/cart/store-credit', ['use' => true]);
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.store_credit.applied.amount', '40.00')
            ->where('cart.amount_due.amount', '0.00'));

        $this->post('/checkout', $this->checkoutData($this->transfer->id, ['payment_method_id' => null]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $order = $this->order();

        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(StoreCreditGateway::CODE, $order->paymentMethod->gateway);
        $this->assertSame(0, $card->refresh()->balance);
        $this->assertSame(1000, app(Balances::class)->creditAccount($customer, 'USD')->balance);

        // A refund goes back to the balance it came from.
        app(RefundService::class)->refund($order, [], Money::of(40, 'USD'), reason: 'Goodwill');
        $this->assertSame(5000, app(Balances::class)->creditAccount($customer, 'USD')->refresh()->balance);
    }

    public function test_cancelling_an_unpaid_order_gives_the_gift_card_back(): void
    {
        [$card, $code] = $this->giftCard('25');
        $this->post('/cart', ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post('/cart/gift-cards', ['gift_card' => $code]);
        $this->post('/checkout', $this->checkoutData($this->transfer->id))->assertSessionMissing('error');
        $order = $this->order();
        $this->assertSame(0, $card->refresh()->balance);

        app(OrderWorkflow::class)->transition($order, OrderStatus::Cancelled);

        $this->assertSame(2500, $card->refresh()->balance);
        $this->assertSame(PaymentState::Refunded, $order->payments()->where('gateway', StoreCreditGateway::CODE)->sole()->status);
        $this->assertSame(['issued', 'spent', 'released'], $card->transactions()->orderBy('id')->get()->map(fn ($row) => $row->reason->value)->all());
    }

    public function test_the_store_api_applies_gift_cards_and_checks_out_without_a_method_when_they_pay_everything(): void
    {
        [, $code] = $this->giftCard('500');

        $token = $this->postJson('/api/store/v1/cart/items', ['product_id' => $this->lamp->id, 'quantity' => 2])->assertCreated()->json('data.token');
        $headers = ['X-Cart-Token' => $token];

        $this->withHeaders($headers)->postJson('/api/store/v1/cart/gift-cards', ['code' => 'NOPE'])->assertUnprocessable();
        $this->withHeaders($headers)->postJson('/api/store/v1/cart/gift-cards', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.gift_cards.0.applied.amount', '200.00')
            ->assertJsonPath('data.amount_due.amount', '0.00');

        $data = $this->checkoutData(0);
        unset($data['payment_method_id']);
        $this->withHeaders([...$headers, 'Idempotency-Key' => 'k1'])->postJson('/api/store/v1/checkout', $data)->assertCreated();

        $this->assertSame(PaymentStatus::Paid, $this->order()->payment_status);
    }
}
