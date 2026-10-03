<?php

namespace PnShop\Catalog\Filament\Resources\PriceLists\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PnShop\Catalog\Filament\Resources\PriceLists\PriceListResource;

class EditPriceList extends EditRecord
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
