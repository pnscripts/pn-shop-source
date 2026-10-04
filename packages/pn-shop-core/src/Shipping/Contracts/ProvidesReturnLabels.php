<?php

namespace PnShop\Shipping\Contracts;

use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ReturnLabel;
use PnShop\Shipping\ReturnLabelRequest;

/**
 * A carrier that can issue return labels: a shipping label the customer prints to send
 * returned items back. Optional: implement it next to ShippingCarrier. Staff then get
 * "Create return label" on approved returns of orders shipped with the carrier.
 *
 * PnShop\Shipping\Testing\ReturnLabelContractTests checks an implementation.
 */
interface ProvidesReturnLabels
{
    /**
     * Book the return and return its label.
     *
     * @throws \RuntimeException when the carrier refuses (the message is shown to staff).
     */
    public function returnLabel(ReturnLabelRequest $request, ShippingMethod $method): ReturnLabel;
}
