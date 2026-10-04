<?php

namespace PnShop\Shipping\Testing;

use PnShop\Customer\PostalAddress;
use PnShop\Shipping\Contracts\ProvidesReturnLabels;
use PnShop\Shipping\Contracts\ShippingCarrier;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ReturnLabelRequest;
use Tests\TestCase;

/**
 * The contract of carriers that issue return labels (ProvidesReturnLabels). Use it next
 * to ShippingCarrierContractTests:
 *
 *     class MyCarrierTest extends TestCase
 *     {
 *         use RefreshDatabase, ShippingCarrierContractTests, ReturnLabelContractTests;
 *
 *         protected function carrier(): ShippingCarrier { return new MyCarrier(); }
 *     }
 *
 * Fake the carrier's HTTP calls (Http::fake()) in setUp when it books labels online.
 *
 * @mixin TestCase
 */
trait ReturnLabelContractTests
{
    abstract protected function carrier(): ShippingCarrier;

    /**
     * Settings the carrier needs to issue labels in tests.
     *
     * @return array<string, mixed>
     */
    protected function returnLabelSettings(): array
    {
        return [];
    }

    public function test_return_labels_have_an_https_link_and_a_tracking_number_when_given(): void
    {
        $carrier = $this->carrier();
        $this->assertInstanceOf(ProvidesReturnLabels::class, $carrier);

        $method = ShippingMethod::query()->create([
            'shipping_zone_id' => ShippingZone::query()->create(['name' => 'Contract'])->id,
            'name' => 'Contract test',
            'carrier' => $carrier->code(),
            'settings' => $this->returnLabelSettings(),
        ]);

        $from = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'line1' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country_code' => 'BG', 'phone' => '0888123456']);
        $label = $carrier->returnLabel(new ReturnLabelRequest('R-000001', $from, null, [['title' => 'Mug', 'quantity' => 2, 'weight' => 300]]), $method);

        $this->assertStringStartsWith('https://', $label->labelUrl);
        $this->assertTrue($label->trackingNumber === null || trim($label->trackingNumber) !== '');
    }
}
