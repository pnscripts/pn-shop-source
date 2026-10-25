<?php

namespace PnShop\Plugins\Withdrawal;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use PnShop\Extension\Plugin;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Plugins\Withdrawal\Policies\WithdrawalPolicy;

class WithdrawalPlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        Gate::policy(Withdrawal::class, WithdrawalPolicy::class);

        // Where the "Withdraw from contract here" link points (footer and order pages).
        Inertia::share('withdrawal', fn () => ['url' => route('withdrawal.create')]);
    }
}
