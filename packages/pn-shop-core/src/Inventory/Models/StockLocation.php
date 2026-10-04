<?php

namespace PnShop\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use PnShop\Inventory\InventoryService;

/**
 * A place stock is kept: a warehouse or a shop. One default location is created at install.
 *
 * Only active locations that sell online count towards what the storefront can sell.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $sells_online
 * @property int $position
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $country_code
 */
class StockLocation extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active', 'sells_online', 'position', 'address', 'city', 'postcode', 'country_code'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'sells_online' => 'boolean', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        // Which locations sell online is kept for the request; any change refreshes it.
        static::saved(fn () => app(InventoryService::class)->forgetLocations());
        static::deleted(fn () => app(InventoryService::class)->forgetLocations());
    }

    public static function default(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_default')->orderBy('position')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSellingOnline(Builder $query): void
    {
        $query->where('is_active', true)->where('sells_online', true);
    }

    /**
     * Make this the default location: stock changes without a location go here.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::query()->whereKeyNot($this->id)->where('is_default', true)->update(['is_default' => false]);
            $this->forceFill(['is_default' => true, 'is_active' => true])->save();
        });
    }

    /** One line for pickup and the admin, e.g. "1 Main St, Sofia 1000". */
    public function addressLine(): string
    {
        return trim(implode(', ', array_filter([$this->address, trim($this->city.' '.$this->postcode)])), ', ');
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
