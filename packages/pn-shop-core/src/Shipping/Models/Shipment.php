<?php

namespace PnShop\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Sales\Models\Order;

/**
 * A parcel that left the warehouse with some of an order's lines.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $shipping_method_id
 * @property int|null $stock_location_id
 * @property string|null $carrier_name
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string|null $note
 * @property Carbon|null $shipped_at
 */
class Shipment extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_id', 'shipping_method_id', 'stock_location_id', 'carrier_name', 'tracking_number', 'tracking_url', 'note', 'shipped_at', 'actor_type', 'actor_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shipped_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The stock location it left from (null for shipments before 1.4).
     *
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /**
     * @return HasMany<ShipmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
