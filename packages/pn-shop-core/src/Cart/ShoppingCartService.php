<?php

namespace PnShop\Cart;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PnShop\Cart\Exceptions\CartException;
use PnShop\Cart\Totals\CartCalculator;
use PnShop\Cart\Totals\CartTotals;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\PriceDisplay;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Inventory\InventoryService;
use PnShop\Localization\Localization;
use PnShop\Money\MoneyPresenter;

/**
 * The visitor's cart. Cart lines (PnShop\Cart) hold only variant ids and quantities;
 * every read resolves current prices, titles and stock from the database.
 *
 * Safe to keep across requests (controllers are cached on routes, and long-lived
 * workers reuse instances): it always reads the current request's cart, and its
 * memoized items are tied to the cart contents they were built from.
 */
class ShoppingCartService
{
    /** Session keys of earlier cart formats; cleared on first use. */
    private const LEGACY_SESSION_KEYS = ['shopping_cart', 'cart.lines'];

    /** @var Collection<int, CartItemDTO>|null */
    private ?Collection $items = null;

    /** Cart contents the memoized $items were built from. */
    private ?string $itemsFor = null;

    public function __construct(
        private InventoryService $inventory,
        private CartRepository $carts,
        private CartCalculator $calculator,
    ) {}

    public function addItemToCart(int $variantId, int $quantity): void
    {
        if (! app(PriceDisplay::class)->visible()) {
            throw new CartException(__('Please sign in to see prices and order.'));
        }

        $lines = $this->getLines();

        $this->assertQuantityAvailable($variantId, ($lines[$variantId] ?? 0) + $quantity);

        $this->carts->setQuantity($variantId, ($lines[$variantId] ?? 0) + $quantity);
    }

    public function updateItemQuantityInCart(int $variantId, int $quantity): void
    {
        $lines = $this->getLines();

        if (! isset($lines[$variantId])) {
            throw new CartException(__('Item not found in the cart.'));
        }

        $this->assertQuantityAvailable($variantId, $quantity);

        $this->carts->setQuantity($variantId, $quantity);
    }

    public function removeItemFromCart(int $variantId): void
    {
        $this->carts->setQuantity($variantId, 0);
    }

    /**
     * Cart lines for variants that can still be bought, priced from the database.
     *
     * @return Collection<int, CartItemDTO>
     */
    public function getCartItems(): Collection
    {
        $lines = $this->getLines();
        $signature = (string) json_encode($lines);

        if ($this->items !== null && $this->itemsFor === $signature) {
            return $this->items;
        }

        $this->itemsFor = $signature;

        $variants = $this->purchasableVariants()
            ->whereKey(array_keys($lines))
            ->with(['product.media', 'optionValues', 'stockLevels'])
            ->get()
            ->keyBy('id');
        app(PriceResolver::class)->prime($variants);

        return $this->items = collect($lines)
            ->filter(fn (int $quantity, int $variantId) => $variants->has($variantId))
            ->map(fn (int $quantity, int $variantId) => CartItemDTO::fromVariant($variants[$variantId], $quantity))
            ->values();
    }

    /**
     * Raw variant id => quantity lines, without loading products.
     *
     * @return array<int, int>
     */
    public function getLines(): array
    {
        $request = $this->request();

        if ($request->hasSession()) {
            foreach (self::LEGACY_SESSION_KEYS as $key) {
                $request->session()->forget($key);
            }
        }

        return $this->carts->lines();
    }

    /**
     * Subtotal, total lines (shipping, discounts, tax, ...) and grand total.
     *
     * @param  array<string, mixed>  $context  e.g. shipping address or chosen shipping method
     */
    public function totals(array $context = []): CartTotals
    {
        return $this->calculator->calculate($this->getCartItems(), app(Localization::class)->currency()->code, $this->context($context));
    }

    /**
     * The totals context with the cart's own facts filled in: the entered coupon code and
     * the signed-in customer (explicit values win).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function context(array $context = []): array
    {
        return $context + [
            'coupon_code' => $this->carts->couponCode(),
            'user' => Auth::guard('web')->user(),
        ];
    }

    public function couponCode(): ?string
    {
        return $this->carts->couponCode();
    }

    public function setCouponCode(?string $code): void
    {
        $this->carts->setCouponCode($code);
    }

    /**
     * Enter a coupon code. A code that does not exist (or is used up) is refused; a valid
     * code whose conditions the cart does not meet yet is kept and applies once they are met.
     *
     * @return array{code: string, valid: bool, applied: bool, message: string|null}
     *
     * @throws CartException when the code is not valid
     */
    public function applyCoupon(string $code): array
    {
        // Guessing codes: 10 wrong codes per 10 minutes per visitor.
        $attempts = 'pnshop:coupon-attempts:'.$this->request()->ip();

        if (RateLimiter::tooManyAttempts($attempts, 10)) {
            throw new CartException(__('Too many coupon codes tried. Please wait a few minutes.'));
        }

        $previous = $this->couponCode();
        $this->setCouponCode(trim($code));

        $coupon = $this->totals()->meta['coupon'] ?? null;

        if (! is_array($coupon) || empty($coupon['valid'])) {
            $this->setCouponCode($previous);
            RateLimiter::hit($attempts, 600);

            throw new CartException(__('This coupon code is not valid.'));
        }

        return [
            'code' => (string) $coupon['code'],
            'valid' => true,
            'applied' => (bool) $coupon['applied'],
            'message' => is_string($coupon['message'] ?? null) ? $coupon['message'] : null,
        ];
    }

    /**
     * Sum of the cart lines.
     */
    public function getTotalPrice(): Money
    {
        return $this->totals()->subtotal;
    }

    /**
     * What the customer pays.
     */
    public function getFinalPrice(): Money
    {
        return $this->totals()->total();
    }

    public function getTotalQuantity(): int
    {
        return array_sum($this->getLines());
    }

    /**
     * @return array<int, int> variant id => quantity, read under a row lock (see CartRepository)
     */
    public function lockedLines(): array
    {
        return $this->carts->lockedLines();
    }

    public function clearCart(): void
    {
        $this->carts->clear();
        $this->items = null;
    }

    /**
     * @param  array<string, mixed>  $context  passed to the totals pipeline (see totals())
     * @return array<string, mixed>
     */
    public function toArray(array $context = []): array
    {
        $totals = $this->totals($context);

        return [
            'items' => $this->getCartItems()->map(fn (CartItemDTO $item) => [
                'variant_id' => $item->variant_id,
                'product_id' => $item->product_id,
                'title' => $item->title,
                'slug' => $item->slug,
                'variant_label' => $item->variant_label,
                'sku' => $item->sku,
                'price' => MoneyPresenter::present($item->price),
                'sale_price' => MoneyPresenter::present($item->sale_price),
                'unit_price' => MoneyPresenter::present($item->getUnitPrice()),
                'image' => $item->image,
                'stock' => $item->available,
                'quantity' => $item->quantity,
                'line_total' => MoneyPresenter::present($item->getTotalPrice()),
            ])->all(),
            'total_quantity' => $this->getCartItems()->sum('quantity'),
            'total_price' => MoneyPresenter::present($totals->subtotal),
            'final_price' => MoneyPresenter::present($totals->total()),
            'totals' => $totals->toArray(),
            // {code, valid, applied, message} when a coupon code was entered.
            'coupon' => $totals->meta['coupon'] ?? null,
            // The customer group's minimum order, while the products fall short of it.
            'minimum_order' => MoneyPresenter::present(app(PriceResolver::class)->customerGroup()?->minimumOrderShortfall($totals->subtotal)),
        ];
    }

    /**
     * Active variants of active products.
     *
     * @return Builder<ProductVariant>
     */
    public function purchasableVariants(): Builder
    {
        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true));
    }

    private function assertQuantityAvailable(int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new CartException(__('Quantity must be greater than 0.'));
        }

        $variant = $this->purchasableVariants()->with(['product', 'stockLevels'])->find($variantId);

        if (! $variant) {
            throw new CartException(__('This product is not available.'));
        }

        if (! $this->inventory->canSell($variant, $quantity)) {
            throw new CartException(__('Only :stock of :product available.', ['stock' => (int) $variant->available(), 'product' => $variant->product->title]));
        }
    }

    private function request(): Request
    {
        return app('request');
    }
}
