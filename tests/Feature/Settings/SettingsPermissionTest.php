<?php

namespace Tests\Feature\Settings;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Extension\Exceptions\ExtensionException;
use PnShop\Extension\ExtensionManager;
use PnShop\Extension\Manifest;
use PnShop\Extension\PluginLoader;
use PnShop\Settings\Filament\Pages\ManageSettings;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingsRegistry;
use Tests\TestCase;

/**
 * Plugin settings that hold payment or courier credentials need the matching store
 * permission, not only "Manage settings": otherwise a settings editor could swap the shop's
 * Stripe key for their own account.
 */
class SettingsPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/admin/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pnshop.extensions.path' => base_path('extensions'),
            'pnshop.extensions.cache' => sys_get_temp_dir().'/pnshop-plugins-'.bin2hex(random_bytes(4)).'.php',
        ]);

        $manager = app(ExtensionManager::class);

        foreach (['pnshop/stripe', 'pnshop/econt'] as $plugin) {
            $manager->install($plugin);
            $manager->enable($plugin);
        }

        app(Settings::class)->set('plugin.pnshop_stripe', ['secret_key' => 'sk_test_owner', 'webhook_secret' => 'whsec_owner']);
    }

    public function test_plugins_declare_who_may_change_their_credentials(): void
    {
        $registry = app(SettingsRegistry::class);

        $this->assertSame('store.payment_methods.manage', $registry->schema('plugin.pnshop_stripe')?->permission());
        $this->assertSame('store.shipping.manage', $registry->schema('plugin.pnshop_econt')?->permission());
        $this->assertNull($registry->schema('store')?->permission());
    }

    public function test_a_settings_editor_without_payment_rights_cannot_replace_gateway_keys_in_the_admin(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->withPermissions(['system.settings.manage'])->create(), 'admin');

        $page = Livewire::test(ManageSettings::class)->assertDontSee('Webhook signing secret');
        $page->set('data.plugin__pnshop_stripe', ['secret_key' => 'sk_test_attacker', 'webhook_secret' => 'whsec_attacker'])
            ->call('save');

        $this->assertSame('sk_test_owner', app(Settings::class)->get('plugin.pnshop_stripe.secret_key'));
        $this->assertSame('whsec_owner', app(Settings::class)->get('plugin.pnshop_stripe.webhook_secret'));
    }

    public function test_staff_with_payment_rights_see_and_change_gateway_keys(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->withPermissions(['system.settings.manage', 'store.payment_methods.manage'])->create(), 'admin');

        Livewire::test(ManageSettings::class)
            ->set('data.plugin__pnshop_stripe.secret_key', 'sk_test_new')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk_test_new', app(Settings::class)->get('plugin.pnshop_stripe.secret_key'));
    }

    public function test_the_admin_api_applies_the_same_rule(): void
    {
        $settingsOnly = AdminUser::factory()->withPermissions(['system.settings.manage'])->create();
        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue($settingsOnly, 'test', ['*'])->plainTextToken];

        $this->withHeaders($headers)->getJson(self::API.'/settings')->assertOk()
            ->assertJsonMissing(['namespace' => 'plugin.pnshop_stripe'])
            ->assertJsonMissing(['namespace' => 'plugin.pnshop_econt'])
            ->assertJsonFragment(['namespace' => 'store']);
        $this->withHeaders($headers)->getJson(self::API.'/settings/plugin.pnshop_stripe')->assertForbidden();
        $this->withHeaders($headers)->patchJson(self::API.'/settings/plugin.pnshop_stripe', ['values' => ['secret_key' => 'sk_test_attacker']])->assertForbidden();
        $this->assertSame('sk_test_owner', app(Settings::class)->get('plugin.pnshop_stripe.secret_key'));

        $this->app['auth']->forgetGuards();
        $payments = AdminUser::factory()->withPermissions(['system.settings.manage', 'store.payment_methods.manage'])->create();
        $this->withHeaders(['Authorization' => 'Bearer '.app(StaffTokens::class)->issue($payments, 'test', ['*'])->plainTextToken])
            ->patchJson(self::API.'/settings/plugin.pnshop_stripe', ['values' => ['secret_key' => 'sk_test_new']])->assertOk();
        $this->assertSame('sk_test_new', app(Settings::class)->get('plugin.pnshop_stripe.secret_key'));
    }

    public function test_a_plugin_with_secrets_and_no_declared_permission_needs_extension_rights(): void
    {
        $manifest = fn (array $extra) => Manifest::fromArray([
            'id' => 'acme/keys', 'name' => 'Keys', 'version' => '1.0.0', 'type' => 'plugin',
            'provider' => 'Acme\\Keys\\KeysPlugin', 'autoload' => ['psr-4' => ['Acme\\Keys\\' => 'src/']],
            ...$extra,
        ], 'archive');

        $this->assertSame('system.extensions.manage', PluginLoader::settingsPermission($manifest(['settings' => [['key' => 'token', 'type' => 'secret', 'label' => 'Token']]])));
        $this->assertNull(PluginLoader::settingsPermission($manifest(['settings' => [['key' => 'label', 'type' => 'string', 'label' => 'Label']]])));
        $this->assertSame('acme.keys.manage', PluginLoader::settingsPermission($manifest(['settings_permission' => 'acme.keys.manage', 'settings' => [['key' => 'token', 'type' => 'secret', 'label' => 'Token']]])));

        $this->expectException(ExtensionException::class);
        $manifest(['settings_permission' => 'not a permission']);
    }
}
