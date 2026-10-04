<?php

namespace PnShop\Localization;

use DateTimeZone;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Localization\Http\Middleware\LocalizeRequest;
use PnShop\Localization\Models\Country;
use PnShop\Localization\Models\Currency;
use PnShop\Localization\Models\Language;
use PnShop\Localization\Policies\LocalizationPolicy;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;

class LocalizationServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Localization::class);
        $this->app->scoped(CurrencyConverter::class);
    }

    protected function permissions(): array
    {
        return [
            new Permission('store.localization.manage', 'Manage languages, currencies and countries', 'Store'),
        ];
    }

    protected function bootModule(): void
    {
        $this->app->make(HttpKernel::class)->prependMiddleware(LocalizeRequest::class);

        foreach ([Language::class, Currency::class, Country::class] as $model) {
            Gate::policy($model, LocalizationPolicy::class);
        }

        $timezones = DateTimeZone::listIdentifiers();

        $this->app->make(SettingsRegistry::class)->register(new SettingsSchema(
            'localization',
            'Localization',
            new SettingDefinition(
                'timezone',
                SettingType::Select,
                'Store timezone',
                default: config('app.timezone', 'UTC'),
                required: true,
                help: 'Used for order dates, reports and scheduled publishing.',
                options: array_combine($timezones, $timezones),
            ),
        ));

        Inertia::share('localization', fn () => $this->sharedProps(request()));

        // Interface text for the current language, sent once per full page load.
        Inertia::share('translations', Inertia::once(fn () => $this->interfaceTranslations(app()->getLocale())));
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedProps(Request $request): array
    {
        $localization = $this->app->make(Localization::class);
        $locale = app()->getLocale();
        $base = substr($request->getBaseUrl(), 0, strlen($request->getBaseUrl()) - strlen((string) $request->attributes->get('locale_prefix', '')));
        $origin = $request->getSchemeAndHttpHost().$base;

        return [
            'locale' => $locale,
            'currency' => $localization->currency()->code,
            'languages' => $localization->languages()->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->native_name,
                'url' => $localization->alternateFor($language->code) ?? $localization->switchUrl($language->code, $origin, $request->getPathInfo(), $request->getQueryString()),
                'active' => $language->code === $locale,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function interfaceTranslations(string $locale): array
    {
        if ($locale === 'en') {
            return [];
        }

        // lang/<locale>.json plus the JSON translations of plugins (loadJsonTranslationsFrom).
        return app('translator')->getLoader()->load($locale, '*', '*');
    }
}
