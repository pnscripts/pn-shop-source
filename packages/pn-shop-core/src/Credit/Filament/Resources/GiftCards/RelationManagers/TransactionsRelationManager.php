<?php

namespace PnShop\Credit\Filament\Resources\GiftCards\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use PnShop\Credit\Filament\BalanceActions;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order', 'adminUser']))
            ->defaultSort('id', 'desc')
            ->columns(BalanceActions::ledgerColumns());
    }
}
