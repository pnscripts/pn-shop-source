<?php

namespace PnShop\Credit\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use PnShop\Acl\Models\AdminUser;
use PnShop\Credit\BalanceReason;
use PnShop\Sales\Models\Order;

/**
 * One change to a gift card or store credit balance (append-only, like the payment ledger).
 *
 * @property int $id
 * @property string $account_type
 * @property int $account_id
 * @property string $currency
 * @property int $amount signed minor units
 * @property int $balance_after minor units
 * @property BalanceReason $reason
 * @property int|null $order_id
 * @property int|null $payment_id
 * @property int|null $admin_user_id
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class BalanceTransaction extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reason' => BalanceReason::class, 'amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function amountMoney(): Money
    {
        return Money::ofMinor($this->amount, $this->currency);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function account(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
