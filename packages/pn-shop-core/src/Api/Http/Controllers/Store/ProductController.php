<?php

namespace PnShop\Api\Http\Controllers\Store;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use PnShop\Api\Http\Controllers\ApiController;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Catalog\Presenters\ProductDetailPresenter;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Catalog\ProductBrowser;

class ProductController extends ApiController
{
    /**
     * List products
     *
     * Active products as cards, newest first. Filter with `category` and `brand` (slugs, a
     * category includes its subcategories), `filter[<attribute id>][]=<value id>` and `q`
     * (title search). `sort` is `-id` (newest, default) or `id`.
     *
     * @return array<string, mixed>
     */
    #[QueryParameter('category', 'Category slug; includes its subcategories.', type: 'string')]
    #[QueryParameter('brand', 'Brand slug.', type: 'string')]
    #[QueryParameter('q', 'Search in product titles.', type: 'string')]
    #[QueryParameter('filter', 'Attribute values: filter[<attribute id>][]=<value id>. Values of one attribute are alternatives; attributes narrow down.', type: 'object')]
    #[QueryParameter('sort', '`-id` (newest first, default) or `id`.', type: 'string', example: '-id')]
    public function index(Request $request): array
    {
        [, $direction] = $this->sort($request, ['id'], '-id');

        $products = ProductBrowser::fromInput($request->only(['category', 'brand', 'filter', 'q']))
            ->query()
            ->with(ProductCardPresenter::RELATIONS)
            ->orderBy('products.id', $direction)
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        app(PriceResolver::class)->primeProducts($products->items());

        return $this->paginated($products, fn (Product $product) => ProductCardPresenter::present($product));
    }

    /**
     * Show a product
     *
     * The product page: gallery, options, purchasable variants with price and stock,
     * breadcrumbs, attributes and related products. Looked up by slug in the response language.
     *
     * @return array<string, mixed>
     */
    public function show(string $slug): array
    {
        $product = Product::query()->active()->whereTranslated('slug', $slug)->firstOrFail();

        ProductDetailPresenter::load($product);

        abort_if($product->variants->isEmpty(), 404);

        return ['data' => [
            ...ProductDetailPresenter::present($product, ProductDetailPresenter::trail($product)),
            'related' => ProductDetailPresenter::related($product),
        ]];
    }
}
