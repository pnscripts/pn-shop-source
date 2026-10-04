<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use PnShop\Api\Http\Resources\AdminCatalogPresenter;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\ProductType;

/**
 * Products. Simple products carry price, sale price, SKU, barcode, weight and stock on the
 * product itself (its only variant); products with variants manage them through
 * /products/{id}/variants. Prices are decimal amounts in the store currency.
 */
class ProductController extends AdminController
{
    /**
     * List products
     *
     * Filters: `filter[is_active]`, `filter[is_featured]`, `filter[category_id]`, `filter[brand_id]`, `filter[sku]`
     * (any variant), `filter[updated_since]` (ISO 8601) and `q` (title). Sort: `id`, `updated_at`
     * (prefix `-` for descending).
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', Product::class);

        [$column, $direction] = $this->sort($request, ['id', 'updated_at'], 'id');
        $filter = (array) $request->input('filter', []);

        $products = $this->updatedSince(Product::query(), $request)
            ->with(AdminCatalogPresenter::PRODUCT_RELATIONS)
            ->when(isset($filter['is_active']), fn (Builder $query) => $query->where('is_active', filter_var($filter['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(isset($filter['is_featured']), fn (Builder $query) => $query->where('is_featured', filter_var($filter['is_featured'], FILTER_VALIDATE_BOOLEAN)))
            ->when(isset($filter['category_id']), fn (Builder $query) => $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey((int) $filter['category_id'])))
            ->when(isset($filter['brand_id']), fn (Builder $query) => $query->where('brand_id', (int) $filter['brand_id']))
            ->when(isset($filter['sku']), fn (Builder $query) => $query->whereHas('variants', fn (Builder $variants) => $variants->where('sku', (string) $filter['sku'])))
            ->when($request->filled('q'), fn (Builder $query) => $query->whereLike('products.title', '%'.addcslashes($request->string('q')->toString(), '%_\\').'%'))
            ->orderBy('products.'.$column, $direction)
            ->when($column !== 'id', fn (Builder $query) => $query->orderBy('products.id', $direction))
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return $this->paginated($products, fn (Product $product) => AdminCatalogPresenter::product($product));
    }

    /**
     * Show a product
     *
     * @return array<string, mixed>
     */
    public function show(Product $product): array
    {
        Gate::authorize('view', $product);

        return ['data' => AdminCatalogPresenter::product($product->load(AdminCatalogPresenter::PRODUCT_RELATIONS))];
    }

    /**
     * Create a product
     *
     * The slug is generated from the title when left out. For a simple product, `price` is
     * required and `stock` sets the counted quantity.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Product::class);
        $this->authorizeStock($request);

        $data = $request->validate($this->rules(null));
        $product = DB::transaction(fn () => $this->save(new Product, $data));

        return response()->json(['data' => AdminCatalogPresenter::product($product)], 201);
    }

    /**
     * Update a product
     *
     * Send only the fields to change.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, Product $product): array
    {
        Gate::authorize('update', $product);
        $this->authorizeStock($request);

        $data = $request->validate($this->rules($product));

        return ['data' => AdminCatalogPresenter::product(DB::transaction(fn () => $this->save($product, $data)))];
    }

    /**
     * Delete a product
     *
     * Moves it to the trash (restorable in the admin).
     */
    public function destroy(Product $product): JsonResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Product $product): array
    {
        $creating = $product === null;
        $simple = $product === null || $product->type === ProductType::Simple;
        $sometimes = $creating ? [] : ['sometimes'];

        return [
            'type' => [...$sometimes, Rule::enum(ProductType::class)],
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('products', 'slug')->ignore($product?->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'category_id' => [...$sometimes, 'required', 'integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
            'category_ids' => ['sometimes', 'array'],
            // Channels the product is shown in; empty: every channel.
            'channel_ids' => ['sometimes', 'array'],
            'channel_ids.*' => ['integer', Rule::exists('channels', 'id')],
            'category_ids.*' => ['integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'tax_class_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tax_classes', 'id')],
            // Products with variants: the options (e.g. size, color) each variant picks a value of.
            'option_ids' => ['sometimes', 'array'],
            'option_ids.*' => ['integer', Rule::exists('options', 'id')],
            'gallery' => ['sometimes', 'array'],
            'gallery.*' => ['integer', Rule::exists('media', 'id')],
            // Simple products only; the variant endpoints handle products with variants.
            'price' => $simple ? [...($creating ? ['required_unless:type,variable'] : ['sometimes']), 'numeric', 'min:0', 'max:99999999'] : ['prohibited'],
            'sale_price' => $simple ? ['sometimes', 'nullable', 'numeric', 'min:0'] : ['prohibited'],
            'sku' => $simple ? ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('product_variants', 'sku')->ignore($product?->defaultVariant()?->id)] : ['prohibited'],
            'barcode' => $simple ? ['sometimes', 'nullable', 'string', 'max:64'] : ['prohibited'],
            'weight' => $simple ? ['sometimes', 'nullable', 'integer', 'min:0'] : ['prohibited'],
            'stock' => $simple ? ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'] : ['prohibited'],
            ...$this->translationRules([
                'title' => ['string', 'max:255'],
                'slug' => ['string', 'max:255', 'alpha_dash:ascii'],
                'description' => ['string'],
                'meta_title' => ['string', 'max:255'],
                'meta_description' => ['string', 'max:500'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Product $product, array $data): Product
    {
        if (array_key_exists('category_id', $data)) {
            $data['product_category_id'] = $data['category_id'];
        }

        $this->fillTranslatable($product, Arr::except($data, ['category_id', 'category_ids', 'channel_ids', 'gallery', 'option_ids']))->save();

        if (array_key_exists('channel_ids', $data)) {
            $product->channels()->sync(array_values(array_unique(array_map('intval', $data['channel_ids']))));
        }

        if (array_key_exists('option_ids', $data)) {
            $positions = [];

            foreach (array_values($data['option_ids']) as $position => $optionId) {
                $positions[(int) $optionId] = ['position' => $position];
            }

            $product->options()->sync($positions);
        }

        if (array_key_exists('category_ids', $data)) {
            // The primary category always stays among the product's categories.
            $product->categories()->sync(array_values(array_unique([...array_map('intval', $data['category_ids']), $product->product_category_id])));
        }

        if (array_key_exists('gallery', $data)) {
            $product->syncMediaCollection('gallery', array_values(array_map('intval', $data['gallery'])));
        }

        return $product->refresh()->load(AdminCatalogPresenter::PRODUCT_RELATIONS);
    }
}
