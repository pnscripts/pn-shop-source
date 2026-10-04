<?php

namespace PnShop\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use PnShop\Channel\Channels;
use PnShop\Customer\Models\User;

/**
 * @property int $id
 * @property string $token
 * @property int|null $user_id
 * @property int|null $channel_id
 * @property string|null $coupon_code
 * @property list<int>|null $gift_card_ids gift cards entered (see PnShop\Credit\CartBalances)
 * @property bool $use_store_credit
 * @property Carbon $updated_at
 */
class Cart extends Model
{
    /** @var list<string> */
    protected $fillable = ['token', 'user_id', 'coupon_code', 'channel_id', 'gift_card_ids', 'use_store_credit'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['gift_card_ids' => 'array', 'use_store_credit' => 'boolean'];
    }

    protected static function booted(): void
    {
        // A cart belongs to the storefront it was started on.
        static::creating(function (Cart $cart): void {
            $cart->channel_id ??= app(Channels::class)->current()->id;
        });
    }

    /**
     * @return HasMany<CartLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
