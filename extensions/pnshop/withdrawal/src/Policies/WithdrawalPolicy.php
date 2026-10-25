<?php

namespace PnShop\Plugins\Withdrawal\Policies;

use PnShop\Acl\Models\AdminUser;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;

/**
 * Statements come only from customers: staff see them and mark them handled, but never
 * create, edit or delete them (they are the customer's record of withdrawing).
 */
class WithdrawalPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->can('withdrawal.manage');
    }

    public function view(AdminUser $admin, Withdrawal $withdrawal): bool
    {
        return $admin->can('withdrawal.manage');
    }

    public function update(AdminUser $admin, Withdrawal $withdrawal): bool
    {
        return $admin->can('withdrawal.manage');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, Withdrawal $withdrawal): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
