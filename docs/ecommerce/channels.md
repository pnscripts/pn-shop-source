# Channels (several storefronts)

Since 1.5, one PN Shop installation can run several storefronts, called **channels**. For example:
- a retail shop on `shop.example.com`;
- a wholesale shop on `wholesale.example.com`;
- a trade shop under `shop.example.com/trade`.

They share the admin, the catalog, the stock and (unless a channel keeps its own) the customer accounts. Each channel can have its own address, languages, currency, theme, store details, part of the catalog, stock locations, and payment and shipping methods.

A shop with one store needs nothing new. The existing store is the **default channel**. It answers on every address, and all existing orders, carts and customers belong to it.

## Adding a channel

Admin → Store → Channels (permission `system.channels.manage`).

| Field | Meaning |
|---|---|
| Name, code | The name is for staff (customers see the store name below); the code is used by the Admin API. |
| Domain | e.g. `wholesale.example.com`. Point the domain's DNS at this installation and add it to the web server; PN Shop trusts the domains of active channels. Empty: any domain. |
| Path | e.g. `trade` for `example.com/trade`. A path cannot hide a page (`shop`, `cart`, …), a language code or the admin. Empty: the domain's root. |
| Active | Inactive channels are not served; their addresses fall back to the default channel. |
| Languages, default language | The languages the channel offers and which one has no URL prefix. Empty: the shop's. |
| Currency | The currency it sells in. See [Currency](#currency). Empty: the shop's default. |
| Store details | Store name, contact email, phone and address, theme, tax country and whether search engines may index it. Empty fields use the shop's settings. |
| Stock locations, payment methods, shipping methods | What the channel sells from and offers. Empty: all of them. |

A request is served by the channel matching:
1. its domain and first path segment;
2. otherwise its domain alone;
3. otherwise a channel without a domain;
4. otherwise the default channel.

*Make default* changes which channel serves addresses that match no other.

### Paths and languages

A channel on a path behaves as if the shop were installed in that folder:

- `example.com/trade/shop` is its shop page;
- `example.com/trade/bg/shop` is the same page in Bulgarian;
- every link the shop builds keeps `/trade`.

### Theme

Choosing a theme for a channel publishes the theme's files when the channel is saved. Like the shop's own theme, it must be built first (`npm run build:theme -- <vendor/name>`).

## Currency

Prices and amounts are entered in the shop's **default currency**: catalog prices, shipping costs, promotion amounts and minimums, payment method limits, customer group minimum orders, and plugin settings.

A channel selling in another currency converts them with the currency's exchange rate (Admin → Store → Currencies), rounding half up. For exact prices, create a [price list](pricing.md) in the channel's currency. Its prices replace the converted ones, by customer group and quantity as usual.

Carts, checkout, orders, invoices and the Store API of the channel are all in its currency. Reports show totals per currency.

## Catalog and content

Products, categories and pages have a **Channels** field (shown once there are several channels). Leave it empty to show the record everywhere. Otherwise it appears only in the chosen channels:

- in their listings, search, sitemaps and Store API;
- a product page in another channel answers 404;
- the cart and checkout refuse the product.

The admin and the Admin API always see everything.

## Stock, payment and delivery

- **Stock locations:** a channel limited to some locations sells only their stock and reserves its orders there. See [Stock locations](stock-locations.md). For channels that each sell their own stock, limit every channel, the default one included, to its own locations.
- **Payment and shipping methods:** a channel offers only its own methods, and checkout refuses others.

## Orders, carts and customers

- **Orders** record their channel. The admin's order list has a *Channel* column and filter, and the dashboard can be narrowed to one channel (*Channel* filter, with *Sales today* per currency).
- **Carts** belong to their channel. A customer has one cart per channel, and a guest's cart on one channel is not used on another.
- **Customers** are shared by default: one account signs in on every channel, and the channel where an account was opened is recorded.
- **Separate customer accounts:** a channel created with *Separate customer accounts* has its own customers (for example a trade store whose customers must not use the retail login).
  - The same email address can have an account there and a shared one; each password signs in only where its account belongs. Registration, sign-in, password reset and the Store API all work within the channel's accounts.
  - The choice is made when the channel is created and cannot be changed later (customers would lose their sign-in). The default channel always uses the shared accounts.
  - Channels on a path share the browser session: a customer signed in on one is signed out on this device when they open a channel with other accounts. Channels on their own domain have separate sessions anyway.
  - A Store API token works only on channels of its account; elsewhere it is refused (401).
  - Admin → Customers shows which accounts a customer belongs to; the Admin API returns `accounts_channel_id` (null when shared).
  - A channel whose customers have their own accounts cannot be deleted; deactivate it instead.
- **Emails and links:** order emails use the order's channel, for its store name and for links to its address. Signed order links point at the channel the order was placed on.

## Admin API

| Endpoint | Channels |
|---|---|
| `GET /orders` | `channel` (code) on each order; `filter[channel]=<code>` |
| `GET /orders/{id}` | `channel` |
| `GET/POST/PATCH /products` | `channel_ids` (empty: every channel) |
| `POST /price-lists` | `currency` (default: the shop's) |

Channels themselves are managed in the admin panel.

## For plugin and theme authors

- `PnShop\Channel\Channels`:
  - `current()` is the request's channel;
  - `isActive()` is false in the admin, the console and jobs;
  - `using($channel, fn () => …)` runs code as a channel (its settings apply and links use its address).
- Settings listed in `Channel::OVERRIDABLE` are overridden by the active channel, transparently, through `Settings::get()`.
- `Localization::currency()` is the request's currency. `defaultCurrency()` stays the shop's.
- Amounts from your settings are in the default currency. Convert them with `app(CurrencyConverter::class)->fromDefault($amount, $totals->currency())`, as the bundled handling-fee plugin does. Prices offered to the `catalog.price` pipeline in another currency are converted automatically.
- Models can be limited to channels with the `LimitedToChannels` trait, which adds `channels()` and `inChannel()`.
