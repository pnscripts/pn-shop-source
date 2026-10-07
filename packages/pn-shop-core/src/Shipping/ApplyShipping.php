<?php

namespace PnShop\Shipping;

use Closure;
use PnShop\Cart\Totals\CartTotals;
use PnShop\Cart\Totals\ThresholdSubtotal;
use PnShop\Cart\Totals\TotalLine;
use PnShop\Customer\PostalAddress;
use PnShop\Shipping\Models\ShippingMethod;

/**
 * cart.totals stage: adds the chosen shipping method's price once checkout knows the
 * address (context `shipping_address`) and the method (context `shipping_method`).
 */
class ApplyShipping
{
    public const PRIORITY = 200;

    public function __construct(private ShippingService $shipping) {}

    public function handle(CartTotals $totals, Closure $next): mixed
    {
        $address = $totals->context['shipping_address'] ?? null;
        $method = $totals->context['shipping_method'] ?? null;

        if ($address instanceof PostalAddress && $method instanceof ShippingMethod && $address->country_code !== '') {
            $quote = $this->shipping->quote($method, new ShippingRequest(
                $totals->items,
                app(ThresholdSubtotal::class)->of($totals),
                $address->country_code,
                $address->postcode,
                $totals->context['user'] ?? null,
            ));

            if ($quote !== null) {
                $totals->add(new TotalLine('shipping', $method->name, $quote->price));
            }
        }

        return $next($totals);
    }
}
