<?php

namespace PnShop\Channel\Policies;

use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;
use PnShop\Acl\Policies\PermissionPolicy;
use PnShop\Customer\Models\User;

class ChannelPolicy extends PermissionPolicy
{
    protected string $permission = 'system.channels.manage';

    /** The default channel cannot be deleted. */
    public function delete(AdminUser $admin, Model $model): bool
    {
        // A channel with its own customer accounts keeps them: deactivate it instead.
        return parent::delete($admin, $model) && ! $model->getAttribute('is_default')
            && ! ($model->getAttribute('separate_accounts') && User::modelClass()::query()->where('account_scope', $model->getKey())->exists());
    }
}
