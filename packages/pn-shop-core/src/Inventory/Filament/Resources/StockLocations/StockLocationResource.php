<?php

namespace PnShop\Inventory\Filament\Resources\StockLocations;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
use Illuminate\Database\Eloquent\Builder;
use PnShop\Inventory\Filament\Resources\StockLocations\Pages\ManageStockLocations;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Localization\Models\Country;
use UnitEnum;

/**
 * Warehouses and shops that hold stock. Stock changes without a location go to the
 * default one; the storefront sells the stock of active locations that sell online.
 */
class StockLocationResource extends Resource
{
    protected static ?string $model = StockLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Stock locations';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->placeholder('Sofia shop'),
                TextInput::make('code')->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true),
                Toggle::make('is_active')->label('Active')->default(true)
                    ->disabled(fn (?StockLocation $record) => $record->is_default ?? false)
                    ->helperText('Inactive locations keep their stock but nothing is sold or shipped from them.'),
                Toggle::make('sells_online')->label('Sells online')->default(true)
                    ->helperText('Its stock counts as available in the shop. Turn off for stock kept only for a physical shop.'),
                TextInput::make('position')->integer()->minValue(0)->default(0)
                    ->helperText('Orders are served from the default location first, then in this order.'),
            ]),
            Section::make('Address')->columns(2)->description('Used to ship from the nearest location and for store pickup.')->schema([
                TextInput::make('address')->maxLength(255)->columnSpanFull(),
                TextInput::make('city')->maxLength(255),
                TextInput::make('postcode')->maxLength(32),
                Select::make('country_code')
                    ->label('Country')
                    ->searchable()
                    ->options(fn () => Country::query()->where('is_active', true)->get()->mapWithKeys(fn (Country $country) => [$country->code => $country->name()])->sort()->all()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('levels as on_hand_total', 'on_hand'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('is_default')->orderBy('position')->orderBy('id'))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (StockLocation $record) => $record->addressLine() ?: null),
                TextColumn::make('code')->color('gray'),
                TextColumn::make('on_hand_total')->label('Units on hand')->numeric()->default(0),
                IconColumn::make('sells_online')->label('Online')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_default')->label('Default')->boolean(),
            ])
            ->recordActions([
                Action::make('makeDefault')
                    ->label('Make default')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (StockLocation $record) => ! $record->is_default)
                    ->authorize(fn (StockLocation $record) => auth('admin')->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Stock changes without a location, and the stock field of product forms, will use this location.')
                    ->action(function (StockLocation $record): void {
                        $record->makeDefault();
                        Notification::make()->success()->title(__(':name is now the default location.', ['name' => $record->name]))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStockLocations::route('/')];
    }
}
