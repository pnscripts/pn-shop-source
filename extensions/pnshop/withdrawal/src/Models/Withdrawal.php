<?php

namespace PnShop\Plugins\Withdrawal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PnShop\Sales\Models\Order;

/**
 * A customer's statement that they withdraw from a distance contract.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $order_id
 * @property int|null $channel_id
 * @property string $order_number
 * @property string $name
 * @property string $email
 * @property string|null $items
 * @property string|null $locale
 * @property Carbon $submitted_at
 * @property Carbon|null $handled_at
 * @property string|null $staff_note
 * @property-read Order|null $order
 */
class Withdrawal extends Model
{
    protected $table = 'pnshop_withdrawals';

    // Written only by WithdrawalStatements; staff change only handled_at and staff_note.
    protected $fillable = ['handled_at', 'staff_note'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'handled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function newReference(): string
    {
        do {
            $reference = 'W-'.Str::upper(Str::random(8));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
