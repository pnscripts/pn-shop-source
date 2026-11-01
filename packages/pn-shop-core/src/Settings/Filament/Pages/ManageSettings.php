<?php

namespace PnShop\Settings\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use PnShop\Settings\Filament\SettingField;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\Settings;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use UnitEnum;

/**
 * One form for every registered settings schema (core, themes and plugins), one tab each.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    /** @var array<string, array<string, mixed>> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth('admin')->user()?->can('system.settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $state = [];

        foreach (self::editableSchemas() as $namespace => $schema) {
            $state[self::stateKey($namespace)] = $settings->namespace($namespace);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        // Core settings first, then the plugins' tabs.
        $schemas = collect(self::editableSchemas())->sortBy(fn ($settingsSchema, string $namespace) => str_starts_with($namespace, 'plugin.') ? 1 : 0, SORT_NUMERIC);

        foreach ($schemas as $namespace => $settingsSchema) {
            $tabs[] = Tab::make($settingsSchema->label)
                ->statePath(self::stateKey($namespace))
                ->schema(array_values(array_map(
                    fn (SettingDefinition $definition) => SettingField::make($definition),
                    $settingsSchema->definitions(),
                )));
        }

        return $schema
            ->statePath('data')
            ->components([Tabs::make('settings')->tabs($tabs)->persistTabInQueryString()]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(Settings::class);

        foreach (self::editableSchemas() as $namespace => $schema) {
            $values = $state[self::stateKey($namespace)] ?? [];

            if ($values !== []) {
                $settings->set($namespace, $values);
            }
        }

        activity('settings')->log('Settings updated');

        Notification::make()->success()->title('Settings saved.')->send();
    }

    /**
     * The visible groups this staff account may change: a payment gateway's keys, for
     * example, also need store.payment_methods.manage.
     *
     * @return array<string, SettingsSchema>
     */
    private static function editableSchemas(): array
    {
        $admin = auth('admin')->user();

        return array_filter(
            app(SettingsRegistry::class)->all(),
            fn (SettingsSchema $schema) => ! $schema->isHidden() && $schema->editableBy($admin),
        );
    }

    private static function stateKey(string $namespace): string
    {
        return str_replace('.', '__', $namespace);
    }
}
