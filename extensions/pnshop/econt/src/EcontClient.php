<?php

namespace PnShop\Plugins\Econt;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PnShop\Settings\Settings;

/**
 * Econt's JSON services (https://ee.econt.com/services/): POST with HTTP Basic auth.
 */
final class EcontClient
{
    public const PRODUCTION = 'https://ee.econt.com/services/';

    public const DEMO = 'https://demo.econt.com/ee/services/';

    public function __construct(private Settings $settings) {}

    public function baseUrl(): string
    {
        $override = config('services.econt.base_url');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/').'/';
        }

        return $this->setting('environment') === 'production' ? self::PRODUCTION : self::DEMO;
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->setting('username')) !== '' && trim((string) $this->setting('password')) !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws EcontException
     */
    public function call(string $service, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new EcontException('Econt is not set up: enter the username and password in the plugin settings.');
        }

        // After a failure, wait a minute before asking again: public pages (checkout, office
        // search) must not pile up slow calls while the courier is down.
        $down = 'pnshop.econt.down.'.md5($this->baseUrl());

        if (Cache::has($down)) {
            throw new EcontException('Econt did not answer a moment ago; trying again shortly.');
        }

        try {
            $response = Http::withBasicAuth((string) $this->setting('username'), (string) $this->setting('password'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post($this->baseUrl().ltrim($service, '/'), (object) $payload);
        } catch (ConnectionException $e) {
            Cache::put($down, true, 60);

            throw new EcontException('Econt did not answer: '.$e->getMessage(), previous: $e);
        }

        if ($response->serverError()) {
            Cache::put($down, true, 60);
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || (isset($data['type']) && isset($data['message']) && ! isset($data['label']))) {
            $message = is_array($data) ? trim((string) ($data['message'] ?? '')) : '';

            throw new EcontException('Econt refused the request'.($message !== '' ? ': '.$message : ' (HTTP '.$response->status().').'));
        }

        return $data;
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_econt.'.$key);
    }
}
