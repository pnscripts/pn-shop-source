<?php

namespace PnShop\Shipping;

use Brick\Money\Money;

/**
 * A waybill issued by a carrier for an outgoing parcel.
 */
final readonly class ShippingLabel
{
    /**
     * @param  string  $trackingNumber  the parcel's number at the carrier
     * @param  string|null  $labelUrl  where staff download the waybill (https), when the carrier hosts it
     * @param  string|null  $pdf  the waybill itself (PDF bytes), when the carrier returns the file; kept privately with the shipment
     * @param  Money|null  $price  what the carrier charges, when it says
     */
    public function __construct(
        public string $trackingNumber,
        public ?string $labelUrl = null,
        public ?string $pdf = null,
        public ?Money $price = null,
    ) {}
}
