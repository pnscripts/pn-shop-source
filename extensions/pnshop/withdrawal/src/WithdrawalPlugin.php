<?php

namespace PnShop\Plugins\Withdrawal;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use PnShop\Extension\Plugin;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Plugins\Withdrawal\Policies\WithdrawalPolicy;

class WithdrawalPlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        Gate::policy(Withdrawal::class, WithdrawalPolicy::class);

        // Limits of their own, so other storefront requests never use them up.
        RateLimiter::for('pnshop-withdrawal', fn (Request $request) => Limit::perMinute(10)->by('withdrawal|'.$request->ip()));
        RateLimiter::for('pnshop-withdrawal-confirm', fn (Request $request) => Limit::perMinute(5)->by('withdrawal-confirm|'.$request->ip()));

        // Where the "Withdraw from contract here" link points (footer and order pages).
        Inertia::share('withdrawal', fn () => ['url' => route('withdrawal.create')]);
    }
}
