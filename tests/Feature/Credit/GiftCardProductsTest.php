<?php

namespace Tests\Feature\Credit;

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Catalog\Models\Product;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Notifications\GiftCardIssued;
use PnShop\Credit\PurchasedGiftCards;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\RefundService;
use PnShop\Promotion\Models\Promotion;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use Tests\TestCase;

/**
 * Gift cards sold as products: recipients per card, no shipping, no discounts, not paid
 * with balances, issued and emailed when paid, cancelled when refunded.
 */
class GiftCardProductsTest extends TestCase
{
    use RefreshDatabase;

    private Product $giftCard;

    private Product $lamp;

    private PaymentMethod $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giftCard = Product::factory()->active()->create(['title' => 'Gift card', 'is_gift_card' => true, 'price' => '50.00', 'sale_price' => null, 'stock' => 0]);
        $this->giftCard->defaultVariant()->update(['track_inventory' => false]);
        $this->lamp = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 10]);
        $this->transfer = PaymentMethod::factory()->create(['gateway' => 'bank_transfer']);
        ShippingMethod::factory()->for(ShippingZone::factory()->create(['countries' => ['BG']]), 'zone')->create(['name' => 'Courier', 'settings' => ['cost' => '5.00']]);
    }

    private function addGiftCard(int $quantity = 1, ?array $recipient = null): void
    {
        $this->post('/cart', ['product_id' => $this->giftCard->id, 'quantity' => $quantity, 'gift_card' => $recipient])->assertSessionHasNoErrors();
    }

    private function order(): Order
    {
        return Order::query()->with('items')->latest('id')->firstOrFail();
    }

    public function test_gift_cards_go_to_their_recipients_when_the_order_is_paid(): void
    {
        Notification::fake();
        $this->addGiftCard(1, ['email' => 'ana@example.test', 'name' => 'Ana', 'message' => 'Happy birthday!']);
        $this->addGiftCard(1);

        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.gift_card', true)
            ->where('cart.items.0.quantity', 2)
            ->where('cart.items.0.gift_card_recipients.0.email', 'ana@example.test'));

        // Only gift cards: no delivery to choose.
        $this->get('/checkout')->assertInertia(fn (Assert $page) => $page->where('shippingRequired', false));
        $this->post('/checkout', $this->checkoutData($this->transfer->id))->assertSessionMissing('error');
        $order = $this->order();
        $this->assertSame('100.00', (string) $order->total->getAmount());
        // Compared without key order: MySQL's JSON type sorts object keys.
        $this->assertEquals([['email' => 'ana@example.test', 'name' => 'Ana', 'message' => 'Happy birthday!']], $order->items->sole()->gift_card_recipients);
        $this->assertSame(0, GiftCard::query()->count(), 'Nothing is issued before payment.');

        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);

        $cards = GiftCard::query()->orderBy('id')->get();
        $this->assertSame(['ana@example.test', 'jane@example.com'], $cards->pluck('recipient_email')->all());
        $this->assertSame([5000, 5000], $cards->pluck('balance')->all());
        $this->assertSame('Happy birthday!', $cards[0]->message);
        $this->assertSame($order->items->sole()->id, $cards[0]->order_item_id);
        Notification::assertSentOnDemand(GiftCardIssued::class, fn (GiftCardIssued $mail, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'ana@example.test'
            && $mail->from === 'Jane Doe' && $mail->recipientName === 'Ana'
            && $mail->card->is(app(Balances::class)->findGiftCard($mail->code)));
        Notification::assertSentOnDemandTimes(GiftCardIssued::class, 2);
        $this->assertSame(FulfillmentStatus::Fulfilled, $order->fresh()->fulfillment_status);

        // Issued once, however often it is asked.
        app(PurchasedGiftCards::class)->issue($order->fresh());
        $this->assertSame(2, GiftCard::query()->count());
    }

    public function test_gift_cards_are_not_discounted_nor_paid_with_balances(): void
    {
        Promotion::factory()->create(['name' => 'Half price', 'actions' => [['type' => 'percent_off', 'data' => ['percent' => 50]]], 'conditions' => []]);
        [, $code] = app(Balances::class)->issueGiftCard(Money::of(100, 'USD'));

        $this->addGiftCard();
        $this->post('/cart', ['product_id' => $this->lamp->id, 'quantity' => 1]);
        $this->post('/cart/gift-cards', ['gift_card' => $code])->assertSessionHasNoErrors();

        // The lamp is half price and paid by the gift card; the new gift card is neither.
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.totals.total.amount', '60.00')
            ->where('cart.gift_cards.0.applied.amount', '10.00')
            ->where('cart.amount_due.amount', '50.00'));

        $this->post('/checkout', $this->checkoutData($this->transfer->id, ['shipping_method_id' => ShippingMethod::query()->sole()->id]))->assertSessionMissing('error');
        $order = $this->order();
        $this->assertSame('65.00', (string) $order->total->getAmount(), 'The lamp is shipped: 10.00 + 50.00 + 5.00.');
        // The gift card paid the lamp and its shipping (15.00), not the gift card bought.
        $this->assertSame(8500, app(Balances::class)->findGiftCard($code)?->balance);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status, 'The gift card bought is paid by bank transfer.');
    }

    public function test_refunding_a_gift_card_cancels_it(): void
    {
        $this->addGiftCard(1, ['email' => 'ana@example.test']);
        $this->post('/checkout', $this->checkoutData($this->transfer->id))->assertSessionMissing('error');
        $order = $this->order();
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);
        $card = GiftCard::query()->sole();

        // Ana has already spent 20.00 of it.
        app(Balances::class)->change($card, Money::of(-20, 'USD'), BalanceReason::Spent);

        app(RefundService::class)->refund($order->fresh(), [$order->items->sole()->id => 1]);

        $card->refresh();
        $this->assertFalse($card->is_active);
        $this->assertSame(0, $card->balance);
        $this->assertTrue($order->history()->where('note', 'like', '%had been used: $30.00 of $50.00 was left%')->exists());
    }

    public function test_the_store_api_adds_gift_cards_with_a_recipient(): void
    {
        $cart = $this->postJson('/api/store/v1/cart/items', ['product_id' => $this->giftCard->id, 'quantity' => 1, 'gift_card' => ['email' => 'ana@example.test', 'message' => 'For you']])
            ->assertCreated()
            ->assertJsonPath('data.items.0.gift_card', true)
            ->assertJsonPath('data.items.0.gift_card_recipients.0.email', 'ana@example.test');

        $this->withHeader('X-Cart-Token', (string) $cart->json('data.token'))
            ->postJson('/api/store/v1/cart/items', ['product_id' => $this->giftCard->id, 'quantity' => 1, 'gift_card' => ['email' => 'not-an-email']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gift_card.email');

        $this->getJson('/api/store/v1/products/'.$this->giftCard->slug)->assertOk()->assertJsonPath('data.is_gift_card', true);
    }
}
