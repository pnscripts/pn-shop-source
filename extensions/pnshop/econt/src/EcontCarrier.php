<?php

namespace PnShop\Plugins\Econt;

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
 * Econt Express: the price of the parcel from Econt's label service (mode "calculate"),
 * to the customer's address or to an Econt office. Bulgaria only.
 *
 * When Econt cannot be asked (not set up, down, refuses), the method's fallback price is
 * used, or the method is not offered when it has none. With cash on delivery, the price
 * includes Econt's fee for collecting the amount.
 *
 * Waybills: staff create them from the shipment form (mode "create"); Econt hosts the PDF.
 */
final class EcontCarrier extends Carrier implements ProvidesShippingLabels
{
    public const CODE = 'econt';

    /** Econt collects cash on delivery in Bulgaria's currency. */
    private const COD_CURRENCY = 'EUR';

    /** Fixed lev rate, for old answers in leva. */
    private const LEV_RATE = '1.95583';

    public function __construct(private EcontClient $client, private EcontLocations $locations, private SelectedOffice $selected, private Settings $settings) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Econt';
    }

    protected function carrierSettings(): array
    {
        return [
            new SettingDefinition('delivery', SettingType::Select, 'Deliver to', default: 'address', required: true, options: ['address' => "The customer's address", 'office' => 'An Econt office the customer picks']),
            new SettingDefinition('fallback_price', SettingType::Decimal, 'Price when Econt does not answer', help: 'Empty: the method is not offered while Econt cannot be asked.', rules: ['min:0']),
            new SettingDefinition('default_weight', SettingType::Decimal, 'Weight of products without one (kg)', default: '1', rules: ['min:0.01', 'max:1000']),
        ];
    }

    /** Econt's own tracking page unless the method sets another link. */
    public function trackingUrl(string $trackingNumber, ShippingMethod $method): string
    {
        return parent::trackingUrl($trackingNumber, $method) ?? 'https://www.econt.com/services/track-shipment/'.rawurlencode($trackingNumber);
    }

    public function quote(ShippingRequest $request, ShippingMethod $method): ?Money
    {
        if (strtoupper($request->countryCode) !== 'BG') {
            return null;
        }

        try {
            $label = $this->labelFor($request, $method);

            if ($label === null) {
                return $this->fallback($request, $method);
            }

            $answer = Cache::remember('pnshop.econt.quote.'.md5($this->client->baseUrl().json_encode($label)), now()->addMinutes(10), fn () => $this->client->call('Shipments/LabelService.createLabel.json', ['label' => $label, 'mode' => 'calculate']));

            return $this->price($answer, $request->currency()) ?? $this->fallback($request, $method);
        } catch (Throwable $e) {
            Log::warning('Econt price not available; using the fallback price.', ['error' => $e->getMessage(), 'method' => $method->id]);

            return $this->fallback($request, $method);
        }
    }

    /**
     * The label sent for the price, or null when the destination cannot be priced.
     *
     * @return array<string, mixed>|null
     */
    public function labelFor(ShippingRequest $request, ShippingMethod $method): ?array
    {
        $city = $this->locations->cityByPostcode((string) $request->postcode, $request->city);

        if ($city === null) {
            return null;
        }

        $label = [
            ...$this->sender(),
            'receiverClient' => ['name' => $request->customer !== null ? (string) $request->customer->name : 'Customer'],
            'packCount' => 1,
            'shipmentType' => 'PACK',
            'weight' => $this->weight($request, $method),
            ...$this->cashOnDelivery($request->cashOnDelivery),
        ];

        if ($method->setting('delivery') === 'office') {
            // The office the customer picked, or any office in their town until they pick one.
            $office = $this->selected->office();
            $office = $office !== null && $office['city_id'] === $city['id'] ? $office : ($this->locations->offices($city['id'])[0] ?? null);

            if ($office === null) {
                return null;
            }

            $label['receiverOfficeCode'] = $office['code'];
        } else {
            $label['receiverAddress'] = ['city' => ['id' => $city['id'], 'country' => ['code3' => EcontLocations::COUNTRY], 'name' => $city['name'], 'postCode' => $city['postCode']]];
        }

        return $label;
    }

    /**
     * Book the parcel with Econt (label service, mode "create").
     *
     * @throws EcontException when Econt is not set up, the address cannot be found or Econt refuses.
     */
    public function shippingLabel(ShippingLabelRequest $request, ShippingMethod $method): ShippingLabel
    {
        $label = $this->waybillFor($request, $method);
        $answer = $this->client->call('Shipments/LabelService.createLabel.json', ['label' => $label, 'mode' => 'create']);
        $number = trim((string) ($answer['label']['shipmentNumber'] ?? ''));

        if ($number === '') {
            throw new EcontException('Econt did not return a waybill number.');
        }

        // Econt hosts the PDF (the link carries its own key); the demo service answers with http.
        $pdf = (string) preg_replace('#^http://([a-z0-9.-]*\.)?econt\.com/#i', 'https://$1econt.com/', (string) ($answer['label']['pdfURL'] ?? ''));

        return new ShippingLabel($number, str_starts_with($pdf, 'https://') ? $pdf : null, price: $this->price($answer, $request->cashOnDelivery?->getCurrency()->getCurrencyCode() ?? 'EUR'));
    }

    /**
     * The label sent to create the waybill.
     *
     * @return array<string, mixed>
     *
     * @throws EcontException when the destination cannot be found.
     */
    public function waybillFor(ShippingLabelRequest $request, ShippingMethod $method): array
    {
        if ($request->cashOnDelivery !== null && $request->cashOnDelivery->getCurrency()->getCurrencyCode() !== self::COD_CURRENCY) {
            throw new EcontException('Econt collects cash on delivery in euro; this order is in '.$request->cashOnDelivery->getCurrency()->getCurrencyCode().'.');
        }

        $to = $request->to;
        $label = [
            ...$this->sender(),
            'receiverClient' => array_filter([
                'name' => mb_substr(($to->company !== null && $to->company !== '' ? $to->company.', ' : '').$to->fullName(), 0, 100),
                'phones' => array_filter([$to->phone]),
                'email' => $request->email,
            ]),
            'packCount' => 1,
            'shipmentType' => 'PACK',
            'weight' => $this->kilograms(array_map(fn (array $item) => [$item['weight'], $item['quantity']], $request->items), $method),
            'shipmentDescription' => $request->contents(),
            'orderNumber' => mb_substr($request->reference, 0, 50),
            ...$this->cashOnDelivery($request->cashOnDelivery),
        ];

        $office = $request->order !== null ? DB::table('pnshop_econt_order_offices')->where('order_id', $request->order->id)->value('office_code') : null;

        if ($method->setting('delivery') === 'office' || $office !== null) {
            if ($office === null) {
                throw new EcontException('The order has no Econt office.');
            }

            $label['receiverOfficeCode'] = (string) $office;

            return $label;
        }

        $city = $this->locations->cityByPostcode((string) $to->postcode, $to->city);

        if ($city === null) {
            throw new EcontException('Econt does not know the town of postcode '.$to->postcode.' ('.$to->city.').');
        }

        $label['receiverAddress'] = [
            'city' => ['id' => $city['id'], 'country' => ['code3' => EcontLocations::COUNTRY], 'name' => $city['name'], 'postCode' => $city['postCode']],
            'fullAddress' => trim($to->line1.($to->line2 !== null && $to->line2 !== '' ? ', '.$to->line2 : '')),
        ];

        return $label;
    }

    /**
     * Who sends the parcel: the shop, from its office or address.
     *
     * @return array<string, mixed>
     */
    private function sender(): array
    {
        $sender = ['senderClient' => ['name' => $this->setting('sender_name') ?: 'Shop', 'phones' => array_filter([$this->setting('sender_phone')])]];
        $office = trim((string) $this->setting('sender_office_code'));

        if ($office !== '') {
            $sender['senderOfficeCode'] = $office;
        } else {
            $sender['senderAddress'] = [
                'city' => ['country' => ['code3' => EcontLocations::COUNTRY], 'name' => (string) $this->setting('sender_city'), 'postCode' => (string) $this->setting('sender_postcode')],
                'street' => (string) $this->setting('sender_street'),
            ];
        }

        return $sender;
    }

    /**
     * Econt's cash-on-delivery service ("наложен платеж"): Econt collects the amount from
     * the receiver and charges a fee for it, which the price then includes. Euro only.
     *
     * @return array<string, mixed>
     */
    private function cashOnDelivery(?Money $amount): array
    {
        if ($amount === null || ! $amount->isPositive() || $amount->getCurrency()->getCurrencyCode() !== self::COD_CURRENCY) {
            return [];
        }

        return ['services' => [
            'cdAmount' => (float) (string) $amount->getAmount(),
            'cdType' => 'get',
            'cdCurrency' => $amount->getCurrency()->getCurrencyCode(),
        ]];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function price(array $answer, string $currency): ?Money
    {
        $total = $answer['label']['totalPrice'] ?? null;
        $from = strtoupper((string) ($answer['label']['currency'] ?? $currency));

        if (! is_numeric($total) || (float) $total < 0) {
            return null;
        }

        $amount = BigDecimal::of((string) $total);

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

    private function setting(string $key): string
    {
        return trim((string) $this->settings->get('plugin.pnshop_econt.'.$key));
    }
}
