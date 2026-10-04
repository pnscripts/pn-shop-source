<?php

namespace PnShop\Sales;

use Illuminate\Routing\UrlGenerator;
use PnShop\Localization\Localization;
use PnShop\Sales\Models\Order;

/**
 * Links to an order's page for emails: signed (guests have no session in their mail
 * client) and in the language the order was placed in. Anyone with the link sees the
 * order, like a parcel tracking link, until it expires (180 days by default).
 */
final class OrderLinks
{
    /** How long a signed link keeps working (pnshop.security.order_link_days). */
    public static function days(): int
    {
        return max(1, (int) config('pnshop.security.order_link_days', 180));
    }

    public static function signedShow(Order $order): string
    {
        $localization = app(Localization::class);
        $locale = $order->locale !== null && $localization->isSupported($order->locale) ? $order->locale : $localization->defaultLocale();

        // On the storefront the order was placed on. A copy of the URL generator, so the
        // shared one (and any root a caller forced) is left alone.
        /** @var UrlGenerator $urls */
        $urls = clone app('url');
        $urls->forceRootUrl(rtrim($order->channel?->url() ?? config()->string('app.url'), '/').$localization->prefix($locale));

        return $urls->temporarySignedRoute('orders.show', now()->addDays(self::days()), ['order' => $order]);
    }
}
