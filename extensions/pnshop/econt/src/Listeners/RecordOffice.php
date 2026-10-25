<?php

namespace PnShop\Plugins\Econt\Listeners;

use Illuminate\Support\Facades\DB;
use PnShop\Plugins\Econt\EcontCarrier;
use PnShop\Plugins\Econt\SelectedOffice;
use PnShop\Sales\Events\OrderPlacing;
use PnShop\Sales\Exceptions\CheckoutException;
use PnShop\Shipping\Models\ShippingMethod;

/**
 * Orders for delivery to an Econt office keep the office the customer picked; without one
 * the order is not placed.
 */
final class RecordOffice
{
    public function __construct(private SelectedOffice $selected) {}

    public function handle(OrderPlacing $event): void
    {
        $method = $event->order->shipping_method_id !== null ? ShippingMethod::query()->find($event->order->shipping_method_id) : null;

        if ($method === null || $method->carrier !== EcontCarrier::CODE || $method->setting('delivery') !== 'office') {
            return;
        }

        $office = $this->selected->office();

        if ($office === null) {
            throw new CheckoutException(__('Please choose the Econt office to deliver to.'));
        }

        DB::table('pnshop_econt_order_offices')->insert([
            'order_id' => $event->order->id,
            'office_code' => $office['code'],
            'office_name' => mb_substr($office['name'], 0, 255),
            'office_address' => mb_substr($office['address'], 0, 500),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
