<?php

namespace PnShop\Plugins\PriorPrice;

use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Money\Prices;

/**
 * Every change of a variant's public price (its sale price when one applies, else its
 * price), in the default currency, from the moment the plugin was installed.
 */
final class PriceHistory
{
    public const TABLE = 'pnshop_prior_price_history';

    /**
     * Record the variant's current public price, unless it is already the latest entry.
     */
    public function record(ProductVariant $variant, ?Carbon $at = null): void
    {
        $price = Prices::effective($variant->price, $variant->sale_price);
        $onSale = Prices::isSale($variant->price, $variant->sale_price);
        $amount = $price->getMinorAmount()->toInt();
        $currency = $price->getCurrency()->getCurrencyCode();

        $latest = DB::table(self::TABLE)
            ->where('product_variant_id', $variant->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first(['amount', 'currency', 'on_sale']);

        if ($latest !== null && (int) $latest->amount === $amount && $latest->currency === $currency && (bool) $latest->on_sale === $onSale) {
            return;
        }

        DB::table(self::TABLE)->insert([
            'product_variant_id' => $variant->id,
            'amount' => $amount,
            'currency' => $currency,
            'on_sale' => $onSale,
            'effective_from' => ($at ?? Carbon::now())->toDateTimeString(),
        ]);
    }

    /** Record every variant (on install). */
    public function recordAll(): void
    {
        ProductVariant::query()->orderBy('id')->chunkById(500, function ($variants) {
            foreach ($variants as $variant) {
                $this->record($variant);
            }
        });
    }

    /**
     * The lowest public price in the $days days before the current reduction started, or
     * null when there is no reduction in the history or nothing is known from before it.
     *
     * @return array{price: Money, reduction_from: Carbon}|null
     */
    public function lowestBeforeReduction(int $variantId, int $days, bool $progressive): ?array
    {
        $rows = DB::table(self::TABLE)
            ->where('product_variant_id', $variantId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['amount', 'currency', 'on_sale', 'effective_from'])
            ->values();

        if ($rows->isEmpty() || ! (bool) $rows[0]->on_sale) {
            return null;
        }

        // Where the reduction began: the newest entry, or with back-to-back reductions the
        // first of the run of sale prices.
        $start = 0;

        if ($progressive) {
            while (isset($rows[$start + 1]) && (bool) $rows[$start + 1]->on_sale) {
                $start++;
            }
        }

        $reductionFrom = Carbon::parse($rows[$start]->effective_from);
        $windowStart = $reductionFrom->copy()->subDays($days);
        $lowest = null;

        for ($i = $start + 1; isset($rows[$i]); $i++) {
            $row = $rows[$i];

            // Prices from before a change of the default currency are not comparable.
            if ($row->currency !== $rows[0]->currency) {
                break;
            }

            $lowest = $lowest === null ? $row : ((int) $row->amount < (int) $lowest->amount ? $row : $lowest);

            // This entry was in effect when the window opened: nothing older counts.
            if (Carbon::parse($row->effective_from)->lessThanOrEqualTo($windowStart)) {
                break;
            }
        }

        if ($lowest === null) {
            return null;
        }

        return ['price' => Money::ofMinor((int) $lowest->amount, (string) $lowest->currency), 'reduction_from' => $reductionFrom];
    }
}
