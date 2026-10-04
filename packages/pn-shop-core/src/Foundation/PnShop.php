<?php

namespace PnShop\Foundation;

use PnShop\Acl\AclServiceProvider;
use PnShop\Admin\AdminServiceProvider;
use PnShop\Api\ApiServiceProvider;
use PnShop\Cart\CartServiceProvider;
use PnShop\Catalog\CatalogServiceProvider;
use PnShop\Cms\CmsServiceProvider;
use PnShop\Customer\CustomerServiceProvider;
use PnShop\Extension\ExtensionServiceProvider;
use PnShop\Installer\InstallerServiceProvider;
use PnShop\Inventory\InventoryServiceProvider;
use PnShop\Localization\LocalizationServiceProvider;
use PnShop\Media\MediaServiceProvider;
use PnShop\Payment\PaymentServiceProvider;
use PnShop\Promotion\PromotionServiceProvider;
use PnShop\Returns\ReturnsServiceProvider;
use PnShop\Sales\SalesServiceProvider;
use PnShop\Security\SecurityServiceProvider;
use PnShop\Seo\SeoServiceProvider;
use PnShop\Settings\SettingsServiceProvider;
use PnShop\Shipping\ShippingServiceProvider;
use PnShop\Storefront\StorefrontServiceProvider;
use PnShop\System\SystemServiceProvider;
use PnShop\Tax\TaxServiceProvider;
use PnShop\Theme\ThemeServiceProvider;

final class PnShop
{
    /**
     * The PN Shop core version. Extensions declare compatibility against it.
     */
    public const VERSION = '1.4.0';

    /** The Composer package that holds the core. */
    public const PACKAGE = 'pnscripts/pn-shop-core';

    /**
     * The core modules, booted in this order. They belong to the package (not the shop's
     * config), so modules added by an update load without editing configuration.
     *
     * @var list<class-string<ModuleServiceProvider>>
     */
    public const MODULES = [
        SettingsServiceProvider::class,
        LocalizationServiceProvider::class,
        MediaServiceProvider::class,
        AclServiceProvider::class,
        SystemServiceProvider::class,
        CatalogServiceProvider::class,
        InventoryServiceProvider::class,
        CustomerServiceProvider::class,
        CartServiceProvider::class,
        SecurityServiceProvider::class,
        SalesServiceProvider::class,
        PaymentServiceProvider::class,
        ShippingServiceProvider::class,
        TaxServiceProvider::class,
        PromotionServiceProvider::class,
        ReturnsServiceProvider::class,
        CmsServiceProvider::class,
        ExtensionServiceProvider::class,
        ThemeServiceProvider::class,
        SeoServiceProvider::class,
        StorefrontServiceProvider::class,
        ApiServiceProvider::class,
        AdminServiceProvider::class,
        InstallerServiceProvider::class,
    ];

    /** The package's root folder (src/, routes/, database/, resources/, lang/, config/). */
    public static function path(string $path = ''): string
    {
        $root = dirname(__DIR__, 2);

        return $path === '' ? $root : $root.'/'.ltrim($path, '/');
    }
}
