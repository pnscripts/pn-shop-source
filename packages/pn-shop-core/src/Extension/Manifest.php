<?php

namespace PnShop\Extension;

use Composer\Semver\VersionParser;
use PnShop\Extension\Exceptions\ExtensionException;
use PnShop\Settings\SettingType;
use Throwable;

/**
 * A plugin's pnshop.json, validated before any of the plugin's code is loaded.
 *
 *     {
 *       "id": "acme/store-notice",
 *       "name": "Store notice",
 *       "version": "1.0.0",
 *       "type": "plugin",
 *       "requires": { "pnshop": "^1.1", "php": ">=8.4", "plugins": { "acme/core": "^1.0" } },
 *       "provider": "Acme\\StoreNotice\\StoreNoticePlugin",
 *       "autoload": { "psr-4": { "Acme\\StoreNotice\\": "src/" } },
 *       "permissions": [{ "key": "store_notice.manage", "label": "Manage the store notice" }],
 *       "settings": [{ "key": "text", "type": "string", "label": "Notice text" }],
 *       "settings_permission": "store_notice.manage",
 *       "storefront": "dist/storefront.js"
 *     }
 *
 * "storefront" is a prebuilt ES module loaded on every storefront page after the app; it
 * uses window.PnShop (React, Inertia, registerBlock, registerSlot) instead of bundling React.
 */
final readonly class Manifest
{
    public const FILE = 'pnshop.json';

    public const ID_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?\/[a-z0-9]([a-z0-9-]*[a-z0-9])?$/';

    /**
     * @param  array<string, string>  $requiredPlugins  id => version constraint
     * @param  array<string, string>  $autoload  namespace prefix => directory relative to the plugin
     * @param  list<array{key: string, label: string, group?: string}>  $permissions
     * @param  list<array<string, mixed>>  $settings
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $path,
        public string $provider,
        public ?string $description = null,
        public ?string $pnshopConstraint = null,
        public ?string $phpConstraint = null,
        public array $requiredPlugins = [],
        public array $autoload = [],
        public array $permissions = [],
        public array $settings = [],
        public ?string $author = null,
        public ?string $license = null,
        public ?string $storefront = null,
        public ?string $settingsPermission = null,
    ) {}

    /**
     * @throws ExtensionException when the file is missing or invalid
     */
    public static function fromDirectory(string $directory): self
    {
        $file = rtrim($directory, '/').'/'.self::FILE;

        if (! is_file($file)) {
            throw new ExtensionException(__('No :file found in :path.', ['file' => self::FILE, 'path' => $directory]));
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ExtensionException(__(':file in :path is not valid JSON.', ['file' => self::FILE, 'path' => $directory]));
        }

        return self::fromArray(is_array($data) ? $data : [], $directory);
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws ExtensionException listing every problem found
     */
    public static function fromArray(array $data, string $path): self
    {
        $errors = [];
        $string = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $requires = is_array($data['requires'] ?? null) ? $data['requires'] : [];
        $parser = new VersionParser;

        $id = $string('id');
        if ($id === null || preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[] = 'id must look like "vendor/name" (lowercase letters, digits and dashes).';
        }

        if ($string('name') === null) {
            $errors[] = 'name is required.';
        }

        $version = $string('version');
        try {
            $parser->normalize((string) $version);
        } catch (Throwable) {
            $errors[] = 'version must be a semantic version such as 1.0.0.';
        }

        if (($data['type'] ?? 'plugin') !== 'plugin') {
            $errors[] = 'type must be "plugin".';
        }

        $provider = $string('provider');
        if ($provider === null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $provider) !== 1) {
            $errors[] = 'provider must be a fully qualified class name.';
        }

        $constraints = [];
        foreach (['pnshop', 'php'] as $key) {
            if (isset($requires[$key])) {
                $constraints[$key] = self::constraint($parser, $requires[$key], "requires.{$key}", $errors);
            }
        }

        $plugins = [];
        foreach ((array) ($requires['plugins'] ?? []) as $dependency => $constraint) {
            if (! is_string($dependency) || preg_match(self::ID_PATTERN, $dependency) !== 1) {
                $errors[] = 'requires.plugins keys must be plugin ids.';

                continue;
            }
            $plugins[$dependency] = (string) self::constraint($parser, $constraint, "requires.plugins.{$dependency}", $errors);
        }

        $autoload = [];
        foreach ((array) ($data['autoload']['psr-4'] ?? []) as $namespace => $directory) {
            $relative = is_string($directory) ? trim($directory, '/') : '';

            if (! is_string($namespace) || ! str_ends_with($namespace, '\\') || str_contains($relative, '..') || str_starts_with((string) $directory, '/')) {
                $errors[] = 'autoload.psr-4 must map namespaces ending in "\\" to directories inside the plugin.';

                continue;
            }

            $autoload[$namespace] = $relative;
        }

        if ($provider !== null && ! collect(array_keys($autoload))->contains(fn (string $namespace) => str_starts_with($provider, $namespace))) {
            $errors[] = 'the provider class must be inside an autoload.psr-4 namespace.';
        }

        $permissions = [];
        foreach ((array) ($data['permissions'] ?? []) as $permission) {
            if (! is_array($permission) || ! is_string($permission['key'] ?? null) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $permission['key']) !== 1 || ! is_string($permission['label'] ?? null)) {
                $errors[] = 'permissions must have a dotted "key" (e.g. "notice.manage") and a "label".';

                continue;
            }

            $permissions[] = ['key' => $permission['key'], 'label' => $permission['label'], 'group' => is_string($permission['group'] ?? null) ? $permission['group'] : 'Extensions'];
        }

        $settings = [];
        foreach ((array) ($data['settings'] ?? []) as $setting) {
            if (! is_array($setting) || ! is_string($setting['key'] ?? null) || preg_match('/^[a-z0-9_]+$/', $setting['key']) !== 1 || SettingType::tryFrom((string) ($setting['type'] ?? '')) === null || ! is_string($setting['label'] ?? null)) {
                $errors[] = 'settings must have a "key" (letters, digits, _), a known "type" and a "label".';

                continue;
            }

            $settings[] = $setting;
        }

        // The permission (besides system.settings.manage) that edits this plugin's settings
        // tab, e.g. "store.payment_methods.manage" for a gateway's keys (since 1.11.1).
        $settingsPermission = $string('settings_permission');
        if ($settingsPermission !== null && preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $settingsPermission) !== 1) {
            $errors[] = 'settings_permission must be a dotted permission key (e.g. "store.payment_methods.manage").';
        }

        $storefront = $string('storefront');
        // In a folder of its own (e.g. dist/), which is published to public/: never the plugin root.
        if ($storefront !== null && (! str_ends_with($storefront, '.js') || ! str_contains($storefront, '/') || str_contains($storefront, '..') || str_starts_with($storefront, '/') || ! is_file(rtrim($path, '/').'/'.$storefront) && $path !== 'archive')) {
            $errors[] = 'storefront must be the path of a built .js file in a subfolder of the plugin (e.g. dist/storefront.js).';
        }

        if ($errors !== []) {
            throw new ExtensionException(__('The manifest of :id is invalid: :errors', ['id' => $id ?? $path, 'errors' => implode(' ', array_unique($errors))]));
        }

        return new self(
            id: (string) $id,
            name: (string) $string('name'),
            version: (string) $version,
            path: rtrim($path, '/'),
            provider: (string) $provider,
            description: $string('description'),
            pnshopConstraint: $constraints['pnshop'] ?? null,
            phpConstraint: $constraints['php'] ?? null,
            requiredPlugins: $plugins,
            autoload: $autoload,
            permissions: $permissions,
            settings: $settings,
            author: is_string($data['author'] ?? null) ? $data['author'] : null,
            license: $string('license'),
            storefront: $storefront,
            settingsPermission: $settingsPermission,
        );
    }

    /** "acme/store-notice" → "acme_store_notice", used for settings, views and tables. */
    public function key(): string
    {
        return str_replace(['/', '-'], '_', $this->id);
    }

    public function migrationsPath(): string
    {
        return $this->path.'/database/migrations';
    }

    /**
     * @param  list<string>  $errors
     */
    private static function constraint(VersionParser $parser, mixed $constraint, string $field, array &$errors): ?string
    {
        try {
            $parser->parseConstraints((string) $constraint);

            return (string) $constraint;
        } catch (Throwable) {
            $errors[] = "{$field} must be a version constraint such as ^1.0.";

            return null;
        }
    }
}
