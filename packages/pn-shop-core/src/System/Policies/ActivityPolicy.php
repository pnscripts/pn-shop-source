<?php

namespace PnShop\System\Policies;

use PnShop\Acl\Policies\ReadOnlyPolicy;

/**
 * The activity log is read-only.
 */
class ActivityPolicy extends ReadOnlyPolicy
{
    protected string $permission = 'system.activity.view';
}
