<?php

namespace PnShop\Money;

use Brick\Money\Money;
use Closure;

/**
 * Plugins that change how storefront and API money is written out, e.g. adding the amount
 * in a second currency next to it. Each decorator gets the formatted text, the money and
 * the locale, and returns the new text. One per application (singleton).
 */
final class MoneyDecorators
{
    /** @var list<Closure(string, Money, string): string> */
    private array $decorators = [];

    /**
     * @param  Closure(string, Money, string): string  $decorator
     */
    public function add(Closure $decorator): void
    {
        $this->decorators[] = $decorator;
    }

    public function apply(string $formatted, Money $money, string $locale): string
    {
        foreach ($this->decorators as $decorator) {
            $formatted = $decorator($formatted, $money, $locale);
        }

        return $formatted;
    }
}
