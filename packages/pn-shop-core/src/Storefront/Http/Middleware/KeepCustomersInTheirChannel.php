<?php

namespace PnShop\Storefront\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PnShop\Customer\CustomerAccounts;
use PnShop\Customer\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * A channel with separate customer accounts does not know the customers of the others (and
 * the other way round). Path channels share the browser session, so a customer signed in on
 * one is signed out here, on this device only (their other devices stay signed in).
 */
class KeepCustomersInTheirChannel
{
    public function __construct(private CustomerAccounts $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The host application decides which driver the "web" guard uses, so only a guard that
        // can sign out a single device (the session guard) is asked to.
        /** @var Guard $guard */
        $guard = Auth::guard('web');
        $customer = $guard->user();

        if ($customer instanceof User && ! $this->accounts->belongsHere($customer) && method_exists($guard, 'logoutCurrentDevice')) {
            $guard->logoutCurrentDevice();
        }

        return $next($request);
    }
}
