<?php

namespace PnShop\Inventory\Policies;

use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;
use PnShop\Acl\Policies\PermissionPolicy;
use PnShop\Inventory\Models\StockLevel;

class StockLocationPolicy extends PermissionPolicy
{
    protected string $permission = 'catalog.inventory.manage';

    /**
     * The default location, and a location still holding or reserving stock, cannot be
     * deleted: move the stock out first.
     */
    public function delete(AdminUser $admin, Model $model): bool
    {
        return parent::delete($admin, $model)
            && ! $model->getAttribute('is_default')
            && ! StockLevel::query()->where('stock_location_id', $model->getKey())->where(fn ($query) => $query->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0))->exists();
    }
}
