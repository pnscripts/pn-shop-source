<?php

namespace PnShop\Shipping;

use Brick\Money\Money;
use PnShop\Cart\Totals\CartTotals;
use PnShop\Credit\CartBalances;
use PnShop\Customer\Models\User;
use PnShop\Payment\Gateways\CashOnDelivery;
use PnShop\Payment\Models\PaymentMethod;

/**
 * What the courier will collect at the door, for pricing its cash-on-delivery fee at
 * checkout: the products after discounts that gift cards and store credit leave to pay.
 * Null when the customer does not pay cash on delivery or nothing is left to collect.
 *
 * The checkout quote and the order use the same amount, so the fee the customer saw is
 * the fee the order gets.
 */
final class CashOnDeliveryAmount
{
    public static function of(CartTotals $totals, ?PaymentMethod $method): ?Money
    {
        if (! CashOnDelivery::collects($method)) {
            return null;
        }

        $goods = array_reduce($totals->discounts(), fn (Money $left, Money $discount) => $left->minus($discount), $totals->subtotal);

        if (! $goods->isPositive()) {
            return null;
        }

        $customer = $totals->context['user'] ?? null;
        $left = $goods->minus(app(CartBalances::class)->covered($goods, $customer instanceof User ? $customer : null));

        return $left->isPositive() ? $left : null;
    }
}
