<?php

namespace PnShop\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PnShop\Inventory\Models\StockLocation;

/**
 * Units of an order line held at one stock location, and how many of them shipped.
 * Changed only through StockAllocations.
 *
 * @property int $id
 * @property int $order_item_id
 * @property int|null $stock_location_id
 * @property int $quantity
 * @property int $quantity_shipped
 */
class OrderItemAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_item_id', 'stock_location_id', 'quantity', 'quantity_shipped'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'quantity_shipped' => 'integer'];
    }

    /** Units still waiting to ship from this location. */
    public function held(): int
    {
        return max(0, $this->quantity - $this->quantity_shipped);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }
}
