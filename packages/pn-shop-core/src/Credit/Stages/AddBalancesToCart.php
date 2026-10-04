<?php

namespace PnShop\Credit\Stages;

use Closure;
use PnShop\Cart\CartSummary;
use PnShop\Credit\CartBalances;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Credit\Models\GiftCard;
use PnShop\Money\MoneyPresenter;

/**
 * cart.summary stage: the gift cards entered, the customer's store credit, and what is
 * left to pay after them (`amount_due`).
 */
final class AddBalancesToCart
{
    public function __construct(private CartBalances $balances) {}

    public function handle(CartSummary $summary, Closure $next): mixed
    {
        $total = $summary->totals->total();
        $currency = $total->getCurrency()->getCurrencyCode();
        $customer = $this->balances->customer();
        $paid = [];

        foreach ($this->balances->plan($total, $customer) as [$account, $amount]) {
            $paid[$account->getMorphClass().':'.$account->getKey()] = $amount;
        }

        $credit = $this->balances->creditAccount($customer, $currency);

        $summary->data['gift_cards'] = $this->balances->giftCards($currency)->map(fn (GiftCard $card) => [
            'id' => $card->id,
            'label' => $card->label(),
            'balance' => MoneyPresenter::present($card->balanceMoney()),
            'applied' => MoneyPresenter::present($paid[$card->getMorphClass().':'.$card->id] ?? null),
        ])->values()->all();

        $summary->data['store_credit'] = $credit === null ? null : [
            'available' => MoneyPresenter::present($credit->balanceMoney()),
            'used' => $this->balances->usesCredit(),
            'applied' => MoneyPresenter::present($paid[(new CreditAccount)->getMorphClass().':'.$credit->id] ?? null),
        ];

        $covered = array_reduce($paid, fn ($sum, $amount) => $sum->plus($amount), $total->multipliedBy(0));
        $summary->data['amount_due'] = MoneyPresenter::present($total->minus($covered));

        return $next($summary);
    }
}
