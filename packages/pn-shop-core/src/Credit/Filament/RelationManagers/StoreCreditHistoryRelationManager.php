<?php

namespace PnShop\Credit\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PnShop\Credit\Filament\BalanceActions;

/**
 * Every change to a customer's store credit.
 */
class StoreCreditHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'creditTransactions';

    protected static ?string $title = 'Store credit history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth('admin')->user()?->can('sales.credit.manage');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order', 'adminUser']))
            ->defaultSort('balance_transactions.id', 'desc')
            ->columns([TextColumn::make('currency'), ...BalanceActions::ledgerColumns()]);
    }
}
