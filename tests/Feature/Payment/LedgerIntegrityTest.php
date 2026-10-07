<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Models\Product;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\PaymentResult;
use PnShop\Payment\PaymentService;
use PnShop\Payment\PaymentState;
use PnShop\Sales\Filament\Resources\Orders\Pages\ViewOrder;
use PnShop\Sales\ManualStateChanges;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\PaymentStatus;
use Tests\TestCase;

/**
 * The order's payment state always has the payment, refund and shipment records behind it.
 */
class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $gateway = 'bank_transfer'): Order
    {
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 10]);
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $this->post(route('checkout.store'), $this->checkoutData(PaymentMethod::factory()->create(['gateway' => $gateway])->id))->assertSessionMissing('error');

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_the_same_gateway_answer_is_recorded_once_and_a_paid_payment_never_fails(): void
    {
        $order = $this->order();
        $payment = $order->payments()->sole();
        $payments = app(PaymentService::class);

        $payments->apply($payment, PaymentResult::paid('ch_1'), 'webhook');
        $payments->apply($payment, PaymentResult::paid('ch_1'), 'webhook');
        $payments->apply($payment, PaymentResult::failed('Card declined later'), 'webhook');

        $this->assertSame(PaymentState::Paid, $payment->refresh()->status);
        $this->assertSame(1, $payment->transactions()->where('outcome', 'paid')->count());
        $this->assertSame(0, $payment->transactions()->where('outcome', 'failed')->count());
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_a_gateway_payment_leaves_an_abandoned_attempt_alone(): void
    {
        $order = $this->order();
        $abandoned = $order->payments()->sole();
        $second = Payment::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $order->payment_method_id,
            'gateway' => 'bank_transfer',
            'currency' => $order->currency,
            'amount' => $order->grandTotal(),
        ]);

        app(PaymentService::class)->apply($second, PaymentResult::paid('ch_2'), 'webhook');

        $this->assertSame(PaymentState::Paid, $second->refresh()->status);
        $this->assertSame(PaymentState::Pending, $abandoned->refresh()->status, 'The other attempt was marked paid by staff.');
    }

    public function test_refunds_and_shipments_are_not_set_by_hand(): void
    {
        $this->assertNotContains(PaymentStatus::Refunded, ManualStateChanges::allowed(PaymentStatus::Paid));
        $this->assertNotContains(FulfillmentStatus::Fulfilled, ManualStateChanges::allowed(FulfillmentStatus::Unfulfilled));
        $this->assertContains(PaymentStatus::Paid, ManualStateChanges::allowed(PaymentStatus::Unpaid));

        $order = $this->order();
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);
        $this->actingAs(AdminUser::factory()->administrator()->create(), 'admin');

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('changePayment')
            ->assertActionHidden('changeFulfillment');

        $token = app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'test', ['*'])->plainTextToken;
        $this->withToken($token)->postJson("/api/admin/v1/orders/{$order->id}/transitions", ['field' => 'payment_status', 'to' => 'refunded'])
            ->assertStatus(422);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_the_admin_api_refunds_through_the_refund_service(): void
    {
        $order = $this->order();
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);
        $line = $order->items->sole();
        $token = app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'test', ['*'])->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/v1/orders/{$order->id}/refunds", ['items' => [$line->id => 1], 'reason' => 'Damaged'])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'partially_refunded');

        $refund = Refund::query()->sole();
        $this->assertSame(Refund::COMPLETED, $refund->status);
        $this->assertSame('20.00', (string) $refund->amount->getAmount());
        $this->assertSame(1, $line->refresh()->quantity_refunded);

        // Undoing a refund is never done by hand: only a provider's failed-refund report does it.
        $this->assertNotContains(PaymentStatus::Paid, ManualStateChanges::allowed(PaymentStatus::PartiallyRefunded));
        $this->assertNotContains(PaymentStatus::Paid, ManualStateChanges::allowed(PaymentStatus::Refunded));
        $this->withToken($token)->postJson("/api/admin/v1/orders/{$order->id}/transitions", ['field' => 'payment_status', 'to' => 'paid'])
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'A refund is undone only when the payment provider reports that it failed.');
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->refresh()->payment_status);
    }
}
