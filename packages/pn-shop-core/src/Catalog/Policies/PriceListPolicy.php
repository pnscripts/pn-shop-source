<?php

namespace PnShop\Catalog\Policies;

use PnShop\Acl\Policies\PermissionPolicy;

class PriceListPolicy extends PermissionPolicy
{
    protected string $permission = 'catalog.prices.manage';
}
