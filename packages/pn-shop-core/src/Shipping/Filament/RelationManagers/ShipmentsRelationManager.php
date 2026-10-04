<?php

namespace PnShop\Shipping\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\Models\ShipmentLine;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Shipments';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lines.item', 'location']))
            ->columns([
                TextColumn::make('shipped_at')->label('Shipped')->dateTime(),
                TextColumn::make('carrier_name')->label('Method')->placeholder('—'),
                TextColumn::make('location.name')->label('From')->placeholder('—'),
                TextColumn::make('items')
                    ->state(fn (Shipment $record) => $record->lines->map(fn (ShipmentLine $line) => $line->quantity.' × '.$line->item?->product_title)->all())
                    ->listWithLineBreaks(),
                TextColumn::make('tracking_number')->label('Tracking')->placeholder('—')
                    ->url(fn (Shipment $record) => $record->tracking_url, shouldOpenInNewTab: true),
                TextColumn::make('note')->placeholder('—')->wrap(),
            ]);
    }
}
