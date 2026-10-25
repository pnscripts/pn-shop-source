<?php

namespace PnShop\Plugins\Speedy\Listeners;

use Illuminate\Support\Facades\DB;
use PnShop\Plugins\Speedy\SelectedOffice;
use PnShop\Plugins\Speedy\SpeedyCarrier;
use PnShop\Sales\Events\OrderPlacing;
use PnShop\Sales\Exceptions\CheckoutException;
use PnShop\Shipping\Models\ShippingMethod;

/**
 * Orders for delivery to a Speedy office keep the office the customer picked; without one
 * the order is not placed.
 */
final class RecordOffice
{
    public function __construct(private SelectedOffice $selected) {}

    public function handle(OrderPlacing $event): void
    {
        $method = $event->order->shipping_method_id !== null ? ShippingMethod::query()->find($event->order->shipping_method_id) : null;

        if ($method === null || $method->carrier !== SpeedyCarrier::CODE || $method->setting('delivery') !== 'office') {
            return;
        }

        $office = $this->selected->office();

        if ($office === null) {
            throw new CheckoutException(__('Please choose the Speedy office to deliver to.'));
        }

        DB::table('pnshop_speedy_order_offices')->insert([
            'order_id' => $event->order->id,
            'office_id' => $office['id'],
            'office_name' => mb_substr($office['name'], 0, 255),
            'office_address' => mb_substr($office['address'], 0, 500),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
