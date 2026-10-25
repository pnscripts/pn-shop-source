<?php

namespace PnShop\Cms\Policies;

use PnShop\Acl\Policies\ReadOnlyPolicy;

/** Saved page versions: listed and restored (a custom action) with the pages, never edited. */
class PageRevisionPolicy extends ReadOnlyPolicy
{
    protected string $permission = 'content.pages.manage';
}
