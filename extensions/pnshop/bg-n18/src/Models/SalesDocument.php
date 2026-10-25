<?php

namespace PnShop\Plugins\BgN18\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use PnShop\Sales\Models\Order;

/**
 * A document given to the customer for a sale under Наредба Н-18. Never changed once
 * issued: it is written only by SalesDocuments.
 *
 * @property int $id
 * @property string $type sale|cod
 * @property int|null $number
 * @property int $order_id
 * @property Carbon $issued_at
 * @property int $payment_code
 * @property string|null $transaction_ref
 * @property int $total minor units
 * @property string $currency
 * @property array<string, mixed> $data
 * @property Carbon|null $emailed_at
 * @property-read Order $order
 */
class SalesDocument extends Model
{
    public const SALE = 'sale';

    public const COD = 'cod';

    protected $table = 'pnshop_n18_documents';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'emailed_at' => 'datetime', 'data' => 'array', 'number' => 'integer', 'total' => 'integer', 'payment_code' => 'integer'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The number as printed: 10 digits. */
    public function formattedNumber(): ?string
    {
        return $this->number === null ? null : str_pad((string) $this->number, 10, '0', STR_PAD_LEFT);
    }
}
