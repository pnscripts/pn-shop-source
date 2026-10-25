<?php

namespace PnShop\Plugins\Speedy;

use Illuminate\Support\Facades\Cache;

/**
 * Speedy's sites (towns) and offices in Bulgaria, cached: sites for a month, offices for
 * 12 hours.
 */
final class SpeedyLocations
{
    public function __construct(private SpeedyClient $client) {}

    /**
     * The town with this postcode.
     *
     * @return array{id: int, name: string}|null
     */
    public function siteByPostcode(string $postcode): ?array
    {
        $postcode = trim($postcode);

        if ($postcode === '' || preg_match('/^\d{4}$/', $postcode) !== 1) {
            return null;
        }

        return Cache::remember('pnshop.speedy.'.md5($this->client->baseUrl()).'.site.'.$postcode, now()->addMonth(), function () use ($postcode) {
            $sites = (array) ($this->client->call('location/site', ['countryId' => SpeedyClient::BULGARIA, 'postCode' => $postcode])['sites'] ?? []);
            $site = is_array($sites[0] ?? null) ? $sites[0] : null;

            return $site === null ? null : ['id' => (int) $site['id'], 'name' => (string) ($site['name'] ?? '')];
        });
    }

    /**
     * Offices in a town, or all of Bulgaria.
     *
     * @return list<array{id: int, name: string, address: string, site_id: int|null}>
     */
    public function offices(?int $siteId = null): array
    {
        return Cache::remember('pnshop.speedy.'.md5($this->client->baseUrl()).'.offices.'.($siteId ?? 'all'), now()->addHours(12), function () use ($siteId) {
            $payload = ['countryId' => SpeedyClient::BULGARIA];

            if ($siteId !== null) {
                $payload['siteId'] = $siteId;
            }

            $offices = [];

            foreach ((array) ($this->client->call('location/office', $payload)['offices'] ?? []) as $office) {
                if (! is_array($office) || ! isset($office['id'])) {
                    continue;
                }

                $offices[] = [
                    'id' => (int) $office['id'],
                    'name' => (string) ($office['name'] ?? $office['id']),
                    'address' => (string) ($office['address']['fullAddressString'] ?? ''),
                    'site_id' => isset($office['address']['siteId']) ? (int) $office['address']['siteId'] : ($office['siteId'] ?? $siteId),
                ];
            }

            return $offices;
        });
    }

    /**
     * @return array{id: int, name: string, address: string, site_id: int|null}|null
     */
    public function office(int $id): ?array
    {
        foreach ($this->offices() as $office) {
            if ($office['id'] === $id) {
                return $office;
            }
        }

        return null;
    }
}
