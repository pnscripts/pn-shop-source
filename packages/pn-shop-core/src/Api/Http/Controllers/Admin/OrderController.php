<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PnShop\Api\Http\Resources\OrderPresenter;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Money\MoneyPresenter;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\RefundService;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\ManualStateChanges;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderHistory;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\Models\OrderItemAllocation;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderState;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\ShipmentService;

/**
 * Orders. State changes go through the same workflow as the admin panel (allowed
 * transitions, stock moves, history, emails).
 */
class OrderController extends AdminController
{
    /** @var array<string, class-string<OrderStatus|PaymentStatus|FulfillmentStatus>> */
    private const STATES = [
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'fulfillment_status' => FulfillmentStatus::class,
    ];

    /**
     * List orders
     *
     * Newest first unless sorted. Filters: `filter[status]`, `filter[payment_status]`,
     * `filter[fulfillment_status]`, `filter[email]`, `filter[customer_id]`,
     * `filter[updated_since]`. Sort: `id`, `updated_at`.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', Order::class);

        [$column, $direction] = $this->sort($request, ['id', 'updated_at'], '-id');
        $filter = (array) $request->input('filter', []);

        // Lines: orders placed before stored totals compute theirs from the lines.
        $orders = $this->updatedSince(Order::query()->with('items'), $request)
            ->tap(function (Builder $query) use ($filter): void {
                foreach (array_keys(self::STATES) as $field) {
                    if (isset($filter[$field])) {
                        $query->where($field, (string) $filter[$field]);
                    }
                }
            })
            ->when(isset($filter['email']), fn (Builder $query) => $query->where('email', (string) $filter['email']))
            ->when(isset($filter['customer_id']), fn (Builder $query) => $query->where('user_id', (int) $filter['customer_id']))
            ->reorder()
            ->orderBy('orders.'.$column, $direction)
            ->when($column !== 'id', fn (Builder $query) => $query->orderBy('orders.id', $direction))
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return $this->paginated($orders, fn (Order $order) => [
            ...OrderPresenter::summary($order),
            'email' => $order->email,
            'customer_id' => $order->user_id,
            'updated_at' => $order->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Show an order
     *
     * With payments, the history and the transitions allowed from each current state.
     *
     * @return array<string, mixed>
     */
    public function show(Order $order): array
    {
        Gate::authorize('view', $order);

        return ['data' => $this->detail($order)];
    }

    /**
     * Change a state
     *
     * `field` is status, payment_status or fulfillment_status; `to` must be one of the
     * transitions listed in the order's `transitions`. An optional `note` goes into the history.
     * Refunds and shipments are not state changes: use the refunds and shipments endpoints.
     *
     * @return array<string, mixed>
     */
    public function transition(Request $request, Order $order, OrderWorkflow $workflow): array
    {
        Gate::authorize('update', $order);

        $data = $request->validate([
            'field' => ['required', Rule::in(['status', 'payment_status', 'fulfillment_status'])],
            'to' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $state = self::STATES[$data['field']]::tryFrom($data['to'])
            ?? throw ValidationException::withMessages(['to' => __('Unknown state.')]);

        if (ManualStateChanges::recordedBy($state) !== null) {
            throw ValidationException::withMessages(['to' => ManualStateChanges::recordedBy($state)]);
        }

        try {
            $workflow->transition($order, $state, $this->admin($request), $data['note'] ?? null);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['to' => $e->getMessage()]);
        }

        return ['data' => $this->detail($order->refresh())];
    }

    /**
     * Add a note
     *
     * An internal note in the order history.
     */
    public function note(Request $request, Order $order, OrderWorkflow $workflow): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $workflow->addNote($order, $data['note'], $this->admin($request));

        return response()->json(['data' => $this->detail($order->refresh())], 201);
    }

    /**
     * Ship items
     *
     * Records a shipment. `items` maps order item ids to quantities; leave it out to ship
     * everything not shipped yet. `location` is the stock location code it leaves from:
     * without it, the location holding the units (422 when they are held at several);
     * with it and without `items`, everything waiting there ships.
     */
    public function ship(Request $request, Order $order, ShipmentService $shipments): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*' => ['integer', 'min:1'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', Rule::exists('stock_locations', 'code')],
        ]);

        $quantities = [];
        $location = isset($data['location']) ? StockLocation::query()->where('code', $data['location'])->firstOrFail() : null;

        foreach ((array) ($data['items'] ?? []) as $itemId => $quantity) {
            $quantities[(int) $itemId] = (int) $quantity;
        }

        try {
            $shipments->ship($order, $quantities, $data['tracking_number'] ?? null, $data['note'] ?? null, $this->admin($request), $location);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($order->refresh())], 201);
    }

    /**
     * Refund an order
     *
     * Returns money through the payment's gateway (or records a manual refund for cash on
     * delivery and bank transfer). `items` maps order item ids to units; `extra` is an amount
     * on top, e.g. the shipping. Returned units go back on the shelf when `restock` is true.
     */
    public function refund(Request $request, Order $order, RefundService $refunds): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*' => ['integer', 'min:1'],
            'extra' => ['nullable', 'numeric', 'min:0'],
            'restock' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $quantities = [];

        foreach ((array) ($data['items'] ?? []) as $itemId => $quantity) {
            $quantities[(int) $itemId] = (int) $quantity;
        }

        try {
            $refunds->refund(
                $order,
                $quantities,
                isset($data['extra']) ? Money::of((string) $data['extra'], $order->currency, roundingMode: RoundingMode::HalfUp) : null,
                (bool) ($data['restock'] ?? false),
                $data['reason'] ?? null,
                $this->admin($request),
            );
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->detail($order->refresh())], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Order $order): array
    {
        $order->load([...OrderPresenter::RELATIONS, 'payments', 'history', 'shipments.location', 'items.allocations.location']);

        return [
            ...OrderPresenter::detail($order),
            // With the stock location (code) each parcel left from.
            'shipments' => $order->shipments->map(fn (Shipment $shipment) => [...OrderPresenter::shipment($shipment), 'location' => $shipment->location?->code])->values()->all(),
            // Where each line's units are held (stock location codes), and how many shipped from there.
            'allocations' => $order->items->mapWithKeys(fn (OrderItem $item) => [$item->id => $item->allocations->map(fn (OrderItemAllocation $allocation) => [
                'location' => $allocation->location?->code,
                'quantity' => $allocation->quantity,
                'quantity_shipped' => $allocation->quantity_shipped,
            ])->values()->all()])->all(),
            'customer_id' => $order->user_id,
            'stock_status' => $order->stock_status->value,
            'updated_at' => $order->updated_at?->toIso8601String(),
            'transitions' => array_map(
                fn (string $field) => array_map(fn (OrderState $to) => $to->value, ManualStateChanges::allowed($order->{$field})),
                array_combine(array_keys(self::STATES), array_keys(self::STATES)),
            ),
            'payments' => $order->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'gateway' => $payment->gateway,
                'status' => $payment->status->value,
                'amount' => MoneyPresenter::present($payment->amount),
                'refunded_amount' => MoneyPresenter::present($payment->refunded_amount),
                'reference' => $payment->reference,
                'created_at' => $payment->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => $order->history->map(fn (OrderHistory $entry) => [
                'field' => $entry->field,
                'from' => $entry->from,
                'to' => $entry->to,
                'note' => $entry->note,
                'actor_type' => $entry->actor_type,
                'actor_id' => $entry->actor_id,
                'created_at' => $entry->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
