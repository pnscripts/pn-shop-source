<?php

namespace PnShop\Customer\Filament\Resources\CustomerGroups;

use BackedEnum;
use Brick\Money\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PnShop\Customer\Filament\Resources\CustomerGroups\Pages\ManageCustomerGroups;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Localization\Localization;
use UnitEnum;

class CustomerGroupResource extends Resource
{
    protected static ?string $model = CustomerGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true),
            ]),
            Section::make('Business customers')->columns(2)->schema([
                Select::make('prices_include_tax')
                    ->label('Show prices')
                    ->options(['1' => 'Including tax', '0' => 'Excluding tax'])
                    ->placeholder('As entered in the catalog')
                    ->formatStateUsing(fn (mixed $state) => $state === null ? null : ($state ? '1' : '0'))
                    ->dehydrateStateUsing(fn (mixed $state) => $state === null || $state === '' ? null : (bool) $state)
                    ->helperText('Only what is shown changes: checkout charges the same and lists the tax.'),
                TextInput::make('min_order_total')
                    ->label('Minimum order')
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn () => app(Localization::class)->defaultCurrency()->code)
                    ->formatStateUsing(fn (mixed $state) => $state instanceof Money ? (string) $state->getAmount() : $state)
                    ->helperText('Products, before shipping. Empty: no minimum.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('code')->color('gray'),
                TextColumn::make('min_order_total')->label('Minimum order')->formatStateUsing(fn (?Money $state) => $state?->formatToLocale(app()->getLocale()))->placeholder('—'),
                TextColumn::make('customers_count')->counts('customers')->label('Customers'),
                IconColumn::make('is_default')->label('Default')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomerGroups::route('/')];
    }
}
