<?php

namespace PnShop\Credit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Credit\Models\BalanceTransaction;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Policies\CreditAccountPolicy;
use PnShop\Credit\Policies\GiftCardPolicy;
use PnShop\Customer\Models\User;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;

/**
 * Gift cards and store credit: balances, their ledger, and spending them on orders.
 */
class CreditServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Balances::class);

        Relation::morphMap([
            'gift_card' => GiftCard::class,
            'credit_account' => CreditAccount::class,
            'balance_transaction' => BalanceTransaction::class,
        ]);
    }

    protected function permissions(): array
    {
        return [new Permission('sales.credit.manage', 'Issue gift cards and change store credit', 'Sales')];
    }

    protected function bootModule(): void
    {
        Gate::policy(GiftCard::class, GiftCardPolicy::class);
        Gate::policy(CreditAccount::class, CreditAccountPolicy::class);

        // A customer's store credit (set up here, so the Customer module does not depend on this one).
        User::resolveRelationUsing('creditAccounts', fn (User $user) => $user->hasMany(CreditAccount::class, 'user_id'));
        User::resolveRelationUsing('creditTransactions', fn (User $user) => $user
            ->hasManyThrough(BalanceTransaction::class, CreditAccount::class, 'user_id', 'account_id')
            ->where('balance_transactions.account_type', (new CreditAccount)->getMorphClass()));
    }
}
