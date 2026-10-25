<?php

namespace PnShop\Extension;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\Extension\PermissionRegistry;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;
use Throwable;

/**
 * Boots the enabled plugins on every request from a cached list (no database query, no
 * scanning). Their code is autoloaded from their folders. In safe mode nothing is booted,
 * so a broken plugin can always be disabled or removed.
 */
final class PluginLoader
{
    private ?ClassLoader $classLoader = null;

    /** @var array<string, Manifest> id => manifest of booted plugins */
    private array $booted = [];

    /** @var array<class-string, Manifest> */
    private array $byProvider = [];

    /** @var list<string> */
    private array $failures = [];

    public function __construct(private Application $app) {}

    public static function cachePath(): string
    {
        return (string) config('pnshop.extensions.cache', base_path('bootstrap/cache/pnshop-plugins.php'));
    }

    public static function safeMode(): bool
    {
        return (bool) config('pnshop.extensions.safe_mode', false);
    }

    /**
     * Enabled plugins from the cache: id => manifest data.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enabled(): array
    {
        $file = self::cachePath();

        if (self::safeMode() || ! is_file($file)) {
            return [];
        }

        $data = require $file;

        return is_array($data) && is_array($data['plugins'] ?? null) ? $data['plugins'] : [];
    }

    /**
     * Storefront scripts of enabled plugins (public URLs with a version for cache busting).
     *
     * @return list<string>
     */
    public static function storefrontScripts(): array
    {
        $scripts = [];

        foreach (self::enabled() as $id => $plugin) {
            $script = $plugin['manifest']['storefront'] ?? null;

            if (is_string($script) && is_file(public_path('extensions/'.$id.'/'.basename($script)))) {
                $scripts[] = asset('extensions/'.$id.'/'.basename($script)).'?v='.rawurlencode((string) ($plugin['manifest']['version'] ?? ''));
            }
        }

        return $scripts;
    }

    /**
     * Admin screen folders of enabled plugins: [directory, namespace].
     *
     * @return list<array{string, string}>
     */
    public static function filamentDirectories(): array
    {
        $directories = [];

        foreach (self::enabled() as $plugin) {
            foreach ((array) ($plugin['autoload'] ?? []) as $namespace => $directory) {
                $filament = rtrim((string) $plugin['path'], '/').'/'.trim((string) $directory, '/').'/Filament';

                if (is_dir($filament)) {
                    $directories[] = [$filament, rtrim((string) $namespace, '\\').'\\Filament'];
                }
            }
        }

        return $directories;
    }

    /** Register and boot every enabled plugin. A plugin that fails to load is skipped and reported. */
    public function bootEnabled(): void
    {
        foreach (self::enabled() as $id => $data) {
            try {
                $this->boot(Manifest::fromArray($data['manifest'] ?? [], (string) ($data['path'] ?? '')));
            } catch (Throwable $e) {
                $this->failures[] = (string) $id;
                report($e);
            }
        }
    }

    /**
     * Make a plugin's classes loadable (before installing or booting it).
     */
    public function autoload(Manifest $manifest): void
    {
        $this->classLoader ??= tap(new ClassLoader, fn (ClassLoader $loader) => $loader->register(true));

        foreach ($manifest->autoload as $namespace => $directory) {
            $this->classLoader->addPsr4($namespace, $manifest->path.'/'.$directory);
        }
    }

    /** The plugin's provider instance (not registered). */
    public function provider(Manifest $manifest): Plugin
    {
        $this->autoload($manifest);

        $class = $manifest->provider;

        if (! class_exists($class) || ! is_subclass_of($class, Plugin::class)) {
            throw new Exceptions\ExtensionException(__('The provider :class of :id was not found or does not extend :base.', ['class' => $class, 'id' => $manifest->id, 'base' => Plugin::class]));
        }

        $provider = new $class($this->app);
        $provider->setManifest($manifest);
        $this->byProvider[$class] = $manifest;

        return $provider;
    }

    public function boot(Manifest $manifest): void
    {
        if (isset($this->booted[$manifest->id])) {
            return;
        }

        $provider = $this->provider($manifest);

        $this->app->make(PermissionRegistry::class)->register(...array_map(
            fn (array $permission) => new Permission($permission['key'], $permission['label'], $permission['group'] ?? 'Extensions'),
            $manifest->permissions,
        ));

        if ($manifest->settings !== []) {
            $this->app->make(SettingsRegistry::class)->register(new SettingsSchema(
                'plugin.'.$manifest->key(),
                $manifest->name,
                ...array_map(fn (array $setting) => new SettingDefinition(
                    (string) $setting['key'],
                    SettingType::from((string) $setting['type']),
                    (string) $setting['label'],
                    default: $setting['default'] ?? null,
                    required: (bool) ($setting['required'] ?? false),
                    help: isset($setting['help']) ? (string) $setting['help'] : null,
                    options: (array) ($setting['options'] ?? []),
                    // Extra validation rules, e.g. ["date_format:Y-m-d"] (since 1.11).
                    rules: array_values(array_filter((array) ($setting['rules'] ?? []), 'is_string')),
                ), $manifest->settings),
            ));
        }

        $this->app->register($provider);
        $this->booted[$manifest->id] = $manifest;
    }

    public function isBooted(string $id): bool
    {
        return isset($this->booted[$id]);
    }

    /**
     * @param  class-string  $provider
     */
    public function manifestFor(string $provider): ?Manifest
    {
        return $this->byProvider[$provider] ?? null;
    }

    /**
     * Plugins that failed to boot in this request.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Write the boot cache for these enabled plugins.
     *
     * @param  list<Manifest>  $manifests
     * @param  array<string, mixed>  $raw  id => decoded pnshop.json
     */
    public static function writeCache(array $manifests, array $raw): void
    {
        $plugins = [];

        foreach ($manifests as $manifest) {
            $plugins[$manifest->id] = [
                'path' => $manifest->path,
                'autoload' => $manifest->autoload,
                'manifest' => $raw[$manifest->id] ?? [],
            ];
        }

        $file = self::cachePath();
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        @mkdir(dirname($file), 0755, true);
        file_put_contents($temporary, '<?php return '.var_export(['plugins' => $plugins], true).';'.PHP_EOL, LOCK_EX);
        rename($temporary, $file);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
