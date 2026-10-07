<?php

namespace PnShop\Api\Http\Controllers\Store;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PnShop\Api\Http\Controllers\ApiController;
use PnShop\Cart\CartRepository;
use PnShop\Cart\GiftCardRecipientRules;
use PnShop\Cart\ShoppingCartService;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\ProductType;
use PnShop\Credit\CartBalances;
use PnShop\Localization\Localization;

/**
 * The cart of the customer behind the token, or the guest cart named by the X-Cart-Token
 * header. A guest's first change creates a cart: keep its "token" from the response and send
 * it as X-Cart-Token from then on. Signing in (POST /auth/login with the header) merges the
 * guest cart into the customer's.
 */
class CartController extends ApiController
{
    public function __construct(private ShoppingCartService $cart, private CartRepository $carts) {}

    /**
     * Show the cart
     *
     * Lines priced from the catalog right now, the totals breakdown and the cart token.
     *
     * @return array<string, mixed>
     */
    public function show(): array
    {
        return $this->cart();
    }

    /**
     * Add to the cart
     *
     * Adds `quantity` of a variant (`variant_id`), or of a simple product (`product_id`), to
     * what is already in the cart. Stock is checked. For gift card products, `gift_card`
     * names who receives these cards (`email`, `name`, `message`); without it they go to
     * the buyer.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required_without:product_id', 'nullable', 'integer'],
            'product_id' => ['required_without:variant_id', 'nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            ...GiftCardRecipientRules::rules(),
        ]);

        $this->cart->addItemToCart($this->variantId($data), (int) $data['quantity'], $data['gift_card'] ?? null);

        return response()->json($this->cart(), 201);
    }

    /**
     * Change a line's quantity
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, int $variant): array
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:1000']]);

        $this->cart->updateItemQuantityInCart($variant, (int) $data['quantity']);

        return $this->cart();
    }

    /**
     * Remove a line
     *
     * @return array<string, mixed>
     */
    public function destroy(int $variant): array
    {
        $this->cart->removeItemFromCart($variant);

        return $this->cart();
    }

    /**
     * Enter a coupon code
     *
     * An unknown or used-up code is refused (422). A valid code the cart does not qualify for
     * yet is kept: `coupon.applied` is false and `coupon.message` says why.
     *
     * @return array<string, mixed>
     */
    public function applyCoupon(Request $request): array
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $this->cart->applyCoupon($data['code']);

        return $this->cart();
    }

    /**
     * Remove the coupon code
     *
     * @return array<string, mixed>
     */
    public function removeCoupon(): array
    {
        $this->cart->setCouponCode(null);

        return $this->cart();
    }

    /**
     * Enter a gift card code
     *
     * Its balance pays part or all of the order at checkout (`gift_cards`, `amount_due`).
     * A wrong, used-up, expired or other-currency code is refused (422).
     *
     * @return array<string, mixed>
     */
    public function applyGiftCard(Request $request, CartBalances $balances): array
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $balances->apply($data['code'], app(Localization::class)->currency()->code);

        return $this->cart();
    }

    /**
     * Remove a gift card
     *
     * @return array<string, mixed>
     */
    public function removeGiftCard(int $giftCard, CartBalances $balances): array
    {
        $balances->remove($giftCard);

        return $this->cart();
    }

    /**
     * Spend store credit
     *
     * `use: true` spends the signed-in customer's store credit on the order (`store_credit`).
     *
     * @return array<string, mixed>
     */
    public function useStoreCredit(Request $request, CartBalances $balances): array
    {
        $balances->useCredit((bool) $request->validate(['use' => ['required', 'boolean']])['use']);

        return $this->cart();
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function cart(): array
    {
        return ['data' => [
            // Null for a customer's cart (found through the account) and for a guest without a cart yet.
            'token' => auth()->check() ? null : $this->carts->guestToken(),
            ...$this->cart->toArray(),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function variantId(array $data): int
    {
        if (! empty($data['variant_id'])) {
            return (int) $data['variant_id'];
        }

        $product = Product::query()->active()->with('variants')->find((int) $data['product_id']);

        if ($product === null) {
            throw ValidationException::withMessages(['product_id' => __('This product is not available.')]);
        }

        if ($product->type === ProductType::Variable) {
            throw ValidationException::withMessages(['variant_id' => __('Please choose an option.')]);
        }

        return $product->defaultVariant()->id ?? throw ValidationException::withMessages(['product_id' => __('This product is not available.')]);
    }
}
