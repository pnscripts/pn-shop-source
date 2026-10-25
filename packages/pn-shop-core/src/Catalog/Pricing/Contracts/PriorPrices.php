<?php

namespace PnShop\Catalog\Pricing\Contracts;

use Brick\Money\Money;
use PnShop\Catalog\Pricing\PriceQuote;

/**
 * The price shown struck through next to a reduced price ("was"). Core shows the regular
 * price; a plugin can bind its own, e.g. the lowest price of the 30 days before the
 * reduction that EU price-indication rules ask for.
 */
interface PriorPrices
{
    /**
     * The "was" price for this quote, in the quote's currency, or null to show the price
     * without announcing a reduction. Only called for reduced quotes.
     */
    public function priorPrice(PriceQuote $quote): ?Money;
}
