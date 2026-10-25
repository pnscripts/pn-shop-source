<?php

namespace PnShop\Shipping;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Cart\Totals\CartCalculator;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Sales\Policies\OrderRecordPolicy;
use PnShop\Shipping\Carriers\FlatRate;
use PnShop\Shipping\Carriers\FreeShipping;
use PnShop\Shipping\Carriers\PriceBased;
use PnShop\Shipping\Carriers\StorePickup;
use PnShop\Shipping\Carriers\WeightBased;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\Policies\ShippingPolicy;

/**
 * Shipping zones, methods and carriers; shipments.
 */
class ShippingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShippingCarrierManager::class, function ($app) {
            $manager = new ShippingCarrierManager($app);

            foreach ([FlatRate::class, FreeShipping::class, StorePickup::class, WeightBased::class, PriceBased::class] as $carrier) {
                $manager->register($carrier);
            }

            return $manager;
        });

        Relation::morphMap([
            'shipping_zone' => ShippingZone::class,
            'shipping_method' => ShippingMethod::class,
            'shipment' => Shipment::class,
        ]);
    }

    protected function permissions(): array
    {
        return [
            new Permission('store.shipping.manage', 'Manage shipping zones and methods', 'Store'),
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(ShippingZone::class, ShippingPolicy::class);
        Gate::policy(ShippingMethod::class, ShippingPolicy::class);
        Gate::policy(Shipment::class, OrderRecordPolicy::class);

        $this->app->make(PipelineRegistry::class)->stage(CartCalculator::PIPELINE, ApplyShipping::class, ApplyShipping::PRIORITY);
    }
}
