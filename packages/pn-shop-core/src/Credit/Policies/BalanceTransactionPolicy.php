<?php

namespace PnShop\Credit\Policies;

use PnShop\Acl\Policies\ReadOnlyPolicy;

/** Gift card and store credit ledger lines: written only by Balances::change(). */
class BalanceTransactionPolicy extends ReadOnlyPolicy
{
    protected string $permission = 'sales.credit.manage';
}
