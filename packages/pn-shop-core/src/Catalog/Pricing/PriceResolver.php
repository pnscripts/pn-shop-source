<?php

namespace PnShop\Catalog\Pricing;

use Brick\Money\Money;
use Illuminate\Support\Facades\Auth;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Catalog\Pricing\Models\PriceListEntry;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Customer\Models\User;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Localization\Localization;
use Throwable;

/**
 * What a customer pays for a variant: the "catalog.price" pipeline (the sale price, then
 * price lists and quantity tiers, then any stages plugins add), for the signed-in customer's
 * group, or the default group for guests.
 *
 * One per request (scoped). Listings call prime() with all their variants first, so price
 * lists are read in one query; quotes are kept for the rest of the request.
 */
class PriceResolver
{
    public const PIPELINE = 'catalog.price';

    private ?PriceContext $context = null;

    /** Set by forCustomer(): the context no longer follows the signed-in customer. */
    private bool $pinned = false;

    /** @var array<int, list<array{price_list_id: int, min_quantity: int, price: Money}>> variant id => entries */
    private array $entries = [];

    /** @var array<string, PriceQuote> */
    private array $quotes = [];

    private ?bool $hasPriceLists = null;

    /** @var array<int, CustomerGroup|null> */
    private array $groups = [];

    public function __construct(private PipelineRegistry $pipelines, private Localization $localization) {}

    public function context(): PriceContext
    {
        if ($this->pinned && $this->context !== null) {
            return $this->context;
        }

        // Follows the signed-in customer: signing in during a request (or the next request in
        // a long-running process) prices for them.
        $customer = $this->currentCustomer();

        if ($this->context === null || $this->context->customer?->getKey() !== $customer?->getKey()) {
            $this->switchTo($this->contextFor($customer));
        }

        /** @var PriceContext */
        return $this->context;
    }

    /**
     * Price for this customer instead of the signed-in one (checkout, the APIs, tests).
     */
    public function forCustomer(?User $customer): void
    {
        $this->pinned = true;

        if ($this->context === null || $this->context->customer?->getKey() !== $customer?->getKey()) {
            $this->switchTo($this->contextFor($customer));
        }
    }

    private function switchTo(PriceContext $context): void
    {
        if ($this->context?->key() !== $context->key()) {
            $this->entries = [];
            $this->quotes = [];
        }

        $this->context = $context;
    }

    public function contextFor(?User $customer): PriceContext
    {
        $groupId = $customer !== null ? $customer->customer_group_id : null;

        try {
            $groupId ??= CustomerGroup::query()->where('is_default', true)->value('id');
            $currency = $this->localization->defaultCurrency()->code;
        } catch (Throwable) {
            // No database yet (installing): plain prices.
            $currency = (string) config('pnshop.money.currency', 'EUR');
        }

        return new PriceContext(is_numeric($groupId) ? (int) $groupId : null, $currency, $customer);
    }

    /**
     * The current customer's group (the default group for guests): its B2B options.
     */
    public function customerGroup(): ?CustomerGroup
    {
        $id = $this->context()->customerGroupId;

        if ($id === null) {
            return null;
        }

        if (! array_key_exists($id, $this->groups)) {
            $this->groups[$id] = CustomerGroup::query()->find($id);
        }

        return $this->groups[$id];
    }

    /**
     * The price of one unit (and the regular price) for the quantity.
     */
    public function quote(ProductVariant $variant, int $quantity = 1): PriceQuote
    {
        // First, so a change of customer clears quotes made for someone else.
        $context = $this->context();
        $quantity = max(1, $quantity);
        $key = $variant->id.'|'.$quantity.'|'.$variant->price->getMinorAmount()->toInt().'|'.($variant->sale_price?->getMinorAmount()->toInt() ?? '-');

        if (isset($this->quotes[$key])) {
            return $this->quotes[$key];
        }

        $this->prime([$variant]);

        /** @var PriceQuote $quote */
        $quote = $this->pipelines->run(self::PIPELINE, new PriceQuote($variant, $quantity, $context, $variant->price));

        return $this->quotes[$key] = $quote;
    }

    /**
     * Read the price-list entries of these variants in one query.
     *
     * @param  iterable<ProductVariant|int>  $variants
     */
    public function prime(iterable $variants): void
    {
        $context = $this->context();
        $ids = [];

        foreach ($variants as $variant) {
            $id = $variant instanceof ProductVariant ? $variant->id : (int) $variant;

            if ($id > 0 && ! array_key_exists($id, $this->entries)) {
                $ids[] = $id;
                $this->entries[$id] = [];
            }
        }

        if ($ids === [] || ! $this->hasPriceLists()) {
            return;
        }

        $rows = PriceListEntry::query()
            ->whereIn('product_variant_id', $ids)
            ->whereIn('price_list_id', PriceList::query()->applicable($context->customerGroupId, $context->currency)->select('id'))
            ->get(['price_list_id', 'product_variant_id', 'min_quantity', 'price']);

        foreach ($rows as $row) {
            $this->entries[$row->product_variant_id][] = ['price_list_id' => $row->price_list_id, 'min_quantity' => $row->min_quantity, 'price' => $row->price];
        }
    }

    /**
     * prime() for the loaded variants of these products (a listing, before its cards).
     *
     * @param  iterable<Product>  $products
     */
    public function primeProducts(iterable $products): void
    {
        $variants = [];

        foreach ($products as $product) {
            if ($product->relationLoaded('variants')) {
                foreach ($product->getRelationValue('variants') as $variant) {
                    $variants[] = $variant;
                }
            }
        }

        $this->prime($variants);
    }

    /**
     * @return list<array{price_list_id: int, min_quantity: int, price: Money}>
     */
    public function entriesFor(int $variantId): array
    {
        return $this->entries[$variantId] ?? [];
    }

    /** Whether any price list is active at all (most shops have none: no queries then). */
    private function hasPriceLists(): bool
    {
        try {
            return $this->hasPriceLists ??= PriceList::query()->where('is_active', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function currentCustomer(): ?User
    {
        foreach (['web', 'store-api'] as $guard) {
            try {
                $user = Auth::guard($guard)->user();
            } catch (Throwable) {
                continue;
            }

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}
