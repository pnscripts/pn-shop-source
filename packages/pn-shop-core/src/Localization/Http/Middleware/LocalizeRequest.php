<?php

namespace PnShop\Localization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PnShop\Installer\Http\Middleware\InstallGate;
use PnShop\Localization\Localization;
use PnShop\Settings\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware that serves "/bg/shop" from the "/shop" route with the locale set to "bg".
 *
 * The language prefix becomes the request's base URL, exactly as if the store were installed
 * in a "/bg" subdirectory. Everything Laravel derives from the request — route() and url(),
 * pagination links, redirects, back(), Ziggy — therefore keeps the prefix without special
 * handling, while routes stay unprefixed (so route caching works). Assets keep the real root.
 * The default language is never prefixed.
 */
class LocalizeRequest
{
    public function __construct(private Localization $localization) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The installer runs before the database (and its settings) exist.
        if ($request->attributes->get(InstallGate::ATTRIBUTE) === true) {
            return $next($request);
        }

        $timezone = app(Settings::class)->get('localization.timezone');
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        $default = $this->localization->defaultLocale();
        app()->setLocale($default);

        $locale = $request->segment(1);

        if ($locale === null) {
            return $next($request);
        }

        $path = substr($request->getPathInfo(), strlen($locale) + 1) ?: '/';
        $query = $request->getQueryString();

        // One canonical URL: /en/shop (default language) redirects to /shop.
        if ($locale === $default && $request->isMethod('GET')) {
            return redirect()->to($path.($query !== null ? '?'.$query : ''), 301);
        }

        if (! $this->localization->isSupported($locale)) {
            return $next($request);
        }

        // Admin, Livewire, assets, ... are never localized: /bg/_debugbar/x is served as /_debugbar/x.
        if ($this->isUnlocalized($path)) {
            $server = $request->server->all();
            $server['REQUEST_URI'] = $request->getBaseUrl().$path.($query !== null ? '?'.$query : '');
            $unprefixed = $request->duplicate(server: $server);
            app()->instance('request', $unprefixed);

            return $next($unprefixed);
        }

        app()->setLocale($locale);

        $localized = $request->duplicate(server: $this->serverWithBase($request, $locale));
        $localized->attributes->set('locale_prefix', '/'.$locale);

        // A channel on a path already moved the base: assets stay at the real root.
        URL::useAssetOrigin($request->attributes->get('asset_root', $request->root()));
        app()->instance('request', $localized);

        return $next($localized);
    }

    /**
     * Put the URL generator back after the response, so work done afterwards
     * (long-lived workers, tests) starts from the real root.
     */
    public function terminate(Request $request, Response $response): void
    {
        URL::useAssetOrigin(config('app.asset_url'));

        if (app('request')->attributes->has('locale_prefix')) {
            app()->instance('request', $request);
        }
    }

    private function isUnlocalized(string $path): bool
    {
        $first = explode('/', ltrim($path, '/'))[0];

        // Livewire 4 serves its endpoints under a hashed prefix such as "livewire-602b24a2".
        return in_array($first, Localization::UNLOCALIZED_PATHS, true) || str_starts_with($first, 'livewire-');
    }

    /**
     * Server variables that make Symfony compute "<real base>/<locale>" as the base URL.
     *
     * @return array<string, mixed>
     */
    private function serverWithBase(Request $request, string $locale): array
    {
        $server = $request->server->all();
        $script = (string) ($server['SCRIPT_NAME'] ?? '') ?: '/index.php';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

        $server['SCRIPT_NAME'] = $base.'/'.$locale.'/'.basename($script);
        $server['PHP_SELF'] = $server['SCRIPT_NAME'];
        $server['SCRIPT_FILENAME'] = (string) ($server['SCRIPT_FILENAME'] ?? '') ?: public_path(basename($script));

        // Symfony only detects the base when the URI continues past it: "/bg" must become "/bg/".
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        $uriPath = (string) strtok($uri, '?');

        if ($uriPath === $base.'/'.$locale) {
            $server['REQUEST_URI'] = $uriPath.'/'.substr($uri, strlen($uriPath));
        }

        return $server;
    }
}
