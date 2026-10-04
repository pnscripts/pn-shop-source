<?php

namespace PnShop\Shipping;

use PnShop\Customer\PostalAddress;

/**
 * What a carrier needs to book a return: who sends it, where it goes, what is in it.
 */
final readonly class ReturnLabelRequest
{
    /**
     * @param  string  $reference  the return number, e.g. "R-000012"
     * @param  PostalAddress  $from  the customer's address (the order's shipping address)
     * @param  PostalAddress|null  $to  where returns go (the shipping location's address), when known
     * @param  list<array{title: string, quantity: int, weight: int}>  $items  weights in grams
     */
    public function __construct(
        public string $reference,
        public PostalAddress $from,
        public ?PostalAddress $to,
        public array $items,
    ) {}

    /** Total weight in grams. */
    public function weight(): int
    {
        return array_sum(array_map(fn (array $item) => $item['weight'] * $item['quantity'], $this->items));
    }
}
