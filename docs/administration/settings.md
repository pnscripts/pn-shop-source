# Settings

Staff with **system.settings.manage** change the shop's settings in Admin → System → Settings, one tab per area. The same settings can be read and changed with the Admin API (`GET/PATCH /api/admin/v1/settings/{namespace}`). Values are checked before they are saved, and secret values (payment keys) are stored encrypted and never shown again.

With several [channels](../ecommerce/channels.md), a channel can override the store name, contact details and address, the theme, the tax country and search engine indexing; everything else is shared.

Server settings (database, mail, the shop's address, trusted proxies) live in `.env` instead; see [installation](../installation/installation.md) and [deployment](../installation/deployment.md).

## Store

| Setting | Default | Notes |
|---|---|---|
| Store name | `APP_NAME` | Shown in page titles, emails and the admin. |
| Contact email, phone, address | — | Shown to customers; the email also receives store alerts unless *Emails* names another address. |

## Localization

| Setting | Default | Notes |
|---|---|---|
| Store timezone | UTC | Order dates, reports and scheduled publishing. |

Languages, currencies and countries have their own screens under Admin → Store.

## Customers

| Setting | Default | Notes |
|---|---|---|
| Require a verified email address | off | New customers get a link by email and must open it before using their account pages or ordering through the Store API. Turning it on also asks existing customers to verify. |
| Show prices to guests | on | Off: only signed-in customers see prices and can add to the cart. See [Pricing](../ecommerce/pricing.md#prices-for-signed-in-customers-only). |

## Orders

| Setting | Default | Notes |
|---|---|---|
| Order number prefix, digits | `ORD-`, 6 | Applies to new orders, e.g. ORD-000042. |
| Cancel unpaid orders after (hours) | 168 | Pending, unpaid orders that have not shipped are cancelled and their stock released. Allow enough time for bank transfers; 0 turns this off. Cash-on-delivery orders that have shipped, and orders paid by invoice, are never cancelled. |
| Issue invoices | when paid | Or when placed, or only when staff issue them. |
| Invoice number prefix, digits | `INV-`, 6 | |
| Legal name, tax / VAT number, footer | — | Printed on invoices; the legal name defaults to the store name. |

## Emails

Each customer email can be turned off: order confirmation, shipping updates, cancellations, refunds and return updates. *New order alert to the store* goes to *Send store alerts to*, or to the store's contact email.

## Tax

| Setting | Default | Notes |
|---|---|---|
| Prices include tax | on | On: catalog prices are what customers pay (usual for VAT). Off: tax is added at checkout. |
| Calculate tax for | shipping address | Or the billing address, or the store's country. |
| Store country | — | Two-letter code, used before the customer enters an address. |

Tax classes, zones and rates are set up under Admin → Store; see [tax](../ecommerce/tax.md).

## Returns

| Setting | Default | Notes |
|---|---|---|
| Customers can request returns online | on | |
| Return period (days after shipping) | 14 | In the EU, consumers have at least 14 days to withdraw from an online purchase. |
| Return number prefix | `RMA-` | |

## Content

| Setting | Default | Notes |
|---|---|---|
| Revisions kept per page | 30 | Older revisions are removed when a page is saved. |

## Search engines

| Setting | Default | Notes |
|---|---|---|
| Let search engines index the shop | on | Turn off on a staging copy: every page is marked noindex and robots.txt disallows everything. |
| Extra robots.txt rules | — | Added to the generated robots.txt. |

## Theme and plugin tabs

- **Theme: *name*:** the active theme's own settings (colours, corners…), applied as CSS variables. See [themes](../themes/themes.md).
- **Plugins:** each enabled plugin with settings gets its own tab, for example the Stripe keys. See [plugins](../extensions/plugins.md).
