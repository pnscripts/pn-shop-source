<?php

namespace PnShop\Storefront\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Catalog\Models\Brand;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductAttribute;
use PnShop\Catalog\Models\ProductAttributeValue;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Catalog\Presenters\ProductDetailPresenter;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Catalog\ProductBrowser;
use PnShop\Seo\CatalogSeo;

class ShopController extends Controller
{
    public function index(Request $request): Response
    {
        $browser = ProductBrowser::fromInput($request->only(['category', 'brand', 'filter', 'q', 'sort']));
        $category = $browser->category;
        $brand = $browser->brand;
        $base = $browser->base();

        $products = $browser->sorted()
            ->with(ProductCardPresenter::RELATIONS)
            ->paginate(12)
            ->withQueryString();
        app(PriceResolver::class)->primeProducts($products->getCollection());
        $products->through(fn (Product $product) => ProductCardPresenter::present($product));

        $trail = $category ? array_values(Category::query()->whereAncestorOf($category, andSelf: true)->defaultOrder()->get()->all()) : [];
        app(CatalogSeo::class)->listing($request, $category, $brand, $trail);

        $categories = Category::query()->active()->defaultOrder()->get(['id', 'title', 'slug', 'parent_id', '_lft', '_rgt']);

        return Inertia::render('shop/index', [
            'products' => $products,
            'categories' => self::tree($categories, null),
            'brands' => Brand::query()->active()->orderBy('name')->get(['id', 'name', 'slug']),
            'facets' => $this->facets($base),
            'filters' => [
                'category' => $category?->slug,
                'category_path' => array_map(fn (Category $item) => $item->slug, $trail),
                'brand' => $brand?->slug,
                'attributes' => (object) $browser->attributeFilters,
                'q' => $browser->search,
                'sort' => $browser->sort,
            ],
            'sorts' => collect(ProductBrowser::SORTS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => __($label)])->values()->all(),
        ]);
    }

    public function show(Request $request, Product $product): Response
    {
        abort_unless($product->is_active && $product->isVisibleInChannel(), 404);

        ProductDetailPresenter::load($product);

        abort_if($product->variants->isEmpty(), 404);

        $trail = ProductDetailPresenter::trail($product);
        app(CatalogSeo::class)->product($request, $product, $trail);

        return Inertia::render('shop/show', [
            'product' => ProductDetailPresenter::present($product, $trail),
            'related' => ProductDetailPresenter::related($product),
        ]);
    }

    /**
     * Filterable attributes with the values that occur among the products being browsed.
     *
     * @param  Builder<Product>  $base
     * @return list<array{id: int, label: string, values: list<array{id: int, value: string}>}>
     */
    private function facets(Builder $base): array
    {
        $productIds = (clone $base)->select('products.id');

        return array_values(ProductAttribute::query()
            ->where('is_filterable', true)
            ->orderBy('position')
            ->with(['values' => fn ($values) => $values->whereHas('products', fn (Builder $products) => $products->whereIn('products.id', $productIds))])
            ->get()
            ->filter(fn (ProductAttribute $attribute) => $attribute->values->isNotEmpty())
            ->map(fn (ProductAttribute $attribute) => [
                'id' => $attribute->id,
                'label' => $attribute->label,
                'values' => array_values($attribute->values->map(fn (ProductAttributeValue $value) => ['id' => $value->id, 'value' => $value->value])->all()),
            ])
            ->all());
    }

    /**
     * Categories as a tree at any depth, for the shop's category rows.
     *
     * @param  Collection<int, Category>  $categories
     * @return list<array{id: int, title: string, slug: string, children: list<array<string, mixed>>}>
     */
    private static function tree(Collection $categories, ?int $parentId): array
    {
        return array_values($categories->where('parent_id', $parentId)->map(fn (Category $category): array => [
            'id' => $category->id,
            'title' => $category->title,
            'slug' => $category->slug,
            'children' => self::tree($categories, $category->id),
        ])->all());
    }
}
