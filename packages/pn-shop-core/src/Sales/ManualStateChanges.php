<?php

namespace PnShop\Sales;

use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderState;
use PnShop\Sales\States\PaymentStatus;

/**
 * The state changes staff may set by hand (admin panel, Admin API). Refunds and shipments
 * are not among them: they come from a Refund (RefundService) or a Shipment (ShipmentService),
 * which also move the money and the stock, so the order never says "refunded" or "shipped"
 * without the records behind it. Returned goods come back through a return request.
 */
final class ManualStateChanges
{
    /** @return list<OrderState> */
    public static function allowed(OrderState $from): array
    {
        return array_values(array_filter($from->transitions(), fn (OrderState $to) => self::recordedBy($to, $from) === null));
    }

    /**
     * Where staff make this change instead, when it cannot be set by hand.
     */
    public static function recordedBy(OrderState $to, ?OrderState $from = null): ?string
    {
        if ($to === PaymentStatus::Paid && in_array($from, [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
            return (string) __('A refund is undone only when the payment provider reports that it failed.');
        }

        $reason = match ($to) {
            PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded => 'Refunds are made with "Refund", which returns the money and records it.',
            FulfillmentStatus::PartiallyFulfilled, FulfillmentStatus::Fulfilled => 'Shipping is recorded with "Create shipment", which takes the stock off the shelf.',
            FulfillmentStatus::Returned => 'Returned goods are recorded through a return request.',
            default => null,
        };

        return $reason === null ? null : (string) __($reason);
    }
}
