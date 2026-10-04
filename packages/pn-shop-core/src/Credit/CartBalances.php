<?php

namespace PnShop\Credit;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PnShop\Cart\CartRepository;
use PnShop\Cart\Exceptions\CartException;
use PnShop\Credit\Contracts\BalanceAccount;
use PnShop\Credit\Exceptions\InsufficientBalance;
use PnShop\Credit\Gateways\StoreCreditGateway;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Credit\Models\GiftCard;
use PnShop\Customer\Models\User;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentService;
use PnShop\Sales\Exceptions\CheckoutException;
use PnShop\Sales\Models\Order;

/**
 * Gift cards and store credit spent on the current cart.
 *
 * The customer enters gift card codes and may choose to spend their store credit; at
 * checkout each balance becomes a paid payment of the order (gateway "store_credit"), and
 * whatever is left is paid with the chosen payment method.
 */
final class CartBalances
{
    public function __construct(private Balances $balances, private CartRepository $carts) {}

    /**
     * Enter a gift card code.
     *
     * @throws CartException when the code is wrong, used up, expired or in another currency.
     */
    public function apply(string $code, string $currency): GiftCard
    {
        // Guessing codes: 10 wrong codes per 10 minutes per visitor.
        $attempts = 'pnshop:gift-card-attempts:'.request()->ip();

        if (RateLimiter::tooManyAttempts($attempts, 10)) {
            throw new CartException(__('Too many gift card codes tried. Please wait a few minutes.'));
        }

        $card = $this->balances->findGiftCard($code);

        if ($card === null) {
            RateLimiter::hit($attempts, 600);

            throw new CartException(__('This gift card code is not valid.'));
        }

        if (! $card->isSpendable()) {
            throw new CartException(__('This gift card has no balance left or has expired.'));
        }

        if ($card->currency !== $currency) {
            throw new CartException(__('This gift card is in :currency and cannot be used here.', ['currency' => $card->currency]));
        }

        $cart = $this->carts->current(create: true);
        $ids = array_values(array_unique([...array_map('intval', $cart->gift_card_ids ?? []), $card->id]));
        $cart?->update(['gift_card_ids' => $ids]);

        return $card;
    }

    public function remove(int $giftCardId): void
    {
        $cart = $this->carts->current();
        $cart?->update(['gift_card_ids' => array_values(array_filter(array_map('intval', $cart->gift_card_ids ?? []), fn (int $id) => $id !== $giftCardId)) ?: null]);
    }

    public function useCredit(bool $use): void
    {
        $this->carts->current(create: true)?->update(['use_store_credit' => $use]);
    }

    public function usesCredit(): bool
    {
        return (bool) $this->carts->current()?->use_store_credit;
    }

    /**
     * Spendable gift cards entered in the cart, in the currency.
     *
     * @return Collection<int, GiftCard>
     */
    public function giftCards(string $currency): Collection
    {
        $ids = array_map('intval', $this->carts->current()->gift_card_ids ?? []);

        return $ids === []
            ? new Collection
            : GiftCard::query()->whereKey($ids)->where('currency', $currency)->get()
                ->filter(fn (GiftCard $card) => $card->isSpendable())
                ->sortBy(fn (GiftCard $card) => array_search($card->id, $ids, true))
                ->values();
    }

    /** The customer's store credit in the currency, when there is some. */
    public function creditAccount(?User $customer, string $currency): ?CreditAccount
    {
        if ($customer === null) {
            return null;
        }

        $account = CreditAccount::query()->where('user_id', $customer->id)->where('currency', $currency)->first();

        return $account !== null && $account->isSpendable() ? $account : null;
    }

    /**
     * What each balance pays of the total: gift cards in the order they were entered, then
     * store credit when chosen.
     *
     * @return list<array{0: BalanceAccount&Model, 1: Money}>
     */
    public function plan(Money $total, ?User $customer): array
    {
        $currency = $total->getCurrency()->getCurrencyCode();
        $accounts = [...$this->giftCards($currency)->all()];

        if ($this->usesCredit() && ($credit = $this->creditAccount($customer, $currency)) !== null) {
            $accounts[] = $credit;
        }

        $plan = [];
        $left = $total;

        foreach ($accounts as $account) {
            if (! $left->isPositive()) {
                break;
            }

            $take = $account->balanceMoney()->isLessThan($left) ? $account->balanceMoney() : $left;
            $plan[] = [$account, $take];
            $left = $left->minus($take);
        }

        return $plan;
    }

    /** How much of the total the cart's balances pay. */
    public function covered(Money $total, ?User $customer): Money
    {
        return array_reduce($this->plan($total, $customer), fn (Money $sum, array $part) => $sum->plus($part[1]), Money::zero($total->getCurrency()));
    }

    /**
     * Pay the order with the cart's balances (inside the checkout transaction), then forget
     * them on the cart.
     *
     * @throws CheckoutException when a balance changed meanwhile.
     */
    public function spend(Order $order, ?User $customer): void
    {
        $method = $this->paymentMethod();
        $payments = app(PaymentService::class);

        foreach ($this->plan($order->grandTotal(), $customer) as [$account, $amount]) {
            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'payment_method_id' => $method->id,
                'gateway' => StoreCreditGateway::CODE,
                'currency' => $order->currency,
                'amount' => $amount,
                'reference' => $account->getMorphClass().':'.$account->getKey(),
            ]);

            try {
                $this->balances->change($account, $amount->negated(), BalanceReason::Spent, $order, $payment);
            } catch (InsufficientBalance) {
                throw new CheckoutException(__('The balance of :account changed. Please review your cart.', ['account' => $account->label()]));
            }

            $payments->recordPaid($payment, $account->label());
        }

        $this->carts->current()?->update(['gift_card_ids' => null, 'use_store_credit' => false]);
    }

    /**
     * The payment method recorded for balance payments (never offered at checkout).
     */
    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::query()->firstOrCreate(
            ['gateway' => StoreCreditGateway::CODE],
            ['name' => 'Gift card or store credit', 'is_active' => false, 'position' => 999],
        );
    }

    /** The signed-in customer of the storefront or the Store API. */
    public function customer(): ?User
    {
        foreach (['web', 'store-api'] as $guard) {
            $user = rescue(fn () => Auth::guard($guard)->user(), null, false);

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}
