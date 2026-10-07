<?php

namespace PnShop\Cart\Totals;

use Brick\Money\Money;
use PnShop\Settings\Settings;

/**
 * The subtotal that order thresholds compare with: free-shipping minimums and customer
 * group minimum orders. Before discounts by default; after discounts when the setting
 * "Thresholds use the subtotal after discounts" (sales.thresholds_after_discounts) is on.
 */
final class ThresholdSubtotal
{
    public function __construct(private Settings $settings) {}

    public function afterDiscounts(): bool
    {
        return (bool) $this->settings->get('sales.thresholds_after_discounts');
    }

    public function of(CartTotals $totals): Money
    {
        if (! $this->afterDiscounts()) {
            return $totals->subtotal;
        }

        $subtotal = array_reduce($totals->discounts(), fn (Money $left, Money $discount) => $left->minus($discount), $totals->subtotal);

        return $subtotal->isNegative() ? Money::zero($totals->currency()) : $subtotal;
    }
}
