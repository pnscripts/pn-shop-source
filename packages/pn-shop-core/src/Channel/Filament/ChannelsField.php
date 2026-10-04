<?php

namespace PnShop\Channel\Filament;

use Filament\Forms\Components\Select;
use PnShop\Channel\Models\Channel;

/**
 * "Only in these channels" for products, categories and pages (models using
 * LimitedToChannels). Shown once there are several channels; empty means every channel.
 */
final class ChannelsField
{
    public static function make(): Select
    {
        return Select::make('channels')
            ->label('Channels')
            ->relationship('channels', 'name')
            ->multiple()
            ->preload()
            ->placeholder('Every channel')
            ->helperText('Empty: shown in every channel.')
            ->visible(fn () => Channel::query()->count() > 1);
    }
}
