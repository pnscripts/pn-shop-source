<?php

namespace PnShop\Theme;

use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;
use PnShop\Theme\Console\ThemeCommand;
use Throwable;

/**
 * Storefront themes: the active theme's bundle, settings and CSS variables.
 */
class ThemeServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Scoped: one per request or queued job, so long-running workers see newly added themes.
        $this->app->scoped(ThemeManager::class);
    }

    protected function permissions(): array
    {
        return [new Permission('appearance.themes.manage', 'Choose and configure the storefront theme', 'Appearance')];
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(SettingsRegistry::class);
        $registry->register((new SettingsSchema('appearance', 'Appearance', new SettingDefinition('theme', SettingType::String, 'Active theme', default: ThemeManifest::DEFAULT)))->hidden());

        $this->registerActiveThemeSettings($registry);

        // Server-side rendering must use the active theme's own bundle (its markup); a theme
        // built without one is rendered in the browser only.
        try {
            $theme = $this->app->make(ThemeManager::class)->active();

            if (! $theme->builtin) {
                $bundle = $theme->ssrBundle();
                config($bundle === null ? ['inertia.ssr.enabled' => false] : ['inertia.ssr.bundle' => $bundle]);
            }
        } catch (Throwable) {
            // No database yet (installing): the built-in theme is used.
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ThemeCommand::class]);
        }

        // The root template loads the active theme's bundle (or the built-in one) and its CSS variables.
        View::composer('pnshop::app', function ($view): void {
            $themes = app(ThemeManager::class);
            $theme = $themes->active();

            $view->with([
                'themeBuild' => $themes->bundle($theme),
                'themeEntries' => $theme->entries,
                'themeCss' => $themes->css(),
            ]);
        });

        Inertia::share('theme', function () {
            $themes = app(ThemeManager::class);
            $theme = $themes->active();

            return [
                'id' => $theme->id,
                'settings' => collect($themes->settingsFor($theme))
                    ->mapWithKeys(fn (array $setting) => [$setting['key'] => $this->safeSetting($theme, $setting['key'])])
                    ->all(),
            ];
        });
    }

    private function registerActiveThemeSettings(SettingsRegistry $registry): void
    {
        try {
            $themes = $this->app->make(ThemeManager::class);
            $theme = $themes->active();
            $settings = $themes->settingsFor($theme);
        } catch (Throwable) {
            return;
        }

        if ($settings === []) {
            return;
        }

        $registry->register(new SettingsSchema(
            'theme.'.$theme->key(),
            'Theme: '.$theme->name,
            ...array_map(fn (array $setting) => new SettingDefinition(
                (string) $setting['key'],
                SettingType::from((string) $setting['type']),
                (string) $setting['label'],
                default: $setting['default'] ?? null,
                required: (bool) ($setting['required'] ?? false),
                help: isset($setting['help']) ? (string) $setting['help'] : null,
                options: (array) ($setting['options'] ?? []),
            ), $settings),
        ));
    }

    private function safeSetting(ThemeManifest $theme, string $key): mixed
    {
        try {
            return $this->app->make(Settings::class)->get('theme.'.$theme->key().'.'.$key);
        } catch (Throwable) {
            return null;
        }
    }
}
