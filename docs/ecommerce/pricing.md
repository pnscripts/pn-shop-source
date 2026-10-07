# Pricing and business customers

Since 1.3, what a customer pays for a variant can depend on who they are and how many they buy. This page covers price lists, quantity tiers and the options for business (B2B) customer groups.

## How a price is found

Every price a customer sees or pays comes from the `catalog.price` pipeline. It starts from the variant's price, and each stage may offer a lower one; the lowest offer wins.

| Stage | Priority | Offers |
|---|---|---|
| Sale price | 100 | the variant's sale price |
| Price lists | 200 | the customer's price-list prices for the quantity |

The customer is the signed-in customer (storefront or Store API token). Guests are priced as the default customer group (*Retail*). The same price is used on product cards, the product page, the cart, the order, the invoice and both APIs.

Price lists are read once per page for all of its products. A shop without active price lists runs no extra queries.

## Price lists

Admin → Catalog → Price lists (permission `catalog.prices.manage`).

A price list has:

- a name;
- a customer group, or none for prices that apply to everyone (guests included);
- optional *From* and *Until* dates;
- an *Active* switch.

A price list has a currency: the shop's default one, or the currency of a [channel](channels.md) selling in another one, whose prices then replace the converted catalog prices.

Each price is for one variant from a quantity on (*From quantity*, 1 by default). Several prices for the same variant make **quantity tiers**: for example 90.00 from 10 units and 85.00 from 50. The product page lists the tiers that are lower than the current price as "10 or more: 90.00 each".

A customer may match several lists, such as a list for everyone and one for their group. They pay the lowest price that applies to the quantity in their cart.

### CSV import and export

On a price list, **Import CSV** reads a file with the columns `sku`, `min_quantity` (optional, default 1) and `price`:

```csv
sku,min_quantity,price
LAMP-1,1,80.00
LAMP-1,10,75.50
MUG-1,,9.00
```

- An existing price for the same SKU and quantity is replaced.
- An empty `price` removes that price.
- Rows that cannot be used (unknown SKU, bad quantity or price) are listed after the import. The other rows are saved.

**Export CSV** downloads the list in the same format.

### Admin API

| Endpoint | Notes |
|---|---|
| `GET/POST /price-lists`, `GET/PATCH/DELETE /price-lists/{id}` | `name`, `customer_group_id`, `starts_at`, `ends_at`, `is_active` |
| `GET /price-lists/{id}/prices` | the prices with their SKU (cursor paginated) |
| `PUT /price-lists/{id}/prices` | `prices`: up to 1000 rows `{sku, min_quantity, price}`, same rules as the CSV import; returns `{saved, removed, errors}` |

All of them need `catalog.prices.manage`. See [APIs](../api/README.md).

## Business customer groups

Admin → Sales → Customer groups → *Business customers*:

| Option | Effect |
|---|---|
| **Show prices** | *Including tax*, *Excluding tax*, or as entered in the catalog. See below. |
| **Minimum order** | The products (before shipping, and before discounts unless *Thresholds use the subtotal after discounts* is on in Admin → Settings → Orders) must reach this amount. The cart shows the minimum instead of the checkout button, and checkout refuses smaller orders (web and Store API). |

Move a customer into a group from their customer page or with `PATCH /customers/{id}` in the Admin API.

### Prices with or without tax

Catalog prices are entered with or without tax as set in Settings → Tax → *Prices include tax*. A group can see them the other way. Business customers usually see net prices.

The conversion uses the tax rates of the store country (Settings → Tax → *Store country*) for each product's tax class. Without a store country, prices are shown as entered.

Product cards and pages then say "excl. tax" next to the price. The APIs return `price_includes_tax` with each product.

Only what is shown changes. The cart and checkout charge the same amounts and show the tax as its own line.

### Payment methods for some groups

A payment method can be limited to customer groups (*Availability → Only for these customer groups*). Guests count as the default group. Checkout offers the method only to those groups and checks the choice again when the order is placed.

### Paying by invoice

The **Invoice (pay later)** gateway (`invoice`) is meant for business customers:

- it is offered to signed-in customers only, and usually limited to groups such as *Wholesale*;
- *Payment terms (days)* (default 30) can be used in the customer instructions as `:days`, next to `:amount` and `:order`;
- the order waits as *unpaid* until staff record the payment, as with a bank transfer;
- unpaid invoice orders are **never cancelled automatically** (Settings → Orders → *Cancel unpaid orders after* skips them).

## Prices for signed-in customers only

Settings → Customers → *Show prices to guests* (on by default). When it is off:

- guests see "Sign in to see prices" instead of prices on cards and product pages;
- the Store API returns `null` prices to guests;
- the product's structured data has no offer;
- guests cannot add to the cart or check out.

Signed-in customers see their prices as usual.

## For plugin authors

Add a stage to `catalog.price` to offer your own price, for example from an ERP:

```php
use PnShop\Catalog\Pricing\PriceQuote;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Foundation\Extension\PipelineRegistry;

app(PipelineRegistry::class)->stage(PriceResolver::PIPELINE, function (PriceQuote $quote, \Closure $next) {
    // $quote->variant, $quote->quantity, $quote->context (customerGroupId, currency, customer)
    $quote->offer($myPrice, 'erp'); // kept only when lower than the current offer

    return $next($quote);
}, 300);
```

Stages run for every price shown. Keep them fast, and load data for many variants at once when you can.
