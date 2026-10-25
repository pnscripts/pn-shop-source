<?php

namespace PnShop\Plugins\Speedy;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use PnShop\Extension\Plugin;
use PnShop\Plugins\Speedy\Listeners\RecordOffice;
use PnShop\Sales\Events\OrderPlacing;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\ShippingCarrierManager;

class SpeedyPlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        $this->app->make(ShippingCarrierManager::class)->register(SpeedyCarrier::class);
        // The office picker's own limit (typing a search sends a request), apart from checkout's.
        RateLimiter::for('pnshop-speedy', fn (Request $request) => Limit::perMinute(60)->by('speedy|'.$request->ip()));
        Event::listen(OrderPlacing::class, RecordOffice::class);

        // For the office picker at checkout (storefront/storefront.js).
        Inertia::share('speedy', fn () => request()->routeIs('checkout.*') ? [
            'office_method_ids' => ShippingMethod::query()->where('carrier', SpeedyCarrier::CODE)->get()
                ->filter(fn (ShippingMethod $method) => $method->setting('delivery') === 'office')
                ->map(fn (ShippingMethod $method) => (string) $method->id)->values()->all(),
            'offices_url' => route('speedy.offices'),
            'choose_url' => route('speedy.office'),
        ] : null);

        // The chosen office on the order page.
        Inertia::share('speedyOffice', fn () => request()->routeIs('orders.show') && ($order = request()->route('order')) !== null
            ? DB::table('pnshop_speedy_order_offices')->where('order_id', $order instanceof Model ? $order->getKey() : (int) $order)->first(['office_id', 'office_name', 'office_address'])
            : null);
    }
}
