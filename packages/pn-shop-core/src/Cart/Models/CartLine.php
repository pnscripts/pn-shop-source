<?php

namespace PnShop\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PnShop\Catalog\Models\ProductVariant;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property list<array<string, string|null>>|null $gift_card_recipients gift cards: who receives each card
 */
class CartLine extends Model
{
    /** @var list<string> */
    protected $fillable = ['cart_id', 'product_variant_id', 'quantity', 'gift_card_recipients'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'gift_card_recipients' => 'array'];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
