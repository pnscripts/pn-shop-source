<?php

namespace Tests\Feature\Extensions;

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PnShop\Cart\CartItemDTO;
use PnShop\Catalog\Models\Product;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Plugins\Speedy\SpeedyCarrier;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ShippingCarrierManager;
use PnShop\Shipping\ShippingRequest;

/**
 * The Speedy plugin (extensions/pnshop/speedy) against a stand-in for Speedy's API
 * (Http::fake with the documented request and response shapes; no Speedy account).
 */
class SpeedyPluginTest extends ShippedPluginTestCase
{
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
            self::API.'calculate' => Http::response(['calculations' => [['serviceId' => 505, 'price' => ['amount' => 5.17, 'vat' => 1.03, 'total' => (float) $price, 'currency' => $currency]]]]),
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
        $this->assertSame(390, collect($order->totals)->firstWhere('code', 'shipping')['amount'] ?? null);
        $this->assertSame('ПЛОВДИВ - ЦЕНТЪР', DB::table('pnshop_speedy_order_offices')->where('order_id', $order->id)->value('office_name'));
    }
}
