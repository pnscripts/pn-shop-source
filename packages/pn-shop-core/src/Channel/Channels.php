<?php

namespace PnShop\Channel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use PnShop\Channel\Models\Channel;
use Throwable;

/**
 * The channel (storefront) a request is for, and running code for a given channel.
 *
 * A request's channel is chosen by its host and first path segment (see ResolveChannel);
 * anything that matches no channel goes to the default one. Outside a storefront request
 * (the admin panel, the console, queued jobs) there is no active channel: the default one
 * is reported as current, but none of its settings overrides apply unless code runs inside
 * using().
 *
 * One per request (scoped).
 */
class Channels
{
    private const HOSTS_CACHE = 'pnshop.channels.hosts';

    private ?Channel $active = null;

    /** @var Collection<int, Channel>|null */
    private ?Collection $channels = null;

    /** The channel of this request, or the default one. */
    public function current(): Channel
    {
        return $this->active ?? $this->default();
    }

    /** Whether a storefront channel is active (its overrides apply). */
    public function isActive(): bool
    {
        return $this->active !== null;
    }

    public function activate(?Channel $channel): void
    {
        $this->active = $channel;
    }

    public function default(): Channel
    {
        return $this->all()->firstWhere('is_default', true)
            ?? $this->all()->first()
            ?? (new Channel)->forceFill(['id' => 0, 'code' => 'default', 'name' => 'Main store', 'is_default' => true, 'is_active' => true]);
    }

    /**
     * Active channels, by position.
     *
     * @return Collection<int, Channel>
     */
    public function all(): Collection
    {
        if ($this->channels !== null) {
            return $this->channels;
        }

        try {
            return $this->channels = Channel::query()->active()->orderByDesc('is_default')->orderBy('position')->orderBy('id')->get()->toBase();
        } catch (Throwable) {
            // Not installed yet.
            return $this->channels = collect();
        }
    }

    /**
     * The channel a request is for, and the path prefix it answers on (or null).
     *
     * @return array{0: Channel, 1: string|null}|null
     */
    public function resolve(Request $request): ?array
    {
        $channels = $this->all();

        if ($channels->isEmpty()) {
            return null;
        }

        $host = strtolower($request->getHost());
        $segment = strtolower((string) $request->segment(1));
        $onHost = $channels->filter(fn (Channel $channel) => $channel->hostname === $host);
        $anyHost = $channels->filter(fn (Channel $channel) => $channel->hostname === null);

        foreach ([$onHost, $anyHost] as $candidates) {
            $byPath = $segment === '' ? null : $candidates->first(fn (Channel $channel) => $channel->path === $segment);

            if ($byPath !== null) {
                return [$byPath, $byPath->path];
            }

            $root = $candidates->first(fn (Channel $channel) => $channel->path === null);

            if ($root !== null) {
                return [$root, null];
            }
        }

        return [$this->default(), null];
    }

    /**
     * Whether the active channel uses a stock location, payment or shipping method (any,
     * when its list is empty or no channel is active).
     *
     * @param  'stock_location_ids'|'payment_method_ids'|'shipping_method_ids'  $list
     */
    public function allows(string $list, int $id, ?Channel $channel = null): bool
    {
        $channel ??= $this->active;

        return $channel === null || $channel->allows($list, $id);
    }

    /**
     * The value a channel overrides for a setting, or null.
     */
    public function override(string $path): mixed
    {
        if ($this->active === null || ! in_array($path, Channel::OVERRIDABLE, true)) {
            return null;
        }

        return $this->active->setting($path);
    }

    /**
     * Run code as if serving the channel: its settings apply and links point at its address
     * (order emails, sitemaps, jobs).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function using(?Channel $channel, Closure $callback): mixed
    {
        if ($channel === null) {
            return $callback();
        }

        $previous = $this->enter($channel);

        try {
            return $callback();
        } finally {
            $this->leave($previous);
        }
    }

    /**
     * Start serving a channel outside a request; returns what to give leave() afterwards.
     */
    public function enter(Channel $channel): ?Channel
    {
        $previous = $this->active;
        $this->active = $channel;
        URL::forceRootUrl($channel->url());

        return $previous;
    }

    public function leave(?Channel $previous): void
    {
        $this->active = $previous;
        URL::forceRootUrl($previous?->url());
    }

    /**
     * Host names the shop answers on (for TrustAppHost).
     *
     * @return list<string>
     */
    public function hostnames(): array
    {
        try {
            return Cache::rememberForever(self::HOSTS_CACHE, fn () => array_values(array_filter(
                Channel::query()->active()->pluck('hostname')->all(),
                fn (mixed $host) => is_string($host) && $host !== '',
            )));
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        $this->channels = null;
        Cache::forget(self::HOSTS_CACHE);

        if ($this->active !== null) {
            $this->active = Channel::query()->find($this->active->id) ?? $this->active;
        }
    }
}
