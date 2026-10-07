<?php

namespace PnShop\Api\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PnShop\Cart\CartRepository;
use PnShop\Customer\CustomerAccounts;
use PnShop\Customer\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Store API: the customer behind the bearer token (optional unless the route also uses
 * auth:store-api) and the guest cart from the X-Cart-Token header.
 *
 * The customer is also set on the "web" guard, so code shared with the storefront (the cart,
 * customer-group prices) sees the same customer as on the website.
 */
class StoreCustomer
{
    public const CART_HEADER = 'X-Cart-Token';

    public function __construct(private CartRepository $carts) {}

    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('store-api');
        $customer = Auth::guard('store-api')->user();

        // A token that does not work here is an error, not a silent guest visit; neither is a
        // token of another channel's separate customer accounts.
        if ($customer instanceof User && ! app(CustomerAccounts::class)->belongsHere($customer)) {
            $customer = null;
        }

        if ($customer === null && $request->bearerToken() !== null) {
            throw new AuthenticationException(guards: ['store-api']);
        }

        if ($customer !== null) {
            Auth::guard('web')->setUser($customer);
        }

        $this->carts->useStatelessToken($request->header(self::CART_HEADER));

        return $next($request);
    }
}
