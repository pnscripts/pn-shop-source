<?php

namespace PnShop\Api\Http\Resources;

use PnShop\Customer\Models\CustomerAddress;
use PnShop\Money\MoneyPresenter;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\PaymentService;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderAddress;
use PnShop\Sales\Models\OrderItem;
use PnShop\Shipping\Models\Shipment;

/**
 * Orders and addresses in API responses. States are given as stable values (status) plus a
 * label in the response language (status_label); dates are ISO 8601.
 */
final class OrderPresenter
{
    /** @var list<string> */
    public const RELATIONS = ['items', 'paymentMethod', 'shippingAddress', 'billingAddress', 'shipments.lines', 'refunds', 'invoice'];

    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            'payment_status' => $order->payment_status->value,
            'payment_status_label' => __($order->payment_status->label()),
            'fulfillment_status' => $order->fulfillment_status->value,
            'fulfillment_status_label' => __($order->fulfillment_status->label()),
            'currency' => $order->currency,
            'total' => MoneyPresenter::present($order->grandTotal()),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * Load RELATIONS first.
     *
     * @return array<string, mixed>
     */
    public static function detail(Order $order): array
    {
        return [
            ...self::summary($order),
            'email' => $order->email,
            'name' => $order->name,
            'phone' => $order->phone,
            'locale' => $order->locale,
            'shipping_address' => self::address($order->shippingAddress),
            'billing_address' => self::address($order->billingAddress),
            'shipping_method' => $order->shipping_method_name,
            'payment_method' => $order->paymentMethod?->name,
            'payment_instructions' => app(PaymentService::class)->instructions($order),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'title' => $item->product_title,
                'sku' => $item->product_sku,
                'variant_label' => $item->variant_label,
                'quantity' => $item->quantity,
                'price' => MoneyPresenter::present($item->price),
                'sale_price' => MoneyPresenter::present($item->sale_price),
                'unit_price' => MoneyPresenter::present($item->unitPrice()),
                'line_total' => MoneyPresenter::present($item->lineTotal()),
            ])->values()->all(),
            'totals' => $order->presentTotals(),
            'shipments' => $order->shipments->map(fn (Shipment $shipment) => self::shipment($shipment))->values()->all(),
            'refunds' => $order->refunds->where('status', Refund::COMPLETED)->map(fn (Refund $refund) => [
                'id' => $refund->id,
                'amount' => MoneyPresenter::present($refund->amount),
                'created_at' => $refund->created_at?->toIso8601String(),
            ])->values()->all(),
            'invoice' => $order->invoice === null ? null : ['number' => $order->invoice->number],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function address(OrderAddress|CustomerAddress|null $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $postal = $address->toPostalAddress();

        return [
            ...($address instanceof CustomerAddress ? ['id' => $address->id] : []),
            ...$postal->toArray(),
            'lines' => $postal->lines(),
            ...($address instanceof CustomerAddress ? [
                'is_default_shipping' => $address->is_default_shipping,
                'is_default_billing' => $address->is_default_billing,
            ] : []),
        ];
    }

    /**
     * @return array{id: int, carrier: string|null, tracking_number: string|null, tracking_url: string|null, shipped_at: string|null, quantity: int}
     */
    public static function shipment(Shipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'carrier' => $shipment->carrier_name,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->tracking_url,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'quantity' => (int) $shipment->lines->sum('quantity'),
        ];
    }
}
