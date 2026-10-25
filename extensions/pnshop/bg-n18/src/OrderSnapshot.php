<?php

namespace PnShop\Plugins\BgN18;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;

/**
 * An order's lines and totals as Наредба Н-18 documents and the audit file show them:
 * per line the quantity, the unit price before discount without VAT, the VAT rate and
 * amount and the total with VAT; per order the totals without VAT, the discount, the VAT
 * and the total with VAT. Shipping and other charges are lines of their own.
 *
 * Amounts are decimal strings with two decimals, in the order's currency.
 */
final class OrderSnapshot
{
    /**
     * @return array{lines: list<array{name: string, quantity: string, unit_price: string, unit_price_gross: string, vat_rate: int, vat_group: string, vat: string, total: string}>, total_net: string, discount: string, vat: string, total: string, currency: string}
     */
    public static function of(Order $order): array
    {
        $currency = $order->currency;
        $zero = Money::zero($currency);
        $inclusive = collect($order->totals ?? [])->contains(fn (array $line) => str_starts_with($line['code'], 'tax') && $line['included']);
        $lines = [];
        $net = $zero;
        $discount = $zero;
        $vat = $zero;

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $lineVat = $item->tax_amount;
            $before = $item->lineTotal();
            $gross = $inclusive ? $before->minus($item->discount_amount) : $before->minus($item->discount_amount)->plus($lineVat);
            $lineNet = $gross->minus($lineVat);
            $rate = $lineNet->isPositive() ? $lineVat->getAmount()->dividedBy($lineNet->getAmount(), 6, RoundingMode::HalfUp) : BigDecimal::zero();
            // The unit price before discount, without VAT.
            $unitGross = $inclusive ? $item->unitPrice() : $item->unitPrice()->getAmount()->multipliedBy($rate->plus(1));
            $unitNet = $inclusive ? $item->unitPrice()->getAmount()->dividedBy($rate->plus(1), 2, RoundingMode::HalfUp) : $item->unitPrice()->getAmount();
            $itemDiscountNet = $inclusive ? $item->discount_amount->getAmount()->dividedBy($rate->plus(1), 2, RoundingMode::HalfUp) : $item->discount_amount->getAmount();

            $lines[] = self::line(
                trim($item->product_title.($item->variant_label ? ' ('.$item->variant_label.')' : '')) ?: '—',
                $item->quantity,
                $unitNet,
                $unitGross instanceof Money ? $unitGross->getAmount() : $unitGross,
                $rate,
                $lineVat->getAmount(),
                $gross->getAmount(),
            );

            $net = $net->plus(Money::of($unitNet->multipliedBy($item->quantity), $currency, roundingMode: RoundingMode::HalfUp));
            $discount = $discount->plus(Money::of($itemDiscountNet, $currency, roundingMode: RoundingMode::HalfUp));
            $vat = $vat->plus($lineVat);
        }

        // Shipping, fees and other charges between subtotal and total (not tax, not discounts).
        $taxTotal = $zero;

        foreach ($order->totals ?? [] as $line) {
            if (str_starts_with($line['code'], 'tax')) {
                $taxTotal = $taxTotal->plus(Money::ofMinor($line['amount'], $currency));
            }
        }

        $charges = collect($order->totals ?? [])->filter(fn (array $line) => ! str_starts_with($line['code'], 'tax') && $line['amount'] > 0 && ! $line['included'])->values();
        // VAT the order's tax lines hold beyond the products' own: it belongs to the charges.
        $chargeVat = $taxTotal->isGreaterThan($vat) ? $taxTotal->minus($vat) : $zero;
        $chargesGross = $charges->reduce(fn (Money $sum, array $line) => $sum->plus(Money::ofMinor($line['amount'], $currency)), $zero);

        $shared = $zero;

        foreach ($charges as $index => $line) {
            $gross = Money::ofMinor($line['amount'], $currency);
            // Shared in proportion to the charges; the last one takes the rest (no lost cent).
            $share = $index === $charges->count() - 1
                ? $chargeVat->minus($shared)
                : Money::of($chargeVat->getAmount()->multipliedBy($gross->getAmount())->dividedBy($chargesGross->getAmount(), 2, RoundingMode::HalfUp), $currency);
            $shared = $shared->plus($share);

            if (! $inclusive) {
                $gross = $gross->plus($share);
            }

            $lineNet = $gross->minus($share);
            $rate = $lineNet->isPositive() ? $share->getAmount()->dividedBy($lineNet->getAmount(), 6, RoundingMode::HalfUp) : BigDecimal::zero();
            $lines[] = self::line((string) $line['label'], 1, $lineNet->getAmount(), $gross->getAmount(), $rate, $share->getAmount(), $gross->getAmount());
            $net = $net->plus($lineNet);
            $vat = $vat->plus($share);
        }

        return [
            'lines' => $lines,
            'total_net' => (string) $net->getAmount(),
            'discount' => (string) $discount->getAmount(),
            'vat' => (string) $vat->getAmount(),
            'total' => (string) $order->grandTotal()->getAmount(),
            'currency' => $currency,
        ];
    }

    /**
     * @return array{name: string, quantity: string, unit_price: string, unit_price_gross: string, vat_rate: int, vat_group: string, vat: string, total: string}
     */
    private static function line(string $name, int $quantity, BigDecimal $unitNet, BigDecimal $unitGross, BigDecimal $rate, BigDecimal $vat, BigDecimal $total): array
    {
        $percent = $rate->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        return [
            'name' => mb_substr($name, 0, 200),
            'quantity' => number_format($quantity, 2, '.', ''),
            'unit_price' => (string) $unitNet->toScale(2, RoundingMode::HalfUp),
            'unit_price_gross' => (string) $unitGross->toScale(2, RoundingMode::HalfUp),
            'vat_rate' => $percent,
            'vat_group' => self::vatGroup($percent),
            'vat' => (string) $vat->toScale(2, RoundingMode::HalfUp),
            'total' => (string) $total->toScale(2, RoundingMode::HalfUp),
        ];
    }

    /**
     * The tax group printed on sale documents (Наредба Н-18): А 0 % / exempt, Б 20 %,
     * Г 9 %; other rates are shown as Б when higher than 9 %, Г otherwise.
     */
    public static function vatGroup(int $percent): string
    {
        return match (true) {
            $percent <= 0 => 'А',
            $percent >= 20 => 'Б',
            $percent === 9 => 'Г',
            $percent > 9 => 'Б',
            default => 'Г',
        };
    }
}
