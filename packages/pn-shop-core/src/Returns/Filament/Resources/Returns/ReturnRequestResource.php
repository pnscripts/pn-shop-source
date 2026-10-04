<?php

namespace PnShop\Returns\Filament\Resources\Returns;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use PnShop\Returns\Filament\Resources\Returns\Pages\ListReturnRequests;
use PnShop\Returns\Filament\Resources\Returns\Pages\ViewReturnRequest;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\Models\ReturnRequestLine;
use PnShop\Returns\ReturnStatus;
use PnShop\Sales\Filament\Resources\Orders\OrderResource;
use UnitEnum;

class ReturnRequestResource extends Resource
{
    protected static ?string $model = ReturnRequest::class;

    protected static ?string $modelLabel = 'return';

    protected static ?string $slug = 'returns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationBadge(): ?string
    {
        $open = ReturnRequest::query()->where('status', ReturnStatus::Requested)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Items')->columnSpan(2)->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('product')->state(fn (ReturnRequestLine $record) => trim(($record->orderItem->product_title ?? 'Product').($record->orderItem?->variant_label ? " ({$record->orderItem->variant_label})" : ''))),
                    TextEntry::make('quantity')->label('To return'),
                    TextEntry::make('quantity_received')->label('Received'),
                ]),
                TextEntry::make('customer_note')->label('Customer\'s note')->placeholder('—'),
                TextEntry::make('staff_note')->label('Note to the customer')->placeholder('—'),
            ]),
            Section::make('Return')->columnSpan(1)->schema([
                TextEntry::make('number')->copyable(),
                TextEntry::make('status')->badge(),
                TextEntry::make('reason')->state(fn (ReturnRequest $record) => $record->reason->label()),
                TextEntry::make('return_label_url')
                    ->label('Return label')
                    ->state(fn (ReturnRequest $record) => $record->return_label_url === null ? null : (trim(($record->return_carrier ?? '').' '.($record->return_tracking_number ?? '')) ?: 'Download'))
                    ->url(fn (ReturnRequest $record) => $record->return_label_url, shouldOpenInNewTab: true)
                    ->visible(fn (ReturnRequest $record) => $record->return_label_url !== null),
                TextEntry::make('exchangeOrder.number')
                    ->label('Exchanged for')
                    ->url(fn (ReturnRequest $record) => $record->exchange_order_id !== null ? OrderResource::getUrl('view', ['record' => $record->exchange_order_id]) : null)
                    ->visible(fn (ReturnRequest $record) => $record->exchange_order_id !== null),
                TextEntry::make('order.number')->label('Order')->url(fn (ReturnRequest $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextEntry::make('order.email')->label('Customer')->copyable(),
                TextEntry::make('created_at')->label('Requested')->dateTime(),
                TextEntry::make('received_at')->label('Received')->dateTime()->placeholder('—'),
                TextEntry::make('restocked')->label('Back in stock')->state(fn (ReturnRequest $record) => $record->received_at === null ? '—' : ($record->restocked ? 'Yes' : 'No')),
                TextEntry::make('refund.amount')->label('Refunded')
                    ->state(fn (ReturnRequest $record) => $record->refund?->amount->formatToLocale(app()->getLocale()))
                    ->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('order.number')->label('Order')->searchable(),
                TextColumn::make('order.email')->label('Customer')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('reason')->formatStateUsing(fn (ReturnRequest $record) => $record->reason->label()),
                TextColumn::make('created_at')->label('Requested')->since(),
            ])
            ->filters([SelectFilter::make('status')->options(ReturnStatus::class)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturnRequests::route('/'),
            'view' => ViewReturnRequest::route('/{record}'),
        ];
    }
}
