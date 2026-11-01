<?php

namespace PnShop\Plugins\PayPal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PnShop\Settings\Settings;

/**
 * A minimal PayPal REST client: an OAuth token cached until it expires, JSON requests with
 * PayPal-Request-Id for idempotent writes, and PayPal's error on failure (never the secret).
 */
class PayPalClient
{
    public const LIVE = 'https://api-m.paypal.com';

    public const SANDBOX = 'https://api-m.sandbox.paypal.com';

    public function __construct(private Settings $settings) {}

    public function configured(): bool
    {
        return filled($this->settings->get('plugin.pnshop_paypal.client_id')) && filled($this->settings->get('plugin.pnshop_paypal.client_secret'));
    }

    public function base(): string
    {
        // For developers: another API address (a local stand-in or a proxy), set in
        // config/services.php as paypal.api_url. Shops never set it.
        $override = config('services.paypal.api_url');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        return $this->settings->get('plugin.pnshop_paypal.environment') === 'live' ? self::LIVE : self::SANDBOX;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, ?string $requestId = null, array $headers = []): array
    {
        return $this->send('post', $path, $body, [...$headers, ...($requestId !== null ? ['PayPal-Request-Id' => $requestId] : [])]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->send('get', $path, [], []);
    }

    /** The hosts PayPal serves its webhook signing certificates from. */
    public const CERT_HOSTS = ['api.paypal.com', 'api.sandbox.paypal.com', 'api-m.paypal.com', 'api-m.sandbox.paypal.com'];

    /**
     * The PEM certificate PayPal signs a webhook with: only from PayPal's API hosts, never
     * through a redirect, and only a certificate issued to a paypal.com name; cached for a day.
     */
    public function certificate(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, self::CERT_HOSTS, true)
            || parse_url($url, PHP_URL_PORT) !== null || parse_url($url, PHP_URL_USER) !== null) {
            return null;
        }

        return Cache::remember('pnshop.paypal.cert.'.sha1($url), now()->addDay(), function () use ($url) {
            try {
                $response = Http::timeout(10)->withoutRedirecting()->get($url);
            } catch (ConnectionException) {
                return null;
            }

            $pem = $response->status() === 200 ? $response->body() : '';

            return self::issuedToPayPal($pem) ? $pem : null;
        });
    }

    /** A parseable, currently valid certificate whose common name is a paypal.com host. */
    public static function issuedToPayPal(string $pem): bool
    {
        if (! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return false;
        }

        $info = @openssl_x509_parse($pem);

        if (! is_array($info)) {
            return false;
        }

        $name = strtolower((string) ($info['subject']['CN'] ?? ''));
        $now = time();

        return str_ends_with($name, '.paypal.com')
            && (int) ($info['validFrom_time_t'] ?? PHP_INT_MAX) <= $now
            && (int) ($info['validTo_time_t'] ?? 0) >= $now;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body, array $headers, bool $retried = false): array
    {
        $request = Http::withToken($this->token())->acceptJson()->timeout(20)->withHeaders($headers);

        try {
            $response = $method === 'get'
                ? $request->get($this->base().$path)
                : $request->withBody(json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR), 'application/json')->post($this->base().$path);
        } catch (ConnectionException) {
            throw new PayPalException(__('PayPal could not be reached. Please try again.'));
        }

        // An expired or revoked token: get a new one once.
        if ($response->status() === 401 && ! $retried) {
            Cache::forget($this->tokenKey());

            return $this->send($method, $path, $body, $headers, true);
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            throw $this->error($response, $data);
        }

        return $data;
    }

    private function token(): string
    {
        $id = (string) $this->settings->get('plugin.pnshop_paypal.client_id');
        $secret = (string) $this->settings->get('plugin.pnshop_paypal.client_secret');

        if ($id === '' || $secret === '') {
            throw new PayPalException(__('PayPal is not configured.'));
        }

        $cached = Cache::get($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::withBasicAuth($id, $secret)->asForm()->acceptJson()->timeout(20)
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new PayPalException(__('PayPal could not be reached. Please try again.'));
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new PayPalException('PayPal: the client ID or secret was refused (HTTP '.$response->status().').', status: $response->status());
        }

        // Kept a minute less than PayPal allows.
        Cache::put($this->tokenKey(), $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)));

        return $token;
    }

    private function tokenKey(): string
    {
        return 'pnshop.paypal.token.'.sha1($this->base().'|'.$this->settings->get('plugin.pnshop_paypal.client_id'));
    }

    private function error(Response $response, mixed $data): PayPalException
    {
        $issue = is_array($data) ? ($data['details'][0]['issue'] ?? $data['name'] ?? null) : null;
        $message = is_array($data) && is_string($data['details'][0]['description'] ?? $data['message'] ?? null)
            ? (string) ($data['details'][0]['description'] ?? $data['message'])
            : 'HTTP '.$response->status();

        return new PayPalException('PayPal: '.$message, is_string($issue) ? $issue : null, $response->status());
    }
}
