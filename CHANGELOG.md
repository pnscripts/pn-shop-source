# Changelog

All notable changes to PN Shop. The project follows [semantic versioning](https://semver.org/): breaking changes to plugin and theme contracts come only in major versions and are announced one minor version ahead.

## 1.8.2 (2026-10-06)

No migrations; update with `composer update pnscripts/pn-shop-core` and `php artisan pnshop:update`, then copy `extensions/pnshop/stripe` from the [`pnscripts/pn-shop`](https://github.com/pnscripts/pn-shop) repository.

### Fixes

- **Stripe plugin (1.0.2): customers are thanked when the webhook confirms first.** When Stripe's webhook confirmed the payment before the customer came back from Stripe's page (common with fast webhooks), the customer landed on the plain order page without the "Thank you" message. The return now recognises the Checkout Session started for the payment and shows the signed order page with the message; nothing is recorded twice.

## 1.8.1 (2026-10-06)

Payment plugin fixes found by the first run against Stripe's test mode. No migrations; update with `composer update pnscripts/pn-shop-core` and `php artisan pnshop:update`, then copy `extensions/pnshop/stripe` and `extensions/pnshop/paypal` from the [`pnscripts/pn-shop`](https://github.com/pnscripts/pn-shop) repository.

### Fixes

- **Stripe and PayPal plugins (1.0.1): idempotency keys no longer collide between shops.** Keys were built from the shop's payment id, which repeats across shops (and reinstalls) using the same Stripe or PayPal account. Stripe then refused the payment for 24 hours, and PayPal could answer with the other shop's PayPal order. Starting a payment now uses a random key; captures and refunds use the provider's own ids. Existing shops: copy `extensions/pnshop/stripe` and `extensions/pnshop/paypal` from the [`pnscripts/pn-shop`](https://github.com/pnscripts/pn-shop) repository.
- **Stripe plugin: works with new Stripe accounts.** New accounts have Stripe Managed Payments (Stripe as merchant of record) on by default, which refused every checkout without product tax codes. The shop is the seller and already adds tax, so the plugin turns it off for its Checkout Sessions.

### Tests

- **Stripe end to end:** opt-in tests against Stripe's test mode (`STRIPE_TEST_SECRET_KEY=sk_test_… ./vendor/bin/phpunit --group stripe-live`) and a browser checklist in [Stripe end to end](docs/development/stripe-end-to-end.md). Run on 2026-10-05: checkout, payment with a test card on Stripe's page, partial and full refunds, cancellation.

## 1.8.0 (2026-10-05)

A visual page editor. No migrations; non-default themes should be rebuilt. See the [upgrade notes](docs/upgrades/2026-10-release-1.8.md).

### Added

- **Visual page editor:** *Design* on a page shows its blocks next to a live preview in the storefront's own layout and components. Changes show at once (unsaved); clicking a block in the preview opens it in the editor; desktop, tablet and mobile sizes; one language at a time. Same blocks and rules as the edit page; nothing changes in how pages are stored. See [pages and blocks](docs/cms/pages-and-blocks.md#visual-editor).

## 1.7.1 (2026-10-05)

No migrations; update with `composer update pnscripts/pn-shop-core` and `php artisan pnshop:update`. Existing shops can copy `extensions/pnshop/paypal` from the [`pnscripts/pn-shop`](https://github.com/pnscripts/pn-shop) repository to get the plugin change.

### Added

- **PayPal plugin, for developers:** `services.paypal.api_url` points the plugin at another API address, such as the local stand-in in the source repository (`tests/Support/PayPalStandIn`), to try it end to end without a PayPal account.

## 1.7.0 (2026-10-05)

A PayPal payment plugin, and store emails without a queue worker. See the [upgrade notes](docs/upgrades/2026-10-release-1.7.md).

### Added

- **PayPal plugin** (`extensions/pnshop/paypal`, available in Admin → Extensions):
  - PayPal Checkout with capture on return or by signed webhook;
  - amount checks, and no capture for cancelled orders;
  - refunds from the admin;
  - a sandbox/live switch.

  See its README.

### Fixes

- **Store emails without a queue worker:** order, return and gift card emails were queued but never sent on hosts without `queue:work` (shared hosting). The scheduler now works the queue every minute (`PNSHOP_QUEUE_FROM_SCHEDULER`, on by default), and a refused send is retried 5 times with pauses before it is logged as failed.

## 1.6.0 (2026-10-04)

Gift cards, store credit, exchanges and return labels. See [Gift cards and store credit](docs/ecommerce/gift-cards-and-store-credit.md), [Returns](docs/ecommerce/returns.md) and the [upgrade notes](docs/upgrades/2026-10-release-1.6.md).

### Added

- **Gift cards:** issued by staff or the Admin API. The code is shown once and emailed to the recipient; only its hash is kept. Cards can expire, be disabled and be adjusted.
- **Store credit:** a balance per customer and currency, managed on the customer's page and in the Admin API.
- **A ledger** of every gift card and store credit change, like the payment ledger.
- **Paying with balances:** gift cards and store credit pay part or all of an order. The payment method pays the rest, or is not needed when they pay everything. An order is paid once its payments cover the total.
- **Refunds to store credit** on orders and returns. A guest gets a gift card by email. It works for any payment method, cash on delivery included.
- **Exchanges:** a received return becomes a new order for other items, paid with the returned items' value. The difference is charged with a payment method, or kept as store credit.
- **Return labels:** carriers implementing the new optional `ProvidesReturnLabels` contract issue labels for approved returns. The label is emailed to the customer. A contract test kit is included.

### Fixes

- **Bulgarian invoices:** a generic tax number is no longer labelled as a VAT number (shipped in 1.5.0, missing from its notes).

## 1.5.0 (2026-10-04)

Several storefronts (channels) on one installation. See [Channels](docs/ecommerce/channels.md) and the [upgrade notes](docs/upgrades/2026-10-release-1.5.md).

### Added

- **Channels** (Admin → Store → Channels): storefronts on their own domain, path or both. Each has its own:
  - languages (and default language);
  - currency;
  - theme;
  - store name and contact details, tax country and search engine indexing;
  - stock locations, payment methods and shipping methods.
- **Currency per channel:** catalog prices, shipping costs, promotion amounts and limits are converted with the exchange rate. Price lists in the channel's currency set exact prices.
- **Catalog per channel:** products, categories and pages can be limited to channels (Admin API `channel_ids` on products).
- **Orders, carts and customers** record their channel. Carts are kept per channel; order emails and links use the order's channel.
- **Reports per channel:** a dashboard *Channel* filter, a *Sales today* stat per currency, and a channel column and filter on orders (Admin API `filter[channel]`).

## 1.4.0 (2026-10-04)

Several stock locations. See [Stock locations](docs/ecommerce/stock-locations.md) and the [upgrade notes](docs/upgrades/2026-10-release-1.4.md).

### Added

- **Stock locations:** warehouses and shops with an address, a position and *Sells online*. A default location can be chosen, and a location can be deleted only once it is empty. Admin API `/stock-locations`.
- **Stock per location:** *Stock by location* and *Transfer stock* on products and variants. Transfers are recorded in the stock history at both locations. The Admin API takes `location` on `POST /variants/{id}/stock` and adds `POST /variants/{id}/stock/transfers`; variants list their stock per location.
- **Orders served per location:** checkout reserves each line at the locations that sell online:
  - the customer's country first, then the default location, then by position;
  - one location per line when it can, split across locations otherwise.
- **Shipping from a location:** each shipment leaves from a location, the one holding the units or one staff choose (*Ships from*, Admin API `location`). Cancelling, reopening, refunds and returns move stock at the right locations, so the ledger balances per location.
- **Store pickup at a location:** pickup methods can name a stock location. Orders reserve their units there, and checkout shows whether everything is in stock there.

### Changed

- The storefront's stock counts only active locations that sell online. With one location nothing changes.

## 1.3.0 (2026-10-04)

Pricing and business customers. See [Pricing and business customers](docs/ecommerce/pricing.md) and the [upgrade notes](docs/upgrades/2026-10-release-1.3.md).

### Added

- **Price lists:** prices per customer group (or for everyone) with optional dates, and quantity tiers. CSV import and export in the admin; Admin API `/price-lists` and `/price-lists/{id}/prices` (permission `catalog.prices.manage`).
- **One price pipeline:** `catalog.price` decides what each customer pays on every surface: cards, product page, cart, order, invoice, structured data and the Store API. Plugins can add stages.
- **Business customer groups:**
  - prices shown with or without tax;
  - a minimum order, checked at checkout and shown in the cart.
- **Payment methods:** can be limited to customer groups.
- **Invoice (pay later):** a gateway with payment terms, for signed-in customers. Unpaid invoice orders are not cancelled automatically.
- **Prices for signed-in customers only:** setting *Customers → Show prices to guests*.

### Performance

- Price lists are read once per page for all its products. Shops without active price lists run no extra queries.

## 1.2.0 (2026-10-03)

Catalog and storefront gaps found by the audit of 1.1. See the [upgrade notes](docs/upgrades/2026-10-release-1.2.md). The pricing and B2B release moves to 1.3; the [roadmap](docs/architecture/03-post-1.0-roadmap.md) keeps its order.

### Added

- **Shop page:** a search box, sorting (newest, price, name) and category rows at any depth.
- **Featured products:** shown first on the home page. Set in the product form and the Admin API.
- **Low stock per variant:** a threshold per variant. The dashboard's low-stock list uses it, with 5 as the default.
- **Stock history:** a tab on the product edit page listing every stock movement of its variants and who made it.

### Fixes

- **Product page specifications:** they show every attribute the product has values for, with all the values of multi-select attributes.
- **Product cards:** products sold on backorder show *Available to order* instead of *Out of stock*.

## 1.1.2 (2026-10-03)

The rest of the fixes from the 1.1 audit: the payment ledger, shared services, performance, documentation. Update with `composer update pnscripts/pn-shop-core` and `php artisan pnshop:update`; one migration adds indexes. See the [upgrade notes](docs/upgrades/2026-10-release-1.1.2.md).

### Fixes

- **Payment ledger:**
  - Refunds and shipping are no longer set by hand in the admin or the Admin API; they come from refund, shipment and return records.
  - The same gateway answer (a retried webhook) is recorded once, and a paid payment never goes back to failed or pending.
  - A gateway payment no longer marks the order's other open payments "paid by staff".
  - Refunds are saved as pending before the payment provider is asked.
- **Variants:** the admin can no longer delete a product's last variant or leave it without a default one. "Generate variants" is capped at 200 and runs in one transaction.
- **Configuration:** new options in the core's `config/pnshop.php` now reach shops whose own copy is older.
- **Branding:** the customer account area no longer links to the starter kit's repository and documentation, and the fallback home page greets visitors with the store's name.
- **Hardening:**
  - pages receive only the account fields they show;
  - the appearance cookie is checked;
  - sitemap file numbers are bounded;
  - Store API sign-in takes as long whether or not an account exists;
  - staff API tokens always expire;
  - CMS links refuse protocol-relative addresses;
  - plugin uploads never stay behind.

### Added

- **Admin API:** `POST /orders/{id}/refunds`.
- **Updater:** `pnshop:update` warns when the active theme does not support the new version.
- **Plugins:** enabling, disabling or updating a plugin clears cached routes and admin components.
- **Documentation:** guides for settings, deployment and testing; the skeleton's README is written for shop owners.

### Performance

- Themes are looked up once per request instead of about four times.
- Indexes for the lookups PostgreSQL and SQLite did not index: a customer's orders, an order's payments, shipments, refunds and returns, newest-first listings.
- The storefront's route list leaves out staff and installer routes.

### Development

- **Shared services:** `VariantService`, `DeliveryQuote` and `Registration` replace code copied between the admin, the storefront and the APIs.
- **Static analysis:** Larastan reads every module's migrations.
- **CI:** runs on `next` and pins actions by commit SHA.
- **Dependencies:** Sail and Debugbar removed from the development dependencies (Telescope stays).

## 1.1.1 (2026-10-03)

Fixes from the 1.1 audit. Update with `composer update pnscripts/pn-shop-core` and `php artisan pnshop:update`; one additive migration. See the [upgrade notes](docs/upgrades/2026-10-release-1.1.1.md).

### Fixes

- **Unpaid-order cancellation:**
  - Shipped orders (such as cash on delivery waiting for the money) are no longer cancelled and restocked.
  - An order paid while the job runs is no longer cancelled.
  - Orders with nothing to pay are marked paid when placed.
- **Staff accounts:** staff who manage accounts can no longer change or delete a more powerful (non-administrator) account.
- **Sign-in:** a new or reset password signs out the customer's other sessions and revokes their Store API tokens; deleting an account revokes its tokens. The sign-in form also has a per-IP limit.
- **Stock permission:** stock changes in the product and variant forms and the Admin API's `stock` fields need `catalog.inventory.manage`. Stock set from the product form is recorded with the staff member.
- **Returns:** receiving a return twice can no longer restock it twice, and items of deleted variants are restocked.
- **Coupons:** reopening a cancelled order takes its promotion and coupon uses back within the usage limits, or refuses to reopen.

### Added

- **`TRUSTED_PROXIES`:** behind a load balancer, Cloudflare or a reverse proxy, rate limits see the visitor's real IP.
- **Email verification setting:** *Settings → Customers → Require a verified email address* (off by default).
- **For plugins:** an `OrderReopening` event, dispatched inside the reopening transaction, and an optional `when` guard on `OrderWorkflow::transition()`.

## 1.1.0 (2026-10-02)

The core becomes a Composer package, so shops update with `composer update` and `php artisan pnshop:update`. The database does not change. See the [upgrade notes](docs/upgrades/2026-10-release-1.1.md).

### Platform

- **Core package:** `pnscripts/pn-shop-core` holds the modules, the storefront (controllers, routes, React source, the prebuilt bundle), the base migrations, seeders, views and translations. The shop project (`pnscripts/pn-shop`) keeps only the merchant's own code, configuration, plugins and themes.
- **Migration:** `php artisan pnshop:migrate-to-package` moves a 1.0 shop onto the package. It sets the old in-project files aside (it never deletes them), lists the ones the merchant changed, and checks the wiring.
- **Update checks:** `pnshop:update` refuses to run against a newer database or a `composer.lock` that disagrees with `vendor/`.
- **Prebuilt storefront:** the default storefront ships prebuilt in the package and is published on install, update and `composer update`. Shops need no Node.

### Fixes

- **`composer create-project`:** it no longer stops at a production `migrate` prompt.
- **Static analysis:** the storefront controllers pass Larastan level 7.

## 1.0.0

The first release of PN Shop as a platform: a self-hosted CMS and e-commerce system built on Laravel 13, React 19 and Inertia 3, which grew out of the Laravel React shop starter. Upgrade notes for each step are in [docs/upgrades](docs/upgrades/).

### Platform

- **Core modules:** they live in `core/` (namespace `PnShop\`), with an extension kernel: a permission registry, ordered pipelines (`cart.totals`, `seo.meta`, …) and typed, cached settings.
- **Installation:** a command-line installer (`php artisan pnshop:install`) and a web installer (`/install`), with no default accounts. Fresh installs are tested on SQLite and MySQL 8, and the test suite passes on SQLite, MySQL 8 and PostgreSQL 16.
- **Updates:** `php artisan pnshop:update` has a dry run, backups (SQLite copy, `mysqldump` or `pg_dump`, plus `.env` and optionally uploads), plugin compatibility checks, maintenance mode and a version history.
- **Admin panel:** a Filament 5 panel at `/admin`, with staff accounts separate from customers, roles and permissions, and an activity log.

### Catalog and content

- **Catalog:** products with variants (options such as size or color), each variant with its own SKU, price, sale price, weight and stock. Nested categories, brands, filterable attributes, related products, upsells and cross-sells.
- **Media library:** WebP conversions in several sizes and product galleries.
- **Inventory:** a stock ledger with reservations at checkout. Stock leaves the shelf when the order ships and comes back on cancellation and on returns.
- **CMS:** pages built from content blocks (text, image, gallery, video, hero, product and category grids, call to action, permission-gated HTML), with revisions and scheduled publishing. Header and footer menus.
- **SEO:** meta tags, hreflang, JSON-LD, sitemaps, a generated robots.txt, automatic 301 redirects when slugs change, and manual redirects.
- **Languages and money:** English and Bulgarian storefronts with localized URLs (`/bg/…`), translatable catalog and content, and currencies stored as minor units.

### Selling

- **Cart and checkout:**
  - Database carts that survive sessions and merge when the customer signs in.
  - A totals pipeline: promotions, shipping, fees, tax.
  - Structured addresses, guest and account checkout, and honeypot and time-trap spam protection.
- **Orders:**
  - Numbered orders with separate status, payment and fulfillment states, all changed through a workflow that keeps a history.
  - Partial shipments with tracking, refunds with restocking, and numbered invoices.
  - Emails in the customer's language.
- **Payments:** cash on delivery and bank transfer are built in; card payments come as plugins (Stripe Checkout is included).
- **Shipping:** zones and methods priced flat, free, pickup, by weight or by subtotal.
- **Tax:** classes, zones and rates, with tax-inclusive or tax-exclusive prices.
- **Promotions:** built from conditions (subtotal, item count, products or categories, customer group, shipping country) and discounts (percent, fixed amount, buy X get Y, free shipping).
  - Coupons can be generated in bulk, and both promotions and coupons have total and per-customer usage limits.
  - Discounts are recorded per order line, so tax and refunds use what was actually paid.
- **Returns (RMA):** customers request returns from the order page; staff approve, receive (optionally back into stock) and refund.

### Extending

- **Plugins:**
  - Installed from the `extensions/` folder or as uploaded zip archives, which are off by default.
  - Lifecycle: install, enable, update, disable and uninstall, with migrations and rollback on failure.
  - Safety: safe mode, file integrity checks and optional Ed25519 signatures.
  - What a plugin can add: admin screens, settings, permissions, pipeline stages, promotion types, content blocks and storefront slots.
- **Themes:** prebuilt storefront bundles, child themes that override files by path, and theme settings as CSS variables; Aurora is included as an example.
- **Storefront SDK (`window.PnShop`):** gives plugins named slots and content blocks without rebuilding the shop.
- **APIs:**
  - The **Store API** (`/api/store/v1`) is for headless storefronts and apps: catalog, content, cart, coupons, idempotent checkout, customer accounts and returns.
  - The **Admin API** (`/api/admin/v1`) uses staff tokens limited to chosen permissions: catalog, stock, orders, customers, pages, promotions, returns and settings.
  - Both return problem+json errors, use cursor pagination and come with OpenAPI documents in `docs/api`.

### Security and hardening

The 1.0 security review covered authorization, the APIs, input and files, and money and concurrency. See [docs/security/security.md](docs/security/security.md).

- **Stripe return page:** it no longer gives out signed order links for guessed payment ids.
- **Hosts and the installer:** the shop only answers for its own host once installed, and the installer fails closed when the database is unreachable.
- **Staff permissions:** staff cannot hand out roles or permissions they do not hold, and only administrators can edit administrators.
- **Checkout and refunds:** a double submit cannot place two orders, refunds are serialized, and per-customer promotion limits hold under concurrency on MySQL.
- **Unpaid orders:** they are cancelled automatically after a set time, which releases their stock.
- **Stripe sessions:** a cancelled order's Stripe session is expired, and late payments are flagged.
- **Guest order links:** they expire after 180 days, wrong coupon codes are rate limited, and only static plugin assets are published.

### Quality

- **CI checks:** Pint, Larastan (level 7 for `core/`), ESLint, Prettier, TypeScript, Composer and npm audits, and a bundle-size budget.
- **Tests:** PHPUnit with query-count tests on the storefront pages and API lists, and test runs on SQLite, MySQL and PostgreSQL.
