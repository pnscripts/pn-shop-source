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

    /**
     * The PEM certificate PayPal signs a webhook with, from PayPal's own domain only;
     * cached for a day.
     */
    public function certificate(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'paypal.com' || str_ends_with($host, '.paypal.com'))) {
            return null;
        }

        return Cache::remember('pnshop.paypal.cert.'.sha1($url), now()->addDay(), function () use ($url) {
            try {
                $response = Http::timeout(10)->get($url);
            } catch (ConnectionException) {
                return null;
            }

            return $response->successful() && str_contains($response->body(), 'BEGIN CERTIFICATE') ? $response->body() : null;
        });
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
