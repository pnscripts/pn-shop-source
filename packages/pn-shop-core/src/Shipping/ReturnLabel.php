<?php

namespace PnShop\Shipping;

/**
 * A return label issued by a carrier.
 */
final readonly class ReturnLabel
{
    /**
     * @param  string  $labelUrl  where the customer downloads the label (https)
     * @param  string|null  $trackingNumber  the return parcel's tracking number
     */
    public function __construct(
        public string $labelUrl,
        public ?string $trackingNumber = null,
    ) {}
}
