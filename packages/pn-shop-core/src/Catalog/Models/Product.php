<?php

namespace PnShop\Catalog\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Factories\ProductFactory;
use PnShop\Catalog\ProductRelationType;
use PnShop\Catalog\ProductType;
use PnShop\Channel\Concerns\LimitedToChannels;
use PnShop\Foundation\Concerns\HasSlug;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockMovement;
use PnShop\Localization\Concerns\Translatable;
use PnShop\Localization\Contracts\TranslatableModel;
use PnShop\Media\Concerns\HasMedia;
use PnShop\Tax\Models\TaxClass;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A catalog item. What is sold is a ProductVariant: a simple product has one default
 * variant; a variable product has one variant per option combination.
 *
 * For convenience `price`, `sale_price`, `sku`, `barcode`, `weight` and `stock` read and write
 * the default variant (stock through the inventory ledger), so simple products can be
 * created with `Product::create(['title' => ..., 'price' => '12.50', 'stock' => 5])`.
 *
 * @property int $id
 * @property ProductType $type
 * @property bool $is_gift_card sold as a gift card: each unit issues a card of its price when paid
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property int $product_category_id
 * @property int|null $brand_id
 * @property bool $is_active
 * @property bool $is_featured shown first in the home page's featured products
 * @property string|null $image legacy external image URL
 * @property-read Money|null $price
 * @property-read Money|null $sale_price
 * @property-read string|null $sku
 * @property-read string|null $barcode
 * @property int|null $tax_class_id null uses the default tax class
 * @property-read int|null $stock units available across variants, null when not tracked
 */
class Product extends Model implements TranslatableModel
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasMedia, HasSlug, LimitedToChannels, LogsActivity, SoftDeletes, Translatable;

    /** Pivot table and key for LimitedToChannels. */
    public const CHANNEL_PIVOT = ['channel_product', 'product_id'];

    /** Attributes that belong to the default variant. */
    public const VARIANT_SHORTCUTS = ['price', 'sale_price', 'sku', 'barcode', 'weight'];

    /** @var list<string> */
    protected $fillable = [
        'type',
        'is_gift_card',
        'product_category_id',
        'brand_id',
        'tax_class_id',
        'title',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'is_active',
        'is_featured',
        'image',
        'price',
        'sale_price',
        'sku',
        'barcode',
        'weight',
        'stock',
    ];

    /** @var list<string> */
    protected array $translatable = ['title', 'slug', 'description', 'meta_title', 'meta_description'];

    /** @var array<string, mixed> values to write to the default variant on save */
    private array $pendingVariant = [];

    private ?int $pendingStock = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_gift_card' => 'boolean',
        ];
    }

    /**
     * Visible in the store (and the current channel) and has at least one active variant.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('variants', fn (Builder $variants) => $variants->where('is_active', true))->inChannel();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'product_category_id');
    }

    /**
     * Every category the product appears in, including the primary one.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product', 'product_id', 'product_category_id')
            ->withPivot('position');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderByDesc('is_default')->orderBy('position')->orderBy('id');
    }

    /**
     * The options (Size, Color, ...) a variable product's variants combine.
     *
     * @return BelongsToMany<Option, $this>
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(Option::class)->withPivot('position')->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function relatedProducts(): BelongsToMany
    {
        return $this->linkedProducts(ProductRelationType::Related);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function upsellProducts(): BelongsToMany
    {
        return $this->linkedProducts(ProductRelationType::Upsell);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function crossSellProducts(): BelongsToMany
    {
        return $this->linkedProducts(ProductRelationType::CrossSell);
    }

    /**
     * Products that list this one as a cross-sell (inverse of crossSellProducts).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function crossSellsOf(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'related_product_id', 'product_id')
            ->wherePivot('type', ProductRelationType::CrossSell->value);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    private function linkedProducts(ProductRelationType $type): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'product_id', 'related_product_id')
            ->withPivotValue('type', $type->value)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<ProductAttributeValue, $this>
     */
    public function selectedAttributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class)->withTimestamps();
    }

    /**
     * The stock ledger of all the product's variants, newest first.
     *
     * @return HasManyThrough<StockMovement, ProductVariant, $this>
     */
    public function stockMovements(): HasManyThrough
    {
        return $this->hasManyThrough(StockMovement::class, ProductVariant::class)->latest('stock_movements.id');
    }

    /**
     * The default variant (or the first one), from the loaded `variants` relation when available.
     */
    public function defaultVariant(): ?ProductVariant
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, ProductVariant> $variants */
        $variants = $this->exists ? $this->getRelationValue('variants') : collect();

        return $variants->firstWhere('is_default', true) ?? $variants->first();
    }

    /**
     * Active variants, the cheapest first.
     *
     * @return Collection<int, ProductVariant>
     */
    public function activeVariants(): Collection
    {
        return $this->getRelationValue('variants')->where('is_active', true)->values();
    }

    /**
     * The lowest unit price among active variants (what listings show as "from").
     */
    public function lowestPrice(): ?Money
    {
        return $this->activeVariants()
            ->map(fn (ProductVariant $variant) => $variant->unitPrice())
            ->sortBy(fn (Money $price) => $price->getMinorAmount()->toInt())
            ->first();
    }

    public function hasVaryingPrices(): bool
    {
        return $this->activeVariants()->map(fn (ProductVariant $variant) => (string) $variant->unitPrice()->getAmount())->unique()->count() > 1;
    }

    /**
     * @return Attribute<Money|null, mixed>
     */
    protected function price(): Attribute
    {
        return $this->variantShortcut('price');
    }

    /**
     * @return Attribute<Money|null, mixed>
     */
    protected function salePrice(): Attribute
    {
        return $this->variantShortcut('sale_price');
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function sku(): Attribute
    {
        return $this->variantShortcut('sku');
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function barcode(): Attribute
    {
        return $this->variantShortcut('barcode');
    }

    /**
     * @return Attribute<int|null, mixed>
     */
    protected function weight(): Attribute
    {
        return $this->variantShortcut('weight');
    }

    /**
     * @return BelongsTo<TaxClass, $this>
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    /**
     * @return Attribute<int|null, mixed>
     */
    protected function stock(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                $inventory = app(InventoryService::class);
                $available = $this->activeVariants()->map(fn (ProductVariant $variant) => $inventory->available($variant));

                return $available->contains(null) ? null : (int) $available->sum();
            },
            set: function (mixed $value): array {
                $this->pendingStock = $value === null || $value === '' ? null : (int) $value;

                return [];
            },
        );
    }

    /**
     * The product's specifications for its page: each attribute it has values for, in the
     * attributes' order, with all its values (a multi-select can have several).
     *
     * @return Collection<int, array{attribute: string, value: string}>
     */
    public function getProductAttributesWithValues(): Collection
    {
        $this->loadMissing('selectedAttributeValues.productAttribute');

        return $this->selectedAttributeValues
            ->filter(fn (ProductAttributeValue $value) => $value->productAttribute !== null)
            ->groupBy('product_attribute_id')
            ->sortBy(fn (Collection $values) => [$values->first()?->productAttribute?->position, $values->first()?->product_attribute_id])
            ->map(fn (Collection $values) => [
                'attribute' => (string) $values->first()?->productAttribute?->label,
                'value' => $values->sortBy(['position', 'id'])->pluck('value')->implode(', '),
            ])
            ->values();
    }

    protected static function booted(): void
    {
        static::saved(function (Product $product): void {
            // The primary category is always one of the product's categories.
            if ($product->wasRecentlyCreated || $product->wasChanged('product_category_id')) {
                $product->categories()->syncWithoutDetaching([$product->product_category_id]);
            }

            $product->writeVariantShortcuts();
        });
    }

    /**
     * No native return type on purpose: Eloquent treats every method declared to return
     * Attribute as an accessor and calls it without arguments.
     *
     * @return Attribute<mixed, mixed>
     */
    private function variantShortcut(string $key)
    {
        return Attribute::make(
            get: fn () => $this->defaultVariant()?->getAttribute($key),
            set: function (mixed $value) use ($key): array {
                $this->pendingVariant[$key] = $value;

                return [];
            },
        );
    }

    private function writeVariantShortcuts(): void
    {
        if ($this->pendingVariant === [] && $this->pendingStock === null) {
            return;
        }

        $variant = $this->defaultVariant() ?? new ProductVariant(['product_id' => $this->id, 'is_default' => true, 'price' => 0]);
        $variant->fill($this->pendingVariant)->save();
        $this->pendingVariant = [];

        if ($this->pendingStock !== null) {
            app(InventoryService::class)->setOnHand($variant, $this->pendingStock, self::actingStaff());
            $this->pendingStock = null;
        }

        $this->unsetRelation('variants');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('catalog')
            ->logOnly(['title', 'type', 'is_active', 'product_category_id', 'brand_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /** The staff member saving the product (panel or Admin API), for the stock history. */
    private static function actingStaff(): ?AdminUser
    {
        foreach (['admin', 'admin-api'] as $guard) {
            $user = auth($guard)->user();

            if ($user instanceof AdminUser) {
                return $user;
            }
        }

        return null;
    }
}
