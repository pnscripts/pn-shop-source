<?php

namespace PnShop\Theme;

use Composer\Semver\VersionParser;
use PnShop\Extension\Exceptions\ExtensionException;
use PnShop\Extension\Manifest;
use PnShop\Foundation\PnShop;
use PnShop\Settings\SettingType;
use Throwable;

/**
 * A theme's pnshop.json (type "theme"):
 *
 *     {
 *       "id": "acme/aurora",
 *       "name": "Aurora",
 *       "version": "1.0.0",
 *       "type": "theme",
 *       "parent": "pnshop/default",
 *       "requires": { "pnshop": "^1.1" },
 *       "entries": ["resources/css/app.css", "resources/js/app.tsx"],
 *       "settings": [
 *         { "key": "primary", "type": "color", "label": "Brand colour", "default": "#4f46e5",
 *           "css_var": "--primary", "contrast_var": "--primary-foreground" }
 *       ]
 *     }
 *
 * Every theme ships a prebuilt bundle in dist/ (a Vite build with manifest.json) that is
 * published under public/ (public/themes/<id>/build on activation). The built-in storefront
 * ("builtin", only the one in the core package) lives in pn-shop-core's theme/ folder and is
 * published to public/vendor/pnshop/build. No Node is needed on the shop's server.
 */
final readonly class ThemeManifest
{
    public const DEFAULT = 'pnshop/default';

    /** Where the core's prebuilt storefront is published under public/. */
    public const CORE_BUILD = 'vendor/pnshop/build';

    /**
     * @param  list<string>  $entries
     * @param  list<array<string, mixed>>  $settings
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $path,
        public bool $builtin,
        public ?string $parent = null,
        public ?string $description = null,
        public ?string $pnshopConstraint = null,
        public array $entries = ['resources/css/app.css', 'resources/js/app.tsx'],
        public array $settings = [],
        public ?string $author = null,
    ) {}

    /**
     * @throws ExtensionException
     */
    public static function fromDirectory(string $directory): self
    {
        $file = rtrim($directory, '/').'/'.Manifest::FILE;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($data)) {
            throw new ExtensionException(__('No valid :file found in :path.', ['file' => Manifest::FILE, 'path' => $directory]));
        }

        $errors = [];
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;

        if (($data['type'] ?? null) !== 'theme') {
            $errors[] = 'type must be "theme".';
        }

        $id = $string('id');
        if ($id === null || preg_match(Manifest::ID_PATTERN, $id) !== 1) {
            $errors[] = 'id must look like "vendor/name".';
        }

        foreach (['name', 'version'] as $required) {
            if ($string($required) === null) {
                $errors[] = "{$required} is required.";
            }
        }

        $parser = new VersionParser;
        $constraint = $data['requires']['pnshop'] ?? null;

        try {
            $parser->normalize((string) $string('version'));

            if ($constraint !== null) {
                $parser->parseConstraints((string) $constraint);
            }
        } catch (Throwable) {
            $errors[] = 'version and requires.pnshop must be valid versions and constraints.';
        }

        $parent = $string('parent');
        if ($parent !== null && (preg_match(Manifest::ID_PATTERN, $parent) !== 1 || $parent === $id)) {
            $errors[] = 'parent must be another theme id.';
        }

        $entries = array_values(array_filter((array) ($data['entries'] ?? ['resources/css/app.css', 'resources/js/app.tsx']), fn ($entry) => is_string($entry) && ! str_contains($entry, '..')));

        $settings = [];
        foreach ((array) ($data['settings'] ?? []) as $setting) {
            $valid = is_array($setting)
                && is_string($setting['key'] ?? null) && preg_match('/^[a-z0-9_]+$/', $setting['key']) === 1
                && SettingType::tryFrom((string) ($setting['type'] ?? '')) !== null
                && SettingType::from((string) $setting['type']) !== SettingType::Secret
                && is_string($setting['label'] ?? null)
                && (! isset($setting['css_var']) || (is_string($setting['css_var']) && preg_match('/^--[a-z0-9-]+$/', $setting['css_var']) === 1))
                && (! isset($setting['contrast_var']) || (is_string($setting['contrast_var']) && preg_match('/^--[a-z0-9-]+$/', $setting['contrast_var']) === 1))
                && collect((array) ($setting['options'] ?? []))->keys()->every(fn ($value) => preg_match('/^[A-Za-z0-9#.%(), -]+$/', (string) $value) === 1);

            if (! $valid) {
                $errors[] = 'settings need a key, a known type (not secret), a label, and safe css_var / option values.';

                continue;
            }

            $settings[] = $setting;
        }

        if ($errors !== []) {
            throw new ExtensionException(__('The theme manifest in :path is invalid: :errors', ['path' => $directory, 'errors' => implode(' ', array_unique($errors))]));
        }

        return new self(
            id: (string) $id,
            name: (string) $string('name'),
            version: (string) $string('version'),
            path: rtrim($directory, '/'),
            // Only the core package's own storefront can claim to be built in.
            builtin: ($data['builtin'] ?? false) === true && realpath($directory) === realpath(PnShop::path('theme')),
            parent: $parent,
            description: $string('description'),
            pnshopConstraint: $constraint !== null ? (string) $constraint : null,
            entries: $entries ?: ['resources/css/app.css', 'resources/js/app.tsx'],
            settings: $settings,
            author: $string('author'),
        );
    }

    public function key(): string
    {
        return str_replace(['/', '-'], '_', $this->id);
    }

    /** Where the prebuilt bundle is shipped inside the theme. */
    public function distPath(): string
    {
        return $this->path.'/dist';
    }

    /** The bundle's folder under public/, for @vite(). */
    /**
     * The theme's server-side rendering bundle (npm run build:theme writes it to ssr/, next to
     * dist/ but never published: it is server code), or null when the theme has none.
     */
    public function ssrBundle(): ?string
    {
        foreach (['ssr.js', 'ssr.mjs'] as $file) {
            if (! $this->builtin && is_file($this->path.'/ssr/'.$file)) {
                return $this->path.'/ssr/'.$file;
            }
        }

        return null;
    }

    public function buildDirectory(): string
    {
        return $this->builtin ? self::CORE_BUILD : 'themes/'.$this->id.'/build';
    }
}
