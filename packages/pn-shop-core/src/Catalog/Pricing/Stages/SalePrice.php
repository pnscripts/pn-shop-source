<?php

namespace PnShop\Catalog\Pricing\Stages;

use Closure;
use PnShop\Catalog\Pricing\PriceQuote;
use PnShop\Money\Prices;

/**
 * The variant's own sale price, when it is set and below the price.
 */
final class SalePrice
{
    public function handle(PriceQuote $quote, Closure $next): mixed
    {
        $variant = $quote->variant;

        if ($variant->sale_price !== null && Prices::isSale($variant->price, $variant->sale_price)) {
            $quote->offer($variant->sale_price, 'sale');
        }

        return $next($quote);
    }
}
