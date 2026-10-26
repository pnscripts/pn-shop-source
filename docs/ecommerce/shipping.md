# Shipping

Shipping lives in `PnShop\Shipping`. Staff set it up in Admin → Store → *Shipping zones* and *Shipping methods*, and ship orders from the order page.

## Zones

A zone is a group of destinations with the same delivery options.

- **Countries:** pick one or more, or none for "rest of the world".
- **Postcodes (optional):** `*` matches anything, so `1*` means every postcode starting with 1.
- **Order of checking:** zones are checked from the lowest *position*; the first match is used. Put specific zones (Sofia) before broad ones (Bulgaria), and a catch-all last.

## Methods and carriers

A **shipping method** belongs to a zone and uses a **carrier**, which prices the delivery:

| Carrier | Settings | Price |
|---|---|---|
| Flat rate (`flat_rate`) | price, once per order or per item | the price |
| Free shipping (`free_shipping`) | minimum subtotal (optional) | free, offered only from the minimum: the subtotal before discounts, or after them with *Thresholds use the subtotal after discounts* (Admin → Settings → Orders) |
| Pickup (`pickup`) | price (usually 0), address and hours, stock location (optional) | the price; with a stock location, orders reserve their units there and checkout shows whether everything is in stock there ([details](stock-locations.md#store-pickup)) |
| By weight (`weight_based`) | lines of `grams: price` | the first band at or above the order weight; heavier orders are not offered this method |
| By order subtotal (`price_based`) | lines of `subtotal: price` | the band the subtotal falls into |

- **Weights:** they come from each variant's weight in grams; lines without a weight count as zero.
- **Tracking links:** every built-in carrier can have one, e.g. `https://courier.example/track/{number}`.
- **Translations:** method names and descriptions are translatable, and the description is shown at checkout (delivery times, pickup address).

## Checkout

- **When a delivery option is asked for:** as soon as the store has an active shipping method. A store without methods keeps the old behaviour and asks for none.
- **Live quotes:**
  - while the customer enters the address, the page asks `POST /checkout/quote` for the zone's options, cheapest first, and the updated totals;
  - it's a POST, so postcodes stay out of URLs and logs;
  - it also sends the chosen payment method (`payment_method_id`; the Store API's `POST /checkout/quote` takes the same field). With cash on delivery, couriers that charge for collecting the money (Econt, Speedy) add their fee, priced on the order value before delivery, so the customer sees the final delivery price before placing the order.
- **Placing the order:**
  - the method is checked again against the address and cart;
  - its price is added by the `cart.totals` pipeline (stage `ApplyShipping`, priority 200);
  - the order stores the method and its name.

## Shipments

*Create shipment* on the order page ships some or all of the remaining lines, with an optional tracking number and note.

- **Waybills:** when the order's carrier issues them (`pnshop/econt`, `pnshop/speedy`, or any carrier implementing `ProvidesShippingLabels`), the form has *Create the waybill with …*, on by default. The parcel is booked with the courier first; its number becomes the tracking number, and the waybill is kept with the shipment (*Waybill* in the shipments list downloads it). When the courier refuses, nothing is shipped and staff see the courier's reason. Switch it off to record a parcel booked elsewhere with your own tracking number.
- **Cash on delivery:** for orders paid with the cash-on-delivery method, the waybill asks the courier to collect what is still due, minus what earlier parcels of the order already collect, so a split order is never collected twice. The amount is shown in the form and in the shipments list (*Collects*).
- **Later:** a shipment recorded without a waybill gets *Create waybill* in the shipments list.
- **Storage:** waybill PDFs returned by the courier are kept on the private `local` disk (`storage/app/private/pnshop/waybills`) and are downloaded only by staff who can view the order; couriers that host the PDF (Econt) are linked instead.

- **Stock:** shipped units leave the shelf when they ship (`order_fulfilled` movements). Partial shipments are never taken twice.
- **Location:** with several stock locations, each shipment leaves from one of them (*Ships from*). See [Stock locations](stock-locations.md#shipping-from-a-location).
- **Order state:** the order becomes *Partially shipped* or *Shipped*.
- **Customer view:** customers see their parcels and tracking links on the order page.
- **Shipped without a shipment:** *Update fulfillment → Shipped* still marks everything shipped at once, with no shipment record.
- **Cancelling:** a partly shipped order puts the shipped units back on the shelf and releases the rest.

## Writing a carrier

Carriers that can issue return labels also implement `PnShop\Shipping\Contracts\ProvidesReturnLabels::returnLabel(ReturnLabelRequest, ShippingMethod): ReturnLabel` (see [Returns](returns.md#return-labels)), and use `ReturnLabelContractTests` next to `ShippingCarrierContractTests`.

Carriers that book parcels implement `PnShop\Shipping\Contracts\ProvidesShippingLabels::shippingLabel(ShippingLabelRequest, ShippingMethod): ShippingLabel`. The request has the order number (`reference`), the shipping address (`to`), the stock location's address (`from`, when set), the lines with weights in grams, the amount to collect (`cashOnDelivery`, null for paid orders), the customer's email and the order. Return the courier's number and either an https link (`labelUrl`) or the PDF bytes (`pdf`); throw a `RuntimeException` with the courier's reason when it refuses. Check it with `ShippingLabelContractTests`.

`ShippingRequest::$cashOnDelivery` is set when the customer pays cash on delivery: add the courier's fee for collecting it to the quote.

```php
use PnShop\Shipping\Contracts\ShippingCarrier;

final class AcmeCourier implements ShippingCarrier
{
    public function code(): string { return 'acme_courier'; }
    public function label(): string { return 'Acme Courier'; }
    public function settings(): array { return [new SettingDefinition('api_key', SettingType::String, 'API key', required: true)]; }

    public function quote(ShippingRequest $request, ShippingMethod $method): ?Money
    {
        // $request->weight(), ->subtotal, ->countryCode, ->postcode, ->items
        return Money::of(/* rate from the courier's API */, $request->currency());
    }

    public function trackingUrl(string $number, ShippingMethod $method): ?string
    {
        return "https://acme.example/track/{$number}";
    }
}

app(ShippingCarrierManager::class)->register(AcmeCourier::class);
```

- Return `null` from `quote()` when the method can't deliver the request.
- A carrier that throws is logged and skipped, so checkout keeps working.
- Check carriers with `PnShop\Shipping\Testing\ShippingCarrierContractTests`, like the [payment gateway kit](payments.md#writing-a-gateway).
