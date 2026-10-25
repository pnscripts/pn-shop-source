<?php

namespace PnShop\Promotion;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PnShop\Cart\Totals\CartCalculator;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Promotion\Actions\BuyXGetYAction;
use PnShop\Promotion\Actions\FixedOffAction;
use PnShop\Promotion\Actions\FreeShippingAction;
use PnShop\Promotion\Actions\PercentOffAction;
use PnShop\Promotion\Conditions\CustomerGroupCondition;
use PnShop\Promotion\Conditions\ProductsCondition;
use PnShop\Promotion\Conditions\QuantityCondition;
use PnShop\Promotion\Conditions\ShippingCountryCondition;
use PnShop\Promotion\Conditions\SubtotalCondition;
use PnShop\Promotion\Models\Coupon;
use PnShop\Promotion\Models\Promotion;
use PnShop\Promotion\Policies\PromotionPolicy;
use PnShop\Sales\Events\OrderPlacing;
use PnShop\Sales\Events\OrderReopening;
use PnShop\Sales\Events\OrderStateChanged;

/**
 * Promotions (cart rules) built from registered conditions and actions, coupons, and
 * usage limits; discounts are a stage of the cart.totals pipeline.
 */
class PromotionServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromotionRegistry::class);

        Relation::morphMap(['promotion' => Promotion::class]);
    }

    protected function permissions(): array
    {
        return [new Permission('marketing.promotions.manage', 'Manage promotions and coupons', 'Marketing')];
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PromotionRegistry::class);

        foreach ([SubtotalCondition::class, QuantityCondition::class, ProductsCondition::class, CustomerGroupCondition::class, ShippingCountryCondition::class] as $condition) {
            $registry->condition($condition);
        }

        foreach ([PercentOffAction::class, FixedOffAction::class, BuyXGetYAction::class, FreeShippingAction::class] as $action) {
            $registry->action($action);
        }

        $pipelines = $this->app->make(PipelineRegistry::class);
        $pipelines->stage(CartCalculator::PIPELINE, ApplyPromotions::class, ApplyPromotions::PRIORITY);
        $pipelines->stage(CartCalculator::PIPELINE, ApplyShippingPromotions::class, ApplyShippingPromotions::PRIORITY);

        Event::listen(OrderPlacing::class, [Redemptions::class, 'record']);
        Event::listen(OrderStateChanged::class, [Redemptions::class, 'releaseOnCancel']);
        Event::listen(OrderReopening::class, [Redemptions::class, 'retakeOnReopen']);

        Gate::policy(Promotion::class, PromotionPolicy::class);
        Gate::policy(Coupon::class, PromotionPolicy::class);
    }
}
