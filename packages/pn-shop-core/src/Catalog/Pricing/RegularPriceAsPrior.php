<?php

namespace PnShop\Catalog\Pricing;

use Brick\Money\Money;
use PnShop\Catalog\Pricing\Contracts\PriorPrices;

/**
 * Default "was" price: the variant's regular price.
 */
final class RegularPriceAsPrior implements PriorPrices
{
    public function priorPrice(PriceQuote $quote): ?Money
    {
        return $quote->isReduced() ? $quote->regular : null;
    }
}
