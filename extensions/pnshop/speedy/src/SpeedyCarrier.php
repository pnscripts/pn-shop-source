<?php

namespace PnShop\Plugins\Speedy;

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
 * Speedy: the price from Speedy's /calculate, to the customer's address or to a Speedy
 * office. Bulgaria only. When Speedy cannot be asked, the method's fallback price is used,
 * or the method is not offered when it has none.
 */
final class SpeedyCarrier extends Carrier
{
    public const CODE = 'speedy';

    private const LEV_RATE = '1.95583';

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

        $calculation = [
            'recipient' => $recipient,
            'service' => ['autoAdjustPickupDate' => true, 'serviceIds' => [(int) ($this->setting('service_id') ?: 505)]],
            'content' => ['parcelsCount' => 1, 'totalWeight' => $this->weight($request, $method)],
            'payment' => ['courierServicePayer' => 'SENDER'],
        ];

        $dropoff = (int) $this->setting('dropoff_office_id');

        if ($dropoff > 0) {
            $calculation['sender'] = ['privatePerson' => false, 'dropoffOfficeId' => $dropoff];
        }

        return $calculation;
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
        $default = (float) ($method->setting('default_weight') ?: 1);
        $grams = 0;

        foreach ($request->items as $item) {
            $grams += ($item->weight > 0 ? $item->weight : (int) round($default * 1000)) * $item->quantity;
        }

        return max(0.1, round($grams / 1000, 3));
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_speedy.'.$key);
    }
}
