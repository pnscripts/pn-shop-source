<?php

namespace PnShop\Channel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PnShop\Channel\Channels;
use PnShop\Installer\Http\Middleware\InstallGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware, before the language prefix: picks the channel for the host and the
 * first path segment.
 *
 * A channel on a path ("/trade") becomes the request's base URL, the same way the language
 * prefix does (see LocalizeRequest): routes stay unprefixed and every generated link keeps
 * "/trade". Assets keep the real root.
 */
class ResolveChannel
{
    public function __construct(private Channels $channels) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(InstallGate::ATTRIBUTE) === true) {
            return $next($request);
        }

        $resolved = $this->channels->resolve($request);

        if ($resolved === null) {
            return $next($request);
        }

        [$channel, $path] = $resolved;
        $this->channels->activate($channel);

        if ($path === null) {
            return $next($request);
        }

        $root = $request->root();
        $rebased = $request->duplicate(server: $this->serverWithBase($request, $path));
        $rebased->attributes->set('asset_root', $root);
        $rebased->attributes->set('channel_prefix', '/'.$path);

        URL::useAssetOrigin($root);
        app()->instance('request', $rebased);

        return $next($rebased);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->channels->activate(null);
        URL::useAssetOrigin(config('app.asset_url'));

        if (app('request')->attributes->has('channel_prefix')) {
            app()->instance('request', $request);
        }
    }

    /**
     * Server variables that make Symfony compute "<real base>/<path>" as the base URL.
     *
     * @return array<string, mixed>
     */
    private function serverWithBase(Request $request, string $path): array
    {
        $server = $request->server->all();
        $script = (string) ($server['SCRIPT_NAME'] ?? '') ?: '/index.php';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

        $server['SCRIPT_NAME'] = $base.'/'.$path.'/'.basename($script);
        $server['PHP_SELF'] = $server['SCRIPT_NAME'];
        $server['SCRIPT_FILENAME'] = (string) ($server['SCRIPT_FILENAME'] ?? '') ?: public_path(basename($script));

        $uri = (string) ($server['REQUEST_URI'] ?? '');
        $uriPath = (string) strtok($uri, '?');

        if ($uriPath === $base.'/'.$path) {
            $server['REQUEST_URI'] = $uriPath.'/'.substr($uri, strlen($uriPath));
        }

        return $server;
    }
}
