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
            $this->safely(fn () => $this->documents->issueSaleDocument($event->order));
        }
    }

    public function shipped(ShipmentCreated $event): void
    {
        $order = $event->shipment->order;

        if ($order !== null) {
            $this->safely(fn () => $this->documents->issueCashOnDeliveryDocument($order));
        }
    }

    /**
     * A document that cannot be issued must not stop the payment or shipment: it is
     * reported, and issuing again later (it is idempotent) completes it.
     */
    private function safely(\Closure $issue): void
    {
        try {
            $issue();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
