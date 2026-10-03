<?php

namespace PnShop\Catalog\Presenters;

use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\PriceDisplay;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Media\MediaPresenter;

/**
 * The product shape used by listings (home, shop, related products).
 * Eager-load with ProductCardPresenter::RELATIONS to avoid N+1 queries.
 */
final class ProductCardPresenter
{
    /** @var list<string> */
    public const RELATIONS = ['category:id,title,slug', 'media', 'variants.stockLevels'];

    /**
     * @return array<string, mixed>
     */
    public static function present(Product $product): array
    {
        $variant = self::displayedVariant($product);
        $display = app(PriceDisplay::class);

        return [
            'id' => $product->id,
            'title' => $product->title,
            'slug' => $product->slug,
            // As this customer sees prices: null when hidden from guests, with or without tax.
            'price' => $display->present($variant?->price, $product->tax_class_id),
            'sale_price' => $variant?->isOnSale() ? $display->present($variant->unitPrice(), $product->tax_class_id) : null,
            'price_includes_tax' => $display->includesTax(),
            'price_from' => $product->hasVaryingPrices(),
            'image' => self::mainImage($product),
            'stock' => $product->stock,
            // Can still be ordered with no stock left (a variant sells on backorder).
            'backorder' => $product->activeVariants()->contains(fn (ProductVariant $variant) => $variant->track_inventory && $variant->allow_backorder),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'title' => $product->category->title,
                'slug' => $product->category->slug,
            ] : null,
        ];
    }

    /**
     * Cards for a listing; prices of all its variants are read at once.
     *
     * @param  iterable<Product>  $products
     * @return list<array<string, mixed>>
     */
    public static function presentMany(iterable $products): array
    {
        $products = collect($products);
        app(PriceResolver::class)->primeProducts($products);

        return array_values($products->map(fn (Product $product) => self::present($product))->all());
    }

    /**
     * The cheapest active variant: what a listing shows.
     */
    public static function displayedVariant(Product $product): ?ProductVariant
    {
        return $product->activeVariants()
            ->sortBy(fn (ProductVariant $variant) => $variant->unitPrice()->getMinorAmount()->toInt())
            ->first();
    }

    /**
     * The first gallery image, or the legacy external image URL.
     *
     * @return array{id: int|null, url: string, thumb: string, srcset: string, alt: string, width: int|null, height: int|null}|null
     */
    public static function mainImage(Product $product): ?array
    {
        $media = $product->firstMediaIn('gallery');

        if ($media !== null) {
            return MediaPresenter::present($media, $product->title);
        }

        return $product->image ? ['id' => null, 'url' => $product->image, 'thumb' => $product->image, 'srcset' => '', 'alt' => $product->title, 'width' => null, 'height' => null] : null;
    }
}
