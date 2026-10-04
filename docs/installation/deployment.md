# Deploying a shop

How to run a PN Shop shop on a server: the web server, the background jobs, caches, backups and updates. Install it first ([installation](installation.md)).

## Server

- **PHP:** 8.4 or newer with PHP-FPM, plus the extensions the installer lists.
- **Database:** MySQL 8, MariaDB 10.6+ or PostgreSQL 14+ for a live shop. SQLite works for small shops on one server.
- **Writable folders:** `storage/` and `bootstrap/cache/` must be writable by the PHP user, and `public/` too, because themes and plugin assets are published there.
- **Node:** not needed. The storefront comes prebuilt with the core.

## Web server

Point the site at `public/`, and only at it. With nginx and PHP-FPM:

```nginx
server {
    listen 443 ssl;
    server_name shop.example;
    root /var/www/shop/public;
    index index.php;

    client_max_body_size 20m;   # product images (up to 10 MB) and plugin zips

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

On shared hosting with Apache, the `public/.htaccess` that ships with the project does the same job. The document root must still be `public/`.

## .env

- `APP_ENV=production` and `APP_DEBUG=false`.
- `APP_URL`: the shop's real HTTPS address. Once installed, the shop only answers for this host and its subdomains, plus `PNSHOP_TRUSTED_HOSTS` and the domains of active [channels](../ecommerce/channels.md). Each channel domain also needs its DNS, a web server name and an HTTPS certificate.
- `TRUSTED_PROXIES`: behind a load balancer, Cloudflare or a reverse proxy, the proxies' IPs or ranges (or `*`). Without it, rate limits see every visitor as the proxy.
- `MAIL_*`: a real mail service, so order emails arrive.
- `SESSION_SECURE_COOKIE=true` when the shop is HTTPS only.

## Background jobs

**The scheduler** runs from cron every minute:

```cron
* * * * * cd /var/www/shop && php artisan schedule:run >> /dev/null 2>&1
```

It cancels orders left unpaid (hourly) and removes abandoned carts (daily). Plugins can add their own jobs.

**A queue worker** sends emails and makes image sizes. Keep it running with systemd:

```ini
# /etc/systemd/system/shop-queue.service
[Unit]
Description=PN Shop queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/shop
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

Then run `systemctl enable --now shop-queue`. Supervisor works just as well. After each update, run `php artisan queue:restart` so the worker loads the new code.

## Caches

After every deploy or update:

```bash
php artisan optimize           # config, routes, events and views
php artisan filament:optimize  # admin components and icons
```

Enabling, disabling or updating a plugin clears the route and admin caches by itself. Run both commands again afterwards.

## Backups

- **Database:** back it up every day with your host's tools or `mysqldump` / `pg_dump`, and keep copies off the server. `pnshop:update` also makes a backup before every update, in `storage/app/backups/`.
- **Files:** back up `storage/app/` (uploaded images), `.env`, and your own `extensions/` and `themes/`.
- **A restore test** from time to time is the only proof that the backups work.

## Updates

```bash
composer update pnscripts/pn-shop-core --with-all-dependencies
php artisan pnshop:update --dry-run
php artisan pnshop:update
php artisan optimize && php artisan queue:restart
```

See [updating](updating.md) for what the update checks and does.

## Checklist

- [ ] The site is HTTPS only, with `APP_URL` set to it.
- [ ] `APP_DEBUG=false` and `APP_ENV=production`.
- [ ] `TRUSTED_PROXIES` is set if the shop sits behind a proxy.
- [ ] The cron entry and the queue worker run (`php artisan schedule:list` lists the jobs; a test order email arrives through the queue).
- [ ] A test order arrives by email, for both the customer and the store.
- [ ] Daily database backups are kept off the server.
- [ ] Only plugins and themes you trust are installed, and plugin zip uploads are off unless needed.

More on security: [security](../security/security.md).
