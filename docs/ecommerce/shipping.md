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
| Free shipping (`free_shipping`) | minimum subtotal (optional) | free, offered only from the minimum |
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
  - it's a POST, so postcodes stay out of URLs and logs.
- **Placing the order:**
  - the method is checked again against the address and cart;
  - its price is added by the `cart.totals` pipeline (stage `ApplyShipping`, priority 200);
  - the order stores the method and its name.

## Shipments

*Create shipment* on the order page ships some or all of the remaining lines, with an optional tracking number and note.

- **Stock:** shipped units leave the shelf when they ship (`order_fulfilled` movements). Partial shipments are never taken twice.
- **Location:** with several stock locations, each shipment leaves from one of them (*Ships from*). See [Stock locations](stock-locations.md#shipping-from-a-location).
- **Order state:** the order becomes *Partially shipped* or *Shipped*.
- **Customer view:** customers see their parcels and tracking links on the order page.
- **Shipped without a shipment:** *Update fulfillment → Shipped* still marks everything shipped at once, with no shipment record.
- **Cancelling:** a partly shipped order puts the shipped units back on the shelf and releases the rest.

## Writing a carrier

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
