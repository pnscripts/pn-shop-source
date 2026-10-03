<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Catalog\Pricing\Models\PriceListEntry;
use PnShop\Catalog\Pricing\PriceListEntries;
use PnShop\Money\MoneyPresenter;

/**
 * Price lists: prices per customer group (or for everyone), with optional dates and
 * quantity tiers. A customer pays the lowest price that applies. Needs catalog.prices.manage.
 */
class PriceListController extends AdminController
{
    /**
     * List price lists
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', PriceList::class);

        $lists = PriceList::query()->withCount('entries')->orderBy('id')->cursorPaginate($this->perPage($request))->withQueryString();

        return $this->paginated($lists, fn (PriceList $list) => $this->present($list));
    }

    /**
     * Show a price list
     *
     * @return array<string, mixed>
     */
    public function show(PriceList $priceList): array
    {
        Gate::authorize('view', $priceList);

        return ['data' => $this->present($priceList->loadCount('entries'))];
    }

    /**
     * Create a price list
     *
     * Leave `customer_group_id` empty for prices that apply to everyone (quantity discounts).
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', PriceList::class);

        $list = PriceList::query()->create($request->validate($this->rules(true)));

        return response()->json(['data' => $this->present($list->loadCount('entries'))], 201);
    }

    /**
     * Update a price list
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, PriceList $priceList): array
    {
        Gate::authorize('update', $priceList);

        $priceList->update($request->validate($this->rules(false)));

        return ['data' => $this->present($priceList->loadCount('entries'))];
    }

    /**
     * Delete a price list
     */
    public function destroy(PriceList $priceList): JsonResponse
    {
        Gate::authorize('delete', $priceList);

        $priceList->delete();

        return response()->json(null, 204);
    }

    /**
     * List a price list's prices
     *
     * @return array<string, mixed>
     */
    public function entries(Request $request, PriceList $priceList): array
    {
        Gate::authorize('view', $priceList);

        $entries = $priceList->entries()->with('variant:id,sku')->orderBy('id')->cursorPaginate($this->perPage($request))->withQueryString();

        return $this->paginated($entries, fn (PriceListEntry $entry) => [
            'id' => $entry->id,
            'variant_id' => $entry->product_variant_id,
            'sku' => $entry->variant?->sku,
            'min_quantity' => $entry->min_quantity,
            'price' => MoneyPresenter::present($entry->price),
        ]);
    }

    /**
     * Set prices
     *
     * `prices`: up to 1000 rows of `{sku, min_quantity (default 1), price}`. Existing prices
     * for the same SKU and quantity are replaced; an empty `price` removes one. Rows that
     * cannot be used are reported in `errors` and the others are saved.
     *
     * @return array<string, mixed>
     */
    public function setEntries(Request $request, PriceList $priceList, PriceListEntries $entries): array
    {
        Gate::authorize('update', $priceList);

        $data = $request->validate([
            'prices' => ['required', 'array', 'max:1000'],
            'prices.*.sku' => ['required', 'string', 'max:64'],
            'prices.*.min_quantity' => ['nullable', 'integer', 'min:1'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ['data' => $entries->apply($priceList, $data['prices'])];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PriceList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'customer_group_id' => $list->customer_group_id,
            'currency' => $list->currency,
            'starts_at' => $list->starts_at?->toIso8601String(),
            'ends_at' => $list->ends_at?->toIso8601String(),
            'is_active' => $list->is_active,
            'prices_count' => (int) ($list->getAttribute('entries_count') ?? 0),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'customer_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_groups', 'id')],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
