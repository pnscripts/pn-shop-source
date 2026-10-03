<?php

namespace PnShop\Catalog\Pricing;

use PnShop\Customer\Models\User;

/**
 * Who a price is for: the customer (null for guests), their group (guests count as the
 * default group) and the currency.
 */
final readonly class PriceContext
{
    public function __construct(
        public ?int $customerGroupId,
        public string $currency,
        public ?User $customer = null,
    ) {}

    public function key(): string
    {
        return ($this->customerGroupId ?? 0).'|'.$this->currency;
    }
}
