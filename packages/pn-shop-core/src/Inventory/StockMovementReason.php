<?php

namespace PnShop\Inventory;

enum StockMovementReason: string
{
    case Initial = 'initial';
    case Adjustment = 'adjustment';
    case Order = 'order';
    case OrderFulfilled = 'order_fulfilled';
    case OrderCancelled = 'order_cancelled';
    case OrderReopened = 'order_reopened';
    case Return = 'return';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Opening balance',
            self::Adjustment => 'Manual adjustment',
            self::Order => 'Order placed',
            self::OrderFulfilled => 'Order shipped',
            self::OrderCancelled => 'Order cancelled',
            self::OrderReopened => 'Order reopened',
            self::Return => 'Return',
            self::Transfer => 'Transfer',
        };
    }
}
