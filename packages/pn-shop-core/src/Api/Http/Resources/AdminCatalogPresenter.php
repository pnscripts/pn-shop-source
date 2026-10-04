<?php

namespace PnShop\Api\Http\Resources;

use PnShop\Catalog\Models\Brand;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLevel;
use PnShop\Media\MediaPresenter;
use PnShop\Media\Models\Media;
use PnShop\Money\MoneyPresenter;

/**
 * Catalog records as the Admin API returns them: source-language fields plus every other
 * language under "translations", inactive records included.
 */
final class AdminCatalogPresenter
{
    /** @var list<string> */
    public const PRODUCT_RELATIONS = ['variants.optionValues', 'variants.stockLevels.location', 'categories:id', 'channels:id', 'options:id', 'media', 'allTranslations'];

    /**
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'type' => $product->type->value,
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            'title' => $product->getAttributes()['title'] ?? null,
            'slug' => $product->getAttributes()['slug'] ?? null,
            'description' => $product->getAttributes()['description'] ?? null,
            'meta_title' => $product->getAttributes()['meta_title'] ?? null,
            'meta_description' => $product->getAttributes()['meta_description'] ?? null,
            'translations' => (object) $product->translationsInput(),
            'category_id' => $product->product_category_id,
            'category_ids' => $product->categories->modelKeys(),
            'channel_ids' => $product->channels->modelKeys(),
            'brand_id' => $product->brand_id,
            'tax_class_id' => $product->tax_class_id,
            'option_ids' => $product->options->modelKeys(),
            'gallery' => $product->mediaIn('gallery')->map(fn (Media $media) => MediaPresenter::present($media))->values()->all(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => self::variant($variant))->values()->all(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variant(ProductVariant $variant): array
    {
        $inventory = app(InventoryService::class);

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => MoneyPresenter::present($variant->price),
            'sale_price' => MoneyPresenter::present($variant->sale_price),
            'weight' => $variant->weight,
            'track_inventory' => $variant->track_inventory,
            'allow_backorder' => $variant->allow_backorder,
            'is_default' => $variant->is_default,
            'is_active' => $variant->is_active,
            'low_stock_threshold' => $variant->low_stock_threshold,
            'position' => $variant->position,
            'option_value_ids' => $variant->optionValues->modelKeys(),
            'on_hand' => (int) $variant->stockLevels->sum('on_hand'),
            'reserved' => (int) $variant->stockLevels->sum('reserved'),
            'available' => $inventory->available($variant),
            // Per location: what is on the shelf, reserved for orders, and available.
            'locations' => array_values($variant->stockLevels
                ->sortBy('stock_location_id')
                ->map(fn (StockLevel $level) => [
                    'location' => $level->location?->code,
                    'on_hand' => $level->on_hand,
                    'reserved' => $level->reserved,
                    'available' => max(0, $level->available()),
                ])->all()),
            'updated_at' => $variant->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'is_active' => $category->is_active,
            'title' => $category->getAttributes()['title'] ?? null,
            'slug' => $category->getAttributes()['slug'] ?? null,
            'description' => $category->getAttributes()['description'] ?? null,
            'meta_title' => $category->getAttributes()['meta_title'] ?? null,
            'meta_description' => $category->getAttributes()['meta_description'] ?? null,
            'translations' => (object) $category->translationsInput(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'is_active' => $brand->is_active,
            'name' => $brand->getAttributes()['name'] ?? null,
            'slug' => $brand->getAttributes()['slug'] ?? null,
            'description' => $brand->getAttributes()['description'] ?? null,
            'translations' => (object) $brand->translationsInput(),
            'updated_at' => $brand->updated_at?->toIso8601String(),
        ];
    }
}
