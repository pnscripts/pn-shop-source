<?php

namespace PnShop\Plugins\BgN18\Policies;

use PnShop\Acl\Policies\ReadOnlyPolicy;

/**
 * Issued documents are a fiscal record: staff may see and download them, never change them.
 */
class SalesDocumentPolicy extends ReadOnlyPolicy
{
    protected string $permission = 'n18.manage';
}
