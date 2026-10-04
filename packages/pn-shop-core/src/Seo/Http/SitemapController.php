<?php

namespace PnShop\Seo\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Channel\Channels;
use PnShop\Cms\Models\Page;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Language;
use PnShop\Seo\Seo;
use PnShop\Settings\Settings;

/**
 * /sitemap.xml lists one sitemap per language and kind (pages, categories, products in
 * chunks); every URL carries its alternates in the other languages. Cached for an hour.
 */
class SitemapController
{
    public const PER_FILE = 5000;

    public function __construct(
        private Localization $localization,
        private Seo $seo,
        private Settings $settings,
    ) {}

    public function index(): Response
    {
        abort_unless((bool) $this->settings->get('seo.allow_indexing'), 404);

        $xml = Cache::remember('pnshop.sitemap.'.app(Channels::class)->current()->code.'.index', now()->addHour(), function () {
            $files = [];
            $productFiles = max(1, (int) ceil(Product::query()->active()->count() / self::PER_FILE));

            foreach ($this->localization->languages() as $language) {
                /** @var Language $language */
                $files[] = "{$language->code}-pages";
                $files[] = "{$language->code}-categories";

                for ($i = 1; $i <= $productFiles; $i++) {
                    $files[] = "{$language->code}-products-{$i}";
                }
            }

            $entries = array_map(fn (string $file) => '<sitemap><loc>'.e(url("/sitemaps/{$file}.xml")).'</loc></sitemap>', $files);

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $entries).'</sitemapindex>';
        });

        return $this->xml($xml);
    }

    public function show(Request $request, string $file): Response
    {
        abort_unless((bool) $this->settings->get('seo.allow_indexing'), 404);
        abort_unless(preg_match('/^([a-z]{2,3}(?:-[A-Za-z]{2,4})?)-(pages|categories|products)(?:-([1-9]\d{0,5}))?$/', $file, $match) === 1, 404);

        [, $locale, $kind] = $match;
        $chunk = (int) ($match[3] ?? 1);

        abort_unless($this->localization->isSupported($locale), 404);

        // Only files that exist are built and cached, so made-up numbers cannot fill the cache.
        $files = $kind === 'products' ? max(1, (int) ceil(Product::query()->active()->count() / self::PER_FILE)) : 1;
        abort_if($chunk > $files, 404);

        $xml = Cache::remember('pnshop.sitemap.'.app(Channels::class)->current()->code.".{$locale}-{$kind}-{$chunk}", now()->addHour(), function () use ($request, $locale, $kind, $chunk) {
            $urls = match ($kind) {
                'pages' => $this->pages($request, $locale),
                'categories' => $this->records(Category::query()->active(), fn (Model $category, string $slug) => '/shop?category='.rawurlencode($slug), $request, $locale),
                default => $this->records(Product::query()->active()->orderBy('id')->forPage($chunk, self::PER_FILE), fn (Model $product, string $slug) => '/shop/'.$slug, $request, $locale),
            };

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
                .implode('', array_map(fn (array $url) => $this->entry($url), $urls))
                .'</urlset>';
        });

        return $this->xml($xml);
    }

    /**
     * @return list<array{loc: string, alternates: array<string, string>, lastmod: string|null}>
     */
    private function pages(Request $request, string $locale): array
    {
        $static = array_map(fn (string $path) => [
            'loc' => $this->seo->url($path, $locale, $request),
            'alternates' => $this->alternates(fn (string $code) => $path, $request),
            'lastmod' => null,
        ], ['/', '/shop']);

        $pages = $this->records(Page::query()->live()->where('is_home', false), fn (Model $page, string $slug) => '/'.$slug, $request, $locale);

        return [...$static, ...$pages];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  \Closure(Model, string): string  $path  path for a record and its slug in a language
     * @return list<array{loc: string, alternates: array<string, string>, lastmod: string|null}>
     */
    private function records(Builder $query, \Closure $path, Request $request, string $locale): array
    {
        $urls = [];

        foreach ($query->withoutGlobalScope('translations')->with('translations')->get() as $record) {
            $slugFor = function (string $code) use ($record): string {
                $translated = $record->getRelation('translations')->firstWhere('locale', $code)?->getAttribute('slug');

                return (string) ($translated ?: $record->getAttributes()['slug']);
            };

            $urls[] = [
                'loc' => $this->seo->url($path($record, $slugFor($locale)), $locale, $request),
                'alternates' => $this->alternates(fn (string $code) => $path($record, $slugFor($code)), $request),
                'lastmod' => $record->getAttribute('updated_at')?->toAtomString(),
            ];
        }

        return $urls;
    }

    /**
     * @param  \Closure(string): string  $path
     * @return array<string, string>
     */
    private function alternates(\Closure $path, Request $request): array
    {
        return $this->localization->languages()
            ->mapWithKeys(fn (Language $language) => [$language->code => $this->seo->url($path($language->code), $language->code, $request)])
            ->all();
    }

    /**
     * @param  array{loc: string, alternates: array<string, string>, lastmod: string|null}  $url
     */
    private function entry(array $url): string
    {
        $links = '';

        foreach ($url['alternates'] as $code => $href) {
            $links .= '<xhtml:link rel="alternate" hreflang="'.e($code).'" href="'.e($href).'"/>';
        }

        return '<url><loc>'.e($url['loc']).'</loc>'.($url['lastmod'] ? '<lastmod>'.e($url['lastmod']).'</lastmod>' : '').$links.'</url>';
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
