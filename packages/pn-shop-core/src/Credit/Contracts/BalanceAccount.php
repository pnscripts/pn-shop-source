<?php

namespace PnShop\Credit\Contracts;

use Brick\Money\Money;

/**
 * Something holding a spendable balance: a gift card or a customer's store credit.
 */
interface BalanceAccount
{
    public function balanceMoney(): Money;

    /** Whether it can be spent now (active, not expired). */
    public function isSpendable(): bool;

    /** For customers and staff, e.g. "Gift card •••• 7KQ2" or "Store credit". */
    public function label(): string;

    /** The column holding the balance in minor units. */
    public function balanceColumn(): string;
}
