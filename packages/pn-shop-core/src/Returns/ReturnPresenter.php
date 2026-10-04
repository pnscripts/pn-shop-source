<?php

namespace PnShop\Returns;

use PnShop\Money\MoneyPresenter;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\Models\ReturnRequestLine;

/**
 * Return requests in API responses.
 */
final class ReturnPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(ReturnRequest $return): array
    {
        $return->loadMissing(['lines.orderItem', 'refund', 'exchangeOrder']);

        return [
            'id' => $return->id,
            'number' => $return->number,
            'order_id' => $return->order_id,
            'status' => $return->status->value,
            'status_label' => __($return->status->label()),
            'reason' => $return->reason->value,
            'reason_label' => __($return->reason->label()),
            'customer_note' => $return->customer_note,
            'staff_note' => $return->staff_note,
            'restocked' => $return->restocked,
            'lines' => $return->lines->map(fn (ReturnRequestLine $line) => [
                'id' => $line->id,
                'order_item_id' => $line->order_item_id,
                'title' => $line->orderItem?->product_title,
                'variant_label' => $line->orderItem?->variant_label,
                'quantity' => $line->quantity,
                'quantity_received' => $line->quantity_received,
            ])->values()->all(),
            'refunded' => MoneyPresenter::present($return->refund?->amount),
            // Store credit, or the order the items were exchanged for.
            'refunded_to' => $return->refund?->destination,
            'exchange_order' => $return->exchangeOrder === null ? null : ['id' => $return->exchangeOrder->id, 'number' => $return->exchangeOrder->number],
            'transitions' => array_map(fn (ReturnStatus $status) => $status->value, $return->status->transitions()),
            'created_at' => $return->created_at?->toIso8601String(),
            'updated_at' => $return->updated_at?->toIso8601String(),
        ];
    }
}
