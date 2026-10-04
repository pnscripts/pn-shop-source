<?php

namespace PnShop\Credit;

/**
 * Why a gift card or store credit balance changed.
 */
enum BalanceReason: string
{
    case Issued = 'issued';
    case Adjustment = 'adjustment';
    case Spent = 'spent';
    case Refund = 'refund';
    case Released = 'released';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Adjustment => 'Adjustment',
            self::Spent => 'Spent on an order',
            self::Refund => 'Refund',
            self::Released => 'Order cancelled',
            self::Exchange => 'Exchange',
        };
    }
}
