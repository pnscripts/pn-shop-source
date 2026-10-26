<?php

namespace PnShop\Plugins\Speedy;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PnShop\Localization\CurrencyConverter;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingType;
use PnShop\Shipping\Carriers\Carrier;
use PnShop\Shipping\Contracts\ProvidesShippingLabels;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingLabel;
use PnShop\Shipping\ShippingLabelRequest;
use PnShop\Shipping\ShippingRequest;
use Throwable;

/**
 * Speedy: the price from Speedy's /calculate, to the customer's address or to a Speedy
 * office. Bulgaria only. When Speedy cannot be asked, the method's fallback price is used,
 * or the method is not offered when it has none. With cash on delivery, the price includes
 * Speedy's fee for collecting the amount.
 *
 * Waybills: staff create them from the shipment form (/shipment, then /print); the PDF is
 * kept with the shipment.
 */
final class SpeedyCarrier extends Carrier implements ProvidesShippingLabels
{
    public const CODE = 'speedy';

    private const LEV_RATE = '1.95583';

    /** Speedy collects cash on delivery in Bulgaria's currency. */
    private const COD_CURRENCY = 'EUR';

    public function __construct(private SpeedyClient $client, private SpeedyLocations $locations, private SelectedOffice $selected, private Settings $settings) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Speedy';
    }

    protected function carrierSettings(): array
    {
        return [
            new SettingDefinition('delivery', SettingType::Select, 'Deliver to', default: 'address', required: true, options: ['address' => "The customer's address", 'office' => 'A Speedy office the customer picks']),
            new SettingDefinition('fallback_price', SettingType::Decimal, 'Price when Speedy does not answer', help: 'Empty: the method is not offered while Speedy cannot be asked.', rules: ['min:0']),
            new SettingDefinition('default_weight', SettingType::Decimal, 'Weight of products without one (kg)', default: '1', rules: ['min:0.01', 'max:1000']),
            new SettingDefinition('waybill_paper', SettingType::Select, 'Waybill paper', default: 'A4', required: true, options: ['A4' => 'A4 page', 'A6' => 'A6 sticker']),
        ];
    }

    /** Speedy's own tracking page unless the method sets another link. */
    public function trackingUrl(string $trackingNumber, ShippingMethod $method): string
    {
        return parent::trackingUrl($trackingNumber, $method) ?? 'https://www.speedy.bg/bg/track-shipment?shipmentNumber='.rawurlencode($trackingNumber);
    }

    public function quote(ShippingRequest $request, ShippingMethod $method): ?Money
    {
        if (strtoupper($request->countryCode) !== 'BG') {
            return null;
        }

        try {
            $calculation = $this->calculationFor($request, $method);

            if ($calculation === null) {
                return $this->fallback($request, $method);
            }

            $answer = Cache::remember('pnshop.speedy.quote.'.md5($this->client->baseUrl().json_encode($calculation)), now()->addMinutes(10), fn () => $this->client->call('calculate', $calculation));

            return $this->price($answer, $request->currency()) ?? $this->fallback($request, $method);
        } catch (Throwable $e) {
            Log::warning('Speedy price not available; using the fallback price.', ['error' => $e->getMessage(), 'method' => $method->id]);

            return $this->fallback($request, $method);
        }
    }

    /**
     * The /calculate request (without credentials), or null when the destination cannot
     * be priced.
     *
     * @return array<string, mixed>|null
     */
    public function calculationFor(ShippingRequest $request, ShippingMethod $method): ?array
    {
        $site = $this->locations->siteByPostcode((string) $request->postcode);

        if ($site === null) {
            return null;
        }

        if ($method->setting('delivery') === 'office') {
            // The office the customer picked, or any office in their town until they pick one.
            $office = $this->selected->office();
            $office = $office !== null && $office['site_id'] === $site['id'] ? $office : ($this->locations->offices($site['id'])[0] ?? null);

            if ($office === null) {
                return null;
            }

            $recipient = ['privatePerson' => true, 'pickupOfficeId' => $office['id']];
        } else {
            $recipient = ['privatePerson' => true, 'addressLocation' => ['countryId' => SpeedyClient::BULGARIA, 'siteId' => $site['id']]];
        }

        return [
            'recipient' => $recipient,
            'service' => ['autoAdjustPickupDate' => true, 'serviceIds' => [$this->serviceId()], ...$this->cashOnDelivery($request->cashOnDelivery)],
            'content' => ['parcelsCount' => 1, 'totalWeight' => $this->weight($request, $method)],
            'payment' => ['courierServicePayer' => 'SENDER'],
            ...$this->sender(),
        ];
    }

    /**
     * Book the parcel with Speedy (/shipment) and fetch its waybill (/print).
     *
     * @throws SpeedyException when Speedy is not set up, the address cannot be found or Speedy refuses.
     */
    public function shippingLabel(ShippingLabelRequest $request, ShippingMethod $method): ShippingLabel
    {
        $answer = $this->client->call('shipment', $this->shipmentFor($request, $method));
        $number = trim((string) ($answer['id'] ?? ''));
        $parcels = array_values(array_filter(array_map(fn ($parcel) => is_array($parcel) ? (string) ($parcel['id'] ?? '') : '', (array) ($answer['parcels'] ?? []))));

        if ($number === '') {
            throw new SpeedyException('Speedy did not return a shipment number.');
        }

        // The parcel is booked now: a failed print must not lose it (staff would book it
        // again). Without the PDF, staff print the waybill on Speedy's website.
        try {
            $pdf = $this->client->pdf('print', [
                'paperSize' => $method->setting('waybill_paper') === 'A6' ? 'A6' : 'A4',
                'parcels' => array_map(fn (string $id) => ['parcel' => ['id' => $id]], $parcels === [] ? [$number] : $parcels),
            ]);
        } catch (SpeedyException $e) {
            Log::warning('Speedy booked the parcel but did not return the waybill.', ['shipment' => $number, 'error' => $e->getMessage()]);
            $pdf = null;
        }

        $currency = $request->cashOnDelivery?->getCurrency()->getCurrencyCode() ?? 'EUR';
        $price = is_array($answer['price'] ?? null) ? $this->price(['calculations' => [['price' => $answer['price']]]], $currency) : null;

        return new ShippingLabel($number, pdf: $pdf, price: $price);
    }

    /**
     * The /shipment request (without credentials).
     *
     * @return array<string, mixed>
     *
     * @throws SpeedyException when the destination cannot be found.
     */
    public function shipmentFor(ShippingLabelRequest $request, ShippingMethod $method): array
    {
        if ($request->cashOnDelivery !== null && $request->cashOnDelivery->getCurrency()->getCurrencyCode() !== self::COD_CURRENCY) {
            throw new SpeedyException('Speedy collects cash on delivery in euro; this order is in '.$request->cashOnDelivery->getCurrency()->getCurrencyCode().'.');
        }

        $to = $request->to;
        $company = $to->company !== null && $to->company !== '';
        $phone = trim((string) preg_replace('/[^\d+ ]/', '', (string) $to->phone));
        $recipient = array_filter([
            'clientName' => mb_substr($company ? (string) $to->company : $to->fullName(), 0, 60),
            'contactName' => $company ? mb_substr($to->fullName(), 0, 60) : null,
            'privatePerson' => ! $company,
            'phone1' => $phone !== '' ? ['number' => mb_substr($phone, 0, 20)] : null,
            'email' => $request->email,
        ], fn ($value) => $value !== null);

        $office = $request->order !== null ? DB::table('pnshop_speedy_order_offices')->where('order_id', $request->order->id)->value('office_id') : null;

        if ($method->setting('delivery') === 'office' || $office !== null) {
            if ($office === null) {
                throw new SpeedyException('The order has no Speedy office.');
            }

            $recipient['pickupOfficeId'] = (int) $office;
        } else {
            $site = $this->locations->siteByPostcode((string) $to->postcode);

            if ($site === null) {
                throw new SpeedyException('Speedy does not know the town of postcode '.$to->postcode.'.');
            }

            $recipient['address'] = [
                'countryId' => SpeedyClient::BULGARIA,
                'siteId' => $site['id'],
                'postCode' => (string) $to->postcode,
                'addressNote' => mb_substr(trim($to->line1.($to->line2 !== null && $to->line2 !== '' ? ', '.$to->line2 : '')), 0, 200),
            ];
        }

        return [
            'recipient' => $recipient,
            'service' => ['autoAdjustPickupDate' => true, 'serviceId' => $this->serviceId(), ...$this->cashOnDelivery($request->cashOnDelivery)],
            'content' => [
                'parcelsCount' => 1,
                'totalWeight' => $this->kilograms(array_map(fn (array $item) => [$item['weight'], $item['quantity']], $request->items), $method),
                'contents' => $request->contents(100),
                'package' => 'BOX',
            ],
            'payment' => ['courierServicePayer' => 'SENDER'],
            'ref1' => mb_substr($request->reference, 0, 30),
            ...$this->sender(),
        ];
    }

    private function serviceId(): int
    {
        return (int) ($this->setting('service_id') ?: 505);
    }

    /**
     * The shop's drop-off office, when it hands parcels in at one.
     *
     * @return array<string, mixed>
     */
    private function sender(): array
    {
        $dropoff = (int) $this->setting('dropoff_office_id');

        return $dropoff > 0 ? ['sender' => ['privatePerson' => false, 'dropoffOfficeId' => $dropoff]] : [];
    }

    /**
     * Speedy's cash-on-delivery service: Speedy collects the amount in cash and charges a
     * fee for it, which the price then includes. Euro only.
     *
     * @return array<string, mixed>
     */
    private function cashOnDelivery(?Money $amount): array
    {
        if ($amount === null || ! $amount->isPositive() || $amount->getCurrency()->getCurrencyCode() !== self::COD_CURRENCY) {
            return [];
        }

        return ['additionalServices' => ['cod' => [
            'amount' => (float) (string) $amount->getAmount(),
            'currencyCode' => $amount->getCurrency()->getCurrencyCode(),
            'processingType' => 'CASH',
        ]]];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function price(array $answer, string $currency): ?Money
    {
        $calculation = is_array($answer['calculations'][0] ?? null) ? $answer['calculations'][0] : null;

        if ($calculation === null || is_array($calculation['error'] ?? null) || ! is_numeric($calculation['price']['total'] ?? null)) {
            return null;
        }

        $amount = BigDecimal::of((string) $calculation['price']['total']);
        $from = strtoupper((string) ($calculation['price']['currency'] ?? $currency));

        if ($from === $currency) {
            return Money::of($amount, $currency, roundingMode: RoundingMode::HalfUp);
        }

        if ($from === 'BGN' && $currency === 'EUR') {
            return Money::of($amount->dividedBy(self::LEV_RATE, 2, RoundingMode::HalfUp), 'EUR');
        }

        return app(CurrencyConverter::class)->convert(Money::of($amount, $from, roundingMode: RoundingMode::HalfUp), $currency);
    }

    private function fallback(ShippingRequest $request, ShippingMethod $method): ?Money
    {
        $price = $method->setting('fallback_price');

        return $price === null || $price === '' ? null : app(CurrencyConverter::class)->fromDefault((string) $price, $request->currency());
    }

    /** Kilograms, at least 0.1. */
    private function weight(ShippingRequest $request, ShippingMethod $method): float
    {
        return $this->kilograms($request->items->map(fn ($item) => [(int) $item->weight, (int) $item->quantity])->all(), $method);
    }

    /**
     * Kilograms of [grams, quantity] lines, at least 0.1; lines without a weight count the
     * method's default weight.
     *
     * @param  array<int, array{int, int}>  $lines
     */
    private function kilograms(array $lines, ShippingMethod $method): float
    {
        $default = (int) round((float) ($method->setting('default_weight') ?: 1) * 1000);
        $grams = 0;

        foreach ($lines as [$weight, $quantity]) {
            $grams += ($weight > 0 ? $weight : $default) * $quantity;
        }

        return max(0.1, round($grams / 1000, 3));
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_speedy.'.$key);
    }
}
