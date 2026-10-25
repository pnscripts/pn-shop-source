<?php

namespace PnShop\Acl\Policies;

use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;

/**
 * Records staff may look at but never change through generic admin actions: payments,
 * refunds, shipments, order history, ledgers. They change only through their services
 * (PaymentService, RefundService, ShipmentService, ...), whose own actions check
 * permissions. Subclasses set $permission, the one needed to see them.
 */
abstract class ReadOnlyPolicy
{
    protected string $permission;

    public function viewAny(AdminUser $admin): bool
    {
        return $admin->can($this->permission);
    }

    public function view(AdminUser $admin, Model $model): bool
    {
        return $admin->can($this->permission);
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, Model $model): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, Model $model): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function restore(AdminUser $admin, Model $model): bool
    {
        return false;
    }

    public function restoreAny(AdminUser $admin): bool
    {
        return false;
    }

    public function forceDelete(AdminUser $admin, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function replicate(AdminUser $admin, Model $model): bool
    {
        return false;
    }

    public function reorder(AdminUser $admin): bool
    {
        return false;
    }
}
