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
    /** @var array<int, true> variants shown with the regular price for lack of history (this request) */
    private array $unproven = [];

    public function __construct(private PriceHistory $history, private Settings $settings) {}

    /**
     * Variants whose struck-through price is the regular price because the history does not
     * reach back to before their sale: the storefront note must not call it the lowest.
     *
     * @return list<int>
     */
    public function unproven(): array
    {
        return array_keys($this->unproven);
    }

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
            if ($this->setting('without_history') !== 'regular') {
                return null;
            }

            $this->unproven[$quote->variant->id] = true;

            return $quote->regular;
        }

        return app(CurrencyConverter::class)->convert($found['price'], $quote->unit->getCurrency()->getCurrencyCode());
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_prior_price.'.$key);
    }
}
