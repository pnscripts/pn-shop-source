<?php

namespace Tests\Feature\Extensions;

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PnShop\Cart\CartItemDTO;
use PnShop\Catalog\Models\Product;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Plugins\Econt\EcontCarrier;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ShippingCarrierManager;
use PnShop\Shipping\ShippingRequest;

/**
 * The Econt plugin (extensions/pnshop/econt) against a stand-in for Econt's demo service
 * (Http::fake with the documented request and response shapes; no Econt account).
 */
class EcontPluginTest extends ShippedPluginTestCase
{
    private const DEMO = 'https://demo.econt.com/ee/services/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePlugin('pnshop/econt');
        app(Settings::class)->set('plugin.pnshop_econt', [
            'username' => 'demo-user',
            'password' => 'demo-pass',
            'sender_name' => 'Example Shop',
            'sender_phone' => '0888000000',
            'sender_office_code' => '1127',
        ]);
    }

    private function fakeEcont(string $price = '5.90', string $currency = 'EUR'): void
    {
        Http::fake([
            self::DEMO.'Nomenclatures/NomenclaturesService.getCities.json' => Http::response(['cities' => [
                ['id' => 41, 'name' => 'София', 'postCode' => '1000'],
                ['id' => 77, 'name' => 'Пловдив', 'postCode' => '4000'],
            ]]),
            self::DEMO.'Nomenclatures/NomenclaturesService.getOffices.json' => function (Request $request) {
                $all = [
                    ['code' => '1127', 'name' => 'София Център', 'address' => ['fullAddress' => 'ул. Пример 1', 'city' => ['id' => 41]]],
                    ['code' => '4001', 'name' => 'Пловдив Тракия', 'address' => ['fullAddress' => 'бул. Пример 2', 'city' => ['id' => 77]]],
                ];

                return Http::response(['offices' => array_values(array_filter($all, fn ($office) => ! isset($request['cityID']) || $office['address']['city']['id'] === $request['cityID']))]);
            },
            self::DEMO.'Shipments/LabelService.createLabel.json' => Http::response(['label' => ['totalPrice' => (float) $price, 'currency' => $currency, 'senderDueAmount' => (float) $price]]),
        ]);
    }

    private function method(string $delivery, array $settings = []): ShippingMethod
    {
        return ShippingMethod::factory()->for(ShippingZone::factory()->create(['countries' => ['BG']]), 'zone')
            ->create(['name' => 'Econt '.$delivery, 'carrier' => EcontCarrier::CODE, 'settings' => ['delivery' => $delivery, 'default_weight' => '0.5', ...$settings]]);
    }

    /** Two units of 1.2 kg. */
    private function request(string $postcode = '4000', string $country = 'BG'): ShippingRequest
    {
        $item = new CartItemDTO(1, 1, 'Mug', 'mug', '', null, Money::of('20.00', 'USD'), null, null, null, 2, 1200);

        return new ShippingRequest(collect([$item]), Money::of('40.00', 'USD'), $country, $postcode);
    }

    public function test_it_is_registered_as_a_carrier(): void
    {
        $this->assertTrue(app(ShippingCarrierManager::class)->has('econt'));
        $this->assertSame('https://www.econt.com/services/track-shipment/1051234567', app(EcontCarrier::class)->trackingUrl('1051234567', $this->method('address')));
    }

    public function test_an_address_delivery_is_priced_by_econt(): void
    {
        $this->fakeEcont('7.40', 'USD');

        $price = app(EcontCarrier::class)->quote($this->request('4000'), $this->method('address'));

        $this->assertSame('7.40', (string) $price?->getAmount());
        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), 'LabelService.createLabel.json')) {
                return false;
            }

            $label = $request['label'];

            return $request['mode'] === 'calculate'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('demo-user:demo-pass'))
                && $label['senderOfficeCode'] === '1127'
                && $label['receiverAddress']['city']['id'] === 77
                && $label['receiverAddress']['city']['postCode'] === '4000'
                && $label['shipmentType'] === 'PACK'
                && $label['packCount'] === 1
                && abs($label['weight'] - 2.4) < 0.0001;
        });
    }

    public function test_office_delivery_uses_the_chosen_office(): void
    {
        $this->fakeEcont('4.10', 'USD');
        $method = $this->method('office');

        // Before a choice: any office in the customer's town.
        app(EcontCarrier::class)->quote($this->request('4000'), $method);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'createLabel.json') && $request['label']['receiverOfficeCode'] === '4001');

        $this->postJson(route('econt.office'), ['code' => 'NOPE'])->assertStatus(422);
        $this->postJson(route('econt.office'), ['code' => '4001'])->assertOk();
        $this->getJson(route('econt.offices', ['postcode' => '4000']))->assertOk()
            ->assertJsonPath('offices.0.code', '4001')
            ->assertJsonPath('selected', '4001');
        $this->getJson(route('econt.offices', ['search' => 'център']))->assertJsonPath('offices.0.code', '1127');
    }

    public function test_the_fallback_price_is_used_when_econt_cannot_answer(): void
    {
        Http::fake([self::DEMO.'*' => Http::response(['type' => 'ExInvalidParam', 'message' => 'Invalid data'], 500)]);

        $this->assertNull(app(EcontCarrier::class)->quote($this->request(), $this->method('address')));
        $this->assertSame('6.50', (string) app(EcontCarrier::class)->quote($this->request(), $this->method('address', ['fallback_price' => '6.50']))?->getAmount());
    }

    public function test_only_bulgaria_and_never_without_credentials(): void
    {
        $this->fakeEcont();
        $this->assertNull(app(EcontCarrier::class)->quote($this->request('1000', 'RO'), $this->method('address', ['fallback_price' => '5'])));

        app(Settings::class)->set('plugin.pnshop_econt', ['username' => '']);
        $this->assertSame('5.00', (string) app(EcontCarrier::class)->quote($this->request(), $this->method('address', ['fallback_price' => '5']))?->getAmount());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'createLabel'));
    }

    public function test_production_uses_econts_production_service(): void
    {
        Http::fake(['https://ee.econt.com/services/*' => Http::response(['cities' => []])]);
        app(Settings::class)->set('plugin.pnshop_econt', ['environment' => 'production']);

        app(EcontCarrier::class)->quote($this->request(), $this->method('address'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://ee.econt.com/services/'));
    }

    public function test_orders_for_office_delivery_keep_the_office_and_need_one(): void
    {
        $this->fakeEcont('4.10', 'USD');
        $method = $this->method('office');
        $payment = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery']);
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 5]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->post(route('checkout.store'), $this->checkoutData($payment->id, ['shipping_method_id' => $method->id, 'shipping' => ['postcode' => '4000', 'city' => 'Plovdiv']]))->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());

        $this->postJson(route('econt.office'), ['code' => '4001'])->assertOk();
        $this->post(route('checkout.store'), $this->checkoutData($payment->id, ['shipping_method_id' => $method->id, 'shipping' => ['postcode' => '4000', 'city' => 'Plovdiv']]))->assertSessionMissing('error');

        $order = Order::query()->sole();
        $this->assertSame('4.10', (string) Money::ofMinor(collect($order->totals)->firstWhere('code', 'shipping')['amount'] ?? 0, $order->currency)->getAmount());
        $this->assertSame('Пловдив Тракия', DB::table('pnshop_econt_order_offices')->where('order_id', $order->id)->value('office_name'));
    }
}
