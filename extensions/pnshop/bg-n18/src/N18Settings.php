<?php

namespace PnShop\Plugins\BgN18;

use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;

/**
 * The plugin's settings, read in one place.
 */
final class N18Settings
{
    /** Payment codes of Приложение 38 (PAYM) that need a sale document (чл. 52о). */
    public const DOCUMENT_CODES = [2, 4];

    public const POSTAL_MONEY_ORDER = 3;

    public function __construct(private Settings $settings) {}

    public function get(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_bg_n18.'.$key);
    }

    public function string(string $key): string
    {
        return trim((string) $this->get($key));
    }

    /**
     * gateway => PAYM code (1–6).
     *
     * @return array<string, int>
     */
    public function paymentCodes(): array
    {
        $codes = [];

        foreach ($this->lines('payment_codes') as $gateway => $value) {
            $code = (int) $value;

            if ($code >= 1 && $code <= 6) {
                $codes[$gateway] = $code;
            }
        }

        return $codes;
    }

    /** The PAYM code of the order's payment, or 5 when the gateway is not listed. */
    public function paymentCodeFor(Order $order): int
    {
        return $this->paymentCodes()[$this->gatewayOf($order)] ?? 5;
    }

    public function gatewayOf(Order $order): string
    {
        $payment = $order->payments()->whereNotNull('reference')->latest('id')->first() ?? $order->payments()->latest('id')->first();

        return (string) ($payment->gateway ?? $order->paymentMethod->gateway ?? '');
    }

    /** The payment service provider's identifier and name for the gateway (PROC_ID). */
    public function providerFor(string $gateway): ?string
    {
        $provider = $this->lines('providers')[$gateway] ?? '';

        return $provider === '' ? null : $provider;
    }

    public function domain(): string
    {
        $domain = $this->string('domain');

        return $domain !== '' ? $domain : (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** Whether the seller data the documents need is filled in. */
    public function isComplete(): bool
    {
        foreach (['seller_name', 'eik', 'seller_address', 'seller_contact', 'e_shop_number'] as $key) {
            if ($this->string($key) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * "key=value" lines of a text setting.
     *
     * @return array<string, string>
     */
    private function lines(string $key): array
    {
        $result = [];

        foreach (preg_split('/\R/', (string) $this->get($key)) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$name, $value] = array_map('trim', explode('=', $line, 2));

                if ($name !== '') {
                    $result[$name] = $value;
                }
            }
        }

        return $result;
    }
}
