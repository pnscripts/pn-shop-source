<?php

namespace PnShop\Plugins\HandlingFee;

use App\Models\User;
use Closure;
use PnShop\Cart\Totals\CartTotals;
use PnShop\Cart\Totals\TotalLine;
use PnShop\Localization\CurrencyConverter;
use PnShop\Plugins\HandlingFee\Models\Exemption;
use PnShop\Settings\Settings;

/**
 * cart.totals stage (priority 300, "fees"): the handling fee for small orders.
 */
class ApplyHandlingFee
{
    public function __construct(private Settings $settings) {}

    public function handle(CartTotals $totals, Closure $next): mixed
    {
        $fee = app(CurrencyConverter::class)->fromDefault((string) $this->settings->get('plugin.pnshop_handling_fee.amount'), $totals->currency());
        $threshold = app(CurrencyConverter::class)->fromDefault((string) ($this->settings->get('plugin.pnshop_handling_fee.threshold') ?: '0'), $totals->currency());
        $user = $totals->context['user'] ?? auth('web')->user();

        $exempt = $user instanceof User && $user->customer_group_id !== null
            && Exemption::query()->where('customer_group_id', $user->customer_group_id)->exists();

        if ($fee->isPositive() && ! $exempt && $totals->items->isNotEmpty() && ($threshold->isZero() || $totals->subtotal->isLessThan($threshold))) {
            $totals->add(new TotalLine('handling_fee', __('Handling fee'), $fee));
        }

        return $next($totals);
    }
}
