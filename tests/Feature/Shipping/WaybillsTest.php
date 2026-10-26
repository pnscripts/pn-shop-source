<?php

namespace Tests\Feature\Shipping;

use Brick\Money\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PnShop\Catalog\Models\Product;
use PnShop\Credit\Balances;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentService;
use PnShop\Payment\PaymentState;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Filament\Resources\Orders\Pages\ViewOrder;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Shipping\Contracts\ProvidesShippingLabels;
use PnShop\Shipping\Contracts\ShippingCarrier;
use PnShop\Shipping\Filament\RelationManagers\ShipmentsRelationManager;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ShipmentService;
use PnShop\Shipping\ShippingCarrierManager;
use PnShop\Shipping\ShippingLabel;
use PnShop\Shipping\ShippingLabelRequest;
use PnShop\Shipping\ShippingRequest;
use PnShop\Shipping\Testing\ShippingCarrierContractTests;
use PnShop\Shipping\Testing\ShippingLabelContractTests;
use PnShop\Shipping\Waybills;
use RuntimeException;
use Tests\Feature\Admin\AdminTestCase;

/**
 * A courier plugin's carrier that books parcels and returns the waybill PDF, and charges
 * 1.50 for collecting cash on delivery.
 */
final class WaybillCourier implements ProvidesShippingLabels, ShippingCarrier
{
    /** @var list<ShippingLabelRequest> */
    public static array $requests = [];

    public static ?string $refuse = null;

    /** Book the parcel but return no document (the courier's print failed). */
    public static bool $noDocument = false;

    public function code(): string
    {
        return 'waybill_courier';
    }

    public function label(): string
    {
        return 'Waybill courier';
    }

    public function settings(): array
    {
        return [];
    }

    public function quote(ShippingRequest $request, ShippingMethod $method): Money
    {
        return Money::of($request->cashOnDelivery === null ? '5.00' : '6.50', $request->currency());
    }

    public function trackingUrl(string $trackingNumber, ShippingMethod $method): string
    {
        return 'https://courier.example/track/'.$trackingNumber;
    }

    public function shippingLabel(ShippingLabelRequest $request, ShippingMethod $method): ShippingLabel
    {
        if (self::$refuse !== null) {
            throw new RuntimeException(self::$refuse);
        }

        self::$requests[] = $request;

        $number = 'WB'.str_pad((string) count(self::$requests), 6, '0', STR_PAD_LEFT);

        return new ShippingLabel($number, pdf: self::$noDocument ? null : "%PDF-1.4\n% waybill ".$request->reference."\n");
    }
}

class WaybillsTest extends AdminTestCase
{
    use ShippingCarrierContractTests, ShippingLabelContractTests;

    protected function carrier(): ShippingCarrier
    {
        return new WaybillCourier;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ShippingCarrierManager::class)->register(WaybillCourier::class);
        WaybillCourier::$requests = [];
        WaybillCourier::$refuse = null;
        WaybillCourier::$noDocument = false;
    }

    /** An order of 2 × 10.00 with the courier, paid with $gateway; placed by a guest. */
    private function order(string $gateway = 'cash_on_delivery', string $carrier = 'waybill_courier'): Order
    {
        $mug = Product::factory()->active()->create(['price' => '10.00', 'sale_price' => null, 'stock' => 5]);
        $mug->defaultVariant()->update(['weight' => 350]);
        $method = ShippingMethod::factory()->for(ShippingZone::factory()->create(), 'zone')->create(['name' => 'Courier', 'carrier' => $carrier, 'settings' => ['cost' => '5.00']]);

        $this->post(route('cart.store'), ['product_id' => $mug->id, 'quantity' => 2]);
        $this->post(route('checkout.store'), $this->checkoutData(PaymentMethod::factory()->create(['gateway' => $gateway])->id, ['shipping_method_id' => $method->id]))->assertSessionMissing('error');

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_the_courier_adds_its_cash_on_delivery_fee_to_the_quote_and_the_order(): void
    {
        $mug = Product::factory()->active()->create(['price' => '10.00', 'sale_price' => null, 'stock' => 5]);
        $method = ShippingMethod::factory()->for(ShippingZone::factory()->create(), 'zone')->create(['name' => 'Courier', 'carrier' => 'waybill_courier']);
        $cod = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery']);
        $card = PaymentMethod::factory()->create(['gateway' => 'bank_transfer']);
        $this->post(route('cart.store'), ['product_id' => $mug->id, 'quantity' => 1]);

        $quote = fn (?int $payment) => $this->postJson(route('checkout.quote'), ['country_code' => 'BG', 'postcode' => '1000', 'shipping_method_id' => $method->id, 'payment_method_id' => $payment])->assertOk();

        $quote(null)->assertJsonPath('options.0.price.amount', '5.00');
        $quote($card->id)->assertJsonPath('options.0.price.amount', '5.00');
        $quote($cod->id)->assertJsonPath('options.0.price.amount', '6.50')->assertJsonPath('totals.total.amount', '16.50');

        // The Store API takes the same field.
        $this->postJson('/api/store/v1/checkout/quote', ['country_code' => 'BG', 'postcode' => '1000', 'payment_method_id' => $cod->id])->assertOk();

        $this->post(route('checkout.store'), $this->checkoutData($cod->id, ['shipping_method_id' => $method->id]))->assertSessionMissing('error');
        $this->assertSame('16.50', (string) Order::query()->sole()->grandTotal()->getAmount());
    }

    public function test_staff_ship_with_a_waybill_and_the_courier_collects_what_is_due_once(): void
    {
        $order = $this->order();
        $line = $order->items->sole();
        $this->actingAsAdministrator();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('createShipment')
            ->assertActionDataSet(['waybill' => true])
            ->setActionData(['quantities' => [$line->id => 1]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Shipment created with waybill WB000001.');

        $request = WaybillCourier::$requests[0];
        $this->assertSame($order->number, $request->reference);
        $this->assertSame('26.50', (string) $request->cashOnDelivery?->getAmount());
        $this->assertSame([['title' => $line->product_title, 'quantity' => 1, 'weight' => 350]], $request->items);
        $this->assertSame('Jane Doe', $request->to->fullName());
        $this->assertSame($order->id, $request->order?->id);

        $shipment = Shipment::query()->sole();
        $this->assertSame('WB000001', $shipment->tracking_number);
        $this->assertSame('https://courier.example/track/WB000001', $shipment->tracking_url);
        $this->assertSame(2650, $shipment->cash_on_delivery);
        Storage::disk('local')->assertExists((string) $shipment->label_path);
        $this->assertSame(FulfillmentStatus::PartiallyFulfilled, $order->fresh()->fulfillment_status);

        // The second parcel: the first one already collects everything.
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$line->id => 1], 'waybill' => true])
            ->assertHasNoActionErrors();

        $this->assertNull(WaybillCourier::$requests[1]->cashOnDelivery);
        $this->assertNull(Shipment::query()->latest('id')->first()?->cash_on_delivery);

        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order->fresh(), 'pageClass' => ViewOrder::class])
            ->assertSee('WB000001')
            ->assertSee('26.50')
            ->assertTableActionVisible('downloadWaybill', $shipment)
            ->assertTableActionHidden('createWaybill', $shipment)
            ->callTableAction('downloadWaybill', $shipment)
            ->assertFileDownloaded('waybill-WB000001.pdf');
    }

    public function test_paid_orders_are_not_collected_on_delivery(): void
    {
        $order = $this->order('bank_transfer');
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);
        $this->assertNull(app(Waybills::class)->cashOnDelivery($order->fresh()));

        $this->actingAsAdministrator();
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$order->items->sole()->id => 2], 'waybill' => true])
            ->assertHasNoActionErrors();

        $this->assertNull(WaybillCourier::$requests[0]->cashOnDelivery);
        $this->assertSame(FulfillmentStatus::Fulfilled, $order->fresh()->fulfillment_status);
    }

    public function test_nothing_ships_when_the_courier_refuses(): void
    {
        $order = $this->order();
        WaybillCourier::$refuse = 'Invalid phone number';
        $this->actingAsAdministrator();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$order->items->sole()->id => 1], 'waybill' => true])
            ->assertNotified('The carrier did not create the waybill: Invalid phone number Nothing was shipped. Fix the order or the carrier settings and try again, or switch the waybill off to record the parcel yourself.');

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(FulfillmentStatus::Unfulfilled, $order->fresh()->fulfillment_status);
    }

    public function test_staff_can_switch_the_waybill_off_and_enter_a_tracking_number(): void
    {
        $order = $this->order();
        $this->actingAsAdministrator();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$order->items->sole()->id => 2], 'waybill' => false, 'tracking_number' => 'OWN-1'])
            ->assertHasNoActionErrors();

        $this->assertSame([], WaybillCourier::$requests);
        $this->assertSame('OWN-1', Shipment::query()->sole()->tracking_number);
    }

    public function test_a_shipment_recorded_without_a_waybill_gets_one_later(): void
    {
        $order = $this->order();
        $shipment = app(ShipmentService::class)->ship($order);
        $this->actingAsAdministrator();

        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order->fresh(), 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('downloadWaybill', $shipment)
            ->callTableAction('createWaybill', $shipment)
            ->assertNotified('Waybill WB000001 created.');

        $shipment->refresh();
        $this->assertSame('WB000001', $shipment->tracking_number);
        $this->assertSame(2650, $shipment->cash_on_delivery);
        $this->assertSame(2, WaybillCourier::$requests[0]->items[0]['quantity']);
        $this->assertTrue($order->history()->where('note', 'Waybill WB000001 created.')->exists());

        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order->fresh(), 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('createWaybill', $shipment);
    }

    public function test_carriers_without_waybills_keep_the_plain_form(): void
    {
        $order = $this->order('cash_on_delivery', 'flat_rate');
        $this->actingAsAdministrator();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('createShipment')
            ->assertActionDataSet(['waybill' => false])
            ->assertFormFieldHidden('waybill')
            ->assertFormFieldVisible('tracking_number');

        $shipment = app(ShipmentService::class)->ship($order);
        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order->fresh(), 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('createWaybill', $shipment)
            ->assertTableActionHidden('downloadWaybill', $shipment);
    }

    public function test_staff_who_may_only_view_orders_cannot_create_waybills(): void
    {
        $order = $this->order();
        $shipment = app(ShipmentService::class)->ship($order);
        $this->actingAsStaff(['sales.orders.view']);

        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order->fresh(), 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('createWaybill', $shipment);

        $this->assertSame([], WaybillCourier::$requests);
    }

    public function test_a_refused_parcel_sent_again_collects_what_staff_enter_but_never_more_than_is_due(): void
    {
        $order = $this->order();
        $line = $order->items->sole();
        $this->actingAsAdministrator();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$line->id => 1], 'waybill' => true])
            ->assertHasNoActionErrors();

        // The customer refused the first parcel, so the second one has to collect everything:
        // the form suggests nothing (the first parcel was to collect it all), staff enter it.
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('createShipment')
            ->assertActionDataSet(['cash_on_delivery' => '0'])
            ->setActionData(['quantities' => [$line->id => 1], 'waybill' => true, 'cash_on_delivery' => '30.00'])
            ->callMountedAction()
            ->assertNotified('The courier can collect at most $26.50 on this order.');

        $this->assertCount(1, WaybillCourier::$requests);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$line->id => 1], 'waybill' => true, 'cash_on_delivery' => '26.50'])
            ->assertHasNoActionErrors();

        $this->assertSame('26.50', (string) WaybillCourier::$requests[1]->cashOnDelivery?->getAmount());
    }

    public function test_cash_recorded_as_paid_is_not_counted_twice(): void
    {
        $order = $this->order();
        $line = $order->items->sole();
        $waybills = app(Waybills::class);

        // Parcel 1 collects 10.00 of the 26.50; the courier pays it over and it is recorded.
        $shipment = app(ShipmentService::class)->ship($order, [$line->id => 1]);
        $waybills->attach($shipment, new ShippingLabel('WB1', pdf: '%PDF-'), Money::of('10.00', $order->currency));
        $this->assertSame('16.50', (string) $waybills->cashOnDelivery($order->fresh())?->getAmount());

        $order->payments()->create(['gateway' => 'cash_on_delivery', 'payment_method_id' => $order->payment_method_id, 'status' => PaymentState::Paid, 'currency' => $order->currency, 'amount' => Money::of('10.00', $order->currency), 'refunded_amount' => Money::zero($order->currency)]);

        $this->assertSame('16.50', (string) app(PaymentService::class)->amountDue($order->fresh())->getAmount());
        $this->assertSame('16.50', (string) $waybills->cashOnDelivery($order->fresh())?->getAmount());
    }

    public function test_one_booking_at_a_time_per_order(): void
    {
        $order = $this->order();
        $shipment = app(ShipmentService::class)->ship($order);
        $lock = Cache::lock('pnshop.waybills.order.'.$order->id, 60);
        $this->assertTrue($lock->get());

        try {
            app(Waybills::class)->createFor($shipment);
            $this->fail('A second booking ran while the first held the lock.');
        } catch (OrderException $e) {
            $this->assertSame('Another parcel of this order is being booked. Try again in a moment.', $e->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame([], WaybillCourier::$requests);
        app(Waybills::class)->createFor($shipment);
        $this->assertSame('WB000001', $shipment->fresh()?->tracking_number);
    }

    public function test_parcels_with_a_tracking_number_are_not_booked_again(): void
    {
        $order = $this->order();
        $shipment = app(ShipmentService::class)->ship($order, trackingNumber: 'AB/123');
        $this->actingAsAdministrator();

        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order->fresh(), 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('createWaybill', $shipment);

        $this->expectExceptionMessage('This shipment already has a waybill.');
        app(Waybills::class)->createFor($shipment);
    }

    public function test_a_parcel_booked_without_a_document_keeps_its_number(): void
    {
        $order = $this->order();
        WaybillCourier::$noDocument = true;
        $this->actingAsAdministrator();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('createShipment', ['quantities' => [$order->items->sole()->id => 2], 'waybill' => true])
            ->assertNotified('Shipment created with waybill WB000001.');

        $shipment = Shipment::query()->sole();
        $this->assertSame('WB000001', $shipment->tracking_number);
        $this->assertFalse($shipment->hasWaybill());
        $this->assertFalse(app(Waybills::class)->canCreateFor($shipment));
    }

    public function test_no_fee_when_gift_cards_or_store_credit_leave_nothing_to_collect(): void
    {
        $mug = Product::factory()->active()->create(['price' => '10.00', 'sale_price' => null, 'stock' => 5]);
        $method = ShippingMethod::factory()->for(ShippingZone::factory()->create(), 'zone')->create(['name' => 'Courier', 'carrier' => 'waybill_courier']);
        $cod = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery']);
        [, $code] = app(Balances::class)->issueGiftCard(Money::of('50.00', 'USD'));
        $this->post(route('cart.store'), ['product_id' => $mug->id, 'quantity' => 1]);
        $this->post(route('cart.gift-cards.store'), ['gift_card' => $code])->assertSessionHasNoErrors();

        $this->postJson(route('checkout.quote'), ['country_code' => 'BG', 'postcode' => '1000', 'shipping_method_id' => $method->id, 'payment_method_id' => $cod->id])
            ->assertOk()->assertJsonPath('options.0.price.amount', '5.00');

        $this->post(route('checkout.store'), $this->checkoutData($cod->id, ['shipping_method_id' => $method->id]))->assertSessionMissing('error');
        $this->assertSame('15.00', (string) Order::query()->sole()->grandTotal()->getAmount());
    }
}
