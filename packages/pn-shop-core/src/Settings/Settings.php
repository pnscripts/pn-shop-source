<?php

namespace PnShop\Settings;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PnShop\Settings\Models\Setting;

/**
 * Typed, cached access to stored settings. Values fall back to the definition default.
 */
final class Settings
{
    private const CACHE_KEY = 'pnshop.settings';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $values = null;

    /** @var (\Closure(string): mixed)|null returns a value overriding a setting (a channel's), or null */
    private ?\Closure $override = null;

    public function __construct(
        private SettingsRegistry $registry,
        private Cache $cache,
    ) {}

    public function get(string $path): mixed
    {
        [$schema, $definition] = $this->registry->resolve($path);

        if ($this->override !== null && $definition->type !== SettingType::Secret) {
            $overridden = ($this->override)($schema->namespace.'.'.$definition->key);

            if ($overridden !== null) {
                return $definition->type->cast($overridden);
            }
        }

        $stored = $this->values()[$schema->namespace][$definition->key] ?? null;

        if ($definition->type === SettingType::Secret) {
            return $this->decrypt($stored);
        }

        return $definition->type->cast($stored ?? $definition->default);
    }

    /**
     * All values of one namespace, keyed by setting key, for forms: secrets are left out (null).
     *
     * @return array<string, mixed>
     */
    public function namespace(string $namespace): array
    {
        $schema = $this->registry->schema($namespace);

        if ($schema === null) {
            return [];
        }

        $values = [];

        foreach ($schema->definitions() as $key => $definition) {
            $values[$key] = $definition->type === SettingType::Secret ? null : $this->get("{$namespace}.{$key}");
        }

        return $values;
    }

    /**
     * Validate and store one or more values of a namespace.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function set(string $namespace, array $values): void
    {
        $rules = [];

        foreach ($values as $key => $value) {
            // An empty secret means "keep the saved one": forms never show secrets.
            if (($value === null || $value === '') && $this->registry->resolve("{$namespace}.{$key}")[1]->type === SettingType::Secret) {
                unset($values[$key]);
            }
        }

        foreach (array_keys($values) as $key) {
            [, $definition] = $this->registry->resolve("{$namespace}.{$key}");
            $rules[$key] = $definition->validationRules();
        }

        $validated = Validator::make($values, $rules)->validate();

        foreach ($validated as $key => $value) {
            [, $definition] = $this->registry->resolve("{$namespace}.{$key}");

            Setting::query()->updateOrCreate(
                ['namespace' => $namespace, 'key' => $key],
                ['value' => $definition->type === SettingType::Secret ? Crypt::encryptString((string) $value) : $definition->type->cast($value)],
            );
        }

        $this->flush();
    }

    /** Whether a secret has been saved (without revealing it). */
    public function hasSecret(string $path): bool
    {
        [$schema, $definition] = $this->registry->resolve($path);

        return $definition->type === SettingType::Secret && filled($this->values()[$schema->namespace][$definition->key] ?? null);
    }

    private function decrypt(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Let the active channel override some settings (store name, theme, ...).
     *
     * @param  \Closure(string): mixed  $resolver  setting path => value, or null to keep the stored one
     */
    public function overrideUsing(\Closure $resolver): void
    {
        $this->override = $resolver;
    }

    public function flush(): void
    {
        $this->values = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function values(): array
    {
        return $this->values ??= $this->cache->rememberForever(self::CACHE_KEY, function () {
            $values = [];

            foreach (Setting::query()->get(['namespace', 'key', 'value']) as $setting) {
                $values[$setting->namespace][$setting->key] = $setting->value;
            }

            return $values;
        });
    }
}
