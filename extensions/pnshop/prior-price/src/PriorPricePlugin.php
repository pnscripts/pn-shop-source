<?php

namespace PnShop\Plugins\PriorPrice;

use Inertia\Inertia;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\Contracts\PriorPrices;
use PnShop\Extension\Plugin;

class PriorPricePlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(PriceHistory::class);
        $this->app->bind(PriorPrices::class, LowestPriorPrice::class);
    }

    protected function bootPlugin(): void
    {
        // Every saved price change is a new history entry (admin, Admin API, imports).
        ProductVariant::saved(function (ProductVariant $variant) {
            if ($variant->wasRecentlyCreated || $variant->wasChanged(['price', 'sale_price'])) {
                $this->app->make(PriceHistory::class)->record($variant);
            }
        });

        // For the note under the price on product pages (storefront/storefront.js).
        Inertia::share('priorPrice', fn () => request()->routeIs('shop.show') ? ['days' => (int) ($this->setting('days') ?? 30)] : null);
    }

    public function install(): void
    {
        $this->app->make(PriceHistory::class)->recordAll();
    }
}
