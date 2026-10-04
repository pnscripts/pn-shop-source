<?php

namespace PnShop\Admin;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PnShop\Extension\PluginLoader;
use PnShop\Settings\Settings;

/**
 * The PN Shop administration panel. Each core module contributes resources, pages
 * and widgets from its own Filament/ directory.
 */
class AdminPanelProvider extends PanelProvider
{
    public const NAVIGATION_GROUPS = ['Catalog', 'Sales', 'Marketing', 'Content', 'Store', 'Appearance', 'Extensions', 'System'];

    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('admin')
            ->authPasswordBroker('admin_users')
            ->login()
            ->passwordReset()
            ->brandName(fn () => app(Settings::class)->get('store.name'))
            ->colors(['primary' => Color::Indigo])
            ->navigationGroups(array_map(
                fn (string $group) => NavigationGroup::make($group),
                self::NAVIGATION_GROUPS,
            ))
            ->pages([Dashboard::class])
            ->widgets([AccountWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);

        foreach (glob(dirname(__DIR__).'/*/Filament', GLOB_ONLYDIR) ?: [] as $directory) {
            $module = basename(dirname($directory));
            $namespace = "PnShop\\{$module}\\Filament";

            $panel
                ->discoverResources(in: "{$directory}/Resources", for: "{$namespace}\\Resources")
                ->discoverPages(in: "{$directory}/Pages", for: "{$namespace}\\Pages")
                ->discoverWidgets(in: "{$directory}/Widgets", for: "{$namespace}\\Widgets");
        }

        // Admin screens of enabled plugins (src/Filament/{Resources,Pages,Widgets}).
        foreach (PluginLoader::filamentDirectories() as [$directory, $namespace]) {
            $panel
                ->discoverResources(in: "{$directory}/Resources", for: "{$namespace}\\Resources")
                ->discoverPages(in: "{$directory}/Pages", for: "{$namespace}\\Pages")
                ->discoverWidgets(in: "{$directory}/Widgets", for: "{$namespace}\\Widgets");
        }

        return $panel;
    }
}
