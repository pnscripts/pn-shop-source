<?php

namespace PnShop\Catalog\Pricing;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Catalog\Pricing\Models\PriceListEntry;
use RuntimeException;
use Throwable;

/**
 * Writing a price list's prices in bulk, by SKU: the CSV import in the admin and the Admin
 * API use the same rules. A row with an empty price removes that price.
 */
class PriceListEntries
{
    public const CSV_HEADER = ['sku', 'min_quantity', 'price'];

    /**
     * @param  iterable<array{sku?: mixed, min_quantity?: mixed, price?: mixed}>  $rows
     * @return array{saved: int, removed: int, errors: list<string>}
     */
    public function apply(PriceList $list, iterable $rows): array
    {
        $saved = 0;
        $removed = 0;
        $errors = [];
        $rows = collect($rows)->values();

        $variants = ProductVariant::query()
            ->whereIn('sku', $rows->pluck('sku')->filter(fn (mixed $sku) => is_string($sku) && trim($sku) !== '')->map(fn (string $sku) => trim($sku))->unique()->all())
            ->pluck('id', 'sku');

        DB::transaction(function () use ($list, $rows, $variants, &$saved, &$removed, &$errors) {
            foreach ($rows as $index => $row) {
                $line = $index + 1;
                $sku = trim((string) ($row['sku'] ?? ''));
                $quantity = $row['min_quantity'] ?? '';
                $quantity = trim((string) $quantity) === '' ? 1 : $quantity;
                $price = trim((string) ($row['price'] ?? ''));

                if (! $variants->has($sku)) {
                    $errors[] = __('Row :line: no variant has the SKU ":sku".', ['line' => $line, 'sku' => $sku]);

                    continue;
                }

                if (! is_numeric($quantity) || (int) $quantity < 1 || (int) $quantity != $quantity) {
                    $errors[] = __('Row :line: the minimum quantity must be a whole number of at least 1.', ['line' => $line]);

                    continue;
                }

                $key = ['price_list_id' => $list->id, 'product_variant_id' => (int) $variants[$sku], 'min_quantity' => (int) $quantity];

                if ($price === '') {
                    $removed += PriceListEntry::query()->where($key)->delete();

                    continue;
                }

                try {
                    $amount = Money::of($price, $list->currency, roundingMode: RoundingMode::HalfUp);
                } catch (Throwable) {
                    $amount = null;
                }

                if ($amount === null || $amount->isNegative()) {
                    $errors[] = __('Row :line: ":price" is not a price.', ['line' => $line, 'price' => $price]);

                    continue;
                }

                PriceListEntry::query()->updateOrCreate($key, ['price' => $amount]);
                $saved++;
            }
        });

        return ['saved' => $saved, 'removed' => $removed, 'errors' => $errors];
    }

    /**
     * @return array{saved: int, removed: int, errors: list<string>}
     */
    public function importCsv(PriceList $list, string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $header = array_map(fn (?string $cell) => strtolower(trim((string) $cell)), str_getcsv((string) array_shift($lines)));

        if (array_diff(['sku', 'price'], $header) !== []) {
            return ['saved' => 0, 'removed' => 0, 'errors' => [__('The first row must name the columns: sku, min_quantity (optional), price.')]];
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }

        return $this->apply($list, $rows);
    }

    public function exportCsv(PriceList $list): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }
        fputcsv($handle, self::CSV_HEADER);

        $list->entries()
            ->with('variant:id,sku')
            ->orderBy('product_variant_id')
            ->orderBy('min_quantity')
            ->each(function (PriceListEntry $entry) use ($handle): void {
                fputcsv($handle, [(string) $entry->variant?->sku, $entry->min_quantity, (string) $entry->price->getAmount()]);
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
