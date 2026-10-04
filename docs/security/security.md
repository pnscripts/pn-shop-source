# Security

How PN Shop protects the shop, and the settings that matter in production.

## Accounts and permissions

- **Separate accounts:** staff (`admin_users`, guard `admin`, Filament at `/admin`) and customers (`users`, guard `web`) are separate tables and sessions. A customer can never become staff.
- **No default accounts:** the installer creates the first administrator.
- **Permissions:**
  - Roles carry permission keys. The `administrator` role holds every permission.
  - Staff can only give what they hold themselves: they cannot assign roles or add permissions beyond their own.
  - Staff who manage accounts can only change (email, password, roles) or delete accounts that hold nothing they do not hold themselves. Only administrators can change administrator accounts.
  - Stock changes need `catalog.inventory.manage`, in the product and variant forms and in the Admin API's `stock` fields as well as its stock endpoint.
- **API tokens:** Admin API tokens carry a chosen subset of their owner's permissions, and every call also checks the owner's current permissions.
  - Store API tokens are customer-only.
  - Both APIs use bearer tokens only, never cookies, so there is no CSRF exposure.
- **New passwords:** changing or resetting a customer's password signs out their other browsers and "remember me" logins and revokes their Store API tokens. Deleting an account revokes its tokens.
- **Email verification:** off by default. *Settings → Customers → Require a verified email address* sends new customers a link and keeps unverified accounts out of the account pages and Store API ordering.
- **Content blocks:** custom HTML needs `cms.html_block`, also when restoring an older page revision or saving through the API.

## Requests

- **Trusted hosts:** once installed, the shop only answers for the host of `APP_URL` and its subdomains, plus `PNSHOP_TRUSTED_HOSTS` (comma-separated). A forged `Host` header cannot put another domain into password-reset emails, signed links or the sitemap. **Set `APP_URL` to the real address.**
- **Installer:** reachable only while the shop is not installed. If the database cannot be reached, every page (the installer included) answers 503 rather than offering a new installation.
- **Spam protection:** checkout and registration are protected by a honeypot and a time trap, and checkout, login (per email and per IP), cart changes and coupon attempts are rate limited.
- **Proxies:** rate limits count per visitor IP. Behind a load balancer, Cloudflare or a reverse proxy, set `TRUSTED_PROXIES` (the proxies' IPs or CIDR ranges, comma-separated, or `*`), or every visitor shares the proxy's IP and one busy visitor can block checkout for everyone.
- **Errors:** API errors never include stack traces or model names, and `APP_DEBUG` must be `false` in production.

## Orders and payments

- **Prices:** always read from the database at checkout, under row locks. Stock reservations and promotion usage limits use conditional updates, so they cannot be oversold under load.
- **Double submits:** the cart is locked and emptied in the checkout transaction, so a double submit cannot place two orders. Refunds of the same order or return are serialized.
- **Unpaid orders:** cancelled automatically after *Settings → Orders → Cancel unpaid orders after* (default 168 hours; 0 turns it off), which releases their stock. The scheduler must be running.
  - Orders that have shipped (fully or partly) are never cancelled automatically: a cash-on-delivery order stays unpaid until the money is collected.
  - The order is checked again under its lock, so a payment that arrives meanwhile wins.
  - Orders with nothing to pay (for example a 100% discount) are marked paid when placed.
- **Guest order links:** signed links in emails and in the Store API's checkout response expire after `PNSHOP_ORDER_LINK_DAYS` (default 180).
- **Stripe:**
  - Webhooks are verified with the signing secret, and amount and currency are checked.
  - The return page only confirms a payment, and links to the order, when the Checkout Session matches.
  - Cancelling an order expires its Checkout Session. A payment that still arrives is recorded and flagged in the order history for a refund.

## Files

- **Media uploads:** images only (JPEG, PNG, WebP, AVIF, GIF; no SVG), checked by content, and stored under random names.
- **Plugin zips:** uploads are off by default (`PNSHOP_EXTENSION_UPLOADS`). Archives are checked before anything is written: no paths outside the plugin, no symbolic links, only allowed file types, and size limits.
- **Plugin signatures:** they can be required (`PNSHOP_REQUIRE_SIGNATURES`).
- **Plugin assets:** only static files (scripts, styles, fonts, images) from a plugin's storefront folder are published to `public/`.
- **Backups:** `pnshop:update` writes them readable by the owner only. They contain `.env` and the database, so keep `storage/` out of the web root, as the default layout does.

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` set to the real HTTPS address.
- The web server serves `public/` only.
- HTTPS everywhere. Behind a load balancer or Cloudflare, set `TRUSTED_PROXIES`.
- The scheduler is running, and the queue worker if you use one; `php artisan queue:failed` lists no failed emails (see [installation](../installation/installation.md)).
- Plugin zip uploads stay off unless needed. Install only plugins and themes you trust: plugins run PHP on your server, and themes run in your visitors' browsers.
- Regular backups (`pnshop:update` backs up before updating; schedule your own as well).

## Reporting a vulnerability

Report security issues privately to the maintainers (see the repository's contact details), not in public issues.
