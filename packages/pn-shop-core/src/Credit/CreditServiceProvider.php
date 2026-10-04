<?php

namespace PnShop\Credit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PnShop\Cart\CartSummary;
use PnShop\Credit\Gateways\StoreCreditGateway;
use PnShop\Credit\Models\BalanceTransaction;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Policies\CreditAccountPolicy;
use PnShop\Credit\Policies\GiftCardPolicy;
use PnShop\Credit\Stages\AddBalancesToCart;
use PnShop\Customer\Models\User;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Payment\Events\RefundCompleted;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\PaymentGatewayManager;
use PnShop\Payment\PaymentService;
use PnShop\Payment\PaymentState;
use PnShop\Sales\Events\OrderStateChanged;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;

/**
 * Gift cards and store credit: balances, their ledger, and spending them on orders.
 */
class CreditServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Balances::class);
        $this->app->scoped(CartBalances::class);
        $this->app->singleton(Exchanges::class);

        $this->app->afterResolving(PaymentGatewayManager::class, fn (PaymentGatewayManager $manager) => $manager->register(StoreCreditGateway::class));

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
        $this->app->make(PipelineRegistry::class)->stage(CartSummary::PIPELINE, AddBalancesToCart::class, 100);

        // A refund to store credit: the customer's credit, or a new gift card emailed to a guest.
        Event::listen(RefundCompleted::class, function (RefundCompleted $event): void {
            $refund = $event->refund;

            if ($refund->destination !== Refund::TO_STORE_CREDIT || $refund->credit_reference !== null) {
                return;
            }

            $refund->forceFill(['credit_reference' => app(Balances::class)->creditRefund($refund)[0]])->save();
        });

        // A cancelled order that was not fully paid gives its gift cards and store credit back.
        Event::listen(OrderStateChanged::class, function (OrderStateChanged $event): void {
            if ($event->to !== OrderStatus::Cancelled || in_array($event->order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
                return;
            }

            foreach ($event->order->payments()->where('gateway', StoreCreditGateway::CODE)->where('status', PaymentState::Paid)->get() as $payment) {
                $account = StoreCreditGateway::account($payment);

                if ($account !== null) {
                    app(Balances::class)->change($account, $payment->amount, BalanceReason::Released, $event->order, $payment);
                }

                app(PaymentService::class)->recordReturned($payment, __('Given back: the order was cancelled.'), $event->actor);
            }
        });

        Gate::policy(GiftCard::class, GiftCardPolicy::class);
        Gate::policy(CreditAccount::class, CreditAccountPolicy::class);

        // A customer's store credit (set up here, so the Customer module does not depend on this one).
        User::resolveRelationUsing('creditAccounts', fn (User $user) => $user->hasMany(CreditAccount::class, 'user_id'));
        User::resolveRelationUsing('creditTransactions', fn (User $user) => $user
            ->hasManyThrough(BalanceTransaction::class, CreditAccount::class, 'user_id', 'account_id')
            ->where('balance_transactions.account_type', (new CreditAccount)->getMorphClass()));
    }
}
