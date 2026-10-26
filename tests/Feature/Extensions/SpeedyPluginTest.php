<?php

namespace Tests\Feature\Extensions;

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PnShop\Cart\CartItemDTO;
use PnShop\Catalog\Models\Product;
use PnShop\Customer\PostalAddress;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Plugins\Speedy\SpeedyCarrier;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;
use PnShop\Shipping\Contracts\ShippingCarrier;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ShippingCarrierManager;
use PnShop\Shipping\ShippingLabelRequest;
use PnShop\Shipping\ShippingRequest;
use PnShop\Shipping\Testing\ShippingLabelContractTests;

/**
 * The Speedy plugin (extensions/pnshop/speedy) against a stand-in for Speedy's API
 * (Http::fake with the documented request and response shapes; no Speedy account).
 */
class SpeedyPluginTest extends ShippedPluginTestCase
{
    use ShippingLabelContractTests;

    private const PDF = "%PDF-1.4\n% Speedy waybill\n";

    protected function carrier(): ShippingCarrier
    {
        $this->fakeSpeedy();

        return app(SpeedyCarrier::class);
    }

    protected function shippingLabelSettings(): array
    {
        return ['delivery' => 'address'];
    }

    private const API = 'https://api.speedy.bg/v1/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePlugin('pnshop/speedy');
        app(Settings::class)->set('plugin.pnshop_speedy', ['username' => 'api-user', 'password' => 'api-pass', 'dropoff_office_id' => 9]);
    }

    private function fakeSpeedy(string $price = '6.20', string $currency = 'USD'): void
    {
        Http::fake([
            self::API.'location/site' => fn (Request $request) => Http::response(['sites' => $request['postCode'] === '4000' ? [['id' => 56784, 'name' => 'ПЛОВДИВ', 'postCode' => '4000']] : []]),
            self::API.'location/office' => function (Request $request) {
                $all = [
                    ['id' => 101, 'name' => 'ПЛОВДИВ - ЦЕНТЪР', 'address' => ['siteId' => 56784, 'fullAddressString' => 'гр. Пловдив, ул. Пример 3']],
                    ['id' => 202, 'name' => 'СОФИЯ - ЛОЗЕНЕЦ', 'address' => ['siteId' => 68134, 'fullAddressString' => 'гр. София, ул. Пример 4']],
                ];

                return Http::response(['offices' => array_values(array_filter($all, fn ($office) => ! isset($request['siteId']) || $office['address']['siteId'] === $request['siteId']))]);
            },
            // Cash on delivery costs 1.20 more.
            self::API.'calculate' => fn (Request $request) => Http::response(['calculations' => [['serviceId' => 505, 'price' => ['amount' => 5.17, 'vat' => 1.03, 'total' => (float) $price + (isset($request['service']['additionalServices']['cod']) ? 1.2 : 0), 'currency' => $currency]]]]),
            self::API.'shipment' => Http::response(['id' => '61234567890', 'parcels' => [['id' => '61234567890', 'seqNo' => 1]], 'price' => ['total' => 7.4, 'currency' => 'EUR']]),
            self::API.'print' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function method(string $delivery, array $settings = []): ShippingMethod
    {
        return ShippingMethod::factory()->for(ShippingZone::factory()->create(['countries' => ['BG']]), 'zone')
            ->create(['name' => 'Speedy '.$delivery, 'carrier' => SpeedyCarrier::CODE, 'settings' => ['delivery' => $delivery, 'default_weight' => '0.5', ...$settings]]);
    }

    /** Two units of 1.2 kg. */
    private function request(string $postcode = '4000', string $country = 'BG'): ShippingRequest
    {
        $item = new CartItemDTO(1, 1, 'Mug', 'mug', '', null, Money::of('20.00', 'USD'), null, null, null, 2, 1200);

        return new ShippingRequest(collect([$item]), Money::of('40.00', 'USD'), $country, $postcode);
    }

    public function test_it_is_registered_with_its_tracking_page(): void
    {
        $this->assertTrue(app(ShippingCarrierManager::class)->has('speedy'));
        $this->assertSame('https://www.speedy.bg/bg/track-shipment?shipmentNumber=61234567890', app(SpeedyCarrier::class)->trackingUrl('61234567890', $this->method('address')));
    }

    public function test_an_address_delivery_is_priced_by_speedy(): void
    {
        $this->fakeSpeedy('6.20');

        $this->assertSame('6.20', (string) app(SpeedyCarrier::class)->quote($this->request(), $this->method('address'))?->getAmount());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/calculate')
            && $request['userName'] === 'api-user'
            && $request['password'] === 'api-pass'
            && $request['recipient'] === ['privatePerson' => true, 'addressLocation' => ['countryId' => 100, 'siteId' => 56784]]
            && $request['service']['serviceIds'] === [505]
            && $request['content']['parcelsCount'] === 1
            && abs($request['content']['totalWeight'] - 2.4) < 0.0001
            && $request['payment'] === ['courierServicePayer' => 'SENDER']
            && $request['sender'] === ['privatePerson' => false, 'dropoffOfficeId' => 9]);
    }

    public function test_office_delivery_uses_the_chosen_office(): void
    {
        $this->fakeSpeedy();
        $method = $this->method('office');

        app(SpeedyCarrier::class)->quote($this->request(), $method);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/calculate') && $request['recipient'] === ['privatePerson' => true, 'pickupOfficeId' => 101]);

        $this->postJson(route('speedy.office'), ['code' => 999])->assertStatus(422);
        $this->postJson(route('speedy.office'), ['code' => 101])->assertOk();
        $this->getJson(route('speedy.offices', ['postcode' => '4000']))->assertOk()->assertJsonPath('offices.0.code', '101')->assertJsonPath('selected', '101');
        $this->getJson(route('speedy.offices', ['search' => 'лозенец']))->assertJsonPath('offices.0.code', '202');
    }

    public function test_errors_fall_back_and_unknown_postcodes_are_not_priced(): void
    {
        Http::fake([self::API.'*' => Http::response(['error' => ['message' => 'Invalid username or password', 'code' => 1]])]);
        $this->assertNull(app(SpeedyCarrier::class)->quote($this->request(), $this->method('address')));
        $this->assertSame('7.00', (string) app(SpeedyCarrier::class)->quote($this->request(), $this->method('address', ['fallback_price' => '7']))?->getAmount());

        $this->fakeSpeedy();
        $this->assertNull(app(SpeedyCarrier::class)->quote($this->request('9999'), $this->method('address')));
        $this->assertNull(app(SpeedyCarrier::class)->quote($this->request('4000', 'GR'), $this->method('address', ['fallback_price' => '7'])));
    }

    public function test_orders_for_office_delivery_keep_the_office_and_need_one(): void
    {
        $this->fakeSpeedy('3.90');
        $method = $this->method('office');
        $payment = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery']);
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 5]);
        $data = $this->checkoutData($payment->id, ['shipping_method_id' => $method->id, 'shipping' => ['postcode' => '4000', 'city' => 'Plovdiv']]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->post(route('checkout.store'), $data)->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());

        $this->postJson(route('speedy.office'), ['code' => 101])->assertOk();
        $this->post(route('checkout.store'), $data)->assertSessionMissing('error');

        $order = Order::query()->sole();
        // 3.90 for the parcel + 1.20 Speedy charges for collecting cash on delivery.
        $this->assertSame(510, collect($order->totals)->firstWhere('code', 'shipping')['amount'] ?? null);
        $this->assertSame('ПЛОВДИВ - ЦЕНТЪР', DB::table('pnshop_speedy_order_offices')->where('order_id', $order->id)->value('office_name'));
    }

    public function test_cash_on_delivery_adds_speedys_fee_to_the_price(): void
    {
        $this->fakeSpeedy('6.20');
        $request = $this->request();
        $cod = new ShippingRequest($request->items, $request->subtotal, 'BG', '4000', cashOnDelivery: Money::of('40.00', 'USD'));

        $this->assertSame('7.40', (string) app(SpeedyCarrier::class)->quote($cod, $this->method('address'))?->getAmount());
        Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/calculate')
            && ($sent['service']['additionalServices'] ?? null) === ['cod' => ['amount' => 40.0, 'currencyCode' => 'USD', 'processingType' => 'CASH']]);
    }

    public function test_waybills_book_the_shipment_and_keep_its_pdf(): void
    {
        $this->fakeSpeedy();
        $to = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'company' => 'Ana Ltd', 'line1' => 'ul. Пример 5', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG', 'phone' => '+359 888-123-456']);

        $label = app(SpeedyCarrier::class)->shippingLabel(new ShippingLabelRequest('100042', $to, null, [['title' => 'Mug', 'quantity' => 2, 'weight' => 600]], Money::of('47.40', 'EUR'), 'ana@example.com'), $this->method('address', ['waybill_paper' => 'A6']));

        $this->assertSame('61234567890', $label->trackingNumber);
        $this->assertSame(self::PDF, $label->pdf);
        $this->assertNull($label->labelUrl);
        $this->assertSame('7.40', (string) $label->price?->getAmount());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/shipment')
            && $request['recipient'] === ['clientName' => 'Ana Ltd', 'contactName' => 'Ana Petrova', 'privatePerson' => false, 'phone1' => ['number' => '+359 888123456'], 'email' => 'ana@example.com', 'address' => ['countryId' => 100, 'siteId' => 56784, 'postCode' => '4000', 'addressNote' => 'ul. Пример 5']]
            && $request['service'] === ['autoAdjustPickupDate' => true, 'serviceId' => 505, 'additionalServices' => ['cod' => ['amount' => 47.4, 'currencyCode' => 'EUR', 'processingType' => 'CASH']]]
            && $request['content'] === ['parcelsCount' => 1, 'totalWeight' => 1.2, 'contents' => '2 × Mug', 'package' => 'BOX']
            && $request['ref1'] === '100042'
            && $request['sender'] === ['privatePerson' => false, 'dropoffOfficeId' => 9]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/print')
            && $request['paperSize'] === 'A6'
            && $request['parcels'] === [['parcel' => ['id' => '61234567890']]]
            && $request['userName'] === 'api-user');
    }

    public function test_a_refused_print_reaches_staff(): void
    {
        Http::fake([
            self::API.'location/site' => Http::response(['sites' => [['id' => 56784, 'name' => 'ПЛОВДИВ']]]),
            self::API.'shipment' => Http::response(['id' => '61234567890', 'parcels' => [['id' => '61234567890']]]),
            self::API.'print' => Http::response(['error' => ['message' => 'Parcel not found']]),
        ]);
        $to = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'line1' => 'x', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG']);

        $this->expectExceptionMessage('Speedy did not return the waybill: Parcel not found');
        app(SpeedyCarrier::class)->shippingLabel(new ShippingLabelRequest('1', $to, null, []), $this->method('address'));
    }
}
