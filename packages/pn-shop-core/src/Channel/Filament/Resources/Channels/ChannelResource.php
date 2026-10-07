<?php

namespace PnShop\Channel\Filament\Resources\Channels;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use PnShop\Channel\Filament\Resources\Channels\Pages\CreateChannel;
use PnShop\Channel\Filament\Resources\Channels\Pages\EditChannel;
use PnShop\Channel\Filament\Resources\Channels\Pages\ListChannels;
use PnShop\Channel\Models\Channel;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Country;
use PnShop\Localization\Models\Currency;
use PnShop\Localization\Models\Language;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Theme\ThemeManager;
use PnShop\Theme\ThemeManifest;
use Throwable;
use UnitEnum;

/**
 * Storefronts on this installation. Each answers on a domain, a path or both, and can
 * have its own languages, currency, theme, store details, tax country, stock locations,
 * payment and shipping methods. Empty fields use the shop's settings.
 */
class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Store';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Channels';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Where it answers')->columnSpanFull()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->helperText('For staff. The name customers see is the store name below.'),
                TextInput::make('code')->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true),
                TextInput::make('hostname')
                    ->label('Domain')
                    ->maxLength(255)
                    ->placeholder('wholesale.example.com')
                    ->helperText('Point the domain at this installation. Empty: any domain.'),
                TextInput::make('path')
                    ->maxLength(64)
                    ->alphaDash()
                    ->placeholder('trade')
                    ->helperText('Serve it under a path, e.g. example.com/trade. Empty: the domain\'s root.')
                    ->rule(fn () => self::pathRule()),
                Toggle::make('is_active')->label('Active')->default(true)->disabled(fn (?Channel $record) => $record->is_default ?? false),
                Toggle::make('separate_accounts')->label('Separate customer accounts')
                    ->helperText('Customers of this channel get their own accounts (the same email can also have one in the other channels). Chosen when the channel is created; it cannot be changed later.')
                    ->default(false)
                    ->disabled(fn (?Channel $record) => $record !== null),
                TextInput::make('position')->integer()->minValue(0)->default(0),
            ]),
            Section::make('Languages and currency')->columns(2)->schema([
                Select::make('locales')
                    ->label('Languages')
                    ->multiple()
                    ->options(fn () => Language::query()->where('is_active', true)->pluck('name', 'code')->all())
                    ->helperText('Empty: all of the shop\'s languages.'),
                Select::make('default_locale')
                    ->label('Default language')
                    ->options(fn () => Language::query()->where('is_active', true)->pluck('name', 'code')->all())
                    ->placeholder('The shop\'s default'),
                Select::make('currency')
                    ->options(fn () => Currency::query()->where('is_active', true)->pluck('name', 'code')->all())
                    ->placeholder(fn () => __('The shop\'s (:code)', ['code' => app(Localization::class)->defaultCurrency()->code]))
                    ->helperText('Catalog prices are converted with the currency\'s exchange rate, unless a price list in this currency sets them.'),
            ]),
            Section::make('Store details')->columns(2)->statePath('settings')->description('Empty fields use the shop\'s settings.')->schema([
                TextInput::make('store.name')->label('Store name')->maxLength(255),
                TextInput::make('store.email')->label('Contact email')->email()->maxLength(255),
                TextInput::make('store.phone')->label('Contact phone')->maxLength(255),
                Textarea::make('store.address')->label('Store address')->rows(2),
                Select::make('appearance.theme')->label('Theme')->options(fn () => self::themeOptions())->placeholder('The shop\'s theme'),
                Select::make('tax.store_country')
                    ->label('Tax country')
                    ->searchable()
                    ->options(fn () => Country::query()->where('is_active', true)->get()->mapWithKeys(fn (Country $country) => [$country->code => $country->name()])->sort()->all())
                    ->helperText('Taxed before the customer enters an address.'),
                Select::make('seo.allow_indexing')
                    ->label('Search engines')
                    ->options(['1' => 'May index', '0' => 'Do not index'])
                    ->placeholder('As the shop'),
            ]),
            Section::make('Stock, payment and delivery')->columnSpanFull()->columns(3)->description('Empty: all of them.')->schema([
                Select::make('stock_location_ids')->label('Stock locations')->multiple()
                    ->options(fn () => StockLocation::query()->where('is_active', true)->ordered()->pluck('name', 'id')->all())
                    ->dehydrateStateUsing(fn (?array $state) => self::ids($state)),
                Select::make('payment_method_ids')->label('Payment methods')->multiple()
                    ->options(fn () => PaymentMethod::query()->orderBy('position')->pluck('name', 'id')->all())
                    ->dehydrateStateUsing(fn (?array $state) => self::ids($state)),
                Select::make('shipping_method_ids')->label('Shipping methods')->multiple()
                    ->options(fn () => ShippingMethod::query()->orderBy('position')->pluck('name', 'id')->all())
                    ->dehydrateStateUsing(fn (?array $state) => self::ids($state)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Channel $record) => $record->url()),
                TextColumn::make('code')->color('gray'),
                TextColumn::make('currency')->placeholder(fn () => app(Localization::class)->defaultCurrency()->code),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_default')->label('Default')->boolean(),
            ])
            ->recordActions([
                Action::make('makeDefault')
                    ->label('Make default')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (Channel $record) => ! $record->is_default)
                    ->authorize(fn (Channel $record) => auth('admin')->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Requests that match no channel are served by the default one.')
                    ->action(function (Channel $record): void {
                        $record->makeDefault();
                        Notification::make()->success()->title(__(':name is now the default channel.', ['name' => $record->name]))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannels::route('/'),
            'create' => CreateChannel::route('/create'),
            'edit' => EditChannel::route('/{record}/edit'),
        ];
    }

    /**
     * Copy the channel's theme to public/ so its storefront can load it.
     */
    public static function publishTheme(mixed $channel): void
    {
        if (! $channel instanceof Channel) {
            return;
        }

        $id = $channel->setting('appearance.theme');

        if (! is_string($id) || $id === '') {
            return;
        }

        try {
            $themes = app(ThemeManager::class);
            $theme = $themes->find($id);

            if ($themes->problems($theme) === []) {
                $themes->publish($theme);
            }
        } catch (Throwable $e) {
            Notification::make()->warning()->title(__('The theme could not be published: :message', ['message' => $e->getMessage()]))->send();
        }
    }

    /**
     * A path must not hide a page, a language or an admin address.
     */
    private static function pathRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $path = strtolower(trim((string) $value, '/'));

            if ($path === '') {
                return;
            }

            $taken = collect(Route::getRoutes()->getRoutes())
                ->map(fn ($route) => strtolower(explode('/', ltrim($route->uri(), '/'))[0]))
                ->merge([...Localization::UNLOCALIZED_PATHS, 'themes', 'vendor'])
                ->merge(Language::query()->pluck('code')->all());

            if ($taken->contains($path)) {
                $fail(__('":path" is already used by a page, a language or the admin.', ['path' => $path]));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private static function themeOptions(): array
    {
        try {
            return app(ThemeManager::class)->discover()->mapWithKeys(fn (ThemeManifest $theme) => [$theme->id => $theme->name])->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, mixed>|null  $state
     * @return list<int>|null
     */
    private static function ids(?array $state): ?array
    {
        return $state === null || $state === [] ? null : array_values(array_map('intval', $state));
    }
}
