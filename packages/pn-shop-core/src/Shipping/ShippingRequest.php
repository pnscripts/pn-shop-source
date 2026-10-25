<?php

namespace PnShop\Shipping;

use Brick\Money\Money;
use Illuminate\Support\Collection;
use PnShop\Cart\CartItemDTO;
use PnShop\Customer\Models\User;

/**
 * What is being shipped and where: the lines, their value and weight, and the destination.
 */
final readonly class ShippingRequest
{
    /**
     * @param  Collection<int, CartItemDTO>  $items
     */
    public function __construct(
        public Collection $items,
        public Money $subtotal,
        public string $countryCode,
        public ?string $postcode = null,
        public ?User $customer = null,
        /** The town, when known (since 1.11): couriers whose towns share a postcode range need it. */
        public ?string $city = null,
    ) {}

    /** Total weight in grams; lines without a weight count as zero. */
    public function weight(): int
    {
        return (int) $this->items->sum(fn (CartItemDTO $item) => $item->weight * $item->quantity);
    }

    public function quantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function currency(): string
    {
        return $this->subtotal->getCurrency()->getCurrencyCode();
    }
}
