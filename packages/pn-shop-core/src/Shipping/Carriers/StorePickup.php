<?php

namespace PnShop\Shipping\Carriers;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingType;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingRequest;
use Throwable;

/**
 * Collect in person. With a stock location, the order's units are reserved there and
 * checkout says whether everything is in stock at that location.
 */
final class StorePickup extends Carrier
{
    public function code(): string
    {
        return 'pickup';
    }

    public function label(): string
    {
        return 'Pickup';
    }

    protected function carrierSettings(): array
    {
        return [
            new SettingDefinition('cost', SettingType::Decimal, 'Price', default: '0', rules: ['min:0']),
            new SettingDefinition('location', SettingType::Text, 'Pickup address and hours', help: 'Shown to the customer at checkout and on the order page.'),
            new SettingDefinition('stock_location', SettingType::Select, 'Stock location', help: 'Orders for pickup are served from this location, and checkout shows whether everything is in stock there. Empty: like any other delivery.', options: self::locationOptions()),
        ];
    }

    /**
     * The stock location a pickup method serves orders from, if any.
     */
    public static function stockLocation(?ShippingMethod $method): ?StockLocation
    {
        if ($method === null || $method->carrier !== 'pickup') {
            return null;
        }

        $code = $method->setting('stock_location');

        return is_string($code) && $code !== '' ? StockLocation::query()->where('code', $code)->where('is_active', true)->first() : null;
    }

    /**
     * @return array<string, string>
     */
    private static function locationOptions(): array
    {
        try {
            return StockLocation::query()->where('is_active', true)->ordered()->pluck('name', 'code')->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function quote(ShippingRequest $request, ShippingMethod $method): Money
    {
        return Money::of((string) ($method->setting('cost') ?: '0'), $request->currency(), roundingMode: RoundingMode::HalfUp);
    }

    public function trackingUrl(string $trackingNumber, ShippingMethod $method): ?string
    {
        return null;
    }
}
