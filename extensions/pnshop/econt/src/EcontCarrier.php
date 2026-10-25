<?php

namespace PnShop\Plugins\Econt;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PnShop\Localization\CurrencyConverter;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingType;
use PnShop\Shipping\Carriers\Carrier;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingRequest;
use Throwable;

/**
 * Econt Express: the price of the parcel from Econt's label service (mode "calculate"),
 * to the customer's address or to an Econt office. Bulgaria only.
 *
 * When Econt cannot be asked (not set up, down, refuses), the method's fallback price is
 * used, or the method is not offered when it has none.
 */
final class EcontCarrier extends Carrier
{
    public const CODE = 'econt';

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
            'senderClient' => ['name' => $this->setting('sender_name') ?: 'Shop', 'phones' => array_filter([$this->setting('sender_phone')])],
            'receiverClient' => ['name' => $request->customer !== null ? (string) $request->customer->name : 'Customer'],
            'packCount' => 1,
            'shipmentType' => 'PACK',
            'weight' => $this->weight($request, $method),
        ];

        $senderOffice = trim((string) $this->setting('sender_office_code'));

        if ($senderOffice !== '') {
            $label['senderOfficeCode'] = $senderOffice;
        } else {
            $label['senderAddress'] = [
                'city' => ['country' => ['code3' => EcontLocations::COUNTRY], 'name' => (string) $this->setting('sender_city'), 'postCode' => (string) $this->setting('sender_postcode')],
                'street' => (string) $this->setting('sender_street'),
            ];
        }

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
        $default = (float) ($method->setting('default_weight') ?: 1);
        $grams = 0;

        foreach ($request->items as $item) {
            $grams += ($item->weight > 0 ? $item->weight : (int) round($default * 1000)) * $item->quantity;
        }

        return max(0.1, round($grams / 1000, 3));
    }

    private function setting(string $key): string
    {
        return trim((string) $this->settings->get('plugin.pnshop_econt.'.$key));
    }
}
