<?php

namespace PnShop\Plugins\BgEuro;

use Brick\Math\BigDecimal;
use PnShop\Channel\Models\Channel;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Currency;

/**
 * Whether the shop sells in euro only, as shops in Bulgaria must since 01.01.2026 (the lev
 * stopped being legal tender on 31.01.2026).
 */
final class EuroCheck
{
    public function __construct(private Localization $localization) {}

    /**
     * @return list<array{ok: bool, message: string}>
     */
    public function results(): array
    {
        $results = [];
        $default = $this->localization->defaultCurrency()->code;

        $results[] = $default === 'EUR'
            ? ['ok' => true, 'message' => __('Prices are entered in euro (the default currency is EUR).')]
            : ['ok' => false, 'message' => __('The default currency is :code: prices must be in euro. Convert them at 1.95583 BGN = 1 EUR and make EUR the default currency.', ['code' => $default])];

        $selling = Channel::query()->where('is_active', true)->where('currency', 'BGN')->pluck('name')->all();
        $results[] = $selling === []
            ? ['ok' => true, 'message' => __('No storefront sells in leva.')]
            : ['ok' => false, 'message' => __('These storefronts still sell in leva: :names.', ['names' => implode(', ', $selling)])];

        $lev = Currency::query()->where('code', 'BGN')->where('is_active', true)->first();

        if ($lev !== null && $default === 'EUR') {
            $results[] = BigDecimal::of((string) $lev->exchange_rate)->isEqualTo(Lev::RATE)
                ? ['ok' => true, 'message' => __('The lev is active at the fixed rate 1.95583.')]
                : ['ok' => false, 'message' => __('The lev is active at the rate :rate instead of the fixed 1.95583.', ['rate' => $lev->exchange_rate])];
        }

        return $results;
    }

    public function passes(): bool
    {
        return collect($this->results())->every(fn (array $result) => $result['ok']);
    }
}
