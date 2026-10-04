<?php

namespace PnShop\Credit\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use PnShop\Credit\Contracts\BalanceAccount;
use PnShop\Customer\Models\User;

/**
 * A gift card: a code with a balance in one currency. Only a hash of the code is kept;
 * staff see the full code once, when the card is issued.
 *
 * @property int $id
 * @property string $code_hash
 * @property string $last4
 * @property string $currency
 * @property int $initial_amount minor units
 * @property int $balance minor units
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property string|null $recipient_email
 * @property int|null $user_id
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class GiftCard extends Model implements BalanceAccount
{
    /** @var list<string> */
    protected $fillable = ['expires_at', 'is_active', 'recipient_email', 'user_id', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'is_active' => 'boolean', 'initial_amount' => 'integer', 'balance' => 'integer'];
    }

    /** "ABCD-EFGH-JKLM-NPQR" from what a customer typed (case, spaces and dashes ignored). */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    public function balanceMoney(): Money
    {
        return Money::ofMinor($this->balance, $this->currency);
    }

    public function initialMoney(): Money
    {
        return Money::ofMinor($this->initial_amount, $this->currency);
    }

    public function isSpendable(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture()) && $this->balance > 0;
    }

    public function label(): string
    {
        return __('Gift card •••• :last4', ['last4' => $this->last4]);
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
