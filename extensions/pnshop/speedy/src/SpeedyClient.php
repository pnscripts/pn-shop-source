<?php

namespace PnShop\Plugins\Speedy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PnShop\Settings\Settings;

/**
 * Speedy's REST API (https://api.speedy.bg/v1): JSON POSTs with the API user and password
 * in every body.
 */
final class SpeedyClient
{
    public const BASE_URL = 'https://api.speedy.bg/v1';

    /** Bulgaria in Speedy's country ids (ISO 3166 numeric). */
    public const BULGARIA = 100;

    public function __construct(private Settings $settings) {}

    public function baseUrl(): string
    {
        $override = config('services.speedy.base_url');

        return rtrim(is_string($override) && $override !== '' ? $override : self::BASE_URL, '/');
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->setting('username')) !== '' && trim((string) $this->setting('password')) !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SpeedyException
     */
    public function call(string $path, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new SpeedyException('Speedy is not set up: enter the API username and password in the plugin settings.');
        }

        $body = ['userName' => (string) $this->setting('username'), 'password' => (string) $this->setting('password'), 'language' => app()->getLocale() === 'bg' ? 'BG' : 'EN', ...$payload];

        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(10)->post($this->baseUrl().'/'.ltrim($path, '/'), $body);
        } catch (ConnectionException $e) {
            throw new SpeedyException('Speedy did not answer: '.$e->getMessage(), previous: $e);
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            throw new SpeedyException('Speedy refused the request (HTTP '.$response->status().').');
        }

        if (is_array($data['error'] ?? null)) {
            throw new SpeedyException('Speedy refused the request: '.trim((string) ($data['error']['message'] ?? 'unknown error')));
        }

        return $data;
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_speedy.'.$key);
    }
}
