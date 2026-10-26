<?php

namespace PnShop\Shipping;

use Brick\Money\Money;
use PnShop\Customer\PostalAddress;
use PnShop\Sales\Models\Order;

/**
 * What a carrier needs to book an outgoing parcel: who receives it, where it leaves from,
 * what is in it, and how much the courier collects on delivery.
 */
final readonly class ShippingLabelRequest
{
    /**
     * @param  string  $reference  the order number, e.g. "100042"
     * @param  PostalAddress  $to  the order's shipping address
     * @param  PostalAddress|null  $from  the stock location's address, when it has one
     * @param  list<array{title: string, quantity: int, weight: int}>  $items  weights in grams (0 when unknown)
     * @param  Money|null  $cashOnDelivery  the amount the courier collects (cash on delivery), null for paid orders
     * @param  string|null  $email  the customer's email, for carriers that notify the receiver
     * @param  Order|null  $order  the order, for carrier data kept with it (e.g. the office the customer picked)
     */
    public function __construct(
        public string $reference,
        public PostalAddress $to,
        public ?PostalAddress $from,
        public array $items,
        public ?Money $cashOnDelivery = null,
        public ?string $email = null,
        public ?Order $order = null,
    ) {}

    /** Total weight in grams; lines without a weight count as zero. */
    public function weight(): int
    {
        return array_sum(array_map(fn (array $item) => $item['weight'] * $item['quantity'], $this->items));
    }

    /** "2 × Mug, 1 × Poster", shortened to $max characters, for the carrier's content field. */
    public function contents(int $max = 100): string
    {
        $text = implode(', ', array_map(fn (array $item) => $item['quantity'].' × '.$item['title'], $this->items));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }
}
