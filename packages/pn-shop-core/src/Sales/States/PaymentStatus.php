<?php

namespace PnShop\Sales\States;

/**
 * Whether the order has been paid for.
 */
enum PaymentStatus: string implements OrderState
{
    case Unpaid = 'unpaid';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';

    public static function field(): string
    {
        return 'payment_status';
    }

    public static function fieldLabel(): string
    {
        return 'Payment';
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Authorized => 'Authorized',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Failed => 'Payment failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::Authorized => 'info',
            self::Paid => 'success',
            self::PartiallyRefunded, self::Refunded => 'gray',
            self::Failed => 'danger',
        };
    }

    public function transitions(): array
    {
        return match ($this) {
            self::Unpaid => [self::Authorized, self::Paid, self::Failed],
            self::Authorized => [self::Paid, self::Unpaid, self::Failed],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            // Back to paid (or less refunded) only when the provider reports a refund failed
            // (RefundService::failedAtProvider); never by hand, see ManualStateChanges.
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded, self::Paid],
            self::Refunded => [self::PartiallyRefunded, self::Paid],
            self::Failed => [self::Unpaid, self::Authorized, self::Paid],
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function canTransitionTo(OrderState $state): bool
    {
        return in_array($state, $this->transitions(), true);
    }
}
