<?php

namespace PnShop\Api\Http\Controllers\Store;

use PnShop\Api\Http\Controllers\ApiController;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Country;
use PnShop\Localization\Models\Language;
use PnShop\Settings\Settings;

class StoreController extends ApiController
{
    /**
     * Store information
     *
     * Name, contact details, languages, currency and the countries the store sells to.
     *
     * @return array<string, mixed>
     */
    public function show(Settings $settings, Localization $localization): array
    {
        // The channel's currency (prices are given in it).
        $currency = $localization->currency();

        return ['data' => [
            'name' => $settings->get('store.name'),
            'email' => $settings->get('store.email'),
            'phone' => $settings->get('store.phone'),
            'address' => $settings->get('store.address'),
            'locale' => app()->getLocale(),
            'default_locale' => $localization->defaultLocale(),
            'languages' => $localization->languages()->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->name,
            ])->values()->all(),
            'currency' => $currency->code,
            'countries' => Country::query()->where('is_active', true)->get()
                ->map(fn (Country $country) => ['code' => $country->code, 'name' => $country->name()])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ]];
    }
}
