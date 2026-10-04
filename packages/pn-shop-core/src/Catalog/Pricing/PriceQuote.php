<?php

namespace PnShop\Catalog\Pricing;

use Brick\Money\Money;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Localization\CurrencyConverter;

/**
 * The price of a variant for one customer and quantity, as the "catalog.price" pipeline
 * works it out. Stages offer lower prices; the customer pays the lowest one offered.
 */
final class PriceQuote
{
    /** What paid the unit price: "base", "sale", "price_list:<id>", or a plugin's own name. */
    public string $source = 'base';

    public Money $unit;

    public function __construct(
        public readonly ProductVariant $variant,
        public readonly int $quantity,
        public readonly PriceContext $context,
        /** The variant's regular price, shown struck through when the customer pays less. */
        public readonly Money $regular,
    ) {
        $this->unit = $regular;
    }

    /**
     * Use this price when it is lower than the one so far.
     */
    public function offer(Money $price, string $source): void
    {
        // Prices entered in the default currency are converted to the quote's (the channel's).
        $price = app(CurrencyConverter::class)->convert($price, $this->unit->getCurrency()->getCurrencyCode());

        if ($price->isLessThan($this->unit)
            && ! $price->isNegative()) {
            $this->unit = $price;
            $this->source = $source;
        }
    }

    public function isReduced(): bool
    {
        return $this->unit->isLessThan($this->regular);
    }

    public function total(): Money
    {
        return $this->unit->multipliedBy($this->quantity);
    }
}
