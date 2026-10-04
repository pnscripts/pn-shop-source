# PN Shop

**PN Shop** is an open-source, self-hosted CMS and e-commerce platform by PN Scripts. It is built on **Laravel 13**, with a **React 19 / Inertia** storefront and a **Filament 5** admin panel. You can extend it with plugins and themes. It is MIT-licensed and free.

This is the source repository. Shops are created from the published packages `pnscripts/pn-shop` (the project) and `pnscripts/pn-shop-core` (the core). Documentation is in [docs/](docs/README.md), and the changes per release are in [CHANGELOG.md](CHANGELOG.md).

## What it does

- **Catalog:** products with variants (size, colour…), each with its own SKU, price, sale price, weight and stock; nested categories, brands, filterable attributes, related products, upsells and cross-sells; a media library with WebP sizes and responsive images.
- **Inventory:** a stock ledger with reservations at checkout. Stock leaves the shelf when an order ships and comes back on cancellation and returns.
- **Selling:**
  - carts kept in the database (they follow customers across devices);
  - guest and account checkout with structured addresses;
  - shipping zones and methods (flat, free, pickup, by weight, by subtotal);
  - tax classes, zones and rates, with prices including or excluding tax;
  - promotions and coupons with usage limits.
- **Orders:** numbered orders with separate status, payment and fulfillment states, partial shipments with tracking, refunds, invoices, return requests (RMA), and emails in the customer's language.
- **Payments:** cash on delivery and bank transfer are built in. Card payments come as plugins; Stripe Checkout is included.
- **Content and SEO:** pages built from content blocks, with revisions and scheduled publishing; header and footer menus; meta tags, hreflang, JSON-LD, sitemaps and automatic redirects.
- **Languages and money:** translatable catalog and content, localized URLs (`/bg/…`), and amounts stored as minor units.
- **Extending:**
  - plugins (install, update and remove from the admin or the command line, with signatures and a safe mode);
  - child themes that override storefront files by path;
  - storefront slots and blocks for plugins;
  - a **Store API** and an **Admin API** with an OpenAPI description.
- **Running it:**
  - a command-line and web installer with no default accounts;
  - `pnshop:update` with a dry run, backups and plugin checks;
  - staff roles and permissions, and an activity log.

Planned next: price lists and B2B pricing, stock in several locations, and several storefronts on one installation. See the [roadmap](docs/architecture/03-post-1.0-roadmap.md).

## Create a shop

```bash
composer create-project pnscripts/pn-shop shop
cd shop
php artisan pnshop:install          # or open the site in a browser: /install
```

The installer checks the server, sets up the database (SQLite, MySQL, MariaDB or PostgreSQL), creates the tables and the store settings, and creates the **first administrator**. There are no default accounts. The storefront comes prebuilt, so the server needs no Node.

To update, run `composer update pnscripts/pn-shop-core --with-all-dependencies` and then `php artisan pnshop:update`. Read [installing](docs/installation/installation.md) and [updating](docs/installation/updating.md) before going live, and also the [security checklist](docs/security/security.md#production-checklist).

## Requirements

- PHP 8.4 or newer, with the usual extensions (the installer lists anything missing).
- MySQL 8, MariaDB 10.6+, PostgreSQL 14+ or SQLite 3.
- A cron entry for the scheduler (it also sends the queued emails); on a server, a queue worker as well.
- Node 22.12+ (24 recommended) only for building themes or developing the storefront.

## Developing PN Shop

In this repository the core lives in `packages/pn-shop-core`. Composer links it into `vendor/` through a path repository.

```bash
git clone git@github.com:pnscripts/pn-shop-source.git pn-shop
cd pn-shop
composer install
cp .env.example .env               # set APP_ENV=local and APP_DEBUG=true for development
php artisan key:generate
php artisan pnshop:install --demo  # with demo categories and products
npm ci
composer run dev                   # app server, Vite, queue worker and logs
```

Checks (CI runs them all on every pull request, on SQLite, MySQL and PostgreSQL):

```bash
./vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse && vendor/bin/phpstan analyse -c phpstan-core.neon
npm run format:check && npm run lint:check && npm run types
```

Start with [core modules](docs/development/core-modules.md) for the architecture, [plugins](docs/extensions/plugins.md) and [themes](docs/themes/themes.md) for extending, and [publishing](docs/release/publishing.md) for releases.

## More from PN Scripts

- [PN Invoice](https://github.com/pnscripts/pn-invoice): free PHP library to write and validate EN 16931 e-invoices (UBL and CII).
- [Laravel and Filament upgrades and care](https://pnscripts.com/services/laravel-filament-care): fixed-price upgrades to Laravel 13 and Filament 5, and monthly care plans.
- All products: [pnscripts.com/products](https://pnscripts.com/products)

## License

MIT © 2026 Petar Nikolov / PN Scripts
