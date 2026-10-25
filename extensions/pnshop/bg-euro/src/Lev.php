<?php

namespace PnShop\Plugins\BgEuro;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Lev ↔ euro at the fixed rate, as the Euro Introduction Act (ЗВЕРБ) requires: the full
 * rate, never rounded (чл. 12), and the result rounded to the cent on the third decimal,
 * half up (чл. 13).
 */
final class Lev
{
    public const RATE = '1.95583';

    /** Leva for a euro amount (for showing both). */
    public static function fromEuro(Money $euro): Money
    {
        return Money::of($euro->getAmount()->multipliedBy(self::RATE)->toScale(2, RoundingMode::HalfUp), 'BGN');
    }

    /** Euro for a lev amount (conversion of old prices). */
    public static function toEuro(string|BigDecimal $leva): Money
    {
        return Money::of(BigDecimal::of($leva)->dividedBy(self::RATE, 2, RoundingMode::HalfUp), 'EUR');
    }
}
