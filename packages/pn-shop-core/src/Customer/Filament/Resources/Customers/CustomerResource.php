<?php

namespace PnShop\Customer\Filament\Resources\Customers;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use PnShop\Credit\Filament\RelationManagers\StoreCreditHistoryRelationManager;
use PnShop\Credit\Filament\RelationManagers\StoreCreditRelationManager;
use PnShop\Customer\Filament\Resources\Customers\Pages\EditCustomer;
use PnShop\Customer\Filament\Resources\Customers\Pages\ListCustomers;
use PnShop\Customer\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use PnShop\Customer\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use PnShop\Customer\Models\User;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'customers';

    protected static ?string $modelLabel = 'customer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->maxLength(50),
                Select::make('customer_group_id')->label('Customer group')->relationship('customerGroup', 'name')->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('customerGroup.name')->label('Group')->badge(),
                TextColumn::make('orders_count')->counts('orders')->label('Orders')->sortable(),
                TextColumn::make('created_at')->label('Registered')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_group_id')->label('Group')->relationship('customerGroup', 'name'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [OrdersRelationManager::class, AddressesRelationManager::class, StoreCreditRelationManager::class, StoreCreditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
