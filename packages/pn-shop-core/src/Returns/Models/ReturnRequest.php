<?php

namespace PnShop\Returns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use PnShop\Customer\Models\User;
use PnShop\Payment\Models\Refund;
use PnShop\Returns\ReturnReason;
use PnShop\Returns\ReturnStatus;
use PnShop\Sales\Models\Order;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A customer's request to send items of an order back (RMA).
 *
 * @property int $id
 * @property string $number e.g. RMA-000012
 * @property int $order_id
 * @property int|null $user_id
 * @property ReturnStatus $status
 * @property ReturnReason $reason
 * @property string|null $customer_note
 * @property string|null $staff_note shown to the customer with approvals and rejections
 * @property bool $restocked
 * @property int|null $refund_id
 * @property int|null $exchange_order_id
 * @property string|null $return_label_url
 * @property string|null $return_tracking_number
 * @property string|null $return_carrier
 * @property Carbon|null $approved_at
 * @property Carbon|null $received_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReturnRequest extends Model
{
    use LogsActivity;

    /** @var list<string> */
    protected $fillable = ['number', 'order_id', 'user_id', 'status', 'reason', 'customer_note', 'staff_note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'reason' => ReturnReason::class,
            'restocked' => 'boolean',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ReturnRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReturnRequestLine::class)->orderBy('id');
    }

    /**
     * The order the returned items were exchanged for.
     *
     * @return BelongsTo<Order, $this>
     */
    public function exchangeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'exchange_order_id');
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('sales')->logOnly(['status', 'staff_note'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
