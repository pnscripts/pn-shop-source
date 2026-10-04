<?php

namespace PnShop\Channel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Channel\Http\Middleware\ResolveChannel;
use PnShop\Channel\Models\Channel;
use PnShop\Channel\Policies\ChannelPolicy;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Localization\Localization;
use PnShop\Settings\Settings;

/**
 * Channels: several storefronts on one installation.
 */
class ChannelServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Channels::class);

        Relation::morphMap(['channel' => Channel::class]);
    }

    protected function permissions(): array
    {
        return [new Permission('system.channels.manage', 'Manage channels (storefronts)', 'System')];
    }

    protected function bootModule(): void
    {
        Gate::policy(Channel::class, ChannelPolicy::class);

        // Prepended after the language middleware, so it runs first: the channel decides the languages.
        $this->app->make(HttpKernel::class)->prependMiddleware(ResolveChannel::class);

        $this->app->make(Settings::class)->overrideUsing(fn (string $path) => $this->app->make(Channels::class)->override($path));

        $this->app->make(Localization::class)->restrictUsing(function (): array {
            $channels = $this->app->make(Channels::class);

            if (! $channels->isActive()) {
                return [null, null];
            }

            $channel = $channels->current();

            return [$channel->default_locale, $channel->locales === null || $channel->locales === [] ? null : $channel->locales];
        });
    }
}
