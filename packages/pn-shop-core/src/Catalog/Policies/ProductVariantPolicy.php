<?php

namespace PnShop\Catalog\Policies;

use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Models\ProductVariant;

/**
 * Variants are part of their product: seen with catalog.products.view, added, edited,
 * reordered and removed with catalog.products.update.
 */
class ProductVariantPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->can('catalog.products.view');
    }

    public function view(AdminUser $admin, ProductVariant $variant): bool
    {
        return $admin->can('catalog.products.view');
    }

    public function create(AdminUser $admin): bool
    {
        return $admin->can('catalog.products.update');
    }

    public function update(AdminUser $admin, ProductVariant $variant): bool
    {
        return $admin->can('catalog.products.update');
    }

    public function delete(AdminUser $admin, ProductVariant $variant): bool
    {
        return $admin->can('catalog.products.update');
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return $admin->can('catalog.products.update');
    }

    public function reorder(AdminUser $admin): bool
    {
        return $admin->can('catalog.products.update');
    }
}
