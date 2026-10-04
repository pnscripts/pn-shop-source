<?php

namespace PnShop\Catalog\Filament\Resources\Products\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use PnShop\Catalog\Filament\Resources\Products\ProductResource;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\ProductType;
use PnShop\Inventory\Filament\StockActions;
use PnShop\Localization\Filament\SavesTranslations;

class EditProduct extends EditRecord
{
    use SavesTranslations;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        // Simple products: stock of the one variant at each location (variable products have these per variant).
        $variant = fn (mixed $record) => $record instanceof Product && $record->type === ProductType::Simple ? $record->defaultVariant() : null;

        return [StockActions::byLocation($variant), StockActions::transfer($variant), DeleteAction::make(), RestoreAction::make()];
    }
}
