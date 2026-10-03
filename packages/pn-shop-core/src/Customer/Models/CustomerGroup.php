<?php

namespace PnShop\Customer\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PnShop\Money\MoneyCast;

/**
 * Segments customers (e.g. Retail, Wholesale) for group prices and tax rules.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_default
 * @property Money|null $min_order_total orders below this (goods, before shipping) are refused
 * @property bool|null $prices_include_tax how its customers see catalog prices (null: as entered)
 */
class CustomerGroup extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'min_order_total', 'prices_include_tax'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'min_order_total' => MoneyCast::class, 'prices_include_tax' => 'boolean'];
    }

    public static function default(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The group's minimum order when these products (before shipping) fall short of it.
     */
    public function minimumOrderShortfall(Money $subtotal): ?Money
    {
        $minimum = $this->min_order_total;

        return $minimum !== null && $minimum->isPositive() && $subtotal->isLessThan($minimum) ? $minimum : null;
    }
}
