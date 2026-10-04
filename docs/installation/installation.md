# Installing PN Shop

## Requirements

- **PHP:** 8.4 or newer, with the extensions pdo (plus the driver for your database), mbstring, openssl, tokenizer, xml, ctype, fileinfo, intl, bcmath, sodium and curl. zip and gd or imagick are optional.
- **Database:** MySQL 8, MariaDB 10.6+, PostgreSQL 14+ or SQLite 3.
- **Writable folders:** `storage/`, `bootstrap/cache/` and `public/` (for themes, plugin assets and the storage link), plus the project folder or `.env` for the installer.
- **Node:** only for building themes from source. The default storefront ships prebuilt in releases.
- **Background tasks:** a cron entry for the scheduler; on a server, also a queue worker (see the end of this page).

The installer checks all of this before it starts. There are **no default accounts**: you create the first administrator while installing.

## With a command line

```bash
composer create-project pnscripts/pn-shop shop    # or unpack a release archive
cd shop
php artisan pnshop:install
```

The project is a thin skeleton: the core comes from the Composer package `pnscripts/pn-shop-core` (in `vendor/`), with its storefront prebuilt, so no Node is needed. Your own code, configuration, plugins (`extensions/`) and themes (`themes/`) live in the project. Updates come through Composer; see [Updating](updating.md).

A git clone of the PN Shop repository also works. It is meant for developing PN Shop itself: the core comes from `packages/pn-shop-core`, and the storefront is built with `npm ci && npm run build`.

The command does the following:

1. Checks the server.
2. Asks for anything you did not pass as options.
3. Writes the database settings to `.env`.
4. Creates the tables.
5. Adds the payment, shipping and tax defaults.
6. Saves the store name, language, currency, country, timezone and price mode.
7. Creates the administrator.
8. Activates the default theme.
9. Writes the install lock.

Unattended, for scripts and containers:

```bash
php artisan pnshop:install --no-interaction \
  --db-connection=mysql --db-host=127.0.0.1 --db-database=shop --db-username=shop --db-password=… \
  --app-url=https://shop.example --store-name="My Shop" --locale=bg --currency=EUR --country=BG \
  --timezone=Europe/Sofia --admin-name="Owner" --admin-email=owner@shop.example --generate-password
```

| Option | Meaning |
|---|---|
| `--net-prices` | Catalog prices exclude tax (by default they include it, as is usual with VAT) |
| `--generate-password` | Prints a random administrator password once |
| `--demo` | Adds demo categories and products (needs the development dependencies) |

When database options are given, the command saves them to `.env` and continues in a fresh process, so every part of the application uses the new database.

## Without a command line (web installer)

Upload the files, point the web server at `public/`, and open the shop's address. Until PN Shop is installed, every page leads to **/install**:

1. **Requirements:** what the server has and is missing.
2. **Database:** MySQL, MariaDB, PostgreSQL, or SQLite (no setup). For the others, create an empty database and a user first. The connection is checked before anything is saved.
3. **Store and administrator:** the same settings as the command.

Notes on the web installer:

- **It runs without a database:** it works before the database exists, using file sessions and no front-end assets.
- **It switches itself off:** once installed, `/install` no longer exists.
- **Finish it right away:** until it is finished, anyone who can reach the site can run it. Finish the installation as soon as the files are uploaded, or install with the command line.
- **Cached configuration:** if the configuration is cached (`php artisan config:cache`), clear it first with `php artisan config:clear`.

## Shops set up before the installer existed

A shop that already has staff accounts counts as installed. Its first `php artisan migrate` records it in the version history (`adopt`), and the web installer never opens on it.

## After installing

- **Scheduler:** add `* * * * * cd /path/to/shop && php artisan schedule:run >> /dev/null 2>&1` to cron. It cancels orders left unpaid (hourly), removes abandoned carts (daily) and works through the queue every minute, so emails and image sizes are sent even without a queue worker. Scheduled pages and sitemaps need no job.
- **Queue worker (optional, recommended on a server):** run `php artisan queue:work --tries=3`, kept running by Supervisor or systemd, so emails go out at once. You can then turn off the scheduler's queue pass with `PNSHOP_QUEUE_FROM_SCHEDULER=false`. See [deployment](deployment.md#background-jobs).
- **Mail:** set `MAIL_*` in `.env`, then the store email under Admin → Settings.
- **Payment, shipping and tax:** set them up under Admin → Store.
- **Production `.env`:** use `APP_ENV=production` and `APP_DEBUG=false`. After every deploy, run `php artisan optimize`.
- **Going live:** see [deployment](deployment.md) for the web server, the worker, backups and the checklist.

## Starting over

The install is recorded in the database (`system_versions`) and in `storage/app/pnshop-installed.json`. To reinstall, use an empty database **and** delete the lock file.
