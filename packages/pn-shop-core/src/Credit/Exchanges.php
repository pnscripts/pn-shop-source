<?php

namespace PnShop\Credit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PnShop\Cart\CartRepository;
use PnShop\Cart\Exceptions\CartException;
use PnShop\Cart\ShoppingCartService;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Channel\Channels;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Notifications\GiftCardIssued;
use PnShop\Customer\Models\User;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\PaymentService;
use PnShop\Payment\RefundService;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\ReturnService;
use PnShop\Sales\Checkout\CheckoutService;
use PnShop\Sales\Exceptions\CheckoutException;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderAddress;

/**
 * Exchanges: a received return becomes a new order for other variants.
 *
 * The returned items' value is refunded as store credit (a gift card for a guest) and spent
 * on the new order, which goes through checkout like any other: the customer's prices,
 * stock reservations, tax, the confirmation email. When the new items cost more, the rest
 * is paid with the chosen payment method; when they cost less, the difference stays as
 * store credit (a guest receives the gift card's code).
 */
final class Exchanges
{
    public function __construct(
        private ReturnService $returns,
        private RefundService $refunds,
        private Balances $balances,
    ) {}

    /**
     * @param  array<int, int>  $variants  variant id => quantity of the new items
     * @param  int|null  $paymentMethodId  pays what the credit does not cover
     * @param  int|null  $shippingMethodId  the delivery of the new items (default: the original order's)
     *
     * @throws OrderException when the return cannot be exchanged or the new order cannot be placed.
     */
    public function exchange(ReturnRequest $return, array $variants, ?int $paymentMethodId = null, ?int $shippingMethodId = null, ?Model $actor = null): Order
    {
        $lock = Cache::lock('pnshop:return:'.$return->id, 120);

        if (! $lock->block(15)) {
            throw new OrderException(__('This return is being handled already.'));
        }

        try {
            $return->refresh();
            $this->returns->assertCanExchange($return);

            $variants = array_filter(array_map('intval', $variants), fn (int $quantity) => $quantity > 0);
            $quantities = $this->returns->receivedQuantities($return);

            if ($variants === [] || $quantities === []) {
                throw new OrderException(__('Choose the new items, and receive the returned ones first.'));
            }

            $order = $return->order()->with(['addresses', 'channel'])->firstOrFail();
            $customer = $order->user_id !== null ? User::modelClass()::query()->find($order->user_id) : null;

            // All or nothing: a new order that cannot be placed (no stock) leaves no refund behind.
            return DB::transaction(fn () => app(Channels::class)->using($order->channel, fn () => $this->place($return, $order, $customer instanceof User ? $customer : null, $quantities, $variants, $paymentMethodId, $shippingMethodId, $actor)));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<int, int>  $quantities
     * @param  array<int, int>  $variants
     */
    private function place(ReturnRequest $return, Order $order, ?User $customer, array $quantities, array $variants, ?int $paymentMethodId, ?int $shippingMethodId, ?Model $actor): Order
    {
        // 1. The returned items' value becomes credit.
        $refund = $this->refunds->refund($order, $quantities, restock: false, reason: __('Exchange for return :number', ['number' => $return->number]), actor: $actor, destination: Refund::TO_EXCHANGE);
        [$reference, $code] = $this->balances->creditRefund($refund, BalanceReason::Exchange, notify: false);
        $refund->forceFill(['credit_reference' => $reference])->save();
        $return->forceFill(['refund_id' => $refund->id])->save();

        $card = str_starts_with($reference, (new GiftCard)->getMorphClass().':') ? GiftCard::query()->find((int) explode(':', $reference)[1]) : null;

        // 2. The new order, through checkout, paid with that credit first.
        app(PriceResolver::class)->forCustomer($customer);

        $exchange = app(CartRepository::class)->temporary(function () use ($order, $customer, $variants, $card, $paymentMethodId, $shippingMethodId) {
            $cart = app(ShoppingCartService::class);

            try {
                foreach ($variants as $variantId => $quantity) {
                    $cart->addItemToCart((int) $variantId, $quantity);
                }
            } catch (CartException $e) {
                throw new OrderException($e->getMessage());
            }

            $balances = app(CartBalances::class);
            $card !== null ? app(CartRepository::class)->current(create: true)?->update(['gift_card_ids' => [$card->id]]) : $balances->useCredit(true);

            $shipping = $order->addresses->firstWhere('type', OrderAddress::SHIPPING);
            $billing = $order->addresses->firstWhere('type', OrderAddress::BILLING);

            try {
                return app(CheckoutService::class)->place([
                    'email' => $order->email,
                    'shipping' => $shipping?->toPostalAddress()->toArray() ?? [],
                    'billing' => $billing?->toPostalAddress()->toArray(),
                    'billing_same_as_shipping' => $billing === null,
                    'shipping_method_id' => $shippingMethodId ?? $order->shipping_method_id,
                    'payment_method_id' => $paymentMethodId,
                ], $customer);
            } catch (CheckoutException $e) {
                throw new OrderException($e->getMessage());
            }
        });

        app(PaymentService::class)->start($exchange);
        $this->returns->markExchanged($return, $exchange, $actor);

        // 3. A guest's leftover credit: send them the gift card.
        if ($card !== null && $code !== null && $card->refresh()->balance > 0) {
            Notification::route('mail', $order->email)->notify(new GiftCardIssued($card, $code));
        }

        return $exchange;
    }
}
