<?php

namespace PnShop\Sales\Checkout;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PnShop\Cart\CartItemDTO;
use PnShop\Cart\ShoppingCartService;
use PnShop\Cart\Totals\CartCalculator;
use PnShop\Cart\Totals\ThresholdSubtotal;
use PnShop\Cart\Totals\TotalLine;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\PriceDisplay;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Channel\Channels;
use PnShop\Credit\CartBalances;
use PnShop\Customer\Models\CustomerAddress;
use PnShop\Customer\Models\User;
use PnShop\Customer\PostalAddress;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\OrderStockStatus;
use PnShop\Localization\Localization;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentService;
use PnShop\Sales\Events\OrderPlaced;
use PnShop\Sales\Events\OrderPlacing;
use PnShop\Sales\Exceptions\CheckoutException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderAddress;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Sales\StockAllocations;
use PnShop\Shipping\Carriers\StorePickup;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingRequest;
use PnShop\Shipping\ShippingService;
use PnShop\Tax\TaxResult;

class CheckoutService
{
    public function __construct(
        private ShoppingCartService $cart,
        private StockAllocations $allocations,
        private InventoryService $inventory,
        private CartCalculator $calculator,
        private OrderWorkflow $workflow,
        private PaymentService $payments,
        private ShippingService $shipping,
    ) {}

    /**
     * Place an order from the current cart.
     *
     * Prices come from the variant rows, and stock is reserved with a conditional update,
     * so it cannot be oversold even under concurrent checkouts. It leaves the shelf when
     * the order ships (OrderWorkflow).
     *
     * The order keeps copies of the shipping and billing addresses and its totals from
     * the cart.totals pipeline, computed from the locked variant rows.
     *
     * @param  array<string, mixed>  $data  input validated with CheckoutRules: email, shipping (address),
     *                                      billing + billing_same_as_shipping, save_address, shipping_method_id,
     *                                      payment_method_id
     *
     * @throws CheckoutException when the cart is empty or a product is unavailable.
     */
    public function place(array $data, ?User $user = null): Order
    {
        $lines = $this->cart->getLines();

        if ($lines === []) {
            throw new CheckoutException(__('Your cart is empty.'));
        }

        $shipping = PostalAddress::fromArray($data['shipping']);
        $billing = ($data['billing_same_as_shipping'] ?? true) || empty($data['billing']) ? $shipping : PostalAddress::fromArray($data['billing']);

        // Prices are those of the customer placing the order (their group), or a guest's.
        app(PriceResolver::class)->forCustomer($user);

        if (! app(PriceDisplay::class)->visible()) {
            throw new CheckoutException(__('Please sign in to see prices and order.'));
        }

        $order = DB::transaction(function () use ($data, $user, $shipping, $billing) {
            // The cart is locked and emptied in this transaction: a double submit cannot place
            // the same cart twice.
            $lines = $this->cart->lockedLines();

            if ($lines === []) {
                throw new CheckoutException(__('Your cart is empty.'));
            }

            ksort($lines);

            $variants = ProductVariant::query()
                ->whereKey(array_keys($lines))
                ->with(['product.media', 'optionValues', 'stockLevels'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // The channel's currency: the cart was priced in it.
            $currency = app(Localization::class)->currency()->code;

            $order = Order::create([
                'user_id' => $user?->id,
                'channel_id' => app(Channels::class)->current()->id,
                'name' => $shipping->fullName(),
                'email' => $data['email'],
                'phone' => (string) $shipping->phone,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'fulfillment_status' => FulfillmentStatus::Unfulfilled,
                'currency' => $currency,
                'locale' => app()->getLocale(),
                'stock_status' => OrderStockStatus::Reserved,
            ]);

            $order->addresses()->createMany([
                ['type' => OrderAddress::SHIPPING, ...$shipping->toArray()],
                ['type' => OrderAddress::BILLING, ...$billing->toArray()],
            ]);

            $items = collect();
            $orderItems = [];
            $recipients = $this->cart->giftCardRecipients();

            foreach ($lines as $variantId => $quantity) {
                $variant = $variants->get($variantId);

                if (! $variant || ! $variant->is_active || ! $variant->product->is_active || ! $variant->product->isVisibleInChannel()) {
                    throw new CheckoutException(__('A product in your cart is no longer available. Please review your cart.'));
                }

                $orderItems[] = [OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_title' => $variant->product->title,
                    'product_sku' => $variant->sku,
                    'variant_label' => $variant->label() ?: null,
                    'quantity' => $quantity,
                    'currency' => $currency,
                    // What this customer pays at this quantity; sale_price holds it when it is below the price.
                    'price' => $variant->regularPrice(),
                    'sale_price' => $variant->isOnSale($quantity) ? $variant->unitPrice($quantity) : null,
                    // Gift cards: who receives each card (missing entries: the buyer).
                    'gift_card_recipients' => $variant->product->is_gift_card ? array_slice($recipients[$variant->id] ?? [], 0, $quantity) : null,
                ]), $variant, $quantity];

                $items->push(CartItemDTO::fromVariant($variant, $quantity));
            }

            $shippingMethod = $this->shippingMethod($data, $items, $currency, $shipping, $user);

            // Reserve the units at the locations that will ship them (StockAllocations); for
            // pickup at a stock location, there.
            $pickup = StorePickup::stockLocation($shippingMethod);

            foreach ($orderItems as [$orderItem, $variant, $quantity]) {
                try {
                    $this->allocations->reserve($orderItem, $variant, $quantity, $shipping->country_code, $pickup);
                } catch (InsufficientStock) {
                    throw new CheckoutException($pickup !== null
                        ? __('Only :stock of :product are available for pickup at :location.', ['product' => $variant->product->title, 'stock' => (int) $this->inventory->availableAt($variant, $pickup), 'location' => $pickup->name])
                        : __('Not enough stock for :product. Available: :stock.', ['product' => $variant->product->title, 'stock' => (int) $variant->available()]));
                }
            }

            $totals = $this->calculator->calculate($items, $currency, $this->cart->context([
                'shipping_address' => $shipping,
                'billing_address' => $billing,
                'shipping_method' => $shippingMethod,
                'user' => $user,
                'email' => $data['email'],
            ]));

            $minimum = app(PriceResolver::class)->customerGroup()?->minimumOrderShortfall(app(ThresholdSubtotal::class)->of($totals));

            if ($minimum !== null) {
                throw new CheckoutException(__('The minimum order is :amount. Please add more products.', ['amount' => $minimum->formatToLocale(app()->getLocale())]));
            }

            // Gift cards and store credit pay first; the payment method pays the rest.
            $balances = app(CartBalances::class);
            $due = $totals->total()->minus($balances->covered($totals->total(), $user));
            $method = isset($data['payment_method_id']) ? PaymentMethod::query()->find((int) $data['payment_method_id']) : null;

            if ($due->isPositive()) {
                if ($method === null) {
                    throw new CheckoutException(__('Please choose a payment method.'));
                }

                if (! $this->payments->accepts($method, new PaymentContext($due, $shipping->country_code, $user))) {
                    throw new CheckoutException(__('This payment method is not available for your order. Please choose another one.'));
                }
            } else {
                $order->forceFill(['payment_method_id' => $balances->paymentMethod()->id]);
            }

            $tax = $totals->meta['tax'] ?? null;

            foreach ($order->items()->get() as $orderItem) {
                $orderItem->update([
                    'discount_amount' => $totals->discountOn('item:'.$orderItem->product_variant_id),
                    ...($tax instanceof TaxResult ? ['tax_amount' => $tax->forLine('item:'.$orderItem->product_variant_id)] : []),
                ]);
            }

            $order->update([
                'shipping_method_id' => $shippingMethod?->id,
                'shipping_method_name' => $shippingMethod?->name,
                'subtotal' => $totals->subtotal,
                'total' => $totals->total(),
                'totals' => array_map(fn (TotalLine $line) => [
                    'code' => $line->code,
                    'label' => $line->label,
                    'amount' => $line->amount->getMinorAmount()->toInt(),
                    'included' => $line->included,
                ], $totals->lines()),
            ]);

            OrderPlacing::dispatch($order, $totals, $user);

            $balances->spend($order, $user);

            $this->workflow->recordPlaced($order, $user);

            $this->cart->clearCart();

            return $order;
        }, attempts: 3);

        OrderPlaced::dispatch($order);

        if ($user !== null && ($data['save_address'] ?? false)) {
            $this->saveToAddressBook($user, $shipping);
        }

        return $order->load(['items', 'paymentMethod', 'addresses']);
    }

    /**
     * The chosen shipping method, checked against the address and the cart; null when the
     * store has no shipping methods.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, CartItemDTO>  $items
     *
     * @throws CheckoutException
     */
    private function shippingMethod(array $data, Collection $items, string $currency, PostalAddress $address, ?User $user): ?ShippingMethod
    {
        if (! $this->shipping->isRequired($items)) {
            return null;
        }

        $method = ShippingMethod::query()->find((int) ($data['shipping_method_id'] ?? 0));
        $subtotal = app(ThresholdSubtotal::class)->of($this->calculator->calculate($items, $currency, $this->cart->context(['user' => $user])));

        if ($method === null || $this->shipping->quote($method, new ShippingRequest($items, $subtotal, $address->country_code, $address->postcode, $user)) === null) {
            throw new CheckoutException(__('Please choose a delivery option for this address.'));
        }

        return $method;
    }

    /**
     * Keep the shipping address for next time, unless the customer already has it.
     */
    private function saveToAddressBook(User $user, PostalAddress $address): void
    {
        $saved = $user->addresses()->get();

        if ($saved->contains(fn (CustomerAddress $existing) => $existing->toPostalAddress()->toArray() === $address->toArray())) {
            return;
        }

        $first = $saved->isEmpty();

        $user->addresses()->create([
            ...$address->toArray(),
            'is_default_shipping' => $first,
            'is_default_billing' => $first,
        ]);
    }
}
