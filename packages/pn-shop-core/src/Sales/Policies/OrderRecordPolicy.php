<?php

namespace PnShop\Sales\Policies;

use PnShop\Acl\Policies\ReadOnlyPolicy;

/** Payments, refunds, shipments and history of an order: visible with the orders, changed only through their services. */
class OrderRecordPolicy extends ReadOnlyPolicy
{
    protected string $permission = 'sales.orders.view';
}
