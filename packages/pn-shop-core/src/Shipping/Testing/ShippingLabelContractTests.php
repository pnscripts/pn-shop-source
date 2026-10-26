<?php

namespace PnShop\Shipping\Testing;

use Brick\Money\Money;
use PnShop\Customer\PostalAddress;
use PnShop\Shipping\Contracts\ProvidesShippingLabels;
use PnShop\Shipping\Contracts\ShippingCarrier;
use PnShop\Shipping\Models\ShippingMethod;
use PnShop\Shipping\Models\ShippingZone;
use PnShop\Shipping\ShippingLabelRequest;
use Tests\TestCase;

/**
 * The contract of carriers that issue waybills (ProvidesShippingLabels). Use it next to
 * ShippingCarrierContractTests:
 *
 *     class MyCarrierTest extends TestCase
 *     {
 *         use RefreshDatabase, ShippingCarrierContractTests, ShippingLabelContractTests;
 *
 *         protected function carrier(): ShippingCarrier { return new MyCarrier(); }
 *     }
 *
 * Fake the carrier's HTTP calls (Http::fake()) in setUp when it books parcels online. The
 * test parcel goes to 4000 Plovdiv, Bulgaria.
 *
 * @mixin TestCase
 */
trait ShippingLabelContractTests
{
    abstract protected function carrier(): ShippingCarrier;

    /**
     * Method settings the carrier needs to issue waybills in tests.
     *
     * @return array<string, mixed>
     */
    protected function shippingLabelSettings(): array
    {
        return [];
    }

    public function test_waybills_have_a_number_and_a_document(): void
    {
        $carrier = $this->carrier();
        $this->assertInstanceOf(ProvidesShippingLabels::class, $carrier);

        $method = ShippingMethod::query()->create([
            'shipping_zone_id' => ShippingZone::query()->create(['name' => 'Contract'])->id,
            'name' => 'Contract test',
            'carrier' => $carrier->code(),
            'settings' => $this->shippingLabelSettings(),
        ]);

        $to = PostalAddress::fromArray(['first_name' => 'Ana', 'last_name' => 'Petrova', 'line1' => '3 Main St', 'city' => 'Plovdiv', 'postcode' => '4000', 'country_code' => 'BG', 'phone' => '0888123456']);

        foreach ([null, Money::of('25.00', 'EUR')] as $cash) {
            $label = $carrier->shippingLabel(new ShippingLabelRequest('100001', $to, null, [['title' => 'Mug', 'quantity' => 2, 'weight' => 300]], $cash, 'ana@example.com'), $method);

            $this->assertNotSame('', trim($label->trackingNumber));
            $this->assertTrue(
                ($label->labelUrl !== null && str_starts_with($label->labelUrl, 'https://')) || ($label->pdf !== null && str_starts_with($label->pdf, '%PDF-')),
                'A waybill needs an https link or the PDF itself.',
            );
        }
    }
}
