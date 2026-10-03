<?php

namespace PnShop\Payment;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Payment\Gateways\BankTransfer;
use PnShop\Payment\Gateways\CashOnDelivery;
use PnShop\Payment\Gateways\Invoice;
use PnShop\Payment\Listeners\SyncPaymentsWithOrder;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\Policies\PaymentMethodPolicy;
use PnShop\Sales\Events\OrderStateChanged;

/**
 * Payment gateways, payment methods and the payment ledger.
 */
class PaymentServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            $manager = new PaymentGatewayManager($app);
            $manager->register(CashOnDelivery::class);
            $manager->register(BankTransfer::class);
            $manager->register(Invoice::class);

            return $manager;
        });

        Relation::morphMap([
            'payment_method' => PaymentMethod::class,
            'payment' => Payment::class,
            'refund' => Refund::class,
        ]);
    }

    protected function permissions(): array
    {
        return [
            new Permission('store.payment_methods.manage', 'Manage payment methods', 'Store'),
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(PaymentMethod::class, PaymentMethodPolicy::class);

        Event::listen(OrderStateChanged::class, SyncPaymentsWithOrder::class);
    }
}
