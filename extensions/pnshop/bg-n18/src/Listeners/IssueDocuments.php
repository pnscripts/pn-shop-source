<?php

namespace PnShop\Plugins\BgN18\Listeners;

use PnShop\Plugins\BgN18\SalesDocuments;
use PnShop\Sales\Events\OrderStateChanged;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Shipping\Events\ShipmentCreated;

/**
 * Sale documents when card payments complete; cash-on-delivery documents when the
 * parcel leaves.
 */
final class IssueDocuments
{
    public function __construct(private SalesDocuments $documents) {}

    public function paid(OrderStateChanged $event): void
    {
        if ($event->to === PaymentStatus::Paid) {
            $this->documents->issueSaleDocument($event->order);
        }
    }

    public function shipped(ShipmentCreated $event): void
    {
        if ($event->shipment->order !== null) {
            $this->documents->issueCashOnDeliveryDocument($event->shipment->order);
        }
    }
}
