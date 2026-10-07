<?php

namespace PnShop\Channel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use PnShop\Channel\Channels;
use PnShop\Customer\Models\User;

/**
 * A storefront: where it answers (a domain, a path or both), its languages and currency,
 * the settings it overrides (store name, theme, tax country, ...), and which stock
 * locations, payment methods and shipping methods it uses. Empty lists mean "all".
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $hostname e.g. "wholesale.example.com"; null answers on any host
 * @property string|null $path e.g. "trade" for example.com/trade; null for the host's root
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $separate_accounts its own customer accounts (chosen when the channel is created)
 * @property string|null $default_locale
 * @property list<string>|null $locales
 * @property string|null $currency
 * @property array<string, mixed>|null $settings overridden settings, by path ("store.name" => "...")
 * @property list<int>|null $stock_location_ids
 * @property list<int>|null $payment_method_ids
 * @property list<int>|null $shipping_method_ids
 * @property int $position
 */
class Channel extends Model
{
    /**
     * Settings a channel may override, by path. Everything else is shared.
     */
    public const OVERRIDABLE = [
        'store.name',
        'store.email',
        'store.phone',
        'store.address',
        'appearance.theme',
        'tax.store_country',
        'seo.allow_indexing',
    ];

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'hostname', 'path', 'is_active', 'separate_accounts', 'default_locale', 'locales', 'currency', 'settings',
        'stock_location_ids', 'payment_method_ids', 'shipping_method_ids', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'separate_accounts' => 'boolean',
            'locales' => 'array',
            'settings' => 'array',
            'stock_location_ids' => 'array',
            'payment_method_ids' => 'array',
            'shipping_method_ids' => 'array',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Channel $channel): void {
            $channel->hostname = self::normalizeHost($channel->hostname);
            $channel->path = self::normalizePath($channel->path);

            // Chosen once: switching later would leave customers unable to sign in. The
            // default channel (the main store) always uses the shared accounts.
            if ($channel->exists && $channel->isDirty('separate_accounts')) {
                $channel->separate_accounts = (bool) $channel->getOriginal('separate_accounts');
            }

            if ($channel->is_default) {
                $channel->separate_accounts = false;
            }
        });

        // Its customers could sign in nowhere else.
        static::deleting(function (Channel $channel): void {
            if ($channel->separate_accounts && User::modelClass()::query()->where('account_scope', $channel->getKey())->exists()) {
                throw new LogicException(__('This channel has its own customer accounts. Deactivate it instead of deleting it.'));
            }
        });

        static::saved(fn () => app(Channels::class)->flush());
        static::deleted(fn () => app(Channels::class)->flush());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** The value of an overridden setting, or null when the channel uses the shop's. */
    public function setting(string $path): mixed
    {
        $value = data_get($this->settings ?? [], $path);

        return $value === '' ? null : $value;
    }

    /**
     * Whether a list (stock locations, payment or shipping methods) allows an id: an empty
     * list allows everything.
     *
     * @param  'stock_location_ids'|'payment_method_ids'|'shipping_method_ids'  $list
     */
    public function allows(string $list, int $id): bool
    {
        $ids = $this->{$list};

        return $ids === null || $ids === [] || in_array($id, array_map('intval', $ids), true);
    }

    /**
     * The channel's address, e.g. "https://wholesale.example.com" or "https://example.com/trade".
     */
    public function url(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($this->hostname !== null) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $port = parse_url($base, PHP_URL_PORT);
            $base = $scheme.'://'.$this->hostname.($port ? ':'.$port : '');
        }

        return $base.($this->path !== null ? '/'.$this->path : '');
    }

    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::query()->whereKeyNot($this->id)->where('is_default', true)->update(['is_default' => false]);
            $this->forceFill(['is_default' => true, 'is_active' => true])->save();
        });
    }

    private static function normalizeHost(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $host = explode('/', $host)[0];

        return $host === '' ? null : $host;
    }

    private static function normalizePath(?string $path): ?string
    {
        $path = trim(strtolower(trim((string) $path)), '/');

        return $path === '' ? null : $path;
    }
}
