# PN Shop

This is your [PN Shop](https://pnscripts.com/products/pn-shop) project: a self-hosted CMS and e-commerce platform on Laravel, with a React storefront and a Filament admin panel.

The core comes from the Composer package `pnscripts/pn-shop-core` (in `vendor/`). This project holds what is yours: configuration, `.env`, your own code in `app/`, routes in `routes/web.php`, plugins in `extensions/` and themes in `themes/`.

## Install

```bash
php artisan pnshop:install        # or open the site in a browser: /install
```

The installer sets up the database, the store settings and the first administrator. The storefront comes prebuilt, so the server needs no Node.

## Update

```bash
composer update pnscripts/pn-shop-core --with-all-dependencies
php artisan pnshop:update         # --dry-run first shows what will happen
```

## Go live

- Set `APP_URL` to the shop's HTTPS address. Behind a load balancer or Cloudflare, also set `TRUSTED_PROXIES`.
- Run the scheduler (`* * * * * php artisan schedule:run`): it also sends the queued emails, so shared hosting needs nothing more. On a server, add a queue worker (`php artisan queue:work`) so emails go out at once.

## Documentation

- [Installing](https://github.com/pnscripts/pn-shop-source/blob/main/docs/installation/installation.md)
- [Updating](https://github.com/pnscripts/pn-shop-source/blob/main/docs/installation/updating.md)
- [Security checklist](https://github.com/pnscripts/pn-shop-source/blob/main/docs/security/security.md)
- [Plugins](https://github.com/pnscripts/pn-shop-source/blob/main/docs/extensions/plugins.md) and [themes](https://github.com/pnscripts/pn-shop-source/blob/main/docs/themes/themes.md)
- [All documentation](https://github.com/pnscripts/pn-shop-source/tree/main/docs)

PN Shop is MIT-licensed. Issues and contributions: [pnscripts/pn-shop-source](https://github.com/pnscripts/pn-shop-source).
