<?php

namespace Tests\Feature\Themes;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PnShop\Foundation\PnShop;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;
use PnShop\Storefront\Http\Middleware\HandleInertiaRequests;
use PnShop\Theme\Filament\Pages\ManageThemes;
use PnShop\Theme\ThemeManager;
use PnShop\Theme\ThemeManifest;
use Tests\Feature\Admin\AdminTestCase;

class ThemesTest extends AdminTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pnshop-themes-'.bin2hex(random_bytes(4));
        File::copyDirectory(base_path('tests/Fixtures/themes'), $this->root.'/themes');
        File::ensureDirectoryExists($this->root.'/public');

        config(['pnshop.themes.path' => $this->root.'/themes']);
        $this->app->usePublicPath($this->root.'/public');
        $this->app->forgetInstance(ThemeManager::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_themes_are_discovered_and_problems_reported(): void
    {
        $invalid = [];
        $themes = app(ThemeManager::class);

        $this->assertSame(['acme/child', 'acme/orphan', 'pnshop/default'], $themes->discover($invalid)->keys()->all());
        $this->assertStringContainsString('type must be "theme"', (string) reset($invalid));
        $this->assertStringContainsString('acme/missing is missing', implode(' ', $themes->problems($themes->find('acme/orphan'))));
        $this->assertSame([], $themes->problems($themes->find('acme/child')));
        $this->assertSame('pnshop/default', $themes->active()->id);
    }

    public function test_a_theme_renders_on_the_server_with_its_own_bundle_and_never_publishes_it(): void
    {
        $themes = app(ThemeManager::class);
        $child = $themes->find('acme/child');

        $this->assertNull($child->ssrBundle(), 'Built without ssr/: rendered in the browser only.');
        $this->assertNull($themes->builtin()->ssrBundle(), 'The built-in storefront uses bootstrap/ssr.');

        File::ensureDirectoryExists($child->path.'/ssr');
        File::put($child->path.'/ssr/ssr.js', 'export default {};');
        $this->assertSame($child->path.'/ssr/ssr.js', $child->ssrBundle());

        // Publishing copies dist/ only: the server bundle never becomes a public file.
        $themes->publish($child);
        $this->assertFileDoesNotExist(public_path('themes/acme/child/build/ssr.js'));
        $this->assertSame([], File::glob(public_path('themes/acme/child/build').'/**/ssr*'));
    }

    public function test_the_built_in_storefront_comes_from_the_core_package(): void
    {
        // A 1.0 install keeps its old themes/pnshop/default folder; another theme claims to be built in.
        File::copyDirectory(PnShop::path('theme'), $this->root.'/themes/pnshop/default');
        File::ensureDirectoryExists($this->root.'/themes/acme/impostor');
        File::put($this->root.'/themes/acme/impostor/pnshop.json', (string) json_encode(['id' => 'acme/impostor', 'name' => 'Impostor', 'version' => '1.0.0', 'type' => 'theme', 'builtin' => true]));

        $themes = app(ThemeManager::class)->discover();

        $this->assertSame(PnShop::path('theme'), $themes->get('pnshop/default')?->path);
        $this->assertTrue($themes->get('pnshop/default')?->builtin);
        $this->assertFalse($themes->get('acme/impostor')?->builtin);
        $this->assertSame('vendor/pnshop/build', $themes->get('pnshop/default')?->buildDirectory());
    }

    public function test_the_storefront_loads_the_core_bundle_unless_the_project_builds_its_own(): void
    {
        $themes = app(ThemeManager::class);
        $default = $themes->builtin();
        $this->withVite();

        // The core's prebuilt bundle, published (as on install, update and composer update).
        $shipped = new ThemeManifest(ThemeManifest::DEFAULT, 'PN Shop Default', '1.1.0', $this->root.'/core', true);
        File::copyDirectory(base_path('tests/Fixtures/themes/acme/child/dist'), $this->root.'/core/dist');
        $themes->publish($shipped);

        $this->assertFileExists($this->root.'/public/vendor/pnshop/build/manifest.json');
        $this->assertSame('vendor/pnshop/build', $themes->bundle($default));
        $this->assertStringContainsString('/vendor/pnshop/build/assets/app-child.js', (string) $this->get('/')->assertOk()->getContent());

        // npm run build in the project (developing the core or the storefront) wins.
        File::copyDirectory($this->root.'/public/vendor/pnshop/build', $this->root.'/public/build');
        $this->assertNull($themes->bundle($default));
    }

    public function test_activating_a_theme_publishes_it_and_the_storefront_loads_its_bundle(): void
    {
        app(ThemeManager::class)->activate('acme/child');

        $this->assertFileExists($this->root.'/public/themes/acme/child/build/manifest.json');
        $this->app->forgetInstance(ThemeManager::class);
        $this->withVite();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('/themes/acme/child/build/assets/app-child.js', $html);
        $this->assertStringContainsString('/themes/acme/child/build/assets/app-child.css', $html);
        $this->assertStringContainsString(':root{--radius:1rem}', $html);
        // A light brand colour gets dark text.
        $this->assertStringContainsString(':root:not(.dark){--primary:#ffd400;--primary-foreground:#111111}', $html);
        $this->assertStringNotContainsString('/build/assets/app-', str_replace('/themes/acme/child/build/assets/app-', '', $html));
    }

    public function test_theme_settings_become_css_variables_and_props(): void
    {
        app(ThemeManager::class)->activate('acme/child');
        $this->app->forgetInstance(ThemeManager::class);
        $this->refreshThemeSettings();

        app(Settings::class)->set('theme.acme_child', ['primary' => '#1e3a8a', 'radius' => '0rem']);

        $this->assertStringContainsString(':root:not(.dark){--primary:#1e3a8a;--primary-foreground:#ffffff}', app(ThemeManager::class)->css());
        $this->get('/')->assertInertia(fn ($page) => $page->where('theme.id', 'acme/child')->where('theme.settings.radius', '0rem'));

        // Unsafe values never reach the CSS.
        $this->expectException(ValidationException::class);
        app(Settings::class)->set('theme.acme_child', ['primary' => 'red;}body{display:none']);
    }

    public function test_a_missing_or_unbuilt_active_theme_falls_back_to_the_default(): void
    {
        app(ThemeManager::class)->activate('acme/child');
        File::deleteDirectory($this->root.'/themes/acme/child/dist');
        $this->app->forgetInstance(ThemeManager::class);

        $this->assertSame('pnshop/default', app(ThemeManager::class)->active()->id);
        $this->get('/')->assertOk();

        $this->expectExceptionMessage('not built');
        app(ThemeManager::class)->activate('acme/child');
    }

    public function test_switching_themes_changes_the_asset_version(): void
    {
        $before = $this->get('/')->headers->get('X-Inertia-Version') ?? $this->inertiaVersion();
        app(ThemeManager::class)->activate('acme/child');
        $this->app->forgetInstance(ThemeManager::class);

        $this->assertNotSame($before, $this->inertiaVersion());
    }

    public function test_staff_activate_themes_in_the_admin_and_the_cli(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(ManageThemes::class)
            ->assertSee('Acme child')
            ->assertSee('acme/missing is missing')
            ->callTableAction('activate', 'acme/child');

        $this->assertSame('acme/child', app(Settings::class)->get('appearance.theme'));

        $this->artisan('pnshop:theme', ['action' => 'activate', 'id' => 'pnshop/default'])->assertSuccessful();
        $this->artisan('pnshop:theme', ['action' => 'activate', 'id' => 'acme/orphan'])->assertFailed();
        $this->artisan('pnshop:theme', ['action' => 'list'])->assertSuccessful()->expectsOutputToContain('acme/child');
    }

    public function test_contrast_colours(): void
    {
        $this->assertSame('#ffffff', ThemeManager::contrast('#000'));
        $this->assertSame('#111111', ThemeManager::contrast('#ffffff'));
        $this->assertSame('#ffffff', ThemeManager::contrast('#4f46e5'));
    }

    private function inertiaVersion(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }

    private function refreshThemeSettings(): void
    {
        $registry = app(SettingsRegistry::class);
        $theme = app(ThemeManager::class)->active();
        $registry->register(new SettingsSchema('theme.'.$theme->key(), 'Theme', ...array_map(
            fn (array $setting) => new SettingDefinition($setting['key'], SettingType::from($setting['type']), $setting['label'], default: $setting['default'] ?? null, options: $setting['options'] ?? []),
            app(ThemeManager::class)->settingsFor($theme),
        )));
    }
}
