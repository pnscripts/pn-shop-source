<?php

namespace PnShop\Plugins\HandlingFee;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use PnShop\Cart\ShoppingCartService;
use PnShop\Cart\Totals\CartCalculator;
use PnShop\Extension\Plugin;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Localization\CurrencyConverter;
use PnShop\Money\MoneyPresenter;
use PnShop\Plugins\HandlingFee\Models\Exemption;
use PnShop\Plugins\HandlingFee\Policies\ExemptionPolicy;

class HandlingFeePlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        $this->app->make(PipelineRegistry::class)->stage(CartCalculator::PIPELINE, ApplyHandlingFee::class, 300);

        Gate::policy(Exemption::class, ExemptionPolicy::class);

        // Data for the storefront slot (storefront/storefront.js): how much more avoids the fee.
        Inertia::share('handlingFee', fn () => request()->routeIs('cart.*') ? $this->hint() : null);
    }

    /**
     * @return array{missing: array<string, mixed>|null}|null
     */
    private function hint(): ?array
    {
        $totals = $this->app->make(ShoppingCartService::class)->totals();
        $threshold = app(CurrencyConverter::class)->fromDefault((string) ($this->setting('threshold') ?: '0'), $totals->currency());

        if ($totals->line('handling_fee') === null || $threshold->isZero()) {
            return null;
        }

        return ['missing' => MoneyPresenter::present($threshold->minus($totals->subtotal))];
    }

    public function uninstall(bool $keepData): void
    {
        // Everything the plugin stores is in its own table, which the manager drops
        // (with its migration) when the data is not kept. Nothing else to clean up.
    }
}
