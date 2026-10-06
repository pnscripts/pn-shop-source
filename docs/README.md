# PN Shop documentation

PN Shop is a self-hosted CMS and e-commerce platform by PN Scripts. These documents describe the released version (see [CHANGELOG.md](../CHANGELOG.md)) and the plans for later releases.

## Running a shop

| Document | Purpose |
|---|---|
| [installation/installation.md](installation/installation.md) | Installing: requirements, `pnshop:install`, the web installer, after installing |
| [installation/updating.md](installation/updating.md) | Updating: `composer update` + `pnshop:update`, from 1.0 to 1.1, dry run, backups, plugin compatibility |
| [installation/deployment.md](installation/deployment.md) | Running a shop on a server: web server, cron, queue worker, caches, backups, go-live checklist |
| [administration/settings.md](administration/settings.md) | Every setting in Admin → System → Settings, with its default |
| [security/security.md](security/security.md) | Security model, hardening, production checklist |
| [administration/staff-and-roles.md](administration/staff-and-roles.md) | Admin panel, staff accounts, roles and the activity log |
| [ecommerce/catalog-and-inventory.md](ecommerce/catalog-and-inventory.md) | Products, variants, options, categories, brands, attributes, images, inventory |
| [ecommerce/gift-cards-and-store-credit.md](ecommerce/gift-cards-and-store-credit.md) | Gift cards, store credit, paying with them, refunds to store credit |
| [ecommerce/channels.md](ecommerce/channels.md) | Several storefronts on one installation: domains and paths, languages, currency, theme, catalog per channel, reports |
| [ecommerce/stock-locations.md](ecommerce/stock-locations.md) | Several warehouses and shops: stock per location, transfers, where orders are served and shipped from, store pickup |
| [ecommerce/pricing.md](ecommerce/pricing.md) | Price lists, quantity tiers, business customer groups (net prices, minimum order, invoice payment), prices for signed-in customers only |
| [ecommerce/customers-cart-and-checkout.md](ecommerce/customers-cart-and-checkout.md) | Customer accounts and groups, address books, carts, the totals pipeline, checkout, reservations, spam protection |
| [ecommerce/orders.md](ecommerce/orders.md) | Order numbers, status/payment/fulfillment states, history, invoices, emails |
| [ecommerce/payments.md](ecommerce/payments.md) | Payment methods, gateways, payments and transactions |
| [ecommerce/shipping.md](ecommerce/shipping.md) | Shipping zones, methods and carriers, checkout delivery, shipments |
| [ecommerce/tax.md](ecommerce/tax.md) | Tax classes, zones and rates, inclusive or exclusive prices |
| [ecommerce/returns.md](ecommerce/returns.md) | Return requests (RMA): customer form, staff workflow, restocking, refunds, exchanges and return labels |
| [marketing/promotions.md](marketing/promotions.md) | Promotions (conditions and discounts), coupons, usage limits |
| [cms/pages-and-blocks.md](cms/pages-and-blocks.md) | CMS pages, scheduling, revisions, the homepage, content blocks |
| [cms/menus.md](cms/menus.md) | Header and footer menus, item types, translations, caching |
| [seo/seo.md](seo/seo.md) | Meta tags, hreflang, JSON-LD, sitemaps, robots.txt, staging mode, redirects |

## Developers, plugin and theme authors

| Document | Purpose |
|---|---|
| [development/core-modules.md](development/core-modules.md) | The core package, module layout, permissions, pipelines and settings, the storefront build |
| [development/localization-and-money.md](development/localization-and-money.md) | Languages, localized URLs, translatable models, interface text, money |
| [development/testing.md](development/testing.md) | Running the tests, other databases, test conventions, the CI checks |
| [development/stripe-end-to-end.md](development/stripe-end-to-end.md) | The Stripe plugin against Stripe's test mode: API tests and a browser checklist |
| [extensions/plugins.md](extensions/plugins.md) | Plugins: trust model, manifest, lifecycle, CLI, safe mode, signatures, writing a plugin |
| [themes/themes.md](themes/themes.md) | Themes: manifest, settings as CSS variables, override-by-path builds, activation, slots |
| [api/README.md](api/README.md) | Store API and Admin API: tokens, errors, pagination, idempotency, endpoints, OpenAPI documents |
| [release/publishing.md](release/publishing.md) | Publishing a release: the core package and the project skeleton |
| [release/pnscripts-com-product-page.md](release/pnscripts-com-product-page.md) | Keeping the PN Shop page on pnscripts.com in step (for whoever updates that site) |

## Upgrade notes

| Release | Notes |
|---|---|
| 1.8.1 | no upgrade steps beyond copying the Stripe and PayPal plugins: payment plugin fixes (see the CHANGELOG) |
| 1.8.0 | [upgrades/2026-10-release-1.8.md](upgrades/2026-10-release-1.8.md): visual page editor |
| 1.7.1 | no upgrade steps: a developer setting for the PayPal plugin (see the CHANGELOG) |
| 1.7.0 | [upgrades/2026-10-release-1.7.md](upgrades/2026-10-release-1.7.md): PayPal plugin |
| 1.6.0 | [upgrades/2026-10-release-1.6.md](upgrades/2026-10-release-1.6.md): gift cards, store credit, exchanges, return labels |
| 1.5.0 | [upgrades/2026-10-release-1.5.md](upgrades/2026-10-release-1.5.md): channels — several storefronts with their own domain, languages, currency, theme and catalog |
| 1.4.0 | [upgrades/2026-10-release-1.4.md](upgrades/2026-10-release-1.4.md): stock locations, stock per location and transfers, orders shipped per location, pickup at a location |
| 1.3.0 | [upgrades/2026-10-release-1.3.md](upgrades/2026-10-release-1.3.md): price lists and quantity tiers, business customer groups, invoice payment, prices for signed-in customers only |
| 1.2.0 | [upgrades/2026-10-release-1.2.md](upgrades/2026-10-release-1.2.md): search and sorting, featured products, low-stock thresholds, stock history |
| 1.1.2 | [upgrades/2026-10-release-1.1.2.md](upgrades/2026-10-release-1.1.2.md): the payment ledger, shared services, performance, documentation |
| 1.1.1 | [upgrades/2026-10-release-1.1.1.md](upgrades/2026-10-release-1.1.1.md): fixes from the 1.1 audit |
| 1.1.0 | [upgrades/2026-10-release-1.1.md](upgrades/2026-10-release-1.1.md): the core as the `pnscripts/pn-shop-core` package |
| 1.0.0 | Phase notes: [0–1](upgrades/2026-10-phase-0-1.md) safety fixes and version upgrade, [2](upgrades/2026-10-phase-2.md) core foundation and admin, [3](upgrades/2026-10-phase-3.md) localization and money, [4](upgrades/2026-10-phase-4.md) catalog, [5](upgrades/2026-10-phase-5.md) customers, carts and checkout, [6](upgrades/2026-10-phase-6.md) orders, payments, shipping, tax, [7](upgrades/2026-10-phase-7.md) CMS, menus and SEO, [8](upgrades/2026-10-phase-8.md) extensions, [9](upgrades/2026-10-phase-9.md) themes, [10](upgrades/2026-10-phase-10.md) APIs, [11](upgrades/2026-10-phase-11.md) promotions and returns, [12](upgrades/2026-10-phase-12.md) installer and updater, [13](upgrades/2026-10-phase-13.md) hardening |

## Architecture and history

| Document | Purpose |
|---|---|
| [architecture/01-discovery-and-gap-analysis.md](architecture/01-discovery-and-gap-analysis.md) | The original starter: what existed, what was missing (2026-10-01) |
| [architecture/02-platform-architecture-proposal.md](architecture/02-platform-architecture-proposal.md) | The platform architecture, decisions D1–D7, the phases to 1.0 |
| [architecture/03-post-1.0-roadmap.md](architecture/03-post-1.0-roadmap.md) | Releases after 1.0 (core package, pricing/B2B, locations, channels, returns+), approved 2026-10-02 |
| [research/version-modernization.md](research/version-modernization.md) | Versions and the upgrade plan of 2026-10-01 |
| [research/security-performance-audit.md](research/security-performance-audit.md) | The 1.0-era security and performance audit |
