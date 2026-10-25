<?php

namespace PnShop\Plugins\BgN18;

use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PnShop\Payment\Models\Refund;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use PnShop\Sales\Models\Order;
use PnShop\Sales\States\OrderStatus;
use PnShop\Settings\Settings;
use XMLWriter;

/**
 * The monthly standardized audit file of Наредба Н-18, Приложение 38 (чл. 52т, ал. 2),
 * due by the 15th of the following month: the orders delivered in the month, their lines,
 * totals and payment, and the refunds made in the month. Amounts in euro, as stored.
 *
 * Which orders count as delivered in the month: those whose first shipment left in it, and
 * orders with nothing to ship (services, downloads) whose sale document was issued in it.
 */
final class AuditFile
{
    public function __construct(private N18Settings $settings) {}

    public function xml(int $year, int $month): string
    {
        $timezone = (string) (app(Settings::class)->get('localization.timezone') ?: config('app.timezone', 'UTC'));
        $from = Carbon::create($year, $month, 1, 0, 0, 0, $timezone);
        $to = $from->copy()->addMonth();
        [$fromUtc, $toUtc] = [$from->copy()->utc(), $to->copy()->utc()];

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('audit');
        $this->element($xml, 'eik', $this->settings->string('eik'));
        $this->element($xml, 'e_shop_n', $this->settings->string('e_shop_number'));
        $this->element($xml, 'e_shop_type', (string) ($this->settings->get('e_shop_type') ?: '1'));
        $this->element($xml, 'domain_name', $this->settings->domain());
        $this->element($xml, 'creation_date', Carbon::now($timezone)->format('Y-m-d'));
        $this->element($xml, 'mon', sprintf('%02d', $month));
        $this->element($xml, 'god', (string) $year);

        $xml->startElement('order');

        foreach ($this->orders($fromUtc, $toUtc) as $order) {
            $this->order($xml, $order, $timezone);
        }

        $xml->endElement();

        $refunds = Refund::query()->with('order')->where('status', 'completed')->where('created_at', '>=', $fromUtc)->where('created_at', '<', $toUtc)->orderBy('id')->get();
        $this->element($xml, 'r_ord', (string) $refunds->pluck('order_id')->unique()->count());
        $xml->startElement('rorder');
        $total = null;

        foreach ($refunds as $refund) {
            $xml->startElement('rorderenum');
            $this->element($xml, 'r_ord_n', (string) $refund->order?->number);
            $this->element($xml, 'r_amount', $this->amount($refund->amount));
            $this->element($xml, 'r_date', $refund->created_at?->copy()->setTimezone($timezone)->format('Y-m-d') ?? '');
            $this->element($xml, 'r_paym', (string) $this->refundCode($refund));
            $xml->endElement();
            $total = $total === null ? $refund->amount : $total->plus($refund->amount);
        }

        $xml->endElement();
        $this->element($xml, 'r_total', $total === null ? '0.00' : $this->amount($total));
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * @return Collection<int, Order>
     */
    public function orders(Carbon $fromUtc, Carbon $toUtc): Collection
    {
        $shipped = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereHas('shipments', fn ($q) => $q->whereNotNull('shipped_at'))
            ->whereRaw('(select min(shipped_at) from shipments where shipments.order_id = orders.id) >= ?', [$fromUtc->toDateTimeString()])
            ->whereRaw('(select min(shipped_at) from shipments where shipments.order_id = orders.id) < ?', [$toUtc->toDateTimeString()])
            ->pluck('id');

        $unshipped = SalesDocument::query()
            ->where('type', SalesDocument::SALE)
            ->where('issued_at', '>=', $fromUtc)->where('issued_at', '<', $toUtc)
            ->whereDoesntHave('order.shipments')
            ->pluck('order_id');

        return Order::query()->with(['items', 'payments'])->whereKey($shipped->merge($unshipped)->unique()->all())->orderBy('id')->get();
    }

    private function order(XMLWriter $xml, Order $order, string $timezone): void
    {
        $document = SalesDocument::query()->where('order_id', $order->id)->where('type', SalesDocument::SALE)->first();
        $snapshot = $document !== null ? $document->data : OrderSnapshot::of($order);
        $code = $document?->payment_code ?? $this->settings->paymentCodeFor($order);
        $gateway = $this->settings->gatewayOf($order);

        $xml->startElement('orderenum');
        $this->element($xml, 'ord_n', (string) $order->number);
        $this->element($xml, 'ord_d', $order->created_at?->copy()->setTimezone($timezone)->format('Y-m-d') ?? '');

        if ($document !== null) {
            $this->element($xml, 'doc_n', (string) $document->formattedNumber());
            $this->element($xml, 'doc_date', $document->issued_at->copy()->setTimezone($timezone)->format('Y-m-d'));
        } else {
            $this->element($xml, 'doc_date', $order->created_at?->copy()->setTimezone($timezone)->format('Y-m-d') ?? '');
        }

        $xml->startElement('art');

        foreach ($snapshot['lines'] as $line) {
            $xml->startElement('artenum');
            $this->element($xml, 'art_name', $line['name']);
            $this->element($xml, 'art_quant', $line['quantity']);
            $this->element($xml, 'art_price', $line['unit_price']);
            $this->element($xml, 'art_vat_rate', (string) $line['vat_rate']);
            $this->element($xml, 'art_vat', $line['vat']);
            $this->element($xml, 'art_sum', $line['total']);
            $xml->endElement();
        }

        $xml->endElement();
        $this->element($xml, 'ord_total1', $snapshot['total_net']);
        $this->element($xml, 'ord_disc', $snapshot['discount']);
        $this->element($xml, 'ord_vat', $snapshot['vat']);
        $this->element($xml, 'ord_total2', $snapshot['total']);
        $this->element($xml, 'paym', (string) $code);

        if ($code === 2) {
            $this->element($xml, 'pos_n', $this->settings->string('pos_number'));
        }

        $reference = $document?->transaction_ref ?? $order->payments->whereNotNull('reference')->sortByDesc('id')->first()?->reference;

        if (in_array($code, N18Settings::DOCUMENT_CODES, true)) {
            $this->element($xml, 'trans_n', (string) $reference);
        }

        if ($code === 4) {
            $this->element($xml, 'proc_id', (string) $this->settings->providerFor($gateway));
        }

        $xml->endElement();
    }

    /** How the money went back: 1 payment account, 2 card, 3 cash, 4 other. */
    private function refundCode(Refund $refund): int
    {
        if ($refund->destination !== 'original') {
            return 4;
        }

        $order = $refund->order;
        $code = $order !== null ? $this->settings->paymentCodeFor($order) : 5;

        return match ($code) {
            2, 4 => 2,
            1, 3 => 1,
            6 => 3,
            default => 4,
        };
    }

    private function amount(Money $money): string
    {
        return (string) $money->getAmount()->toScale(2);
    }

    private function element(XMLWriter $xml, string $name, string $value): void
    {
        $xml->writeElement($name, $value);
    }
}
