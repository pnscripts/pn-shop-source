<?php

namespace PnShop\Catalog\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLevel;
use PnShop\Money\MoneyCast;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A sellable version of a product: its own SKU, price and stock.
 * Simple products have exactly one (default) variant.
 *
 * @property int $id
 * @property int $product_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property Money $price
 * @property Money|null $sale_price
 * @property int|null $weight grams
 * @property bool $track_inventory
 * @property bool $allow_backorder
 * @property int|null $low_stock_threshold units left at or below which the variant counts as low on stock (null: the default)
 * @property bool $is_default
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductVariant extends Model
{
    use LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['product_id', 'sku', 'barcode', 'price', 'sale_price', 'weight', 'track_inventory', 'allow_backorder', 'low_stock_threshold', 'is_default', 'is_active', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'sale_price' => MoneyCast::class,
            'weight' => 'integer',
            'track_inventory' => 'boolean',
            'allow_backorder' => 'boolean',
            'low_stock_threshold' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A product's first variant becomes its default variant.
        static::creating(function (ProductVariant $variant): void {
            if (! $variant->is_default && ! static::query()->where('product_id', $variant->product_id)->where('is_default', true)->exists()) {
                $variant->is_default = true;
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsToMany<OptionValue, $this>
     */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(OptionValue::class, 'option_value_product_variant')->with('option');
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * What the current customer pays for one unit at this quantity: the lowest of the price,
     * the sale price and the price lists of their group (see PriceResolver).
     */
    public function unitPrice(int $quantity = 1): Money
    {
        return app(PriceResolver::class)->quote($this, $quantity)->unit;
    }

    /**
     * Whether the current customer pays less than the regular price (shown struck through).
     */
    public function isOnSale(int $quantity = 1): bool
    {
        return app(PriceResolver::class)->quote($this, $quantity)->isReduced();
    }

    /**
     * Units that can be sold now, or null when stock is not tracked.
     */
    public function available(): ?int
    {
        return app(InventoryService::class)->available($this);
    }

    /**
     * "Size: M, Color: Red" (empty for a simple product's variant).
     */
    public function label(): string
    {
        return $this->optionValues
            ->sortBy(fn (OptionValue $value) => $value->option->position ?? 0)
            ->map(fn (OptionValue $value) => ($value->option->name ?? '').': '.$value->value)
            ->implode(', ');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('catalog')
            ->logOnly(['sku', 'price', 'sale_price', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
