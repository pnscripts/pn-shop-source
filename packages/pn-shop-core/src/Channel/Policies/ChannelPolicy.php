<?php

namespace PnShop\Channel\Policies;

use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;
use PnShop\Acl\Policies\PermissionPolicy;

class ChannelPolicy extends PermissionPolicy
{
    protected string $permission = 'system.channels.manage';

    /** The default channel cannot be deleted. */
    public function delete(AdminUser $admin, Model $model): bool
    {
        return parent::delete($admin, $model) && ! $model->getAttribute('is_default');
    }
}
