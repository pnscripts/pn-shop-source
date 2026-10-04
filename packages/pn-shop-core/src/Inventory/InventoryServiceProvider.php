<?php

namespace PnShop\Inventory;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\Models\StockMovement;
use PnShop\Inventory\Policies\StockLocationPolicy;

/**
 * Stock locations, levels and the stock movement ledger.
 */
class InventoryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryService::class);

        Relation::morphMap([
            'stock_location' => StockLocation::class,
            'stock_movement' => StockMovement::class,
        ]);
    }

    protected function bootModule(): void
    {
        Gate::policy(StockLocation::class, StockLocationPolicy::class);
    }

    protected function permissions(): array
    {
        return [
            new Permission('catalog.inventory.manage', 'Adjust stock', 'Catalog'),
        ];
    }
}
