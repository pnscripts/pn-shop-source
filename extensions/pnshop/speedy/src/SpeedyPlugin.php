<?php

namespace PnShop\Plugins\Speedy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
