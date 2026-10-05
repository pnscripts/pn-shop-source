<?php

namespace PnShop\Plugins\PayPal;

use Illuminate\Http\Request;

/**
 * PayPal's webhook signature, checked locally: an RSA-SHA256 signature over
 * "transmission id|transmission time|webhook id|CRC32 of the raw body", made with the
 * certificate at PAYPAL-CERT-URL (accepted from paypal.com only).
 */
class WebhookSignature
{
    public static function valid(Request $request, string $webhookId, PayPalClient $paypal): bool
    {
        $id = (string) $request->header('PAYPAL-TRANSMISSION-ID');
        $time = (string) $request->header('PAYPAL-TRANSMISSION-TIME');
        $signature = base64_decode((string) $request->header('PAYPAL-TRANSMISSION-SIG'), true);
        $algorithm = strtoupper((string) $request->header('PAYPAL-AUTH-ALGO'));

        if ($webhookId === '' || $id === '' || $time === '' || $signature === false || $signature === '' || $algorithm !== 'SHA256WITHRSA') {
            return false;
        }

        $certificate = $paypal->certificate((string) $request->header('PAYPAL-CERT-URL'));

        if ($certificate === null) {
            return false;
        }

        $message = $id.'|'.$time.'|'.$webhookId.'|'.self::crc32($request->getContent());

        return openssl_verify($message, $signature, $certificate, OPENSSL_ALGO_SHA256) === 1;
    }

    /** The unsigned CRC32 of the raw body, in decimal. */
    public static function crc32(string $body): string
    {
        return sprintf('%u', crc32($body));
    }
}
