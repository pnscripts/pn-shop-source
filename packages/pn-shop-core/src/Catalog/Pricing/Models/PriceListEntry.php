<?php

namespace PnShop\Catalog\Pricing\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Money\MoneyCast;

/**
 * One price of a variant in a price list, from a quantity on (1: always).
 *
 * @property int $id
 * @property int $price_list_id
 * @property int $product_variant_id
 * @property int $min_quantity
 * @property Money $price
 */
class PriceListEntry extends Model
{
    /** @var list<string> */
    protected $fillable = ['price_list_id', 'product_variant_id', 'min_quantity', 'price'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => MoneyCast::class, 'min_quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
