<?php

namespace PnShop\Plugins\BgEuro\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use PnShop\Plugins\BgEuro\EuroCheck;

/**
 * Dashboard card: does the shop sell in euro only?
 */
class EuroCheckWidget extends Widget
{
    protected static ?int $sort = 5;

    // A few cheap checks: shown with the page rather than loaded afterwards.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('store.localization.manage') ?? false;
    }

    public function render(): View
    {
        return view()->file(dirname(__DIR__, 3).'/resources/views/euro-check.blade.php', ['results' => app(EuroCheck::class)->results()]);
    }
}
