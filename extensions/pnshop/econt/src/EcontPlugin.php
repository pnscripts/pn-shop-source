<?php

namespace PnShop\Plugins\Econt;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use PnShop\Extension\Plugin;
use PnShop\Plugins\Econt\Listeners\RecordOffice;
use PnShop\Sales\Events\OrderPlacing;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingCarrierManager;

class EcontPlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        $this->app->make(ShippingCarrierManager::class)->register(EcontCarrier::class);
        // The office picker's own limit (typing a search sends a request), apart from checkout's.
        RateLimiter::for('pnshop-econt', fn (Request $request) => Limit::perMinute(60)->by('econt|'.$request->ip()));
        Event::listen(OrderPlacing::class, RecordOffice::class);

        // For the office picker at checkout (storefront/storefront.js).
        Inertia::share('econt', fn () => request()->routeIs('checkout.*') ? [
            'office_method_ids' => ShippingMethod::query()->where('carrier', EcontCarrier::CODE)->get()
                ->filter(fn (ShippingMethod $method) => $method->setting('delivery') === 'office')
                ->map(fn (ShippingMethod $method) => (string) $method->id)->values()->all(),
            'offices_url' => route('econt.offices'),
            'choose_url' => route('econt.office'),
        ] : null);

        // The chosen office on the order page.
        Inertia::share('econtOffice', fn () => request()->routeIs('orders.show') && ($order = request()->route('order')) !== null
            ? DB::table('pnshop_econt_order_offices')->where('order_id', $order instanceof Model ? $order->getKey() : (int) $order)->first(['office_code', 'office_name', 'office_address'])
            : null);
    }
}
