# Stock locations

Since 1.4, stock can be kept at several places, such as a warehouse and one or more shops. Every order line knows which location holds its units, and every parcel says which location it left from.

A shop with one location works as before. The extra screens and fields appear once there is a second location.

## Locations

Admin → Catalog → Stock locations (permission `catalog.inventory.manage`).

| Field | Meaning |
|---|---|
| Name, code | The code is used by the Admin API and CSV tools, e.g. `sofia`. |
| Active | Inactive locations keep their stock, but nothing is sold or shipped from them. |
| Sells online | Its stock counts towards what the shop can sell. Turn it off for stock kept only for a physical shop. |
| Position | Orders are served from the default location first, then in this order. |
| Address, city, postcode, country | Used to serve orders from a location in the customer's country, and for pickup. |

- **Default location:** the first location ("Main warehouse") is the default. *Make default* moves that role to another location. Stock changes without a location go to the default one, including the single *Stock on hand* field of the product and variant forms. Once there are several locations, that field's label names the default location.
- **Deleting:** a location can be deleted only when it is not the default and holds no stock (on hand or reserved).

## Stock per location

On a product (or on each variant of a variable product):

- **Stock by location:** the units on the shelf at each location, reserved ones included. Changing a number records the difference in the stock history, like a stock take.
- **Transfer stock:** moves units from one location to another. Only available units can move, not reserved ones. The stock history records a `transfer` movement at both locations.

The storefront's "in stock" and the quantities customers can order are the sum of the active locations that sell online (for a [channel](channels.md) limited to some locations, of those).

## Where an order's units come from

When an order is placed, each line's units are reserved:

1. at the active locations that sell online;
2. in this order:
   - locations in the customer's shipping country first;
   - then the default location;
   - then by position;
3. from a single location when one has them all, split across locations otherwise;
4. anything no location has (sold on backorder) goes to the first location.

The reservations are kept in `order_item_allocations`: one row per line and location, with how many units shipped from there. The Admin API's order detail lists them under `allocations`.

Orders placed before 1.4 have no allocations. They get one at the default location the first time stock moves for them.

## Shipping from a location

*Create shipment* on the order page asks which location the parcel *Ships from* once there are several locations. It starts from the location holding most of the order's units and fills in what is waiting there.

- Choosing another location fills in what waits at that one.
- Shipping units from a location that does not hold them moves their reservation there first, if that location has them available. Otherwise the shipment is refused.
- In the Admin API, `POST /orders/{id}/shipments`:
  - takes `location` (a code);
  - without `items` and with `location`, ships everything waiting there;
  - without `location`, uses the location holding the units, and answers 422 when they are held at several.
- The order page lists each shipment's location (*From*).

Cancelling an order gives reserved units back at their locations, and shipped units return to the location they left from. Reopening a cancelled order reserves its units again, from wherever stock is now. Refunded units that never shipped stop being held. Returned goods go back to the location they shipped from.

The ledger (`stock_movements`) therefore balances per location: the movements of a variant at a location always add up to what is on hand there.

## Store pickup

A *Pickup* shipping method can name a **stock location** (Admin → Store → Shipping → method settings).

- Orders for pickup reserve their units at that location, whatever the address.
- At checkout, the delivery option shows the location and whether everything in the cart is in stock there. The Store API returns `pickup: {location, address, in_stock}` with each option.
- If the location does not have enough, the order is refused with a message naming the location.

Pickup locations should usually *sell online*. Otherwise their stock does not count towards what customers can add to the cart.

## Admin API

| Endpoint | Notes |
|---|---|
| `GET/POST /stock-locations`, `GET/PATCH/DELETE /stock-locations/{id}` | `PATCH` with `is_default: true` makes a location the default |
| `POST /variants/{id}/stock` | `on_hand` or `adjust`, plus an optional `location` code |
| `POST /variants/{id}/stock/transfers` | `from`, `to` (codes), `quantity`, `note` |
| `POST /orders/{id}/shipments` | optional `location` code |

Variants list their stock per location under `locations`: `[{location, on_hand, reserved, available}]`. All of these need `catalog.inventory.manage` (shipments: `sales.orders.update`).
