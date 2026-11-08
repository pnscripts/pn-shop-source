# AGENTS.md

Guidance for AI assistants and contributors working in this repository: the source of **PN Shop**, a self-hosted CMS and e-commerce platform by PN Scripts.

In the PN Scripts DEV workspace, read the shared AI Brain first: the router [`../../../../ai-brain/AGENTS.md`](../../../../ai-brain/AGENTS.md), then this repo's profile [`../../../../ai-brain/projects/products/pn-shop.md`](../../../../ai-brain/projects/products/pn-shop.md). Rules written in this repository override generic knowledge.

## Layout

- **`packages/pn-shop-core/`** is the core, published as the Composer package `pnscripts/pn-shop-core` (namespace `PnShop\`):
  - `src/<Module>`: Catalog, Sales, Payment, Cms, Api, Admin and the rest;
  - routes, migrations, seeders and translations;
  - `resources/js`: the React/Inertia storefront;
  - `theme/`: the built-in theme.
  - Composer links it into `vendor/` through a path repository.
- **The root** is the `pnscripts/pn-shop` project skeleton (`app/`, `config/`, `routes/web.php`) plus development tooling.
- **`extensions/<vendor>/<name>`** holds plugins and **`themes/<vendor>/<name>`** holds themes.
- **Docs** are in [`docs/`](docs/README.md): start with [core modules](docs/development/core-modules.md) and [testing](docs/development/testing.md).

## Rules

- **Order states:**
  - Change them only through `PnShop\Sales\OrderWorkflow`.
  - Shipments go through `ShipmentService`, refunds through `RefundService`, returns through `ReturnService`.
  - Staff never set "refunded" or "shipped" by hand (`ManualStateChanges`).
- **Money:** amounts are minor units (Brick Money). Prices are always read from the database at checkout; carts hold only variant ids and quantities.
- **Shared logic** lives in core services, used by both the admin panel and the APIs, never copied into controllers or Filament resources. Examples: `VariantService`, `DeliveryQuote`, `Registration`, `CheckoutRules`.
- **Extending:** plugins and themes use the extension points (registries, pipelines, events, `<Slot>`s) and never patch core classes. Do not remove storefront slots.
- **Compatibility:** no breaking changes to plugin or theme contracts before 2.0. Do not delete or rename storefront components that themes can import.
- **Migrations:**
  - Additive and portable (SQLite, MySQL, PostgreSQL).
  - Dated after the newest core migration.
  - Never rename a released migration.
- **Public content** presents PN Shop on its own: no comparisons with, or names of, other shop platforms.
- **Commits:** conventional commits, made by explicit path. Work happens on `next`; `main` is fast-forwarded when CI is green.

## Checks before a change is done

`scripts/release/check.sh` runs everything the lint and tests workflows check, in the same order; run it before every push.

```bash
./vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse && vendor/bin/phpstan analyse -c phpstan-core.neon
npm run format:check && npm run lint:check && npm run types
```

Changes to migrations or SQL are also tested on MySQL and PostgreSQL ([testing](docs/development/testing.md)). Update the docs and `CHANGELOG.md` together with the code.
