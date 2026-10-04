<?php

namespace PnShop\Credit\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use PnShop\Credit\Contracts\BalanceAccount;
use PnShop\Customer\Models\User;

/**
 * A customer's store credit in one currency.
 *
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property int $balance minor units
 */
class CreditAccount extends Model implements BalanceAccount
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'currency'];

    /** @var array<string, mixed> */
    protected $attributes = ['balance' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    public function balanceMoney(): Money
    {
        return Money::ofMinor($this->balance, $this->currency);
    }

    public function isSpendable(): bool
    {
        return $this->balance > 0;
    }

    public function label(): string
    {
        return __('Store credit');
    }

    public function balanceColumn(): string
    {
        return 'balance';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::modelClass());
    }

    /**
     * @return MorphMany<BalanceTransaction, $this>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(BalanceTransaction::class, 'account');
    }
}
