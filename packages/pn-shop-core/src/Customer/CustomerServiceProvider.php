<?php

namespace PnShop\Customer;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Customer\Models\CustomerAddress;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Customer\Models\User;
use PnShop\Customer\Policies\CustomerGroupPolicy;
use PnShop\Customer\Policies\CustomerPolicy;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;

/**
 * Customer accounts (the `users` table), customer groups and address books.
 */
class CustomerServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        Relation::morphMap([
            // The shop's configured customer model (App\Models\User), so stored morph types
            // resolve to the class the shop actually uses.
            'customer' => config('auth.providers.users.model', User::class),
            'customer_address' => CustomerAddress::class,
        ]);
    }

    protected function permissions(): array
    {
        return [
            new Permission('customers.view', 'View customers', 'Customers'),
            new Permission('customers.manage', 'Edit customers and customer groups', 'Customers'),
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(User::class, CustomerPolicy::class);
        Gate::policy(CustomerGroup::class, CustomerGroupPolicy::class);

        $this->app->make(SettingsRegistry::class)->register(new SettingsSchema(
            'customers',
            'Customers',
            new SettingDefinition('require_email_verification', SettingType::Boolean, 'Require a verified email address', default: false, help: 'New customers get a link by email and must open it before using their account pages or ordering through the Store API. Turning this on also asks existing customers to verify.'),
            new SettingDefinition('show_prices_to_guests', SettingType::Boolean, 'Show prices to guests', default: true, help: 'Off: only signed-in customers see prices and can add to the cart (a trade-only shop).'),
        ));
    }
}
