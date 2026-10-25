<?php

namespace PnShop\Plugins\BgN18;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use PnShop\Plugins\BgN18\Notifications\SalesDocumentIssued;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;

/**
 * Issues the Наредба Н-18 documents and emails them to the customer.
 *
 * - "sale" (чл. 52о): for orders paid by card through a virtual POS or a payment service
 *   provider, when they are paid. Numbered with 10 digits, rising by one, never reused.
 * - "cod" (чл. 3, ал. 1 with чл. 26, ал. 1, т. 1, 4, 7 and 8): for cash on delivery
 *   settled as a postal money order, when the parcel is shipped.
 *
 * Each order gets at most one document of each type; issuing again returns it.
 */
final class SalesDocuments
{
    private const COUNTER = 'sale_documents';

    public function __construct(private N18Settings $settings) {}

    public function issueSaleDocument(Order $order): ?SalesDocument
    {
        $code = $this->settings->paymentCodeFor($order);

        if (! in_array($code, N18Settings::DOCUMENT_CODES, true)) {
            return null;
        }

        return $this->issue($order, SalesDocument::SALE, $code);
    }

    public function issueCashOnDeliveryDocument(Order $order): ?SalesDocument
    {
        if ($this->settings->paymentCodeFor($order) !== N18Settings::POSTAL_MONEY_ORDER || ! (bool) ($this->settings->get('cod_documents') ?? true)) {
            return null;
        }

        return $this->issue($order, SalesDocument::COD, N18Settings::POSTAL_MONEY_ORDER);
    }

    private function issue(Order $order, string $type, int $code): ?SalesDocument
    {
        if (! $this->settings->isComplete()) {
            report(new \RuntimeException("Н-18 documents need the seller data and NRA e-shop number (Admin → Settings → plugin settings); order {$order->number} has none."));

            return null;
        }

        $document = DB::transaction(function () use ($order, $type, $code) {
            $existing = SalesDocument::query()->where('order_id', $order->id)->where('type', $type)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $order->loadMissing('items');
            $issuedAt = Carbon::now();
            $reference = $this->transactionReference($order);
            $snapshot = OrderSnapshot::of($order);
            $number = $type === SalesDocument::SALE ? $this->nextNumber() : null;

            $document = new SalesDocument;
            $document->forceFill([
                'type' => $type,
                'number' => $number,
                'order_id' => $order->id,
                'issued_at' => $issuedAt,
                'payment_code' => $code,
                'transaction_ref' => $reference,
                'total' => $order->grandTotal()->getMinorAmount()->toInt(),
                'currency' => $order->currency,
                'data' => [
                    'seller' => [
                        'name' => $this->settings->string('seller_name'),
                        'eik' => $this->settings->string('eik'),
                        'address' => $this->settings->string('seller_address'),
                        'contact' => $this->settings->string('seller_contact'),
                    ],
                    'e_shop_number' => $this->settings->string('e_shop_number'),
                    'order_number' => (string) $order->number,
                    'customer' => $order->name,
                    'payment' => $this->paymentLabel($code),
                    'timezone' => $this->timezone(),
                    ...$snapshot,
                    'qr' => $type === SalesDocument::SALE ? $this->qrText($order, $reference, $issuedAt, $snapshot['total']) : null,
                ],
            ])->save();

            return $document;
        });

        if ($document->wasRecentlyCreated) {
            Notification::route('mail', $order->email)->notify(new SalesDocumentIssued($document));
            $document->forceFill(['emailed_at' => Carbon::now()])->saveQuietly();
        }

        return $document;
    }

    /**
     * The next sale document number, under a row lock so two payments at once never get
     * the same one (the unique index is the last guard).
     */
    private function nextNumber(): int
    {
        $first = max(1, (int) ($this->settings->get('first_number') ?? 1));
        // The row exists before it is locked, so two first documents cannot both insert it.
        DB::table('pnshop_n18_counters')->insertOrIgnore(['name' => self::COUNTER, 'last' => 0]);
        $row = DB::table('pnshop_n18_counters')->where('name', self::COUNTER)->lockForUpdate()->first();
        $next = max((int) ($row->last ?? 0) + 1, $first);

        if ($next > 9_999_999_999) {
            throw new \OverflowException('Н-18 sale document numbers are used up (10 digits).');
        }

        DB::table('pnshop_n18_counters')->where('name', self::COUNTER)->update(['last' => $next]);

        return $next;
    }

    /**
     * The QR code text of Приложение 18а for e-shop software:
     * <e-shop number>*<order number>*<transaction reference>*<YYYY-MM-DD>*<HH:MM:SS>*<total>.
     */
    private function qrText(Order $order, ?string $reference, Carbon $issuedAt, string $total): string
    {
        $local = $issuedAt->copy()->setTimezone($this->timezone());

        return implode('*', [$this->settings->string('e_shop_number'), (string) $order->number, (string) $reference, $local->format('Y-m-d'), $local->format('H:i:s'), $total]);
    }

    private function transactionReference(Order $order): ?string
    {
        $payment = $order->payments()->whereNotNull('reference')->latest('id')->first();

        return $payment?->reference;
    }

    private function paymentLabel(int $code): string
    {
        return match ($code) {
            1 => 'Банков превод',
            2 => 'Карта (виртуален ПОС)',
            3 => 'Наложен платеж (пощенски паричен превод)',
            4 => 'Доставчик на платежни услуги',
            6 => 'Фискален бон',
            default => 'Друг начин на плащане',
        };
    }

    private function timezone(): string
    {
        return (string) (app(Settings::class)->get('localization.timezone') ?: config('app.timezone', 'UTC'));
    }
}
