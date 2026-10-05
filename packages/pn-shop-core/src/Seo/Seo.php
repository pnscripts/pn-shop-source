<?php

namespace PnShop\Seo;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Language;
use PnShop\Settings\Settings;

/**
 * Collects the current page's SEO data. Controllers fill it in; the root template prints
 * it on full page loads (so crawlers and link previews get it without JavaScript).
 * Extensions adjust it in the "seo.meta" pipeline (stages receive the SeoData).
 *
 * Bound per request (scoped).
 */
final class Seo
{
    public const PIPELINE = 'seo.meta';

    /** Routes that should never appear in search results. */
    private const PRIVATE_ROUTES = ['cart.*', 'checkout.*', 'account.*', 'dashboard', 'orders.*', 'invoices.*', 'pages.preview', 'pages.canvas', 'login', 'register', 'password.*', 'verification.*', 'profile.*', 'appearance', 'settings.*'];

    private SeoData $data;

    /** @var array<string, string> */
    private array $languageLinks = [];

    public function __construct(
        private Settings $settings,
        private Localization $localization,
        private PipelineRegistry $pipelines,
    ) {
        $this->data = new SeoData;
    }

    public function data(): SeoData
    {
        return $this->data;
    }

    public function title(?string $title): self
    {
        $this->data->title = $title;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->data->description = self::summary($description);

        return $this;
    }

    public function image(?string $url): self
    {
        $this->data->image = $url;

        return $this;
    }

    public function type(string $type): self
    {
        $this->data->type = $type;

        return $this;
    }

    public function noindex(): self
    {
        $this->data->index = false;

        return $this;
    }

    /**
     * @param  array<string, string>  $alternates  locale => absolute URL
     */
    public function alternates(array $alternates): self
    {
        $this->data->alternates = $alternates;

        return $this;
    }

    /**
     * Where the language switcher should send visitors, when it differs from the same
     * path in another language (translated slugs).
     *
     * @param  array<string, string>  $links  locale => absolute URL
     */
    public function languageLinks(array $links): self
    {
        $this->languageLinks = $links;

        return $this;
    }

    public function languageLinkFor(string $locale): ?string
    {
        return $this->languageLinks[$locale] ?? null;
    }

    public function canonical(string $url): self
    {
        $this->data->canonical = $url;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema  a schema.org object (without @context)
     */
    public function jsonLd(array $schema): self
    {
        $this->data->jsonLd[] = $schema;

        return $this;
    }

    /**
     * Absolute URL of a shop path in a language, e.g. ('/shop/lamp', 'bg') → https://shop.test/bg/shop/lamp.
     */
    public function url(string $path, ?string $locale = null, ?Request $request = null): string
    {
        return $this->localization->switchUrl($locale ?? app()->getLocale(), $this->origin($request ?? request()), $path);
    }

    /**
     * The page's final tags, with defaults filled in.
     *
     * @return array{title: string, description: string|null, canonical: string, alternates: array<string, string>, robots: string, image: string|null, type: string, site_name: string, locale: string, json_ld: list<array<string, mixed>>, json_ld_script: string|null}
     */
    public function resolve(Request $request): array
    {
        $data = $this->pipelines->run(self::PIPELINE, $this->data);
        $data = $data instanceof SeoData ? $data : $this->data;

        $store = (string) $this->settings->get('store.name');
        $origin = $this->origin($request);
        $alternates = $data->alternates !== [] ? $data->alternates : $this->localization->languages()
            ->mapWithKeys(fn (Language $language) => [$language->code => $this->localization->switchUrl($language->code, $origin, $request->getPathInfo())])
            ->all();

        $routeName = (string) $request->route()?->getName();
        $private = collect(self::PRIVATE_ROUTES)->contains(fn (string $pattern) => Str::is($pattern, $routeName));
        // Addresses with a query string are indexed only when the controller set a canonical
        // for them (e.g. a category listing); filtered, sorted and paged lists are not.
        $indexable = $data->index && ! $private && (bool) $this->settings->get('seo.allow_indexing') && ($request->query() === [] || $data->canonical !== null);

        return [
            'title' => $data->title !== null && $data->title !== '' ? "{$data->title} - {$store}" : $store,
            'description' => $data->description,
            'canonical' => $data->canonical ?? ($alternates[app()->getLocale()] ?? $request->url()),
            'alternates' => $alternates,
            // Filtered and paged listings are followed but not indexed.
            'robots' => ($indexable ? 'index' : 'noindex').',follow',
            'image' => $data->image,
            'type' => $data->type,
            'site_name' => $store,
            'locale' => str_replace('-', '_', app()->getLocale()),
            'json_ld' => $data->jsonLd,
            // Encoded here: Blade would read "@context" in a template as a directive.
            'json_ld_script' => $data->jsonLd === [] ? null : (string) json_encode(
                ['@context' => 'https://schema.org', '@graph' => $data->jsonLd],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
            ),
        ];
    }

    public static function summary(?string $text, int $length = 160): ?string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

        return $plain === '' ? null : Str::limit($plain, $length - 1, '…');
    }

    private function origin(Request $request): string
    {
        $prefix = (string) $request->attributes->get('locale_prefix', '');
        $base = substr($request->getBaseUrl(), 0, strlen($request->getBaseUrl()) - strlen($prefix));

        return $request->getSchemeAndHttpHost().$base;
    }
}
