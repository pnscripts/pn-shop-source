<?php

namespace Tests\Feature\Returns;

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Models\Product;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Returns\Filament\Resources\Returns\Pages\ViewReturnRequest;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\Notifications\ReturnLabelIssued;
use PnShop\Returns\ReturnReason;
use PnShop\Returns\ReturnService;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Shipping\Carriers\FlatRate;
use PnShop\Shipping\Contracts\ProvidesReturnLabels;
use PnShop\Shipping\Contracts\ShippingCarrier;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ReturnLabel;
use PnShop\Shipping\ReturnLabelRequest;
use PnShop\Shipping\ShipmentService;
use PnShop\Shipping\ShippingCarrierManager;
use PnShop\Shipping\ShippingRequest;
use PnShop\Shipping\Testing\ReturnLabelContractTests;
use PnShop\Shipping\Testing\ShippingCarrierContractTests;
use Tests\Feature\Admin\AdminTestCase;

/**
 * A courier plugin's carrier that issues return labels.
 */
final class LabelCourier implements ProvidesReturnLabels, ShippingCarrier
{
    /** @var list<ReturnLabelRequest> */
    public static array $requests = [];

    public function code(): string
    {
        return 'label_courier';
    }

    public function label(): string
    {
        return 'Label courier';
    }

    public function settings(): array
    {
        return [];
    }

    public function quote(ShippingRequest $request, ShippingMethod $method): Money
    {
        return Money::of(5, $request->currency());
    }

    public function trackingUrl(string $trackingNumber, ShippingMethod $method): ?string
    {
        return null;
    }

    public function returnLabel(ReturnLabelRequest $request, ShippingMethod $method): ReturnLabel
    {
        self::$requests[] = $request;

        return new ReturnLabel('https://courier.example/labels/'.$request->reference.'.pdf', 'RT'.str_pad((string) count(self::$requests), 6, '0', STR_PAD_LEFT));
    }
}

class ReturnLabelsTest extends AdminTestCase
{
    use RefreshDatabase, ReturnLabelContractTests, ShippingCarrierContractTests;

    protected function carrier(): ShippingCarrier
    {
        return new LabelCourier;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(ShippingCarrierManager::class)->register(LabelCourier::class);
        LabelCourier::$requests = [];
    }

    private function approvedReturn(string $carrier): ReturnRequest
    {
        $mug = Product::factory()->active()->create(['price' => '10.00', 'sale_price' => null, 'stock' => 5]);
        $mug->defaultVariant()->update(['weight' => 350]);
        $method = ShippingMethod::factory()->for(ShippingZone::factory()->create(), 'zone')->create(['name' => 'Courier', 'carrier' => $carrier, 'settings' => ['cost' => '5.00']]);

        $this->post('http://localhost/cart', ['product_id' => $mug->id, 'quantity' => 2]);
        $this->post('http://localhost/checkout', $this->checkoutData(PaymentMethod::factory()->create(['gateway' => 'bank_transfer'])->id, ['shipping_method_id' => $method->id]))->assertSessionMissing('error');
        $order = Order::query()->latest('id')->firstOrFail();
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);
        app(ShipmentService::class)->ship($order);

        $returns = app(ReturnService::class);
        $return = $returns->request($order->fresh(['items']), [$order->items()->sole()->id => 2], ReturnReason::NoLongerNeeded);

        return $returns->approve($return);
    }

    public function test_staff_create_a_return_label_and_the_customer_gets_it(): void
    {
        Notification::fake();
        $return = $this->approvedReturn('label_courier');
        $this->actingAsAdministrator();

        Livewire::test(ViewReturnRequest::class, ['record' => $return->getRouteKey()])
            ->assertActionVisible('returnLabel')
            ->callAction('returnLabel')
            ->assertNotified('Return label sent to the customer.');

        $return->refresh();
        $this->assertSame('https://courier.example/labels/'.$return->number.'.pdf', $return->return_label_url);
        $this->assertSame('RT000001', $return->return_tracking_number);
        $this->assertSame(700, LabelCourier::$requests[0]->weight());
        $this->assertSame('BG', LabelCourier::$requests[0]->from->country_code);
        Notification::assertSentOnDemand(ReturnLabelIssued::class);

        // Once is enough.
        Livewire::test(ViewReturnRequest::class, ['record' => $return->getRouteKey()])->assertActionHidden('returnLabel');
    }

    public function test_carriers_without_return_labels_offer_none(): void
    {
        $return = $this->approvedReturn((new FlatRate)->code());
        $this->actingAsAdministrator();

        Livewire::test(ViewReturnRequest::class, ['record' => $return->getRouteKey()])->assertActionHidden('returnLabel');

        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'erp', ['*'])->plainTextToken];
        $this->withHeaders($headers)->postJson("http://localhost/api/admin/v1/returns/{$return->id}/label")->assertUnprocessable();
    }

    public function test_the_admin_api_creates_return_labels(): void
    {
        Notification::fake();
        $return = $this->approvedReturn('label_courier');
        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->administrator()->create(), 'erp', ['*'])->plainTextToken];

        $this->withHeaders($headers)->postJson("http://localhost/api/admin/v1/returns/{$return->id}/label")
            ->assertOk()
            ->assertJsonPath('data.return_label.tracking_number', 'RT000001');
    }
}
