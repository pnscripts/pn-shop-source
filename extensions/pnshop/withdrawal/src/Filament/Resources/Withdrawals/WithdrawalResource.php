<?php

namespace PnShop\Plugins\Withdrawal\Filament\Resources\Withdrawals;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use PnShop\Plugins\Withdrawal\Filament\Resources\Withdrawals\Pages\ManageWithdrawals;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Plugins\Withdrawal\WithdrawalStatements;
use PnShop\Sales\Filament\Resources\Orders\OrderResource;
use UnitEnum;

class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Withdrawals';

    protected static ?string $modelLabel = 'withdrawal';

    protected static ?string $slug = 'withdrawals';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('staff_note')->label('Staff note')->maxLength(2000)->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),
                TextColumn::make('submitted_at')->label('Sent')->dateTime()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('order_number')->label('Order')->searchable()
                    ->url(fn (Withdrawal $record) => $record->order_id !== null ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null)
                    ->description(fn (Withdrawal $record) => $record->order_id === null ? 'No matching order' : null),
                TextColumn::make('items')->label('Products')->limit(60)->placeholder('All')->toggleable(),
                IconColumn::make('late')->label('Late?')
                    ->state(fn (Withdrawal $record) => app(WithdrawalStatements::class)->looksLate($record))
                    ->boolean()->trueColor('danger')->falseColor('success'),
                TextColumn::make('handled_at')->label('Handled')->dateTime()->placeholder('Open')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('handled')->label('Handled')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('handled_at'), false: fn (Builder $query) => $query->whereNull('handled_at')),
            ])
            ->recordActions([
                Action::make('handled')->label('Mark handled')->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (Withdrawal $record) => $record->handled_at === null)
                    ->authorize('update')
                    ->action(fn (Withdrawal $record) => $record->update(['handled_at' => now()])),
                EditAction::make()->label('Note'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWithdrawals::route('/')];
    }
}
