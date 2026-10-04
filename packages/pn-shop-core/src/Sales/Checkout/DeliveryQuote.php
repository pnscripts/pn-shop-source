<?php

namespace PnShop\Sales\Checkout;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PnShop\Cart\CartItemDTO;
use PnShop\Cart\ShoppingCartService;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Customer\Models\User;
use PnShop\Customer\PostalAddress;
use PnShop\Inventory\InventoryService;
use PnShop\Shipping\Carriers\StorePickup;
use PnShop\Shipping\ShippingQuote;
use PnShop\Shipping\ShippingRequest;
use PnShop\Shipping\ShippingService;

/**
 * The delivery options and totals for the address a customer is entering at checkout,
 * shared by the storefront and the Store API.
 */
class DeliveryQuote
{
    /** @var array<string, list<string>> */
    public const RULES = [
        'country_code' => ['required', 'string', 'size:2'],
        'postcode' => ['nullable', 'string', 'max:32'],
        'shipping_method_id' => ['nullable', 'integer'],
    ];

    public function __construct(private ShoppingCartService $cart, private ShippingService $shipping, private InventoryService $inventory) {}

    /**
     * @param  array<string, mixed>  $data  validated with RULES
     *                                      Pickup options at a stock location also say whether everything is in stock there.
     * @return array{options: list<array<string, mixed>>, selected: int|null, totals: array<string, mixed>}
     */
    public function for(array $data, ?User $customer): array
    {
        $address = PostalAddress::fromArray($data);
        $quotes = $this->shipping->quotes(new ShippingRequest($this->cart->getCartItems(), $this->cart->getTotalPrice(), $address->country_code, $address->postcode, $customer));

        $selected = $quotes->first(fn (ShippingQuote $quote) => $quote->method->id === (int) ($data['shipping_method_id'] ?? 0)) ?? $quotes->first();

        $items = $this->cart->getCartItems();
        $variants = ProductVariant::query()->whereKey($items->pluck('variant_id')->all())->with('stockLevels')->get()->keyBy('id');

        return [
            'options' => array_values($quotes->map(fn (ShippingQuote $quote) => [
                ...$quote->toArray(),
                'pickup' => $this->pickup($quote, $items, $variants),
            ])->all()),
            'selected' => $selected?->method->id,
            'totals' => $this->cart->totals([
                'shipping_address' => $address,
                'shipping_method' => $selected?->method,
                'user' => $customer,
            ])->toArray(),
        ];
    }

    /**
     * For pickup at a stock location: where, and whether everything is in stock there.
     *
     * @param  Collection<int, CartItemDTO>  $items
     * @param  EloquentCollection<int, ProductVariant>  $variants
     * @return array{location: string, address: string, in_stock: bool}|null
     */
    private function pickup(ShippingQuote $quote, Collection $items, EloquentCollection $variants): ?array
    {
        $location = StorePickup::stockLocation($quote->method);

        if ($location === null) {
            return null;
        }

        $inStock = $items->every(function (CartItemDTO $item) use ($variants, $location) {
            $variant = $variants->get($item->variant_id);
            $available = $variant === null ? 0 : $this->inventory->availableAt($variant, $location);

            return $available === null || $available >= $item->quantity;
        });

        return ['location' => $location->name, 'address' => $location->addressLine(), 'in_stock' => $inStock];
    }
}
