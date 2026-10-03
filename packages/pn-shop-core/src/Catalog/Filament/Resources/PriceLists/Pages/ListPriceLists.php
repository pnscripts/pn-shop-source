<?php

namespace PnShop\Catalog\Filament\Resources\PriceLists\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use PnShop\Catalog\Filament\Resources\PriceLists\PriceListResource;

class ListPriceLists extends ListRecords
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
