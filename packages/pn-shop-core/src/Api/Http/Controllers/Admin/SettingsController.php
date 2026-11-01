<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;

/**
 * Store settings by namespace ("store", "seo", "plugin.pnshop.stripe", ...). Secret values
 * are never returned; `is_set` tells whether one is saved.
 */
class SettingsController extends AdminController
{
    public function __construct(private SettingsRegistry $registry, private Settings $settings) {}

    /**
     * List settings
     *
     * Every settings group with its fields and current values.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        Gate::authorize('system.settings.manage');

        return ['data' => array_values(array_map(
            fn (SettingsSchema $schema) => $this->present($schema),
            array_filter($this->registry->all(), fn (SettingsSchema $schema) => ! $schema->isHidden() && $schema->editableBy(request()->user())),
        ))];
    }

    /**
     * Show a settings group
     *
     * @return array<string, mixed>
     */
    public function show(string $namespace): array
    {
        Gate::authorize('system.settings.manage');

        return ['data' => $this->present($this->schema($namespace))];
    }

    /**
     * Update a settings group
     *
     * Send `{"values": {"<key>": <value>}}` with only the keys to change; they are validated
     * like in the admin. An empty secret keeps the saved one.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, string $namespace): array
    {
        Gate::authorize('system.settings.manage');

        $data = $request->validate(['values' => ['required', 'array']]);
        $schema = $this->schema($namespace);
        $unknown = array_diff(array_keys($data['values']), array_keys($schema->definitions()));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => __('Unknown settings: :keys', ['keys' => implode(', ', $unknown)])]);
        }

        $this->settings->set($namespace, $data['values']);

        activity('settings')->withProperties(['namespace' => $namespace, 'keys' => array_keys($data['values'])])->log('Settings updated');

        return ['data' => $this->present($schema)];
    }

    private function schema(string $namespace): SettingsSchema
    {
        $schema = $this->registry->schema($namespace);

        abort_if($schema === null || $schema->isHidden(), 404);
        // E.g. a payment gateway's keys also need store.payment_methods.manage.
        abort_unless($schema->editableBy(request()->user()), 403);

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SettingsSchema $schema): array
    {
        return [
            'namespace' => $schema->namespace,
            'label' => $schema->label,
            'fields' => array_values(array_map(fn (SettingDefinition $definition) => [
                'key' => $definition->key,
                'type' => $definition->type->value,
                'label' => $definition->label,
                'help' => $definition->help,
                'required' => $definition->required,
                'options' => $definition->options === [] ? null : $definition->options,
                ...($definition->type === SettingType::Secret
                    ? ['value' => null, 'is_set' => $this->settings->hasSecret("{$schema->namespace}.{$definition->key}")]
                    : ['value' => $this->settings->get("{$schema->namespace}.{$definition->key}")]),
            ], $schema->definitions())),
        ];
    }
}
