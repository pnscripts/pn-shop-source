<?php

namespace PnShop\Cart;

use Brick\Money\Money;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Money\Prices;

/**
 * A cart line built from the current variant row. Prices are never taken from the session.
 */
final readonly class CartItemDTO
{
    /**
     * @param  array{id: int|null, url: string, thumb: string, srcset: string, alt: string, width: int|null, height: int|null}|null  $image
     */
    public function __construct(
        public int $variant_id,
        public int $product_id,
        public string $title,
        public string $slug,
        public string $variant_label,
        public ?string $sku,
        public Money $price,
        public ?Money $sale_price,
        public ?array $image,
        public ?int $available,
        public int $quantity,
        public int $weight = 0,
        public ?int $taxClassId = null,
    ) {}

    /**
     * Expects the variant's product (with media), option values and stock levels to be loaded.
     */
    public static function fromVariant(ProductVariant $variant, int $quantity): self
    {
        $product = $variant->product;
        // What this customer pays at this quantity (group prices and quantity tiers included).
        $quote = app(PriceResolver::class)->quote($variant, $quantity);

        return new self(
            $variant->id,
            $product->id,
            $product->title,
            $product->slug,
            $variant->label(),
            $variant->sku,
            $quote->regular,
            $quote->isReduced() ? $quote->unit : null,
            ProductCardPresenter::mainImage($product),
            $variant->available(),
            $quantity,
            (int) $variant->weight,
            $product->tax_class_id,
        );
    }

    /**
     * The price of one unit: the reduced price (sale, group price, quantity tier) when there
     * is one, otherwise the regular price.
     */
    public function getUnitPrice(): Money
    {
        return Prices::effective($this->price, $this->sale_price);
    }

    public function getTotalPrice(): Money
    {
        return $this->getUnitPrice()->multipliedBy($this->quantity);
    }
}
