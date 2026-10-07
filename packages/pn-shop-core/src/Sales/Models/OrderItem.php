<?php

namespace PnShop\Sales\Models;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PnShop\Catalog\Models\Product;
use PnShop\Money\MoneyCast;

/**
 * @property string $currency ISO 4217 code, same as the order
 * @property int $id
 * @property int $quantity
 * @property int $quantity_fulfilled units already shipped
 * @property int $quantity_refunded units refunded
 * @property int $quantity_cancelled units refunded before they shipped; they never ship
 * @property Money $price
 * @property Money|null $sale_price
 * @property Money $tax_amount tax included in or added to the line total
 * @property Money $discount_amount promotion discount on the whole line
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string|null $product_title
 * @property string|null $product_sku
 * @property string|null $variant_label
 * @property list<array{email?: string|null, name?: string|null, message?: string|null}>|null $gift_card_recipients null unless a gift card line
 */
class OrderItem extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_title',
        'product_sku',
        'product_variant_id',
        'variant_label',
        'quantity',
        'quantity_fulfilled',
        'quantity_refunded',
        'quantity_cancelled',
        'currency',
        'price',
        'sale_price',
        'tax_amount',
        'discount_amount',
        'gift_card_recipients',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'quantity_fulfilled' => 'integer',
        'quantity_refunded' => 'integer',
        'quantity_cancelled' => 'integer',
        'price' => MoneyCast::class.':currency',
        'sale_price' => MoneyCast::class.':currency',
        'tax_amount' => MoneyCast::class.':currency',
        'discount_amount' => MoneyCast::class.':currency',
        'gift_card_recipients' => 'array',
    ];

    /** A gift card line: its cards are issued and emailed when the order is paid. */
    public function isGiftCard(): bool
    {
        return $this->gift_card_recipients !== null;
    }

    /** Units that still have to ship. */
    public function quantityToShip(): int
    {
        // Gift cards are emailed, never shipped.
        if ($this->isGiftCard()) {
            return 0;
        }

        return max(0, $this->quantity - $this->quantity_fulfilled - $this->quantity_cancelled);
    }

    /** Units that are part of the order (not cancelled by a refund). */
    public function quantityKept(): int
    {
        return max(0, $this->quantity - $this->quantity_cancelled);
    }

    /**
     * Where its units are held; see StockAllocations.
     *
     * @return HasMany<OrderItemAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(OrderItemAllocation::class)->orderBy('id');
    }

    /**
     * Get the order this item belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product for this item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unitPrice(): Money
    {
        // sale_price on an order line is what was actually charged, so it always wins when stored.
        return $this->sale_price !== null && $this->sale_price->isPositive() ? $this->sale_price : $this->price;
    }

    public function lineTotal(): Money
    {
        return $this->unitPrice()->multipliedBy($this->quantity);
    }

    /**
     * The share of the line's promotion discount that belongs to the given number of units.
     */
    public function discountFor(int $quantity): Money
    {
        if ($this->quantity <= 0 || $this->discount_amount->isZero()) {
            return Money::zero($this->discount_amount->getCurrency());
        }

        return $this->discount_amount->multipliedBy($quantity)->dividedBy($this->quantity, RoundingMode::HalfUp);
    }
}
