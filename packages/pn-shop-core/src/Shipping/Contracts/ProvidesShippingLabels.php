<?php

namespace PnShop\Shipping\Contracts;

use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingLabel;
use PnShop\Shipping\ShippingLabelRequest;

/**
 * A carrier that books parcels and issues their waybills (shipping labels) from the admin.
 * Optional: implement it next to ShippingCarrier. Staff then get "Create the waybill" in
 * the shipment form of orders sent with the carrier, and on shipments recorded without one.
 *
 * PnShop\Shipping\Testing\ShippingLabelContractTests checks an implementation.
 */
interface ProvidesShippingLabels
{
    /**
     * Book the parcel with the carrier and return its waybill.
     *
     * @throws \RuntimeException when the carrier refuses (the message is shown to staff).
     */
    public function shippingLabel(ShippingLabelRequest $request, ShippingMethod $method): ShippingLabel;
}
