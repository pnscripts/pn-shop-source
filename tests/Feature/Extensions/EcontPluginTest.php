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
use PnShop\Plugins\Econt\EcontCarrier;
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
 * The Econt plugin (extensions/pnshop/econt) against a stand-in for Econt's demo service
 * (Http::fake with the documented request and response shapes; no Econt account).
 */
class EcontPluginTest extends ShippedPluginTestCase
{
    use ShippingLabelContractTests;

    protected function carrier(): ShippingCarrier
    {
        $this->fakeEcont();

        return app(EcontCarrier::class);
    }

    protected function shippingLabelSettings(): array
    {
        return ['delivery' => 'address'];
    }

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
                ['id' => 41, 'name' => 'София', 'nameEn' => 'Sofia', 'postCode' => '1000'],
                ['id' => 77, 'name' => 'Пловдив', 'nameEn' => 'Plovdiv', 'postCode' => '4000'],
                ['id' => 90, 'name' => 'Банкя', 'nameEn' => 'Bankya', 'postCode' => '1320'],
            ]]),
            self::DEMO.'Nomenclatures/NomenclaturesService.getOffices.json' => function (Request $request) {
                $all = [
                    ['code' => '1127', 'name' => 'София Център', 'address' => ['fullAddress' => 'ул. Пример 1', 'city' => ['id' => 41]]],
                    ['code' => '4001', 'name' => 'Пловдив Тракия', 'address' => ['fullAddress' => 'бул. Пример 2', 'city' => ['id' => 77]]],
                ];

                return Http::response(['offices' => array_values(array_filter($all, fn ($office) => ! isset($request['cityID']) || $office['address']['city']['id'] === $request['cityID']))]);
            },
            self::DEMO.'Shipments/LabelService.createLabel.json' => fn (Request $request) => Http::response(['label' => [
                'totalPrice' => (float) $price + (isset($request['label']['services']['cdAmount']) ? 1.5 : 0),
                'currency' => $currency,
                'senderDueAmount' => (float) $price,
                ...($request['mode'] === 'create' ? ['shipmentNumber' => '1051234567', 'pdfURL' => 'http://demo.econt.com/ee/services/print/1051234567.pdf'] : []),
            ]]),
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

    public function test_postcodes_econt_lists_under_no_town_are_matched_by_the_town_name(): void
    {
        $this->fakeEcont('5.62', 'USD');
        $method = $this->method('address');
        $item = new CartItemDTO(1, 1, 'Mug', 'mug', '', null, Money::of('20.00', 'USD'), null, null, null, 1, 800);

        // Sofia has many postcodes; Econt lists it under 1000 only.
        $this->assertNull(app(EcontCarrier::class)->quote(new ShippingRequest(collect([$item]), Money::of('20.00', 'USD'), 'BG', '1404'), $method));
        $this->assertSame('5.62', (string) app(EcontCarrier::class)->quote(new ShippingRequest(collect([$item]), Money::of('20.00', 'USD'), 'BG', '1404', null, 'гр. София'), $method)?->getAmount());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'createLabel.json') && $request['label']['receiverAddress']['city']['id'] === 41);

        // The checkout sends the town with the postcode.
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 5]);
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->postJson(route('checkout.quote'), ['country_code' => 'BG', 'postcode' => '1407', 'city' => 'Sofia', 'shipping_method_id' => $method->id])
            ->assertOk()->assertJsonPath('options.0.name', 'Econt address');
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

    public function test_after_a_failure_econt_is_left_alone_for_a_minute(): void
    {
        Http::fake([self::DEMO.'*' => Http::response('', 503)]);
        $method = $this->method('address', ['fallback_price' => '6.50']);

        app(EcontCarrier::class)->quote($this->request(), $method);
        app(EcontCarrier::class)->quote($this->request(), $method);
        $this->getJson(route('econt.offices', ['postcode' => '4000']))->assertStatus(503);

        Http::assertSentCount(1);
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
        // The test store sells in USD: Econt collects cash on delivery in euro only, so no fee.
        $this->assertSame('4.10', (string) Money::ofMinor(collect($order->totals)->firstWhere('code', 'shipping')['amount'] ?? 0, $order->currency)->getAmount());
        $this->assertSame('Пловдив Тракия', DB::table('pnshop_econt_order_offices')->where('order_id', $order->id)->value('office_name'));
    }

    public function test_cash_on_delivery_adds_econts_fee_to_the_price(): void
    {
        $this->fakeEcont('7.40', 'EUR');
        $method = $this->method('address');
        $item = new CartItemDTO(1, 1, 'Mug', 'mug', '', null, Money::of('20.00', 'EUR'), null, null, null, 2, 1200);
        $plain = new ShippingRequest(collect([$item]), Money::of('40.00', 'EUR'), 'BG', '4000');
        $cod = new ShippingRequest(collect([$item]), Money::of('40.00', 'EUR'), 'BG', '4000', cashOnDelivery: Money::of('40.00', 'EUR'));

        $this->assertSame('7.40', (string) app(EcontCarrier::class)->quote($plain, $method)?->getAmount());
        $this->assertSame('8.90', (string) app(EcontCarrier::class)->quote($cod, $method)?->getAmount());
        Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'createLabel.json')
            && ($sent['label']['services'] ?? null) == ['cdAmount' => 40, 'cdType' => 'get', 'cdCurrency' => 'EUR']);

        // Econt collects in euro only: a store selling in dollars gets no cash-on-delivery service.
        $this->assertSame('7.40', (string) app(EcontCarrier::class)->quote(new ShippingRequest($this->request()->items, Money::of('40.00', 'USD'), 'BG', '4000', cashOnDelivery: Money::of('40.00', 'USD')), $method)?->getAmount());
        $to = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'line1' => 'x', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG']);
        $this->expectExceptionMessage('Econt collects cash on delivery in euro; this order is in USD.');
        app(EcontCarrier::class)->shippingLabel(new ShippingLabelRequest('1', $to, null, [], Money::of('10.00', 'USD')), $method);
    }

    public function test_waybills_are_created_with_the_receiver_and_the_amount_to_collect(): void
    {
        $this->fakeEcont('7.40', 'EUR');
        $to = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'line1' => 'ul. Pример 5', 'line2' => 'ap. 3', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG', 'phone' => '0888123456']);

        $label = app(EcontCarrier::class)->shippingLabel(new ShippingLabelRequest('100042', $to, null, [['title' => 'Mug', 'quantity' => 2, 'weight' => 0]], Money::of('47.40', 'EUR'), 'ana@example.com'), $this->method('address'));

        $this->assertSame('1051234567', $label->trackingNumber);
        $this->assertSame('https://demo.econt.com/ee/services/print/1051234567.pdf', $label->labelUrl);
        $this->assertSame('8.90', (string) $label->price?->getAmount());
        Http::assertSent(function (Request $request) {
            $label = $request['label'] ?? [];

            return ($request['mode'] ?? null) === 'create'
                && $label['receiverClient'] === ['name' => 'Ana Petrova', 'phones' => ['0888123456'], 'email' => 'ana@example.com']
                && $label['receiverAddress']['city']['id'] === 77
                && $label['receiverAddress']['fullAddress'] === 'ul. Pример 5, ap. 3'
                && $label['orderNumber'] === '100042'
                && $label['shipmentDescription'] === '2 × Mug'
                && abs($label['weight'] - 1.0) < 0.0001
                && $label['services'] == ['cdAmount' => 47.4, 'cdType' => 'get', 'cdCurrency' => 'EUR'];
        });
    }

    public function test_office_orders_get_a_waybill_to_their_office_and_refusals_reach_staff(): void
    {
        $this->fakeEcont('4.10', 'USD');
        $method = $this->method('office');
        $payment = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery']);
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => null, 'stock' => 5]);
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->postJson(route('econt.office'), ['code' => '4001'])->assertOk();
        $this->post(route('checkout.store'), $this->checkoutData($payment->id, ['shipping_method_id' => $method->id, 'shipping' => ['postcode' => '4000', 'city' => 'Plovdiv']]))->assertSessionMissing('error');
        $order = Order::query()->sole();

        $label = app(EcontCarrier::class)->shippingLabel(new ShippingLabelRequest((string) $order->number, PostalAddress::fromArray(['first_name' => 'Jane', 'last_name' => 'Doe', 'line1' => 'x', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG']), null, [], null, null, $order), $method);

        $this->assertSame('1051234567', $label->trackingNumber);
        Http::assertSent(fn (Request $request) => ($request['mode'] ?? null) === 'create' && $request['label']['receiverOfficeCode'] === '4001' && ! isset($request['label']['receiverAddress']));
    }

    public function test_econts_refusal_reaches_staff(): void
    {
        Http::fake([self::DEMO.'*' => Http::response(['type' => 'ExInvalidParam', 'message' => 'Невалиден телефон'], 400)]);
        $to = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'line1' => 'x', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG']);

        $this->expectExceptionMessage('Econt refused the request: Невалиден телефон');
        app(EcontCarrier::class)->shippingLabel(new ShippingLabelRequest('1', $to, null, [], null), $this->method('address'));
    }
}
