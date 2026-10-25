<?php

namespace Tests\Feature\Extensions;

use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
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

        // MySQL commits implicitly on DDL (the plugin's migrations), ending the test's
        // transaction: open it again, or a later rolled-back savepoint (a refused checkout)
        // fails with "SAVEPOINT trans2 does not exist". What was committed (the plugin's
        // tables and record) is cleared by migrating afresh before the next test.
        $connection = DB::connection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->getPdo()->exec('START TRANSACTION');
            $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
        }
    }

    /**
     * Every admin resource of the plugin has a policy once it is enabled (the panel's
     * strict authorization would refuse the screen otherwise).
     */
    protected function assertScreensHavePolicies(string $id, string $namespace): void
    {
        $directory = base_path("extensions/{$id}/src/Filament/Resources");
        $resources = 0;

        foreach (File::allFiles($directory) as $file) {
            if (! str_ends_with($file->getFilename(), 'Resource.php')) {
                continue;
            }

            $class = $namespace.'\\Filament\\Resources\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            /** @var class-string<resource> $class */
            $this->assertNotNull(Gate::getPolicyFor($class::getModel()), "{$class} has no policy.");
            $resources++;
        }

        $this->assertGreaterThan(0, $resources);
    }
}
