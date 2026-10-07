<?php

namespace PnShop\Storefront\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Cart\Exceptions\CartException;
use PnShop\Cart\ShoppingCartService;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Catalog\ProductType;
use PnShop\Credit\CartBalances;
use PnShop\Localization\Localization;
use PnShop\Storefront\Http\Requests\Cart\AddToCartRequest;
use PnShop\Storefront\Http\Requests\Cart\UpdateCartRequest;

class CartController extends Controller
{
    public function __construct(private ShoppingCartService $cart) {}

    public function index(): Response
    {
        $inCart = $this->cart->getCartItems()->pluck('product_id')->unique()->values()->all();

        $suggestions = $inCart === [] ? collect() : Product::query()
            ->active()
            ->whereKeyNot($inCart)
            ->whereHas('crossSellsOf', fn ($query) => $query->whereKey($inCart))
            ->with(ProductCardPresenter::RELATIONS)
            ->limit(4)
            ->get();

        return Inertia::render('cart/index', [
            'cart' => $this->cart->toArray(),
            'suggestions' => ProductCardPresenter::presentMany($suggestions),
        ]);
    }

    public function store(AddToCartRequest $request): RedirectResponse
    {
        try {
            $this->cart->addItemToCart($this->variantId($request), (int) $request->validated('quantity'), $request->validated('gift_card'));
        } catch (CartException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', __('Added to cart.'));
    }

    public function update(UpdateCartRequest $request, int $variant): RedirectResponse
    {
        try {
            $this->cart->updateItemQuantityInCart($variant, (int) $request->validated('quantity'));
        } catch (CartException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', __('Cart updated.'));
    }

    public function destroy(int $variant): RedirectResponse
    {
        $this->cart->removeItemFromCart($variant);

        return back()->with('success', __('Item removed from cart.'));
    }

    public function applyCoupon(Request $request): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:64']])['code'];

        try {
            $coupon = $this->cart->applyCoupon($code);
        } catch (CartException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        // A valid code the cart does not qualify for yet is kept; the cart says why it does not apply.
        return back()->with('success', $coupon['applied'] ? __('Coupon applied.') : __('Coupon code saved.'));
    }

    public function removeCoupon(): RedirectResponse
    {
        $this->cart->setCouponCode(null);

        return back()->with('success', __('Coupon removed.'));
    }

    public function applyGiftCard(Request $request, CartBalances $balances): RedirectResponse
    {
        $code = (string) $request->validate(['gift_card' => ['required', 'string', 'max:64']])['gift_card'];

        try {
            $balances->apply($code, app(Localization::class)->currency()->code);
        } catch (CartException $e) {
            throw ValidationException::withMessages(['gift_card' => $e->getMessage()]);
        }

        return back()->with('success', __('Gift card added.'));
    }

    public function removeGiftCard(int $giftCard, CartBalances $balances): RedirectResponse
    {
        $balances->remove($giftCard);

        return back()->with('success', __('Gift card removed.'));
    }

    public function useStoreCredit(Request $request, CartBalances $balances): RedirectResponse
    {
        $balances->useCredit($request->boolean('use'));

        return back();
    }

    /**
     * The variant to add: given explicitly, or a simple product's only variant.
     */
    private function variantId(AddToCartRequest $request): int
    {
        if ($request->filled('variant_id')) {
            return (int) $request->validated('variant_id');
        }

        $product = Product::query()->with('variants')->findOrFail((int) $request->validated('product_id'));

        if ($product->type === ProductType::Variable) {
            throw ValidationException::withMessages(['variant_id' => __('Please choose an option.')]);
        }

        return $product->defaultVariant()->id ?? throw ValidationException::withMessages(['quantity' => __('This product is not available.')]);
    }
}
