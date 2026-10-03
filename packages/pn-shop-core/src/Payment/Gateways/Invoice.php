<?php

namespace PnShop\Payment\Gateways;

use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingType;

/**
 * Pay later, on the invoice's terms (B2B). Usually limited to customer groups such as
 * Wholesale. Unpaid invoice orders are never cancelled automatically.
 */
final class Invoice extends ManualGateway
{
    public const CODE = 'invoice';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Invoice (pay later)';
    }

    /** Only for signed-in customers: the shop needs to know who to invoice. */
    public function isAvailable(PaymentContext $context, PaymentMethod $method): bool
    {
        return $context->customer !== null;
    }

    public function settings(): array
    {
        return [
            new SettingDefinition('terms_days', SettingType::Integer, 'Payment terms (days)', default: 30, required: true, rules: ['min:0', 'max:365']),
            new SettingDefinition('instructions', SettingType::Text, 'Instructions for the customer', default: __('Pay :amount within :days days of the invoice date, quoting :order.'), help: ':amount, :order and :days are replaced with the order total, its number and the payment terms.'),
        ];
    }

    public function instructions(Payment $payment, PaymentMethod $method): ?string
    {
        $text = parent::instructions($payment, $method);

        return $text === null ? null : str_replace(':days', (string) (int) $method->setting('terms_days'), $text);
    }
}
