<?php

namespace PnShop\Storefront\Http\Middleware;

use Closure;
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
        $guard = Auth::guard('web');
        $customer = $guard->user();

        if ($customer instanceof User && ! $this->accounts->belongsHere($customer) && method_exists($guard, 'logoutCurrentDevice')) {
            $guard->logoutCurrentDevice();
        }

        return $next($request);
    }
}
