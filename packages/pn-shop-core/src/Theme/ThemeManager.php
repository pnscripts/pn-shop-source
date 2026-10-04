<?php

namespace PnShop\Theme;

use Composer\Semver\Semver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PnShop\Extension\Exceptions\ExtensionException;
use PnShop\Foundation\PnShop;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingType;
use Throwable;

/**
 * Finds themes, knows the active one and activates others.
 *
 * The active theme falls back to the built-in default whenever it is missing, invalid
 * or not built, so the storefront always renders.
 */
class ThemeManager
{
    private ?ThemeManifest $active = null;

    /**
     * Themes found on disk, kept for the rest of the request (the active theme, its
     * settings and its CSS are all looked up several times per page).
     *
     * @var array{themes: Collection<string, ThemeManifest>, invalid: array<string, string>}|null
     */
    private ?array $discovered = null;

    public function __construct(private Settings $settings) {}

    public static function path(): string
    {
        return rtrim((string) config('pnshop.themes.path', base_path('themes')), '/');
    }

    /**
     * Themes on disk by id; folders with invalid manifests are reported in $invalid.
     *
     * @param  array<string, string>  $invalid
     * @return Collection<string, ThemeManifest>
     */
    public function discover(array &$invalid = []): Collection
    {
        if ($this->discovered !== null) {
            $invalid = $this->discovered['invalid'];

            return $this->discovered['themes'];
        }

        $themes = collect([ThemeManifest::DEFAULT => $this->builtin()]);

        foreach (glob(self::path().'/*/*', GLOB_ONLYDIR) ?: [] as $directory) {
            try {
                $theme = ThemeManifest::fromDirectory($directory);

                // The built-in storefront comes from the core package (a 1.0 install may still have its old folder).
                if ($theme->id === ThemeManifest::DEFAULT) {
                    continue;
                }

                $themes->put($theme->id, $theme);
            } catch (ExtensionException $e) {
                $invalid[$directory] = $e->getMessage();
            }
        }

        $this->discovered = ['themes' => $themes->sortKeys(), 'invalid' => $invalid];

        return $this->discovered['themes'];
    }

    public function find(string $id): ThemeManifest
    {
        return $this->discover()->get($id) ?? throw new ExtensionException(__('No theme :id was found.', ['id' => $id]));
    }

    public function active(): ThemeManifest
    {
        // Channels can use other themes: the cached one must still be the one asked for.
        if ($this->active !== null && $this->active->id === $this->activeId()) {
            return $this->active;
        }

        $themes = $this->discover();
        $id = $this->activeId();
        $theme = $themes->get($id);

        if ($theme === null || $this->problems($theme, $themes) !== []) {
            $theme = $themes->get(ThemeManifest::DEFAULT) ?? $this->builtin();
        }

        return $this->active = $theme;
    }

    public function activeId(): string
    {
        try {
            return (string) ($this->settings->get('appearance.theme') ?: ThemeManifest::DEFAULT);
        } catch (Throwable) {
            return ThemeManifest::DEFAULT;
        }
    }

    /**
     * The theme and its ancestors, the theme itself first.
     *
     * @param  Collection<string, ThemeManifest>|null  $themes
     * @return list<ThemeManifest>
     */
    public function chain(ThemeManifest $theme, ?Collection $themes = null): array
    {
        $themes ??= $this->discover();
        $chain = [$theme];

        while ($theme->parent !== null && count($chain) < 10) {
            $theme = $themes->get($theme->parent) ?? throw new ExtensionException(__('The parent theme :id is missing.', ['id' => $theme->parent]));

            if (in_array($theme->id, array_map(fn (ThemeManifest $item) => $item->id, $chain), true)) {
                throw new ExtensionException(__('The themes extend each other in a circle.'));
            }

            $chain[] = $theme;
        }

        return $chain;
    }

    /**
     * Why the theme cannot be activated.
     *
     * @param  Collection<string, ThemeManifest>|null  $themes
     * @return list<string>
     */
    public function problems(ThemeManifest $theme, ?Collection $themes = null): array
    {
        $problems = [];

        if ($theme->pnshopConstraint !== null && ! Semver::satisfies(PnShop::VERSION, $theme->pnshopConstraint)) {
            $problems[] = __('Needs PN Shop :constraint (this is :version).', ['constraint' => $theme->pnshopConstraint, 'version' => PnShop::VERSION]);
        }

        try {
            $this->chain($theme, $themes);
        } catch (ExtensionException $e) {
            $problems[] = $e->getMessage();
        }

        if (! $theme->builtin && ! is_file($theme->distPath().'/manifest.json')) {
            $problems[] = __('The theme is not built (no dist/manifest.json). Build it with: npm run build:theme -- :id', ['id' => $theme->id]);
        }

        return $problems;
    }

    public function activate(string $id, ?Model $actor = null): ThemeManifest
    {
        $theme = $this->find($id);
        $problems = $this->problems($theme);

        if ($problems !== []) {
            throw new ExtensionException(implode(' ', $problems));
        }

        $this->publish($theme);
        $this->settings->set('appearance', ['theme' => $theme->id]);
        $this->active = null;
        $this->discovered = null;

        activity('extensions')->causedBy($actor)->event('theme_activated')
            ->withProperties(['theme' => $theme->id, 'version' => $theme->version])
            ->log("Activated theme {$theme->id} {$theme->version}");

        return $theme;
    }

    /**
     * Copy the theme's prebuilt bundle to public/ (public/themes/<id>/build, or
     * public/vendor/pnshop/build for the built-in storefront when the core ships one).
     */
    public function publish(ThemeManifest $theme): void
    {
        if ($theme->builtin && ! is_file($theme->distPath().'/manifest.json')) {
            return;
        }

        $target = public_path($theme->buildDirectory());

        File::deleteDirectory($target);
        File::ensureDirectoryExists(dirname($target));

        if (! File::copyDirectory($theme->distPath(), $target)) {
            throw new ExtensionException(__('Could not publish the theme files to :path.', ['path' => $target]));
        }
    }

    /**
     * Setting definitions of the active theme and its ancestors (a child's setting with the
     * same key wins), for the "theme.<key>" settings tab.
     *
     * @return list<array<string, mixed>>
     */
    public function settingsFor(ThemeManifest $theme): array
    {
        $settings = [];

        foreach (array_reverse($this->chain($theme)) as $item) {
            foreach ($item->settings as $setting) {
                $settings[$setting['key']] = $setting;
            }
        }

        return array_values($settings);
    }

    /**
     * The active theme's settings as CSS custom properties. Colours apply to light mode
     * only (dark mode keeps the theme's own palette); "contrast_var" gets black or white,
     * whichever reads better on the colour.
     */
    public function css(): string
    {
        $theme = $this->active();
        $root = [];
        $light = [];

        foreach ($this->settingsFor($theme) as $setting) {
            if (! isset($setting['css_var'])) {
                continue;
            }

            try {
                $value = $this->settings->get('theme.'.$theme->key().'.'.$setting['key']);
            } catch (Throwable) {
                $value = $setting['default'] ?? null;
            }

            $type = SettingType::from((string) $setting['type']);
            $value = is_scalar($value) ? (string) $value : '';

            if ($type === SettingType::Color && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
                $light[] = "{$setting['css_var']}:{$value}";

                if (isset($setting['contrast_var'])) {
                    $light[] = "{$setting['contrast_var']}:".self::contrast($value);
                }
            } elseif ($type === SettingType::Select && array_key_exists($value, (array) ($setting['options'] ?? []))) {
                $root[] = "{$setting['css_var']}:{$value}";
            }
        }

        return ($root !== [] ? ':root{'.implode(';', $root).'}' : '').($light !== [] ? ':root:not(.dark){'.implode(';', $light).'}' : '');
    }

    /** Black or white text for a background colour (WCAG relative luminance). */
    public static function contrast(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $hex = strlen($hex) === 3 ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex;

        $channel = function (string $part): float {
            $value = hexdec($part) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        return $luminance > 0.179 ? '#111111' : '#ffffff';
    }

    /** The storefront that ships with the core package. */
    public function builtin(): ThemeManifest
    {
        try {
            return ThemeManifest::fromDirectory(PnShop::path('theme'));
        } catch (ExtensionException) {
            return new ThemeManifest(ThemeManifest::DEFAULT, 'PN Shop Default', PnShop::VERSION, PnShop::path('theme'), true);
        }
    }

    /**
     * The folder under public/ whose Vite manifest the storefront loads for the theme, or
     * null for the project's own build (`npm run dev` / `npm run build`). The built-in
     * storefront uses the project's build when there is one, otherwise the core's bundle.
     */
    public function bundle(ThemeManifest $theme): ?string
    {
        if (! $theme->builtin) {
            return $theme->buildDirectory();
        }

        if (is_file(public_path('hot')) || is_file(public_path('build/manifest.json'))) {
            return null;
        }

        return is_file(public_path(ThemeManifest::CORE_BUILD.'/manifest.json')) ? ThemeManifest::CORE_BUILD : null;
    }
}
