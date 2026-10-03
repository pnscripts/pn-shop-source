<?php

namespace PnShop\Catalog\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use PnShop\Customer\Models\CustomerGroup;

/**
 * Prices for one customer group (or for every customer when the group is empty), in one
 * currency, optionally only between two dates. Entries can start at a quantity (tiers).
 *
 * @property int $id
 * @property string $name
 * @property int|null $customer_group_id
 * @property string $currency
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 */
class PriceList extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'customer_group_id', 'currency', 'starts_at', 'ends_at', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /**
     * Lists that apply now to customers of the group (lists for everyone included).
     *
     * @param  Builder<self>  $query
     */
    public function scopeApplicable(Builder $query, ?int $customerGroupId, string $currency): void
    {
        $now = now();

        $query->where('is_active', true)
            ->where('currency', $currency)
            ->where(fn (Builder $query) => $query->whereNull('customer_group_id')->when($customerGroupId !== null, fn (Builder $query) => $query->orWhere('customer_group_id', $customerGroupId)))
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /**
     * @return HasMany<PriceListEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(PriceListEntry::class);
    }
}
