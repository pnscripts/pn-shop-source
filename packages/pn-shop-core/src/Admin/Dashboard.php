<?php

namespace PnShop\Admin;

use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use PnShop\Channel\Models\Channel;

/**
 * The admin home. With several channels, its widgets can be narrowed to one of them.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('channel_id')
                ->label('Channel')
                ->options(fn () => Channel::query()->orderBy('position')->pluck('name', 'id')->all())
                ->placeholder('All channels')
                ->visible(fn () => Channel::query()->count() > 1),
        ]);
    }
}
