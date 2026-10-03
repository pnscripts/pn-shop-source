<?php

namespace PnShop\Catalog\Pricing\Stages;

use Closure;
use PnShop\Catalog\Pricing\PriceQuote;
use PnShop\Catalog\Pricing\PriceResolver;

/**
 * The lowest price-list price that applies to the customer's group (or to everyone) for
 * the quantity: entries start at their min_quantity, so larger quantities can cost less.
 */
final class PriceListPrice
{
    public function __construct(private PriceResolver $prices) {}

    public function handle(PriceQuote $quote, Closure $next): mixed
    {
        foreach ($this->prices->entriesFor($quote->variant->id) as $entry) {
            if ($entry['min_quantity'] <= $quote->quantity) {
                $quote->offer($entry['price'], 'price_list:'.$entry['price_list_id']);
            }
        }

        return $next($quote);
    }
}
