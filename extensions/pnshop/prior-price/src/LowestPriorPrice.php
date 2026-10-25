<?php

namespace PnShop\Plugins\PriorPrice;

use Brick\Money\Money;
use PnShop\Catalog\Pricing\Contracts\PriorPrices;
use PnShop\Catalog\Pricing\PriceQuote;
use PnShop\Localization\CurrencyConverter;
use PnShop\Settings\Settings;

/**
 * The "was" price is the lowest price of the last 30 days (setting) before the sale began.
 *
 * Only sale prices are announced reductions: lower prices from price lists, quantity tiers
 * or customer groups keep the regular price as before.
 */
final class LowestPriorPrice implements PriorPrices
{
    public function __construct(private PriceHistory $history, private Settings $settings) {}

    public function priorPrice(PriceQuote $quote): ?Money
    {
        if ($quote->source !== 'sale') {
            return $quote->regular;
        }

        $found = $this->history->lowestBeforeReduction(
            $quote->variant->id,
            max(1, (int) ($this->setting('days') ?? 30)),
            (bool) ($this->setting('progressive') ?? true),
        );

        if ($found === null) {
            return $this->setting('without_history') === 'regular' ? $quote->regular : null;
        }

        return app(CurrencyConverter::class)->convert($found['price'], $quote->unit->getCurrency()->getCurrencyCode());
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_prior_price.'.$key);
    }
}
