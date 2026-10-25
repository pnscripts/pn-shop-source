<?php

namespace PnShop\Acl\Policies;

use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;

/**
 * A policy where one permission covers every admin action on a resource.
 * Subclasses set $permission, e.g. 'catalog.brands.manage'.
 */
abstract class PermissionPolicy
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
        return $admin->can($this->permission);
    }

    public function update(AdminUser $admin, Model $model): bool
    {
        return $admin->can($this->permission);
    }

    public function delete(AdminUser $admin, Model $model): bool
    {
        return $admin->can($this->permission);
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return $admin->can($this->permission);
    }

    public function restore(AdminUser $admin, Model $model): bool
    {
        return $admin->can($this->permission);
    }

    public function restoreAny(AdminUser $admin): bool
    {
        return $admin->can($this->permission);
    }

    public function forceDelete(AdminUser $admin, Model $model): bool
    {
        return $admin->can($this->permission);
    }

    public function forceDeleteAny(AdminUser $admin): bool
    {
        return $admin->can($this->permission);
    }

    public function replicate(AdminUser $admin, Model $model): bool
    {
        return $admin->can($this->permission);
    }

    public function reorder(AdminUser $admin): bool
    {
        return $admin->can($this->permission);
    }
}
