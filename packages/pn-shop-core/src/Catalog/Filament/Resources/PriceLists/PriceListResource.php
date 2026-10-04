<?php

namespace PnShop\Catalog\Filament\Resources\PriceLists;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PnShop\Catalog\Filament\Resources\PriceLists\Pages\CreatePriceList;
use PnShop\Catalog\Filament\Resources\PriceLists\Pages\EditPriceList;
use PnShop\Catalog\Filament\Resources\PriceLists\Pages\ListPriceLists;
use PnShop\Catalog\Filament\Resources\PriceLists\RelationManagers\EntriesRelationManager;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Currency;
use UnitEnum;

/**
 * Price lists: prices per customer group (or for everyone), with optional dates and
 * quantity tiers. A customer always pays the lowest price that applies.
 */
class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->placeholder('Wholesale 2027'),
                Select::make('customer_group_id')
                    ->label('Customer group')
                    ->options(fn () => CustomerGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Everyone (guests included)')
                    ->helperText('Empty: the prices apply to every customer, e.g. quantity discounts.'),
                Select::make('currency')
                    ->options(fn () => Currency::query()->where('is_active', true)->pluck('name', 'code')->all())
                    ->default(fn () => app(Localization::class)->defaultCurrency()->code)
                    ->required()
                    ->disabled(fn (?PriceList $record) => $record !== null && $record->entries()->exists())
                    ->helperText('Used by channels selling in this currency. Fixed once the list has prices.'),
                DateTimePicker::make('starts_at')->label('From')->helperText('Empty: from now.'),
                DateTimePicker::make('ends_at')->label('Until')->after('starts_at')->helperText('Empty: no end.'),
                Toggle::make('is_active')->label('Active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('currency')->color('gray'),
                TextColumn::make('customerGroup.name')->label('Customer group')->placeholder('Everyone'),
                TextColumn::make('entries_count')->counts('entries')->label('Prices')->sortable(),
                TextColumn::make('starts_at')->label('From')->dateTime()->placeholder('—'),
                TextColumn::make('ends_at')->label('Until')->dateTime()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceLists::route('/'),
            'create' => CreatePriceList::route('/create'),
            'edit' => EditPriceList::route('/{record}/edit'),
        ];
    }
}
