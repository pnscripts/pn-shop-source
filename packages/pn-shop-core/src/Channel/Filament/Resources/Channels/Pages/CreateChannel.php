<?php

namespace PnShop\Channel\Filament\Resources\Channels\Pages;

use Filament\Resources\Pages\CreateRecord;
use PnShop\Channel\Filament\Resources\Channels\ChannelResource;

class CreateChannel extends CreateRecord
{
    protected static string $resource = ChannelResource::class;

    protected function afterCreate(): void
    {
        ChannelResource::publishTheme($this->getRecord());
    }
}
