<?php

namespace PnShop\Catalog;

use Illuminate\Support\Facades\DB;
use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Exceptions\InvalidVariant;
use PnShop\Catalog\Models\OptionValue;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\InventoryService;

/**
 * Creating, changing, generating and deleting variants: the one implementation behind the
 * admin panel and the Admin API, so both apply the same rules.
 */
class VariantService
{
    /** "Generate variants" refuses to create more than this many at once. */
    public const MAX_GENERATED = 200;

    /** The variant fields that can be set directly. */
    public const ATTRIBUTES = ['price', 'sale_price', 'sku', 'barcode', 'weight', 'is_active', 'track_inventory', 'allow_backorder', 'low_stock_threshold', 'position'];

    public function __construct(private InventoryService $inventory) {}

    /**
     * @param  array<string, mixed>  $attributes  any of ATTRIBUTES
     * @param  list<int>|null  $optionValueIds  one value of each of the product's options (null: unchanged)
     * @param  int|null  $stock  the counted quantity on hand (null: unchanged)
     *
     * @throws InvalidVariant
     */
    public function save(Product $product, ProductVariant $variant, array $attributes, ?array $optionValueIds = null, ?int $stock = null, ?AdminUser $actor = null): ProductVariant
    {
        $valueIds = $optionValueIds === null ? null : $this->optionValues($product, $variant, $optionValueIds);

        return DB::transaction(function () use ($product, $variant, $attributes, $valueIds, $stock, $actor) {
            $variant->product_id ??= $product->id;
            $variant->fill(array_intersect_key($attributes, array_flip(self::ATTRIBUTES)))->save();

            if ($valueIds !== null) {
                $variant->optionValues()->sync($valueIds);
            }

            // Only a real change is written, so saving a form does not fill the stock history.
            if ($stock !== null && $stock !== $this->inventory->onHandAt($variant->unsetRelation('stockLevels'))) {
                $this->inventory->setOnHand($variant, $stock, $actor);
            }

            return $variant->refresh()->load(['optionValues', 'stockLevels']);
        });
    }

    /**
     * A product keeps at least one variant, and always has a default one.
     *
     * @throws InvalidVariant
     */
    public function delete(ProductVariant $variant): void
    {
        $product = $variant->product;

        if ($product->variants()->count() <= 1) {
            throw new InvalidVariant(__('A product needs at least one variant.'));
        }

        DB::transaction(function () use ($variant, $product) {
            $variant->delete();

            if ($variant->is_default) {
                $product->variants()->orderBy('position')->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    /**
     * One variant for every combination of option values that has none yet, priced like the
     * default variant.
     *
     * @return int the number of variants created
     *
     * @throws InvalidVariant when there would be more than MAX_GENERATED new variants
     */
    public function generate(Product $product): int
    {
        $product->load(['options.values', 'variants.optionValues']);

        $combinations = [[]];

        foreach ($product->options as $option) {
            $combinations = collect($combinations)
                ->flatMap(fn (array $combination) => $option->values->map(fn (OptionValue $value) => [...$combination, $value->id]))
                ->all();

            if (count($combinations) > self::MAX_GENERATED + $product->variants->count()) {
                break;
            }
        }

        $existing = $product->variants->map(fn (ProductVariant $variant) => collect($variant->optionValues->modelKeys())->sort()->implode('-'));
        $missing = collect($combinations)
            ->map(function (array $combination) {
                sort($combination);

                return $combination;
            })
            ->reject(fn (array $combination) => $combination === [] || $existing->contains(implode('-', $combination)))
            ->values();

        if ($missing->count() > self::MAX_GENERATED) {
            throw new InvalidVariant(__('That would create more than :max variants. Remove some option values, or add the variants you sell one by one.', ['max' => self::MAX_GENERATED]));
        }

        $template = $product->defaultVariant();

        DB::transaction(function () use ($missing, $product, $template) {
            foreach ($missing as $combination) {
                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'price' => $template->price ?? 0,
                    'sale_price' => $template?->sale_price,
                ]);
                $variant->optionValues()->sync($combination);
            }
        });

        return $missing->count();
    }

    /**
     * One value of each of the product's options, and no other variant with the same values.
     *
     * @param  list<int>  $valueIds
     * @return list<int>
     *
     * @throws InvalidVariant
     */
    private function optionValues(Product $product, ProductVariant $variant, array $valueIds): array
    {
        $optionIds = $product->options()->pluck('options.id')->map(fn (mixed $id) => (int) $id)->sort()->values()->all();
        $chosen = OptionValue::query()->whereKey($valueIds)->pluck('option_id', 'id');

        if (count($valueIds) !== $chosen->count() || $chosen->map(fn (mixed $id) => (int) $id)->sort()->values()->all() !== $optionIds) {
            throw new InvalidVariant(__('Choose one value of each of the product\'s options.'), 'option_value_ids');
        }

        sort($valueIds);

        $duplicate = $product->variants()->whereKeyNot($variant->getKey() ?? 0)->with('optionValues')->get()
            ->contains(fn (ProductVariant $other) => $other->optionValues->modelKeys() !== [] && collect($other->optionValues->modelKeys())->sort()->values()->all() === $valueIds);

        if ($duplicate) {
            throw new InvalidVariant(__('A variant with these options already exists.'), 'option_value_ids');
        }

        return $valueIds;
    }
}
