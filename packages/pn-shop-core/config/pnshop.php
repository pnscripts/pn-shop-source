<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extra modules
    |--------------------------------------------------------------------------
    |
    | The core modules are part of PN Shop itself (PnShop::MODULES). Service
    | providers listed here are booted after them, before plugins.
    |
    */

    'extra_modules' => [],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Uploads are stored on this filesystem disk (run `php artisan storage:link`
    | for the default "public" disk). Images get resized WebP conversions no
    | wider than the given pixel widths.
    |
    */

    'media' => [
        'disk' => env('PNSHOP_MEDIA_DISK', 'public'),
        'conversions' => [
            'thumb' => 320,
            'medium' => 800,
            'large' => 1600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Form protection
    |--------------------------------------------------------------------------
    |
    | Checkout and registration refuse submissions that fill the hidden
    | honeypot field, arrive faster than `min_seconds` after the form was
    | shown, or use a form older than `max_age_hours`.
    |
    */

    'security' => [
        'bot_trap' => [
            'min_seconds' => (int) env('PNSHOP_BOT_TRAP_MIN_SECONDS', 2),
            'max_age_hours' => 24,
        ],
        // Days a guest's signed order link (emails, checkout API) keeps working.
        'order_link_days' => (int) env('PNSHOP_ORDER_LINK_DAYS', 180),
        // Hosts answered besides APP_URL's host and its subdomains (comma-separated).
        'trusted_hosts' => env('PNSHOP_TRUSTED_HOSTS', ''),
    ],

    /*
    | Proxies (load balancer, Cloudflare, nginx in front of PHP) whose X-Forwarded-*
    | headers are trusted, so the visitor's real IP is used for rate limits and logs:
    | comma-separated IPs or CIDR ranges, or * for any proxy. Empty: no proxy trusted.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Emails and image sizes are sent through the queue. So that they also go
    | out on hosts without a permanent worker (shared hosting), the scheduler
    | (cron, every minute) works through the queue and stops once it is empty
    | or after `max_time` seconds. With a worker kept running by systemd or
    | Supervisor this is not needed and can be turned off; leaving it on does
    | no harm.
    |
    */

    'queue' => [
        'work_from_scheduler' => (bool) env('PNSHOP_QUEUE_FROM_SCHEDULER', true),
        'max_time' => (int) env('PNSHOP_QUEUE_MAX_TIME', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    |
    | Plugins live in `path` (<vendor>/<name>/pnshop.json) or come from Composer
    | packages of type "pnshop-plugin". Enabled plugins are booted from `cache`.
    | Safe mode boots no plugins at all, so a broken one can be removed.
    | Plugins run with full application privileges: install only code you trust.
    |
    */

    'extensions' => [
        'path' => env('PNSHOP_EXTENSIONS_PATH', base_path('extensions')),
        'cache' => env('PNSHOP_PLUGIN_CACHE', base_path('bootstrap/cache/pnshop-plugins.php')),
        'safe_mode' => (bool) env('PNSHOP_SAFE_MODE', false),
        // Zip uploads in the admin; off by default.
        'uploads' => (bool) env('PNSHOP_EXTENSION_UPLOADS', false),
        // Refuse plugins without a valid signature from one of the trusted keys.
        'require_signatures' => (bool) env('PNSHOP_REQUIRE_SIGNATURES', false),
        // key id => base64 Ed25519 public key
        'trusted_keys' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Themes
    |--------------------------------------------------------------------------
    |
    | Storefront themes live in `path` (<vendor>/<name>/pnshop.json). A theme
    | ships a prebuilt bundle in dist/, published to public/themes/<id>/build.
    |
    */

    'themes' => [
        'path' => env('PNSHOP_THEMES_PATH', base_path('themes')),
    ],

    /*
    |--------------------------------------------------------------------------
    | APIs
    |--------------------------------------------------------------------------
    |
    | Requests per minute for each token (or IP address without a token) on
    | the Store API (/api/store/v1) and the Admin API (/api/admin/v1).
    |
    */

    'api' => [
        'store_rate_limit' => (int) env('PNSHOP_STORE_API_RATE_LIMIT', 120),
        'admin_rate_limit' => (int) env('PNSHOP_ADMIN_API_RATE_LIMIT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer
    |--------------------------------------------------------------------------
    |
    | Until PN Shop is installed every page leads to the web installer
    | (/install). `enforce` is off in the test suite. The lock file marks
    | the installation; delete it only together with the database.
    |
    */

    'installer' => [
        'enforce' => (bool) env('PNSHOP_ENFORCE_INSTALL', true),
        'lock' => env('PNSHOP_INSTALL_LOCK', storage_path('app/pnshop-installed.json')),
        // Cache store for the installer's rate limits (the database may not exist yet).
        'cache_store' => env('PNSHOP_INSTALLER_CACHE', 'file'),
    ],

];
