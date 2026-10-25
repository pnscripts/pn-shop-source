<?php

namespace PnShop\Plugins\BgEuro\Filament\Widgets;

use Filament\Widgets\Widget;
use PnShop\Plugins\BgEuro\EuroCheck;

/**
 * Dashboard card: does the shop sell in euro only?
 */
class EuroCheckWidget extends Widget
{
    protected string $view = 'pnshop_bg_euro::euro-check';

    protected static ?int $sort = 5;

    // A few cheap checks: shown with the page rather than loaded afterwards.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('store.localization.manage') ?? false;
    }

    /**
     * @return array{results: list<array{ok: bool, message: string}>}
     */
    protected function getViewData(): array
    {
        return ['results' => app(EuroCheck::class)->results()];
    }
}
