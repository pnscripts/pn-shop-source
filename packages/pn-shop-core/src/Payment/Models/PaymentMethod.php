<?php

namespace PnShop\Payment\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PnShop\Localization\Concerns\Translatable;
use PnShop\Localization\Contracts\TranslatableModel;
use PnShop\Money\MoneyCast;
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\Factories\PaymentMethodFactory;
use PnShop\Payment\PaymentGatewayManager;
use PnShop\Sales\Models\Order;

/**
 * A payment option offered at checkout: a gateway with the merchant's settings and rules.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $gateway
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property int $position
 * @property Money|null $min_total
 * @property Money|null $max_total
 * @property list<string>|null $countries ISO codes; empty means everywhere
 * @property list<int>|null $customer_group_ids groups it is offered to; empty means every customer
 */
class PaymentMethod extends Model implements TranslatableModel
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, SoftDeletes, Translatable;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'gateway', 'settings', 'is_active', 'position', 'min_total', 'max_total', 'countries', 'customer_group_ids'];

    /** @var list<string> */
    protected array $translatable = ['name', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
            'min_total' => MoneyCast::class,
            'max_total' => MoneyCast::class,
            'countries' => 'array',
            'customer_group_ids' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function gatewayInstance(): ?PaymentGateway
    {
        $gateways = app(PaymentGatewayManager::class);

        return $gateways->has($this->gateway) ? $gateways->get($this->gateway) : null;
    }

    /**
     * A gateway setting, falling back to the gateway's default when it is not filled in.
     */
    public function setting(string $key): mixed
    {
        $value = $this->settings[$key] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        foreach ($this->gatewayInstance()?->settings() ?? [] as $definition) {
            if ($definition->key === $key) {
                return $definition->default;
            }
        }

        return null;
    }

    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }
}
