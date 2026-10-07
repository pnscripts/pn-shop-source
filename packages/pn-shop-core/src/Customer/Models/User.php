<?php

namespace PnShop\Customer\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use PnShop\Customer\Factories\UserFactory;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A customer account (storefront sign-in). Staff accounts are PnShop\Acl\Models\AdminUser.
 *
 * Email verification is required only when Settings → Customers says so; otherwise every
 * account counts as verified and no verification email is sent.
 *
 * The shop's own App\Models\User extends this class (and is the model configured in
 * auth.providers.users), so merchants can add to it without changing the core.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property int|null $customer_group_id
 * @property Carbon|null $email_verified_at
 * @property int $account_scope 0 when shared by all channels, else the id of the channel with separate accounts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, LogsActivity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'account_scope' => 'integer',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->customer_group_id ??= CustomerGroup::query()->where('is_default', true)->value('id');
        });

        // A new password (changed, reset or set by staff) ends every API token, and a deleted
        // account takes its tokens along. Other browser sessions end through AuthenticateSession.
        static::updated(function (User $user): void {
            if ($user->wasChanged('password')) {
                $user->tokens()->delete();
            }
        });

        static::deleting(function (User $user): void {
            $user->tokens()->delete();
        });
    }

    public static function verificationRequired(): bool
    {
        return (bool) app(Settings::class)->get('customers.require_email_verification');
    }

    public function hasVerifiedEmail(): bool
    {
        return ! self::verificationRequired() || $this->email_verified_at !== null;
    }

    public function sendEmailVerificationNotification(): void
    {
        if (self::verificationRequired()) {
            parent::sendEmailVerificationNotification();
        }
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default_shipping')->latest();
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('customers')
            ->logOnly(['name', 'email', 'customer_group_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The customer model the shop uses (auth.providers.users, App\Models\User by default).
     * Core code creates and loads customers through it, so guards and policies see the
     * shop's class.
     *
     * @return class-string<User>
     */
    public static function modelClass(): string
    {
        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', self::class);

        return $model;
    }

    /** Always the "customer" morph alias, also for an instance of this base class. */
    public function getMorphClass(): string
    {
        return 'customer';
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
