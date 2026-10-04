<?php

namespace PnShop\Sales\Filament\Resources\Orders\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use PnShop\Channel\Models\Channel;
use PnShop\Sales\Models\Order;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['paymentMethod', 'items', 'channel']))
            ->columns([
                TextColumn::make('number')->label('Order')->searchable()->sortable(query: fn ($query, string $direction) => $query->orderBy('id', $direction)),
                TextColumn::make('name')->label('Customer')->searchable()
                    ->description(fn ($record) => $record->email),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('channel.name')->label('Channel')->visible(fn () => Channel::query()->count() > 1),
                TextColumn::make('status')->badge(),
                TextColumn::make('payment_status')->label('Payment')->badge()
                    ->description(fn (Order $record) => $record->paymentMethod?->name),
                TextColumn::make('fulfillment_status')->label('Fulfillment')->badge(),
                TextColumn::make('total')->state(fn (Order $record) => $record->grandTotal()->formatToLocale(app()->getLocale())),
                TextColumn::make('created_at')->label('Placed')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel_id')->label('Channel')->relationship('channel', 'name')->visible(fn () => Channel::query()->count() > 1),
                SelectFilter::make('status')->options(OrderStatus::class),
                SelectFilter::make('payment_status')->label('Payment')->options(PaymentStatus::class),
                SelectFilter::make('fulfillment_status')->label('Fulfillment')->options(FulfillmentStatus::class),
                SelectFilter::make('payment_method_id')->label('Payment')->relationship('paymentMethod', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
