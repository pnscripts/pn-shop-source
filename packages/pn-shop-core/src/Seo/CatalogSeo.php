<?php

namespace PnShop\Seo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PnShop\Catalog\Models\Brand;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Cms\Models\Page;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Language;

/**
 * Fills the SEO data for the storefront's standard pages.
 */
final class CatalogSeo
{
    public function __construct(
        private Seo $seo,
        private Localization $localization,
    ) {}

    public function home(Request $request, ?Page $page = null): void
    {
        $home = $this->seo->url('/', null, $request);

        if ($page !== null) {
            $this->seo->title($page->meta_title ?: null)->description($page->meta_description ?: $page->excerpt);
        }

        $this->seo->canonical($home)
            ->jsonLd(Schema::organization($home))
            ->jsonLd(Schema::website($home));
    }

    /**
     * @param  list<Category>  $trail  the product's category and its ancestors, root first
     */
    public function product(Request $request, Product $product, array $trail): void
    {
        $alternates = $this->alternates($product, fn (string $slug) => '/shop/'.$slug, $request);
        $url = $alternates[app()->getLocale()] ?? $request->url();
        $image = ProductCardPresenter::mainImage($product);

        /** @var Collection<int, ProductVariant> $variants */
        $variants = $product->variants;
        $prices = $variants->map(fn (ProductVariant $variant) => $variant->unitPrice())->sortBy(fn ($price) => $price->getMinorAmount()->toInt())->values();
        $default = $product->defaultVariant();

        $this->seo->title($product->getAttribute('meta_title') ?: $product->title)
            ->description($product->getAttribute('meta_description') ?: $product->description)
            ->image($image['url'] ?? null)
            ->type('product')
            ->alternates($alternates)
            ->languageLinks($alternates)
            ->canonical($url);

        if ($prices->isNotEmpty()) {
            $this->seo->jsonLd(Schema::product(
                $product->title,
                $product->description,
                $url,
                $image !== null ? [$image['url']] : [],
                $variants->count() === 1 ? $default?->sku : null,
                $variants->count() === 1 ? $default?->barcode : null,
                $product->brand?->name,
                $prices->first(),
                $variants->contains(fn (ProductVariant $variant) => $variant->available() === null || $variant->available() > 0 || $variant->allow_backorder),
                $prices->last(),
            ));
        }

        $this->seo->jsonLd(Schema::breadcrumbs([
            ['name' => __('Shop'), 'url' => $this->seo->url('/shop', null, $request)],
            ...array_map(fn (Category $category) => ['name' => $category->title, 'url' => $this->seo->url('/shop?category='.rawurlencode($category->slug), null, $request)], $trail),
            ['name' => $product->title, 'url' => $url],
        ]));
    }

    /**
     * The shop listing. Only the plain listing, a category or a brand get a canonical
     * address (and are indexed); filtered, sorted and later pages are not.
     *
     * @param  list<Category>  $trail
     */
    public function listing(Request $request, ?Category $category, ?Brand $brand, array $trail): void
    {
        $query = collect($request->query())->except(['category', 'brand'])->filter(fn ($value) => $value !== null && $value !== '');
        $plain = $query->isEmpty() && ! ($category !== null && $brand !== null);

        if ($category !== null) {
            $this->seo->title($category->getAttribute('meta_title') ?: $category->title)
                ->description($category->getAttribute('meta_description') ?: $category->getAttribute('description'));

            $alternates = $this->alternates($category, fn (string $slug) => '/shop?category='.rawurlencode($slug), $request);

            $this->seo->jsonLd(Schema::breadcrumbs([
                ['name' => __('Shop'), 'url' => $this->seo->url('/shop', null, $request)],
                ...array_map(fn (Category $item) => ['name' => $item->title, 'url' => $this->seo->url('/shop?category='.rawurlencode($item->slug), null, $request)], $trail),
            ]));
        } elseif ($brand !== null) {
            $this->seo->title($brand->name)->description($brand->description);
            $alternates = $this->alternates($brand, fn (string $slug) => '/shop?brand='.rawurlencode($slug), $request);
        } else {
            $this->seo->title(__('Shop'));
            $alternates = $this->sameEverywhere('/shop', $request);
        }

        $this->seo->alternates($alternates);

        // The language switcher keeps the visitor's other filters.
        if ($category !== null || $brand !== null) {
            $rest = $query->isEmpty() ? '' : '&'.http_build_query($query->all());
            $this->seo->languageLinks(array_map(fn (string $url) => $url.$rest, $alternates));
        }

        if ($plain) {
            $this->seo->canonical($alternates[app()->getLocale()] ?? $request->url());
        }
    }

    public function page(Request $request, Page $page): void
    {
        $alternates = $this->alternates($page, fn (string $slug) => '/'.$slug, $request);
        $url = $alternates[app()->getLocale()] ?? $request->url();
        $description = $page->meta_description ?: $page->excerpt;

        $this->seo->title($page->meta_title ?: $page->title)
            ->description($description)
            ->type('article')
            ->alternates($alternates)
            ->languageLinks($alternates)
            ->canonical($url)
            ->jsonLd(Schema::webPage($page->title, Seo::summary($description), $url));
    }

    /**
     * The address of a record in every language, built from its slug in that language.
     *
     * @param  \Closure(string): string  $path
     * @return array<string, string>
     */
    private function alternates(Model $record, \Closure $path, Request $request): array
    {
        $translations = method_exists($record, 'translations') ? $record->translations()->get() : collect();
        $base = (string) ($record->getAttributes()['slug'] ?? '');

        return $this->localization->languages()->mapWithKeys(function (Language $language) use ($translations, $base, $path, $request) {
            $slug = $translations->firstWhere('locale', $language->code)?->getAttribute('slug') ?: $base;

            return [$language->code => $this->seo->url($path((string) $slug), $language->code, $request)];
        })->all();
    }

    /**
     * @return array<string, string>
     */
    private function sameEverywhere(string $path, Request $request): array
    {
        return $this->localization->languages()
            ->mapWithKeys(fn (Language $language) => [$language->code => $this->seo->url($path, $language->code, $request)])
            ->all();
    }
}
