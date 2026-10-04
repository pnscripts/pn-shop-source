<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PnShop\Credit\Exchanges;
use PnShop\Payment\Models\Refund;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\ReturnPresenter;
use PnShop\Returns\ReturnService;
use PnShop\Sales\Exceptions\OrderException;

class ReturnController extends AdminController
{
    /**
     * List return requests
     *
     * Newest first. Filters: `filter[status]`, `filter[order_id]`, `filter[updated_since]`.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', ReturnRequest::class);

        $filter = (array) $request->input('filter', []);

        $returns = $this->updatedSince(ReturnRequest::query(), $request)
            ->when(isset($filter['status']), fn (Builder $query) => $query->where('status', (string) $filter['status']))
            ->when(isset($filter['order_id']), fn (Builder $query) => $query->where('order_id', (int) $filter['order_id']))
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return $this->paginated($returns, fn (ReturnRequest $return) => ReturnPresenter::present($return));
    }

    /**
     * Show a return request
     *
     * @return array<string, mixed>
     */
    public function show(ReturnRequest $return): array
    {
        Gate::authorize('view', $return);

        return ['data' => ReturnPresenter::present($return)];
    }

    /**
     * Move a return request on
     *
     * `action`: approve, reject (needs `note`), receive (`received`: line id => units,
     * default all; `restock`, default true), refund (the received units) or close. `note`
     * is shown to the customer.
     *
     * @return array<string, mixed>
     */
    public function transition(Request $request, ReturnRequest $return, ReturnService $returns): array
    {
        Gate::authorize('update', $return);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'receive', 'refund', 'close'])],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:action,reject'],
            'received' => ['sometimes', 'array'],
            'received.*' => ['integer', 'min:0'],
            'restock' => ['sometimes', 'boolean'],
            // For "refund": the order's payment (default) or store credit.
            'to' => ['sometimes', Rule::in([Refund::TO_ORIGINAL, Refund::TO_STORE_CREDIT])],
        ]);

        $admin = $this->admin($request);
        $note = $data['note'] ?? null;

        try {
            match ((string) $data['action']) {
                'approve' => $returns->approve($return, $note, $admin),
                'reject' => $returns->reject($return, $note, $admin),
                'receive' => $returns->receive($return, array_map('intval', $data['received'] ?? []), (bool) ($data['restock'] ?? true), $admin),
                'refund' => $returns->refund($return, $admin, (string) ($data['to'] ?? Refund::TO_ORIGINAL)),
                default => $returns->close($return, $note, $admin),
            };
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        return ['data' => ReturnPresenter::present($return->refresh())];
    }

    /**
     * Exchange a return
     *
     * Places a new order for `items` (`[{variant_id, quantity}]`) paid with the value of the
     * received items; `payment_method_id` pays any difference (when the new items cost more),
     * `shipping_method_id` delivers them (default: the original order's). A difference in the
     * customer's favour stays as store credit (a gift card emailed to a guest).
     */
    public function exchange(Request $request, ReturnRequest $return, Exchanges $exchanges): JsonResponse
    {
        Gate::authorize('update', $return);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],
            'shipping_method_id' => ['nullable', 'integer', Rule::exists('shipping_methods', 'id')],
        ]);

        $variants = [];

        foreach ($data['items'] as $item) {
            $variants[(int) $item['variant_id']] = ($variants[(int) $item['variant_id']] ?? 0) + (int) $item['quantity'];
        }

        try {
            $order = $exchanges->exchange($return, $variants, $data['payment_method_id'] ?? null, $data['shipping_method_id'] ?? null, $this->admin($request));
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return response()->json(['data' => [...ReturnPresenter::present($return->refresh()), 'exchange_order_id' => $order->id]], 201);
    }
}
