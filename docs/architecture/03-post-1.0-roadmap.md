# PN Shop after 1.0: roadmap proposal

Status: **approved** (2026-10-02), decisions P1–P5 as recommended.
- 1.1 was released on 2026-10-02.
- 1.2, added on 2026-10-03 after the audit of 1.1, closes catalog and storefront gaps.
- 1.3 (pricing and B2B basics) was released on 2026-10-04.
- 1.4 (multi-location inventory) was released on 2026-10-04.
- 1.5 (channels) was released on 2026-10-04; separate customer accounts per channel (decision P2's option) are left for a later release.
- 1.6 (returns, exchanges and store credit) was released on 2026-10-04; selling gift cards as products is left for later.
- The later releases keep their order, each one number higher.

1.0 shipped everything in [02-platform-architecture-proposal.md](02-platform-architecture-proposal.md) §20 except the items it deferred. This document orders those deferred items, together with the follow-ups found while building and reviewing 1.0, into releases. Each phase keeps the 1.0 loop: implement, test (SQLite, MySQL, PostgreSQL), Larastan, lint, build, browser check, review, docs and upgrade notes.

## Where 1.0 leaves off

| Area | In 1.0 | Missing |
|---|---|---|
| Core packaging | Everything in one application; updates by `git pull` or a release archive | `pnscripts/pn-shop-core` as a Composer package, so updates come through `composer update` (decision D2) |
| Pricing | One price and sale price per variant; customer groups exist | Prices per customer group, quantity tiers, price lists, B2B terms. The `catalog.price` pipeline is named in code but has no stages |
| Inventory | `stock_locations` table; all stock in the default location | Stock per location in the admin, transfers, choosing where an order ships from |
| Stores | One store: one domain, one theme, one default currency | Several storefronts (channels) on one installation |
| Content | Pages built from blocks in a form editor | A visual editor on the same block data |
| Returns | Request, approve, receive (restock), refund | Exchanges, return labels from carriers, store credit / gift cards |
| Payments | COD, bank transfer, Stripe (plugin) | PayPal and other gateways as plugins; end-to-end Stripe test with real test keys |
| Tooling | TypeScript 5.9, ESLint 9, Ziggy routes | TypeScript 7, ESLint 10 (blocked by eslint-plugin-react), Wayfinder |

## Proposed releases

### 1.1: core as a Composer package

Do this first. Later updates (1.2+) then reach installed shops through `composer update`, as D2 intended.

- **Package:** move `core/`, `resources/js` (default storefront source), the core migrations, views and translations into `pnscripts/pn-shop-core`.
- **Application skeleton:** the shop project (`pnscripts/pn-shop`) becomes a thin skeleton: `app/`, config, `.env`, `extensions/`, `themes/` and the built default theme.
- **Updates:** `pnshop:update` gains a pre-flight check for a pending `composer update` and verifies the core version against the database.
- **Upgrade path:** existing 1.0 installs move with a one-time `pnshop:migrate-to-package` that rewrites `composer.json` and removes the copied `core/`, after a backup.
- **Exit criteria:**
  - a 1.0 install upgrades to 1.1 with no data change;
  - a fresh `composer create-project pnscripts/pn-shop` installs;
  - updating the core is `composer update` plus `pnshop:update`.

### 1.2: catalog and storefront gaps (released 2026-10-03)

Found by the audit of 1.1 and done before the larger releases:
- storefront search and sorting;
- category rows at any depth;
- featured products;
- a low-stock threshold per variant;
- the stock history tab;
- all values of multi-select attributes;
- "available to order" for backorders.

### 1.3: pricing and B2B basics

- **Price resolution:** a `catalog.price` pipeline (variant price, then sale price, then customer-group price, then quantity tier) used by listings, product pages, the cart and the APIs. Prices stay read from the database at checkout.
- **Price lists:** per customer group and currency, with optional validity dates. Quantity tiers per variant.
- **B2B options per customer group:**
  - prices shown with or without tax;
  - a minimum order value;
  - "invoice" payment terms (a payment method limited to chosen groups);
  - hidden prices for guests.
- **Imports and exports:** price-list CSV import and export in the admin, and Admin API endpoints.
- **Exit criteria:**
  - the same variant shows the correct price for a guest, a retail customer and a B2B customer on every surface (listing, product page, cart, order, invoice, API);
  - query-count tests still pass.

### 1.4: multi-location inventory

- **Stock per location:** levels shown and adjustable per location in the admin and the Admin API, with transfers recorded in the stock ledger.
- **Shipping from a location:** shipments choose the location they ship from, automatically (nearest or default with stock) or by hand. Reservations are made per location.
- **Storefront availability:** "in stock" sums the locations that sell online, and pickup methods can show per-location availability.
- **Exit criteria:** an order split across two locations ships correctly and the ledger balances per location.

### 1.5: multiple stores (channels)

The largest change. It builds on 1.3 (prices per channel and currency) and 1.4 (locations per channel).

- **Channels:** a `channels` table holding the domain or path, default and allowed languages, currencies, theme, stock locations, payment and shipping methods, tax settings and SEO defaults.
- **Catalog and content:** products, categories and pages can be limited to channels. Visibility is a pivot table, so everything is visible everywhere by default.
- **Orders, carts and customers:** they record their channel. Customers are shared across channels by default (a decision is needed, see below).
- **Admin:** a channel switcher in the panel, with reports per channel.
- **Exit criteria:** two channels on different domains, each with its own theme, currency and catalog subset, checking out independently on one installation, with the existing single-store shops migrating unchanged.

### 1.6: returns, exchanges and store credit

- **Exchanges:** a return can become a new order for another variant, with the price difference charged or refunded.
- **Return labels:** a `ShippingCarrier::returnLabel()` contract extension, so carrier plugins can issue labels.
- **Store credit and gift cards:** a balance per customer, gift-card codes, and a payment method that spends both. The ledger mirrors the payment ledger.

### Alongside (no fixed release)

- **Visual page editor:** a canvas over the existing section and block data, with live preview through the storefront's own renderers. The data model does not change, so it can ship whenever ready.
- **Payment plugins:** PayPal first, with the payment gateway contract test kit.
- **Stripe end-to-end test:** with real test-mode keys; it needs the owner's keys.
- **Tooling upgrades:**
  - TypeScript 7 and Wayfinder (replacing Ziggy), each in its own change with the full gate set.
  - ESLint 10 once eslint-plugin-react supports it. Dependabot holds these majors until then.
- **Small follow-ups from 1.0:**
  - an option for free-shipping and subtotal thresholds after discounts (today they use the subtotal before discounts, as documented);
  - optional email verification before Store API accounts can order;
  - SSR for non-default themes.

### Not planned

- **GraphQL:** the Store API covers headless use.
- **Out-of-process "apps":** plugins cover extension.

Revisit both if real demand appears.

## Decisions needed

| # | Decision | Recommendation |
|---|---|---|
| P1 | Order of releases | 1.1 (package) → 1.2 (catalog gaps, added 2026-10-03) → 1.3 (pricing) → 1.4 (locations) → 1.5 (channels) → 1.6 (returns+); the visual editor and payment plugins in parallel when there is room |
| P2 | Customer accounts across channels | Shared by default (one login for all channels of an installation), with a per-channel option to keep them separate |
| P3 | B2B scope in 1.3 | Group prices, tiers, minimum order, invoice terms, hidden guest prices. Quotes and approval workflows come later |
| P4 | Composer package split (1.1) | One core package for now. Splitting into catalog, sales and similar packages would add release overhead for no benefit yet |
| P5 | Versioning | Minor releases (1.x) keep plugin and theme contracts compatible. Anything breaking waits for 2.0 and is announced one minor release ahead |
