<?php

namespace PnShop\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kalnoy\Nestedset\NodeTrait;
use Kalnoy\Nestedset\QueryBuilder;
use PnShop\Catalog\Factories\CategoryFactory;
use PnShop\Channel\Concerns\LimitedToChannels;
use PnShop\Foundation\Concerns\HasSlug;
use PnShop\Localization\Concerns\Translatable;
use PnShop\Localization\Contracts\TranslatableModel;

/**
 * A node in the category tree (nested set: `_lft`, `_rgt`, `parent_id`).
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property int|null $parent_id
 * @property bool $is_active
 *
 * @method static int fixTree()
 */
class Category extends Model implements TranslatableModel
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasSlug, LimitedToChannels, NodeTrait, SoftDeletes, Translatable;

    /** Pivot table and key for LimitedToChannels. */
    public const CHANNEL_PIVOT = ['category_channel', 'category_id'];

    protected $table = 'product_categories';

    /** @var list<string> */
    protected $fillable = ['title', 'slug', 'description', 'meta_title', 'meta_description', 'parent_id', 'is_active'];

    /** @var list<string> */
    protected array $translatable = ['title', 'slug', 'description', 'meta_title', 'meta_description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return QueryBuilder<self>
     */
    public function newEloquentBuilder($query): QueryBuilder
    {
        /** @var QueryBuilder<self> $builder */
        $builder = new QueryBuilder($query);

        return $builder;
    }

    protected function translationForeignKey(): string
    {
        return 'product_category_id';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->inChannel();
    }

    /**
     * Products in this category (as primary or additional category).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product', 'product_category_id', 'product_id')
            ->withPivot('position');
    }

    /**
     * Spec attributes offered for products in this category.
     *
     * @return BelongsToMany<ProductAttribute, $this>
     */
    public function productAttributes(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttribute::class, 'product_attribute_product_category', 'product_category_id', 'product_attribute_id')
            ->withTimestamps();
    }

    /**
     * IDs of this category and all categories below it.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        return array_values(array_map('intval', static::query()
            ->withoutGlobalScope('translations')
            ->whereDescendantOrSelf($this)
            ->pluck('id')
            ->all()));
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
