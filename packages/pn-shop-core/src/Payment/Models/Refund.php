<?php

namespace PnShop\Payment\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use PnShop\Money\MoneyCast;
use PnShop\Sales\Models\Order;

/**
 * Money returned to the customer for some lines (and/or an extra amount such as shipping).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $payment_id
 * @property string $currency
 * @property Money $amount
 * @property string $status completed|failed
 * @property bool $restock
 * @property string|null $reason
 * @property string|null $reference
 * @property string $destination original|store_credit|exchange
 * @property string|null $credit_reference the balance credited ("credit_account:3", "gift_card:12")
 * @property Carbon|null $created_at
 */
class Refund extends Model
{
    /** Saved before the gateway is asked, so a crash after the money moved still leaves a record. */
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** The money goes back to the payment it came from. */
    public const TO_ORIGINAL = 'original';

    /** The money becomes store credit (a gift card for guests). */
    public const TO_STORE_CREDIT = 'store_credit';

    /** The money pays for an exchange order (see PnShop\Credit\Exchanges). */
    public const TO_EXCHANGE = 'exchange';

    /** @var list<string> */
    protected $fillable = ['order_id', 'payment_id', 'currency', 'amount', 'status', 'restock', 'reason', 'reference', 'actor_type', 'actor_id', 'destination', 'credit_reference'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class.':currency', 'restock' => 'boolean'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return HasMany<RefundLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RefundLine::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
