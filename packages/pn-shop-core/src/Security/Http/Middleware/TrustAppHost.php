<?php

namespace PnShop\Security\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;
use PnShop\Channel\Channels;
use PnShop\Installer\Installation;

/**
 * Only answers requests for the shop's own host (APP_URL and its subdomains, plus
 * PNSHOP_TRUSTED_HOSTS and the channels' domains). A forged Host header could otherwise put another domain into
 * password reset emails, signed links and the cached sitemap.
 *
 * Skipped until the shop is installed (APP_URL is set by the installer), and in the local
 * environment and tests.
 */
class TrustAppHost extends TrustHosts
{
    /**
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $extra = array_filter(array_map('trim', explode(',', (string) config('pnshop.security.trusted_hosts'))));

        // Channels on their own domains are trusted too.
        $channels = app(Channels::class)->hostnames();

        return [
            $this->allSubdomainsOfApplicationUrl(),
            ...array_map(fn (string $host) => '^'.preg_quote($host).'$', [...$extra, ...$channels]),
        ];
    }

    protected function shouldSpecifyTrustedHosts()
    {
        return parent::shouldSpecifyTrustedHosts() && app(Installation::class)->isInstalled();
    }
}
