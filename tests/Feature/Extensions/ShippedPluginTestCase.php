<?php

namespace Tests\Feature\Extensions;

use Illuminate\Support\Facades\File;
use PnShop\Extension\ExtensionManager;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Tests of a plugin shipped in extensions/pnshop: installs and enables it with its own
 * plugin cache and public folder.
 */
abstract class ShippedPluginTestCase extends AdminTestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicPath = sys_get_temp_dir().'/pnshop-public-'.bin2hex(random_bytes(4));
        $this->app->usePublicPath($this->publicPath);

        config([
            'pnshop.extensions.path' => base_path('extensions'),
            'pnshop.extensions.cache' => sys_get_temp_dir().'/pnshop-plugins-'.bin2hex(random_bytes(4)).'.php',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink((string) config('pnshop.extensions.cache'));
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    protected function enablePlugin(string $id): void
    {
        $manager = app(ExtensionManager::class);

        $this->assertSame([], $manager->problems($manager->find($id)), "{$id} has problems.");

        $manager->install($id);
        $manager->enable($id);
    }
}
