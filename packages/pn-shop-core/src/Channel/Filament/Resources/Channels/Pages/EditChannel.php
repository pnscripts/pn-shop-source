<?php

namespace PnShop\Channel\Filament\Resources\Channels\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PnShop\Channel\Filament\Resources\Channels\ChannelResource;

class EditChannel extends EditRecord
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        ChannelResource::publishTheme($this->getRecord());
    }
}
