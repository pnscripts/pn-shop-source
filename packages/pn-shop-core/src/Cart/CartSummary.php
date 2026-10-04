<?php

namespace PnShop\Cart;

use PnShop\Cart\Totals\CartTotals;

/**
 * The cart as pages and the Store API show it, passed through the "cart.summary" pipeline
 * so modules and plugins can add their part (gift cards, store credit, ...).
 */
final class CartSummary
{
    public const PIPELINE = 'cart.summary';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data, public readonly CartTotals $totals) {}
}
