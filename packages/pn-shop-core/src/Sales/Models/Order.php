<?php

namespace PnShop\Sales\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use PnShop\Channel\Models\Channel;
use PnShop\Customer\Models\User;
use PnShop\Inventory\OrderStockStatus;
use PnShop\Money\MoneyCast;
use PnShop\Money\MoneyPresenter;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\Models\Refund;
use PnShop\Sales\Factories\OrderFactory;
use PnShop\Sales\OrderNumber;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\Models\ShippingMethod;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string|null $number human order number, e.g. ORD-000042
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property FulfillmentStatus $fulfillment_status
 * @property string|null $locale language the customer used
 * @property int|null $shipping_method_id
 * @property string|null $shipping_method_name delivery method name when the order was placed
 * @property string $currency ISO 4217 code the order was placed in
 * @property OrderStockStatus $stock_status
 * @property string|null $address free-text address of orders placed before structured addresses
 * @property Money|null $subtotal sum of the lines when the order was placed
 * @property Money|null $total what the customer pays
 * @property list<array{code: string, label: string, amount: int, included: bool}>|null $totals breakdown between subtotal and total, amounts in minor units
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'channel_id',
        'name',
        'address',
        'phone',
        'email',
        'number',
        'status',
        'payment_status',
        'fulfillment_status',
        'locale',
        'payment_method_id',
        'shipping_method_id',
        'shipping_method_name',
        'currency',
        'stock_status',
        'subtotal',
        'total',
        'totals',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'subtotal' => MoneyCast::class.':currency',
        'total' => MoneyCast::class.':currency',
        'totals' => 'array',
        'stock_status' => OrderStockStatus::class,
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'fulfillment_status' => FulfillmentStatus::class,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'fulfillment_status' => 'unfulfilled',
    ];

    protected static function booted(): void
    {
        // The number needs the id, so it is assigned right after the insert.
        static::created(function (Order $order): void {
            if ($order->number === null) {
                $order->forceFill(['number' => OrderNumber::for($order)])->saveQuietly();
            }
        });
    }

    /**
     * Get the items for this order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /**
     * The storefront it was placed on.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return HasOne<OrderAddress, $this>
     */
    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::SHIPPING);
    }

    /**
     * @return HasOne<OrderAddress, $this>
     */
    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::BILLING);
    }

    /**
     * Display lines of the shipping address, or of the free-text address of older orders.
     *
     * @return list<string>
     */
    public function shippingLines(): array
    {
        return $this->shippingAddress?->toPostalAddress()->lines() ?? array_values(array_filter([$this->name, $this->address]));
    }

    /**
     * What the customer pays; older orders without stored totals fall back to their lines.
     */
    public function grandTotal(): Money
    {
        return $this->total ?? $this->itemsTotal();
    }

    /**
     * Subtotal, breakdown lines and total, ready for display.
     *
     * @return array{subtotal: array<string, mixed>|null, lines: list<array<string, mixed>>, total: array<string, mixed>|null}
     */
    public function presentTotals(): array
    {
        $subtotal = $this->subtotal ?? $this->itemsTotal();

        return [
            'subtotal' => MoneyPresenter::present($subtotal),
            'lines' => array_map(fn (array $line) => [
                'code' => $line['code'],
                'label' => $line['label'],
                'amount' => MoneyPresenter::present(Money::ofMinor($line['amount'], $this->currency)),
                'included' => $line['included'],
            ], $this->totals ?? []),
            'total' => MoneyPresenter::present($this->grandTotal()),
        ];
    }

    /**
     * Get the user that owns the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * The order's timeline, oldest first.
     *
     * @return HasMany<OrderHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderHistory::class)->orderBy('id');
    }

    /**
     * Status, payment and fulfillment labels in the current language, for the storefront.
     *
     * @return array{status: string, payment_status: string, fulfillment_status: string}
     */
    public function presentStates(): array
    {
        return [
            'status' => __($this->status->label()),
            'payment_status' => __($this->payment_status->label()),
            'fulfillment_status' => __($this->fulfillment_status->label()),
        ];
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class)->withTrashed();
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    /**
     * Get the payment method associated with the order.
     *
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales')
            ->logOnly(['status', 'payment_status', 'fulfillment_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Sum of the order lines in the order currency.
     */
    public function itemsTotal(): Money
    {
        return $this->items->reduce(
            fn (Money $total, OrderItem $item) => $total->plus($item->lineTotal()),
            Money::zero($this->currency),
        );
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
