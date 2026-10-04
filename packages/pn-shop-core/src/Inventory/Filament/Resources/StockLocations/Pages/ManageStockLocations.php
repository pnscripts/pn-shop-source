<?php

namespace PnShop\Inventory\Filament\Resources\StockLocations\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use PnShop\Inventory\Filament\Resources\StockLocations\StockLocationResource;

class ManageStockLocations extends ManageRecords
{
    protected static string $resource = StockLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
