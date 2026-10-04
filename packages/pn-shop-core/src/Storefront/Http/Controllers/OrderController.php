<?php

namespace PnShop\Storefront\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Money\MoneyPresenter;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\PaymentService;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\ReturnReason;
use PnShop\Returns\ReturnService;
use PnShop\Sales\Models\Order;
use PnShop\Shipping\Models\Shipment;

class OrderController extends Controller
{
    public function show(Request $request, Order $order, ReturnService $returns): Response
    {
        abort_unless(self::canView($request, $order), 403);

        $order->load(['items', 'paymentMethod', 'shippingAddress', 'billingAddress', 'shipments.lines', 'refunds', 'invoice']);

        return Inertia::render('orders/show', [
            'order' => [
                'id' => $order->id,
                'name' => $order->name,
                'email' => $order->email,
                'phone' => $order->phone,
                'shipping_address' => $order->shippingLines(),
                'billing_address' => $order->billingAddress?->toPostalAddress()->lines(),
                'number' => $order->number,
                ...$order->presentStates(),
                'payment_method' => $order->paymentMethod?->name,
                'payment_instructions' => app(PaymentService::class)->instructions($order),
                'shipping_method' => $order->shipping_method_name,
                'invoice' => $order->invoice === null ? null : ['number' => $order->invoice->number, 'url' => route('invoices.show', $order->invoice)],
                'refunds' => $order->refunds->where('status', Refund::COMPLETED)->values()->map(fn (Refund $refund) => [
                    'id' => $refund->id,
                    'amount' => MoneyPresenter::present($refund->amount),
                    'date' => self::displayDate($refund->created_at, 'LL'),
                ]),
                'shipments' => $order->shipments->map(fn (Shipment $shipment) => [
                    'id' => $shipment->id,
                    'carrier' => $shipment->carrier_name,
                    'tracking_number' => $shipment->tracking_number,
                    'tracking_url' => $shipment->tracking_url,
                    'shipped_at' => self::displayDate($shipment->shipped_at, 'LL'),
                    'items' => $shipment->lines->sum('quantity'),
                ]),
                'created_at' => self::displayDate($order->created_at, 'LLL'),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->product_title ?? 'Product',
                    'variant_label' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'price' => MoneyPresenter::present($item->price),
                    'sale_price' => MoneyPresenter::present($item->sale_price),
                    'unit_price' => MoneyPresenter::present($item->unitPrice()),
                    'line_total' => MoneyPresenter::present($item->lineTotal()),
                ]),
                'totals' => $order->presentTotals(),
            ],
            'returns' => ReturnRequest::query()->where('order_id', $order->id)->with(['lines', 'exchangeOrder'])->latest('id')->get()->map(fn (ReturnRequest $return) => [
                'id' => $return->id,
                'number' => $return->number,
                'status' => $return->status->value,
                'status_label' => __($return->status->label()),
                'reason' => __($return->reason->label()),
                'staff_note' => $return->staff_note,
                'items' => (int) $return->lines->sum('quantity'),
                'exchange_order' => $return->exchangeOrder?->number,
                'return_label_url' => $return->return_label_url,
                'created_at' => self::displayDate($return->created_at, 'LL'),
            ]),
            'returnable' => $this->returnable($order, $returns),
        ]);
    }

    /**
     * The customer who placed it, or this browser right after checkout or via the email link.
     */
    public static function canView(Request $request, Order $order): bool
    {
        $recentIds = self::recentOrderIds($request);
        $customer = $request->user('web');
        $isOwner = $customer !== null && (int) $order->user_id === (int) $customer->getAuthIdentifier();
        $isRecent = $recentIds->contains($order->id);

        // A signed link from an order email: remember the order for this browser (invoice link, refresh).
        if (! $isOwner && ! $isRecent && $request->hasValidSignature()) {
            $request->session()->put('recent_order_ids', $recentIds->push($order->id)->unique()->values()->all());
            $isRecent = true;
        }

        return $isOwner || $isRecent;
    }

    /**
     * @return array<string, mixed>
     */
    private function returnable(Order $order, ReturnService $returns): array
    {
        $eligibility = $returns->eligibility($order);

        return [
            'allowed' => $eligibility['allowed'],
            'reason' => $eligibility['reason'],
            'deadline' => self::displayDate($eligibility['deadline'], 'LL'),
            'items' => $order->items->filter(fn ($item) => isset($eligibility['items'][$item->id]))->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->product_title,
                'variant_label' => $item->variant_label,
                'max' => $eligibility['items'][$item->id],
            ])->values(),
            'reasons' => ReturnReason::options(),
        ];
    }
}
