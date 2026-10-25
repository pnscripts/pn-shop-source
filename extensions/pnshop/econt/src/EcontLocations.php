<?php

namespace PnShop\Plugins\Econt;

use Illuminate\Support\Facades\Cache;

/**
 * Econt's cities and offices in Bulgaria (Nomenclatures), cached: cities for a week,
 * offices for 12 hours.
 */
final class EcontLocations
{
    public const COUNTRY = 'BGR';

    public function __construct(private EcontClient $client) {}

    /**
     * The city with this postcode.
     *
     * @return array{id: int, name: string, postCode: string}|null
     */
    public function cityByPostcode(string $postcode): ?array
    {
        $postcode = trim($postcode);

        if ($postcode === '') {
            return null;
        }

        foreach ($this->cities() as $city) {
            if ($city['postCode'] === $postcode) {
                return $city;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, name: string, postCode: string}>
     */
    public function cities(): array
    {
        return Cache::remember($this->key('cities'), now()->addWeek(), function () {
            $data = $this->client->call('Nomenclatures/NomenclaturesService.getCities.json', ['countryCode' => self::COUNTRY]);

            return array_values(array_map(fn (array $city) => [
                'id' => (int) ($city['id'] ?? 0),
                'name' => (string) ($city['name'] ?? ''),
                'postCode' => trim((string) ($city['postCode'] ?? '')),
            ], array_filter((array) ($data['cities'] ?? []), 'is_array')));
        });
    }

    /**
     * Offices in a city (or all of Bulgaria), parcel-receiving ones only.
     *
     * @return list<array{code: string, name: string, address: string, city_id: int|null}>
     */
    public function offices(?int $cityId = null): array
    {
        return Cache::remember($this->key('offices.'.($cityId ?? 'all')), now()->addHours(12), function () use ($cityId) {
            $payload = ['countryCode' => self::COUNTRY];

            if ($cityId !== null) {
                $payload['cityID'] = $cityId;
            }

            $data = $this->client->call('Nomenclatures/NomenclaturesService.getOffices.json', $payload);
            $offices = [];

            foreach ((array) ($data['offices'] ?? []) as $office) {
                if (! is_array($office) || trim((string) ($office['code'] ?? '')) === '') {
                    continue;
                }

                $address = is_array($office['address'] ?? null) ? (string) ($office['address']['fullAddress'] ?? '') : '';
                $offices[] = [
                    'code' => (string) $office['code'],
                    'name' => (string) ($office['name'] ?? $office['code']),
                    'address' => $address,
                    'city_id' => isset($office['address']['city']['id']) ? (int) $office['address']['city']['id'] : $cityId,
                ];
            }

            return $offices;
        });
    }

    /**
     * @return array{code: string, name: string, address: string, city_id: int|null}|null
     */
    public function office(string $code): ?array
    {
        foreach ($this->offices() as $office) {
            if ($office['code'] === $code) {
                return $office;
            }
        }

        return null;
    }

    /** Cache keys follow the environment, so demo data never answers production. */
    private function key(string $name): string
    {
        return 'pnshop.econt.'.md5($this->client->baseUrl()).'.'.$name;
    }
}
