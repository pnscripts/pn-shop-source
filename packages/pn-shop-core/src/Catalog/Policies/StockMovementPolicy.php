<?php

namespace PnShop\Catalog\Policies;

use PnShop\Acl\Policies\ReadOnlyPolicy;

/** The stock ledger: visible with the products, written only by InventoryService. */
class StockMovementPolicy extends ReadOnlyPolicy
{
    protected string $permission = 'catalog.products.view';
}
