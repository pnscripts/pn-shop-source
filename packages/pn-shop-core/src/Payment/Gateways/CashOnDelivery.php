<?php

namespace PnShop\Payment\Gateways;

use PnShop\Payment\Models\PaymentMethod;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingType;

final class CashOnDelivery extends ManualGateway
{
    public const CODE = 'cash_on_delivery';

    /** Whether the courier collects the payment for orders paid with this method. */
    public static function collects(?PaymentMethod $method): bool
    {
        return $method !== null && $method->gateway === self::CODE;
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Cash on delivery';
    }

    public function settings(): array
    {
        return [
            new SettingDefinition('instructions', SettingType::Text, 'Instructions for the customer', default: __('Please have :amount ready when your order arrives.'), help: ':amount and :order are replaced with the order total and number.'),
        ];
    }
}
