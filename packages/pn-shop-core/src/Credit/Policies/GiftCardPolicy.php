<?php

namespace PnShop\Credit\Policies;

use PnShop\Acl\Policies\PermissionPolicy;

class GiftCardPolicy extends PermissionPolicy
{
    protected string $permission = 'sales.credit.manage';
}
