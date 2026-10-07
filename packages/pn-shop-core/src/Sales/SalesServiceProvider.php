<?php

namespace PnShop\Sales;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PnShop\Catalog\Models\Product;
use PnShop\Channel\Channels;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Payment\Events\RefundCompleted;
use PnShop\Sales\Console\CancelUnpaidOrdersCommand;
use PnShop\Sales\Events\OrderPlaced;
use PnShop\Sales\Events\OrderStateChanged;
use PnShop\Sales\Invoices\HtmlInvoiceRenderer;
use PnShop\Sales\Invoices\InvoiceRenderer;
use PnShop\Sales\Invoices\IssueInvoiceAutomatically;
use PnShop\Sales\Models\Invoice;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\Notifications\OrderMail;
use PnShop\Sales\Notifications\SendOrderNotifications;
use PnShop\Sales\Policies\OrderPolicy;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;
use PnShop\Shipping\Events\ShipmentCreated;

/**
 * Sales module: orders, checkout, payments.
 */
class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderWorkflow::class);
        $this->app->bindIf(InvoiceRenderer::class, HtmlInvoiceRenderer::class);

        Relation::morphMap(['order' => Order::class, 'invoice' => Invoice::class]);
    }

    protected function permissions(): array
    {
        return [
            new Permission('sales.orders.view', 'View orders', 'Sales'),
            new Permission('sales.orders.update', 'Change order status', 'Sales'),
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);

        // Order emails are written as the order's storefront: its name, address and links.
        $entered = [];
        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$entered): void {
            if ($event->notification instanceof OrderMail && ($channel = $event->notification->order->channel) !== null) {
                $entered[spl_object_id($event->notification)] = app(Channels::class)->enter($channel);
            }
        });
        $leave = function (NotificationSent|NotificationFailed $event) use (&$entered): void {
            $key = spl_object_id($event->notification);

            if (array_key_exists($key, $entered)) {
                app(Channels::class)->leave($entered[$key]);
                unset($entered[$key]);
            }
        };
        Event::listen(NotificationSent::class, $leave);
        Event::listen(NotificationFailed::class, $leave);

        $this->app->make(SettingsRegistry::class)->register(new SettingsSchema(
            'sales',
            'Orders',
            new SettingDefinition('order_number_prefix', SettingType::String, 'Order number prefix', default: 'ORD-', help: 'Applies to new orders, e.g. ORD-000042.', rules: ['max:12']),
            new SettingDefinition('order_number_digits', SettingType::Integer, 'Order number digits', default: 6, required: true, help: 'The order id is padded with zeros to this length.', rules: ['min:1', 'max:12']),
            new SettingDefinition('thresholds_after_discounts', SettingType::Boolean, 'Thresholds use the subtotal after discounts', default: false, help: 'Off: free-shipping minimums and customer group minimum orders compare with the subtotal before discounts. On: with the subtotal after discounts and coupons.'),
            new SettingDefinition('cancel_unpaid_after_hours', SettingType::Integer, 'Cancel unpaid orders after (hours)', default: 168, required: true, rules: ['min:0', 'max:8760'], help: 'Pending orders still unpaid after this time are cancelled and their stock released. Allow enough time for bank transfers. 0 turns this off.'),
            new SettingDefinition('invoice_on', SettingType::Select, 'Issue invoices', default: 'paid', required: true, options: [
                'paid' => 'When the order is paid',
                'placed' => 'When the order is placed',
                'manual' => 'Only when staff issue them',
            ]),
            new SettingDefinition('invoice_prefix', SettingType::String, 'Invoice number prefix', default: 'INV-', rules: ['max:12']),
            new SettingDefinition('invoice_digits', SettingType::Integer, 'Invoice number digits', default: 6, required: true, rules: ['min:1', 'max:12']),
            new SettingDefinition('invoice_legal_name', SettingType::String, 'Legal name on invoices', help: 'Defaults to the store name.', rules: ['max:255']),
            new SettingDefinition('invoice_tax_number', SettingType::String, 'Tax / VAT number', rules: ['max:64']),
            new SettingDefinition('invoice_footer', SettingType::Text, 'Invoice footer', help: 'e.g. bank details or legal notes.'),
        ));

        Event::listen([OrderPlaced::class, OrderStateChanged::class], IssueInvoiceAutomatically::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CancelUnpaidOrdersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('pnshop:orders:cancel-unpaid')->hourly()->withoutOverlapping();
        });

        $this->app->make(SettingsRegistry::class)->register(new SettingsSchema(
            'notifications',
            'Emails',
            new SettingDefinition('order_confirmation', SettingType::Boolean, 'Order confirmation to the customer', default: true),
            new SettingDefinition('shipping_updates', SettingType::Boolean, 'Shipping updates to the customer', default: true),
            new SettingDefinition('cancellations', SettingType::Boolean, 'Cancellations to the customer', default: true),
            new SettingDefinition('refunds', SettingType::Boolean, 'Refunds to the customer', default: true),
            new SettingDefinition('returns', SettingType::Boolean, 'Return request updates to the customer', default: true),
            new SettingDefinition('staff_new_order', SettingType::Boolean, 'New order alert to the store', default: true),
            new SettingDefinition('staff_email', SettingType::Email, 'Send store alerts to', help: 'Defaults to the store contact email.'),
        ));

        Event::listen([OrderPlaced::class, OrderStateChanged::class, ShipmentCreated::class, RefundCompleted::class], SendOrderNotifications::class);

        // Order lines keep their copies of the product; unlink them before the product goes, so
        // the outcome does not depend on the order in which the database runs its cascades.
        Product::forceDeleting(function (Product $product): void {
            OrderItem::query()->where('product_id', $product->id)->update(['product_id' => null, 'product_variant_id' => null]);
        });
    }
}
