<?php

namespace PnShop\Storefront\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Cms\ContentRenderer;
use PnShop\Cms\Models\Page;
use PnShop\Seo\CatalogSeo;

class HomeController extends Controller
{
    public function __invoke(Request $request, ContentRenderer $content, CatalogSeo $seo): Response
    {
        // A page marked as the homepage replaces the default home.
        $home = Page::query()->live()->where('is_home', true)->first();
        $seo->home($request, $home);

        if ($home !== null) {
            return Inertia::render('home', ['products' => [], 'blocks' => $content->render($home), 'title' => $home->title]);
        }

        // Featured products first, then the newest, eight in all.
        $products = Product::query()
            ->active()
            ->with(ProductCardPresenter::RELATIONS)
            ->orderByDesc('is_featured')
            ->latest()
            ->limit(8)
            ->get();
        $products = ProductCardPresenter::presentMany($products);

        return Inertia::render('home', [
            'products' => $products,
            'blocks' => null,
            'title' => null,
        ]);
    }
}
