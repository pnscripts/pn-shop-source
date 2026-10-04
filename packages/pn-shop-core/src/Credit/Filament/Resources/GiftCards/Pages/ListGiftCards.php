<?php

namespace PnShop\Credit\Filament\Resources\GiftCards\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use PnShop\Credit\Filament\Resources\GiftCards\GiftCardResource;

class ListGiftCards extends ListRecords
{
    protected static string $resource = GiftCardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Issue gift card')];
    }
}
