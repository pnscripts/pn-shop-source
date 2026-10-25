<?php

namespace PnShop\Customer\Policies;

use PnShop\Acl\Models\AdminUser;
use PnShop\Customer\Models\CustomerAddress;

/**
 * A customer's saved addresses: seen with customers.view, changed with customers.manage.
 */
class CustomerAddressPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->can('customers.view');
    }

    public function view(AdminUser $admin, CustomerAddress $address): bool
    {
        return $admin->can('customers.view');
    }

    public function create(AdminUser $admin): bool
    {
        return $admin->can('customers.manage');
    }

    public function update(AdminUser $admin, CustomerAddress $address): bool
    {
        return $admin->can('customers.manage');
    }

    public function delete(AdminUser $admin, CustomerAddress $address): bool
    {
        return $admin->can('customers.manage');
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return $admin->can('customers.manage');
    }
}
