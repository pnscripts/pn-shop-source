<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PnShop\Api\Http\Resources\AdminCatalogPresenter;
use PnShop\Catalog\Exceptions\InvalidVariant;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\ProductType;
use PnShop\Catalog\VariantService;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\StockMovementReason;

/**
 * Variants: what is actually sold, with its own SKU, price and stock. The usual sync job
 * for an ERP is "find by SKU, update price and stock".
 */
class VariantController extends AdminController
{
    public function __construct(private InventoryService $inventory, private VariantService $variants) {}

    /**
     * List variants
     *
     * Filters: `filter[sku]` (exact), `filter[product_id]`, `filter[updated_since]`.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', Product::class);

        $filter = (array) $request->input('filter', []);

        $variants = $this->updatedSince(ProductVariant::query(), $request)
            ->with(['optionValues', 'stockLevels.location'])
            ->when(isset($filter['sku']), fn (Builder $query) => $query->where('sku', (string) $filter['sku']))
            ->when(isset($filter['product_id']), fn (Builder $query) => $query->where('product_id', (int) $filter['product_id']))
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return $this->paginated($variants, fn (ProductVariant $variant) => AdminCatalogPresenter::variant($variant));
    }

    /**
     * Add a variant
     *
     * `option_value_ids` must hold one value of each of the product's options.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);
        $this->authorizeStock($request);

        if ($product->type === ProductType::Simple) {
            throw ValidationException::withMessages(['product' => __('A simple product has exactly one variant. Change the product type to "variable" first.')]);
        }

        $data = $request->validate($this->rules(null));
        $variant = $this->save($product, new ProductVariant(['product_id' => $product->id]), $data, $request);

        return response()->json(['data' => AdminCatalogPresenter::variant($variant)], 201);
    }

    /**
     * Update a variant
     *
     * Send only the fields to change. `stock` sets the counted quantity (recorded as a stock
     * adjustment).
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, ProductVariant $variant): array
    {
        Gate::authorize('update', $variant->product);
        $this->authorizeStock($request);

        $data = $request->validate($this->rules($variant));

        return ['data' => AdminCatalogPresenter::variant($this->save($variant->product, $variant, $data, $request))];
    }

    /**
     * Delete a variant
     *
     * A product keeps at least one variant.
     */
    public function destroy(ProductVariant $variant): JsonResponse
    {
        Gate::authorize('update', $variant->product);

        if ($variant->product->variants()->count() <= 1) {
            throw ValidationException::withMessages(['variant' => __('A product needs at least one variant.')]);
        }

        DB::transaction(function () use ($variant) {
            $variant->delete();

            if ($variant->is_default) {
                $variant->product->variants()->orderBy('position')->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(null, 204);
    }

    /**
     * Change stock
     *
     * Either `on_hand` (a count: the quantity on the shelf now) or `adjust` (a difference,
     * e.g. -2 for damaged goods). Recorded in the stock history with the optional `note`.
     * `location` is a stock location code; without it the default location is used.
     *
     * @return array<string, mixed>
     */
    public function stock(Request $request, ProductVariant $variant): array
    {
        Gate::authorize('catalog.inventory.manage');

        $data = $request->validate([
            'on_hand' => ['required_without:adjust', 'prohibits:adjust', 'integer', 'min:0', 'max:10000000'],
            'adjust' => ['required_without:on_hand', 'integer', 'min:-10000000', 'max:10000000', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', Rule::exists('stock_locations', 'code')],
        ]);

        $admin = $this->admin($request);
        $location = isset($data['location']) ? StockLocation::query()->where('code', $data['location'])->firstOrFail() : null;

        if (isset($data['on_hand'])) {
            $this->inventory->setOnHand($variant, (int) $data['on_hand'], $admin, $data['note'] ?? null, $location);
        } else {
            $this->inventory->adjust($variant, (int) $data['adjust'], StockMovementReason::Adjustment, admin: $admin, note: $data['note'] ?? null, location: $location, enforceAvailability: false);
        }

        return ['data' => AdminCatalogPresenter::variant($variant->refresh()->load(['optionValues', 'stockLevels.location']))];
    }

    /**
     * Transfer stock
     *
     * Moves `quantity` available units from the location `from` to `to` (location codes),
     * recorded in the stock history at both. Reserved units cannot be moved (422).
     *
     * @return array<string, mixed>
     */
    public function transfer(Request $request, ProductVariant $variant): array
    {
        Gate::authorize('catalog.inventory.manage');

        $data = $request->validate([
            'from' => ['required', 'string', Rule::exists('stock_locations', 'code')],
            'to' => ['required', 'string', 'different:from', Rule::exists('stock_locations', 'code')],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $from = StockLocation::query()->where('code', $data['from'])->firstOrFail();
        $to = StockLocation::query()->where('code', $data['to'])->firstOrFail();

        try {
            $this->inventory->transfer($variant, $from, $to, (int) $data['quantity'], $this->admin($request), $data['note'] ?? null);
        } catch (InsufficientStock) {
            throw ValidationException::withMessages(['quantity' => __('Not enough stock available at :location.', ['location' => $from->name])]);
        }

        return ['data' => AdminCatalogPresenter::variant($variant->refresh()->load(['optionValues', 'stockLevels.location']))];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?ProductVariant $variant): array
    {
        $sometimes = $variant === null ? [] : ['sometimes'];

        return [
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('product_variants', 'sku')->ignore($variant?->id)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price' => [...$sometimes, 'required', 'numeric', 'min:0', 'max:99999999'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'allow_backorder' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'option_value_ids' => ['sometimes', 'array'],
            'option_value_ids.*' => ['integer', Rule::exists('option_values', 'id')],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Product $product, ProductVariant $variant, array $data, Request $request): ProductVariant
    {
        try {
            return $this->variants->save(
                $product,
                $variant,
                $data,
                array_key_exists('option_value_ids', $data) ? array_values(array_map('intval', (array) $data['option_value_ids'])) : null,
                array_key_exists('stock', $data) ? (int) $data['stock'] : null,
                $this->admin($request),
            );
        } catch (InvalidVariant $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }
}
