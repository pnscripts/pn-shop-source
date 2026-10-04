<?php

namespace PnShop\Localization;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use PnShop\Localization\Models\Currency;

/**
 * Converts amounts between the shop's currencies with their exchange rates (units of a
 * currency per one unit of the default currency), rounding half up.
 *
 * Amounts entered in the admin (catalog prices, shipping costs, promotion amounts, order
 * limits) are in the default currency; a channel selling in another currency converts them.
 */
final class CurrencyConverter
{
    /** @var array<string, BigDecimal> */
    private array $rates = [];

    public function __construct(private Localization $localization) {}

    public function convert(Money $money, string $to): Money
    {
        $from = $money->getCurrency()->getCurrencyCode();

        if ($from === $to) {
            return $money;
        }

        $amount = $money->getAmount()->multipliedBy($this->rate($to))->dividedBy($this->rate($from), 10, RoundingMode::HalfUp);

        return Money::of($amount, $to, roundingMode: RoundingMode::HalfUp);
    }

    /**
     * An amount entered in the default currency, in another one.
     */
    public function fromDefault(string|int|float $amount, string $to): Money
    {
        return $this->convert(Money::of((string) $amount, $this->localization->defaultCurrency()->code, roundingMode: RoundingMode::HalfUp), $to);
    }

    private function rate(string $code): BigDecimal
    {
        if (isset($this->rates[$code])) {
            return $this->rates[$code];
        }

        if ($code === $this->localization->defaultCurrency()->code) {
            return $this->rates[$code] = BigDecimal::one();
        }

        $rate = Currency::query()->where('code', $code)->value('exchange_rate');
        $rate = BigDecimal::of((string) ($rate ?: '1'));

        return $this->rates[$code] = $rate->isPositive() ? $rate : BigDecimal::one();
    }
}
