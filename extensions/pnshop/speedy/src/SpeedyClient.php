<?php

namespace PnShop\Plugins\Speedy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

        $data = $this->send($path, $payload)->json();

        if (! is_array($data)) {
            throw new SpeedyException('Speedy refused the request.');
        }

        if (is_array($data['error'] ?? null)) {
            throw new SpeedyException('Speedy refused the request: '.trim((string) ($data['error']['message'] ?? 'unknown error')));
        }

        return $data;
    }

    /**
     * A document Speedy returns as a file (e.g. /print): the PDF bytes.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws SpeedyException
     */
    public function pdf(string $path, array $payload): string
    {
        if (! $this->isConfigured()) {
            throw new SpeedyException('Speedy is not set up: enter the API username and password in the plugin settings.');
        }

        $response = $this->send($path, $payload, 'application/pdf, application/json');
        $body = $response->body();

        if (! str_starts_with($body, '%PDF-')) {
            $data = $response->json();
            $message = is_array($data) && is_array($data['error'] ?? null) ? trim((string) ($data['error']['message'] ?? '')) : '';

            throw new SpeedyException('Speedy did not return the waybill'.($message !== '' ? ': '.$message : '.'));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SpeedyException when Speedy cannot be reached or answers with an HTTP error.
     */
    private function send(string $path, array $payload, string $accept = 'application/json'): Response
    {
        $body = ['userName' => (string) $this->setting('username'), 'password' => (string) $this->setting('password'), 'language' => app()->getLocale() === 'bg' ? 'BG' : 'EN', ...$payload];

        // After a failure, wait a minute before asking again: public pages (checkout, office
        // search) must not pile up slow calls while the courier is down.
        $down = 'pnshop.speedy.down.'.md5($this->baseUrl());

        if (Cache::has($down)) {
            throw new SpeedyException('Speedy did not answer a moment ago; trying again shortly.');
        }

        try {
            $response = Http::accept($accept)->asJson()->connectTimeout(5)->timeout($accept === 'application/json' ? 10 : 20)->post($this->baseUrl().'/'.ltrim($path, '/'), $body);
        } catch (ConnectionException $e) {
            Cache::put($down, true, 60);

            throw new SpeedyException('Speedy did not answer: '.$e->getMessage(), previous: $e);
        }

        if ($response->serverError()) {
            Cache::put($down, true, 60);
        }

        if (! $response->successful()) {
            $data = $response->json();
            $message = is_array($data) && is_array($data['error'] ?? null) ? trim((string) ($data['error']['message'] ?? '')) : '';

            throw new SpeedyException('Speedy refused the request'.($message !== '' ? ': '.$message : ' (HTTP '.$response->status().').'));
        }

        return $response;
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('plugin.pnshop_speedy.'.$key);
    }
}
