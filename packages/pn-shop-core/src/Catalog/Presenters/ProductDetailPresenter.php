<?php

namespace PnShop\Catalog\Presenters;

use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Option;
use PnShop\Catalog\Models\OptionValue;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\PriceDisplay;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Media\MediaPresenter;
use PnShop\Media\Models\Media;

/**
 * The product page shape (storefront product page and Store API): gallery, options,
 * purchasable variants with prices and stock, breadcrumbs and attributes.
 */
final class ProductDetailPresenter
{
    /**
     * Load what present() needs; only active variants are kept.
     */
    public static function load(Product $product): Product
    {
        $product->load([
            'category:id,title,slug,parent_id,_lft,_rgt',
            'brand:id,name,slug',
            'selectedAttributeValues.productAttribute',
            'media',
            'options.values',
            'variants' => fn ($variants) => $variants->where('is_active', true)->with(['optionValues', 'stockLevels']),
        ]);

        app(PriceResolver::class)->primeProducts([$product]);

        return $product;
    }

    /**
     * The category and its ancestors, root first.
     *
     * @return list<Category>
     */
    public static function trail(Product $product): array
    {
        return $product->category
            ? array_values(Category::query()->whereAncestorOf($product->category, andSelf: true)->defaultOrder()->get()->all())
            : [];
    }

    /**
     * @param  list<Category>  $trail  from trail()
     * @return array<string, mixed>
     */
    public static function present(Product $product, array $trail): array
    {
        $display = app(PriceDisplay::class);
        $usedValueIds = $product->variants->flatMap(fn (ProductVariant $variant) => $variant->optionValues->modelKeys())->unique();

        return [
            'id' => $product->id,
            'type' => $product->type->value,
            'title' => $product->title,
            'slug' => $product->slug,
            'description' => $product->description,
            'image' => ProductCardPresenter::mainImage($product),
            'gallery' => $product->mediaIn('gallery')->map(fn (Media $media) => MediaPresenter::present($media, $product->title))->values()->all(),
            'brand' => $product->brand ? ['name' => $product->brand->name, 'slug' => $product->brand->slug] : null,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'title' => $product->category->title,
                'slug' => $product->category->slug,
            ] : null,
            'breadcrumbs' => array_map(fn (Category $category) => ['title' => $category->title, 'slug' => $category->slug], $trail),
            'attributes' => $product->getProductAttributesWithValues(),
            'options' => $product->options->map(fn (Option $option) => [
                'id' => $option->id,
                'name' => $option->name,
                'values' => $option->values
                    ->filter(fn (OptionValue $value) => $usedValueIds->contains($value->id))
                    ->map(fn (OptionValue $value) => ['id' => $value->id, 'value' => $value->value])
                    ->values(),
            ])->values(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'option_value_ids' => $variant->optionValues->modelKeys(),
                // As this customer sees prices: null when hidden from guests, with or without tax.
                'price' => $display->present($variant->regularPrice(), $product->tax_class_id),
                // What this customer pays for one (sale price, or their group's price).
                'sale_price' => $variant->isOnSale() ? $display->present($variant->unitPrice(), $product->tax_class_id) : null,
                'tiers' => self::tiers($variant),
                'stock' => $variant->available(),
                'can_backorder' => $variant->allow_backorder,
            ])->values(),
            'default_variant_id' => $product->defaultVariant()?->id,
            'price_includes_tax' => $display->includesTax(),
            'prices_visible' => $display->visible(),
        ];
    }

    /**
     * Lower prices from a quantity on, for this customer: [{min_quantity, price}], cheapest last.
     *
     * @return list<array{min_quantity: int, price: array<string, mixed>|null}>
     */
    public static function tiers(ProductVariant $variant): array
    {
        $display = app(PriceDisplay::class);

        if (! $display->visible()) {
            return [];
        }

        $tiers = [];
        $previous = $variant->unitPrice();
        $quantities = collect(app(PriceResolver::class)->entriesFor($variant->id))->pluck('min_quantity')->filter(fn (int $quantity) => $quantity > 1)->unique()->sort()->values();

        foreach ($quantities as $quantity) {
            $price = $variant->unitPrice($quantity);

            if ($price->isLessThan($previous)) {
                $tiers[] = ['min_quantity' => $quantity, 'price' => $display->present($price, $variant->product?->tax_class_id)];
                $previous = $price;
            }
        }

        return $tiers;
    }

    /**
     * Upsells first, then related products; only purchasable ones.
     *
     * @return list<array<string, mixed>>
     */
    public static function related(Product $product, int $limit = 8): array
    {
        $load = fn ($query) => $query->active()->with(ProductCardPresenter::RELATIONS);

        $product->load(['upsellProducts' => $load, 'relatedProducts' => $load]);

        return ProductCardPresenter::presentMany($product->upsellProducts
            ->concat($product->relatedProducts)
            ->unique('id')
            ->take($limit));
    }
}
