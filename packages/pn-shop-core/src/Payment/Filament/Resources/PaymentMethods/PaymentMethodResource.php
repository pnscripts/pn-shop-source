<?php

namespace PnShop\Payment\Filament\Resources\PaymentMethods;

use BackedEnum;
use Brick\Money\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Localization\Filament\TranslationsSection;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Country;
use PnShop\Payment\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use PnShop\Payment\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use PnShop\Payment\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentGatewayManager;
use PnShop\Settings\Filament\SettingField;
use PnShop\Settings\SettingDefinition;
use UnitEnum;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Store';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $gateways = app(PaymentGatewayManager::class);
        $currency = app(Localization::class)->defaultCurrency()->code;
        $money = fn (TextInput $input) => $input->numeric()->minValue(0)->prefix($currency)
            ->formatStateUsing(fn (mixed $state) => $state instanceof Money ? (string) $state->getAmount() : $state);

        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->helperText('Shown to customers at checkout.'),
                Select::make('gateway')
                    ->options($gateways->options())
                    ->required()
                    ->live()
                    // Start a new gateway from its defaults, so staff see and adjust them.
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('settings', $state !== null && $gateways->has($state)
                        ? collect($gateways->get($state)->settings())->mapWithKeys(fn (SettingDefinition $definition) => [$definition->key => $definition->default])->all()
                        : []))
                    ->helperText('How the payment is taken.'),
                Textarea::make('description')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Availability')->columnSpan(1)->schema([
                Toggle::make('is_active')->label('Offered at checkout')->default(true),
                TextInput::make('position')->integer()->default(0)->helperText('Lower numbers are listed first.'),
                $money(TextInput::make('min_total')->label('Minimum order total')),
                $money(TextInput::make('max_total')->label('Maximum order total')->gte('min_total')),
                Select::make('countries')
                    ->label('Only for these countries')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => Country::query()->where('is_active', true)->get()->mapWithKeys(fn (Country $country) => [$country->code => $country->name()])->sort()->all())
                    ->helperText('Shipping country. Leave empty for all countries.'),
                Select::make('customer_group_ids')
                    ->label('Only for these customer groups')
                    ->multiple()
                    ->options(fn () => CustomerGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->dehydrateStateUsing(fn (?array $state) => $state === null || $state === [] ? null : array_values(array_map('intval', $state)))
                    ->helperText('Guests count as the default group. Leave empty for every customer.'),
            ]),
            Section::make('Gateway settings')
                ->columnSpan(2)
                ->statePath('settings')
                ->visible(fn (Get $get) => $gateways->has((string) $get('gateway')) && $gateways->get((string) $get('gateway'))->settings() !== [])
                ->schema(fn (Get $get) => $gateways->has((string) $get('gateway'))
                    ? array_map(fn (SettingDefinition $definition) => SettingField::make($definition), $gateways->get((string) $get('gateway'))->settings())
                    : []),
            TranslationsSection::make([
                'name' => fn (string $name) => TextInput::make($name)->label('Name')->maxLength(255),
                'description' => fn (string $name) => Textarea::make($name)->label('Description')->rows(3),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $gateways = app(PaymentGatewayManager::class);

        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('gateway')
                    ->formatStateUsing(fn (string $state) => $gateways->has($state) ? $gateways->get($state)->label() : "{$state} (not installed)")
                    ->color(fn (string $state) => $gateways->has($state) ? null : 'danger'),
                IconColumn::make('is_active')->label('Offered')->boolean(),
                TextColumn::make('orders_count')->counts('orders')->label('Orders'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
            'create' => CreatePaymentMethod::route('/create'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
