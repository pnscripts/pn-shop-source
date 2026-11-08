<?php

namespace PnShop\Extension;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PnShop\Acl\PermissionSynchronizer;
use PnShop\Extension\Exceptions\ExtensionException;
use PnShop\Extension\Models\Extension;
use PnShop\Foundation\PnShop;
use PnShop\Settings\Models\Setting;
use PnShop\Settings\Settings;
use Throwable;

/**
 * Discovers plugins and moves them through their lifecycle:
 * install → enable → (update) → disable → uninstall.
 *
 * Every step validates first, records its outcome (status, error, audit log) and rebuilds
 * the boot cache. Migrations run by a failed install or update are rolled back.
 */
class ExtensionManager
{
    /** File types copied to public/extensions/<id>/ from a plugin's storefront folder. */
    private const STOREFRONT_ASSETS = ['js', 'mjs', 'css', 'map', 'json', 'woff', 'woff2', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'txt'];

    public function __construct(
        private PluginLoader $loader,
        private Migrator $migrator,
    ) {}

    public static function path(): string
    {
        return rtrim((string) config('pnshop.extensions.path', base_path('extensions')), '/');
    }

    /**
     * Every plugin found on disk (extensions/<vendor>/<name>) and in Composer packages of
     * type "pnshop-plugin", by id. Folders with an invalid manifest are listed in $invalid.
     *
     * @param  array<string, string>  $invalid  path => error, filled by reference
     * @return Collection<string, Manifest>
     */
    public function discover(array &$invalid = []): Collection
    {
        $directories = glob(self::path().'/*/*', GLOB_ONLYDIR) ?: [];

        foreach ($this->composerPackages() as $directory) {
            $directories[] = $directory;
        }

        $found = collect();

        foreach ($directories as $directory) {
            if (! is_file($directory.'/'.Manifest::FILE)) {
                continue;
            }

            try {
                $manifest = Manifest::fromDirectory($directory);
                $found->put($manifest->id, $manifest);
            } catch (ExtensionException $e) {
                $invalid[$directory] = $e->getMessage();
            }
        }

        return $found->sortKeys();
    }

    public function find(string $id): Manifest
    {
        return $this->discover()->get($id) ?? throw new ExtensionException(__('No plugin :id was found.', ['id' => $id]));
    }

    public function status(string $id): ExtensionStatus
    {
        return Extension::query()->find($id)->status ?? ExtensionStatus::Available;
    }

    /**
     * Problems that block installing or enabling the plugin (requirements, signature, collisions).
     *
     * @return list<string>
     */
    public function problems(Manifest $manifest): array
    {
        $problems = [];

        if ($manifest->pnshopConstraint !== null && ! Semver::satisfies(PnShop::VERSION, $manifest->pnshopConstraint)) {
            $problems[] = __('Needs PN Shop :constraint (this is :version).', ['constraint' => $manifest->pnshopConstraint, 'version' => PnShop::VERSION]);
        }

        if ($manifest->phpConstraint !== null && ! Semver::satisfies(PHP_VERSION, $manifest->phpConstraint)) {
            $problems[] = __('Needs PHP :constraint (this is :version).', ['constraint' => $manifest->phpConstraint, 'version' => PHP_VERSION]);
        }

        foreach ($manifest->requiredPlugins as $dependency => $constraint) {
            $installed = Extension::query()->find($dependency);

            if ($installed === null || $installed->status !== ExtensionStatus::Enabled || ! Semver::satisfies($installed->version, $constraint)) {
                $problems[] = __('Needs the plugin :id :constraint, installed and enabled.', ['id' => $dependency, 'constraint' => $constraint]);
            }
        }

        try {
            PackageIntegrity::verify($manifest->path);
        } catch (ExtensionException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    /**
     * Run the plugin's migrations and install hook. On failure everything this run did is rolled back.
     */
    public function install(string $id, ?Model $actor = null): Extension
    {
        $manifest = $this->find($id);
        $existing = Extension::query()->find($id);

        if ($existing !== null && $existing->status !== ExtensionStatus::Failed) {
            throw new ExtensionException(__(':id is already installed.', ['id' => $id]));
        }

        $this->assertNoProblems($manifest);

        $ran = [];

        try {
            $plugin = $this->loader->provider($manifest);
            $this->migrate($manifest, $ran);
            $plugin->install();
        } catch (Throwable $e) {
            $this->rollback($manifest, $ran);
            $this->record($manifest, ExtensionStatus::Failed, $e->getMessage());
            $this->audit('install_failed', $manifest, $actor, ['error' => $e->getMessage()]);

            throw new ExtensionException(__('Installing :id failed: :error', ['id' => $id, 'error' => $e->getMessage()]), previous: $e);
        }

        $extension = $this->record($manifest, ExtensionStatus::Installed, null, PackageIntegrity::checksums($manifest->path));
        $this->audit('installed', $manifest, $actor);

        return $extension;
    }

    public function enable(string $id, ?Model $actor = null): Extension
    {
        $manifest = $this->find($id);
        $extension = $this->installed($id);

        if ($extension->status === ExtensionStatus::Enabled) {
            return $extension;
        }

        if ($extension->status === ExtensionStatus::Failed) {
            throw new ExtensionException(__(':id failed to install. Install it again first.', ['id' => $id]));
        }

        if (Comparator::greaterThan($manifest->version, $extension->version)) {
            throw new ExtensionException(__('Version :version of :id is waiting to be installed. Update it first.', ['version' => $manifest->version, 'id' => $id]));
        }

        $this->assertNoProblems($manifest);

        $this->publishStorefront($manifest);
        $extension->forceFill(['status' => ExtensionStatus::Enabled, 'enabled_at' => now(), 'error' => null])->save();
        $this->rebuildCache();

        // Boot it now so its permissions exist for the synchronisation below.
        $this->loader->boot($manifest);
        app(PermissionSynchronizer::class)->sync();

        $this->audit('enabled', $manifest, $actor);

        return $extension;
    }

    /**
     * @throws ExtensionException when enabled plugins depend on it
     */
    public function disable(string $id, ?Model $actor = null): Extension
    {
        $extension = $this->installed($id);

        $dependants = $this->dependants($id);

        if ($dependants !== []) {
            throw new ExtensionException(__('Disable :plugins first: they need :id.', ['plugins' => implode(', ', $dependants), 'id' => $id]));
        }

        $extension->forceFill(['status' => ExtensionStatus::Disabled])->save();
        $this->unpublishStorefront($id);
        $this->rebuildCache();
        $this->audit('disabled', $extension, $actor);

        return $extension;
    }

    /**
     * Install a newer version that was placed in the plugin's folder: new migrations, then upgrade().
     */
    public function update(string $id, ?Model $actor = null): Extension
    {
        $manifest = $this->find($id);
        $extension = $this->installed($id);
        $from = $extension->version;

        if (! Comparator::greaterThan($manifest->version, $from)) {
            throw new ExtensionException(__(':id :version is already installed.', ['id' => $id, 'version' => $from]));
        }

        $this->assertNoProblems($manifest);

        $ran = [];

        try {
            $plugin = $this->loader->provider($manifest);
            $this->migrate($manifest, $ran);
            $plugin->upgrade($from, $manifest->version);
        } catch (Throwable $e) {
            $this->rollback($manifest, $ran);
            $extension->forceFill(['error' => $e->getMessage()])->save();
            $this->audit('update_failed', $manifest, $actor, ['from' => $from, 'error' => $e->getMessage()]);

            throw new ExtensionException(__('Updating :id failed: :error', ['id' => $id, 'error' => $e->getMessage()]), previous: $e);
        }

        $extension->forceFill([
            'name' => $manifest->name,
            'version' => $manifest->version,
            'error' => null,
            'checksums' => PackageIntegrity::checksums($manifest->path),
        ])->save();

        if ($extension->status === ExtensionStatus::Enabled) {
            $this->publishStorefront($manifest);
        }

        $this->rebuildCache();
        $this->audit('updated', $manifest, $actor, ['from' => $from]);

        return $extension;
    }

    /**
     * Remove the plugin from the shop. With $keepData false its migrations are rolled back
     * and its settings deleted. Its files stay on disk.
     */
    public function uninstall(string $id, bool $keepData = true, ?Model $actor = null): void
    {
        $extension = $this->installed($id);

        if ($extension->status === ExtensionStatus::Enabled) {
            throw new ExtensionException(__('Disable :id before uninstalling it.', ['id' => $id]));
        }

        $manifest = $this->discover()->get($id);

        if ($manifest !== null) {
            if ($extension->status !== ExtensionStatus::Failed) {
                $this->loader->provider($manifest)->uninstall($keepData);
            }

            if (! $keepData) {
                $this->rollback($manifest, array_values(array_map('strval', DB::table('extension_migrations')->where('extension_id', $id)->pluck('migration')->all())));
                Setting::query()->where('namespace', 'plugin.'.$manifest->key())->delete();
                app(Settings::class)->flush();
            }
        }

        DB::table('extension_migrations')->where('extension_id', $id)->delete();
        $this->unpublishStorefront($id);
        $extension->delete();
        $this->rebuildCache();
        $this->audit('uninstalled', $extension, $actor, ['keep_data' => $keepData]);
    }

    /**
     * Files changed since install.
     *
     * @return array{added: list<string>, changed: list<string>, removed: list<string>}
     */
    public function verify(string $id): array
    {
        return PackageIntegrity::compare($this->installed($id)->checksums ?? [], $this->find($id)->path);
    }

    /**
     * Copy the plugin's storefront script (and the files next to it, e.g. chunks) to
     * public/extensions/<id>/, where the storefront loads it.
     */
    public function publishStorefront(Manifest $manifest): void
    {
        $this->unpublishStorefront($manifest->id);

        if ($manifest->storefront === null) {
            return;
        }

        $target = public_path('extensions/'.$manifest->id);
        $source = dirname($manifest->path.'/'.$manifest->storefront);

        // Only static assets are published: never PHP or other server-side files.
        foreach (File::allFiles($source) as $file) {
            if (! in_array(strtolower($file->getExtension()), self::STOREFRONT_ASSETS, true)) {
                continue;
            }

            $destination = $target.'/'.$file->getRelativePathname();
            File::ensureDirectoryExists(dirname($destination));

            if (! File::copy($file->getPathname(), $destination)) {
                throw new ExtensionException(__('Could not publish the storefront files of :id.', ['id' => $manifest->id]));
            }
        }
    }

    public function unpublishStorefront(string $id): void
    {
        if (preg_match(Manifest::ID_PATTERN, $id) === 1) {
            File::deleteDirectory(public_path('extensions/'.$id));
        }
    }

    /** Rewrite the list of enabled plugins that is booted on every request. */
    public function rebuildCache(): void
    {
        $manifests = [];
        $raw = [];

        foreach (Extension::query()->where('status', ExtensionStatus::Enabled)->get() as $extension) {
            $manifest = $this->discover()->get($extension->id);

            if ($manifest !== null) {
                $manifests[] = $manifest;
                $raw[$manifest->id] = json_decode((string) file_get_contents($manifest->path.'/'.Manifest::FILE), true);
            }
        }

        PluginLoader::writeCache($manifests, $raw);

        // Cached routes and admin screens would still list (or miss) the plugin's routes and
        // pages. They are rebuilt on the next request; re-run `php artisan optimize` when ready.
        if (app()->routesAreCached()) {
            Artisan::call('route:clear');
        }

        Artisan::call('filament:clear-cached-components');
    }

    /**
     * Enabled plugins that require this one.
     *
     * @return list<string>
     */
    public function dependants(string $id): array
    {
        $enabled = Extension::query()->where('status', ExtensionStatus::Enabled)->pluck('id')->all();

        return array_values($this->discover()
            ->filter(fn (Manifest $manifest) => in_array($manifest->id, $enabled, true) && array_key_exists($id, $manifest->requiredPlugins))
            ->keys()
            ->all());
    }

    private function installed(string $id): Extension
    {
        return Extension::query()->find($id) ?? throw new ExtensionException(__(':id is not installed.', ['id' => $id]));
    }

    private function assertNoProblems(Manifest $manifest): void
    {
        $problems = $this->problems($manifest);

        if ($problems !== []) {
            throw new ExtensionException(implode(' ', $problems));
        }
    }

    /**
     * Run the plugin's pending migrations and remember them. Whatever ran is added to
     * $ran even when a later migration fails, so it can be rolled back.
     *
     * @param  list<string>  $ran
     */
    private function migrate(Manifest $manifest, array &$ran): void
    {
        if (! is_dir($manifest->migrationsPath())) {
            return;
        }

        $repository = $this->migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $own = array_keys($this->migrator->getMigrationFiles([$manifest->migrationsPath()]));
        $before = $repository->getRan();

        try {
            $this->migrator->run([$manifest->migrationsPath()]);
        } finally {
            $ran = array_values(array_intersect($own, array_diff($repository->getRan(), $before)));

            foreach ($ran as $migration) {
                DB::table('extension_migrations')->insertOrIgnore(['extension_id' => $manifest->id, 'migration' => $migration, 'created_at' => now()]);
            }
        }
    }

    /**
     * Roll back the given migrations of the plugin, newest first.
     *
     * @param  list<string>  $migrations
     */
    private function rollback(Manifest $manifest, array $migrations): void
    {
        if ($migrations === [] || ! is_dir($manifest->migrationsPath())) {
            return;
        }

        $files = $this->migrator->getMigrationFiles([$manifest->migrationsPath()]);

        foreach (array_reverse($migrations) as $migration) {
            if (! isset($files[$migration])) {
                continue;
            }

            // The migrator resolves anonymous and named migration classes alike, always to an object.
            $instance = (fn (string $path) => $this->resolvePath($path))->call($this->migrator, $files[$migration]);

            try {
                if (method_exists($instance, 'down')) {
                    $instance->down();
                }
            } finally {
                $this->migrator->getRepository()->delete((object) ['migration' => $migration]);
                DB::table('extension_migrations')->where('extension_id', $manifest->id)->where('migration', $migration)->delete();
            }
        }
    }

    /**
     * @param  array<string, string>|null  $checksums
     */
    private function record(Manifest $manifest, ExtensionStatus $status, ?string $error, ?array $checksums = null): Extension
    {
        return Extension::query()->updateOrCreate(['id' => $manifest->id], [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'status' => $status,
            'error' => $error,
            'checksums' => $checksums,
            'installed_at' => $status === ExtensionStatus::Failed ? null : now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, Manifest|Extension $plugin, ?Model $actor, array $properties = []): void
    {
        $id = $plugin instanceof Manifest ? $plugin->id : $plugin->id;
        $version = $plugin instanceof Manifest ? $plugin->version : $plugin->version;

        activity('extensions')
            ->causedBy($actor)
            ->withProperties(['extension' => $id, 'version' => $version, ...$properties])
            ->event($event)
            ->log(ucfirst(str_replace('_', ' ', $event))." {$id} {$version}");
    }

    /**
     * Install paths of Composer packages of type "pnshop-plugin".
     *
     * @return list<string>
     */
    private function composerPackages(): array
    {
        $installed = base_path('vendor/composer/installed.json');

        if (! is_file($installed)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($installed), true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        $paths = [];

        foreach ((array) $packages as $package) {
            if (is_array($package) && ($package['type'] ?? null) === 'pnshop-plugin' && is_string($package['install-path'] ?? null)) {
                $paths[] = (string) realpath(base_path('vendor/composer/'.$package['install-path']));
            }
        }

        return array_values(array_filter($paths));
    }
}
