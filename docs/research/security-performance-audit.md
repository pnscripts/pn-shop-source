# PN Shop (pn-shop-source): security and performance audit

- Date: 2026-10-01
- Scope: `app/`, `routes/`, `bootstrap/`, `config/{auth,session,app,telescope,filesystems,inertia}`, `database/{migrations,seeders,factories}`, `resources/views`, `resources/js` (pages/components), `public/.htaccess`, `public/build`, `.github/workflows`
- Mode: read-only. No repository files were modified. `.env` was checked only for key names.
- Paths below are relative to the repository root.

## Results of the commands that were run

| Check | Result |
|---|---|
| `php artisan test` | **68 passed (218 assertions), 6.2s.** No failures. |
| `php artisan route:list --except-vendor` | 40 app routes. The 8 admin routes are all inside `['auth','admin']`. `/orders/{order}`, `/cart*` and `/checkout*` are public, which is intended for guest checkout. Vendor routes are also registered: `_debugbar/*` (dev dependency), `docs/api` and `docs/api.json` (Scramble, a production dependency, with access limited to the local environment), and `storage/{path}` GET/PUT (signed local-disk serving). |
| `composer audit` | 3 advisories. **laravel/framework 13.29.0**: XSS in the debug page (low, fixed in 13.30.0). **league/commonmark 2.10.0**: DisallowedRawHtml bypass (medium) and a GFM table quadratic DoS (high). The app does not parse markdown itself, so commonmark only enters through the framework (`Str::markdown`). |
| `npm audit` | 22 vulnerabilities in total (2 critical, 12 high). With `--omit=dev` there are still 14, because build tools (vite, rollup, tailwind, concurrently) are listed under `dependencies`. The one that matters at runtime is **axios 1.8.1**, which is shipped to browsers through `@inertiajs/core@2.0.4` (prototype-pollution gadgets, XSRF-token leakage gadget, ReDoS) together with form-data 4.0.2. The rest affect build or dev time only (vite dev-server `fs.deny` bypasses, shell-quote via concurrently, rollup, lodash, picomatch). |
| Query count (in-memory SQLite, 30 products, run from a scratchpad script) | `/` = **34 queries**, `/shop` = **52 queries**, `/shop/{slug}` = 9 queries. Each product card costs about 4 queries, caused by `HasTranslations`. |
| Bundle (`public/build`) | 720 KB total. `app-*.js` is 325 KB (106 KB gzip), `app-logo-*.js` 80 KB, `app-layout-*.js` 28 KB, CSS 76 KB. Pages load lazily (`import.meta.glob` without `eager`), so code splitting works. |

**Things that were checked and are OK:**
- Every admin route uses `auth` + `admin` middleware, and the write FormRequests check `isAdmin()` a second time.
- `is_admin` is not in `User::$fillable`. Registration and profile update pass explicit fields, so users cannot make themselves admin.
- The order IDOR check is correct: owner, an order ID stored in this session, or admin.
- Inactive products are rejected both when adding to the cart and at checkout.
- No `dangerouslySetInnerHTML` and no `{!! !!}`.
- No raw SQL, and no `orderBy` built from user input.
- There are no file uploads.
- No CSRF exemptions.
- Login is rate-limited (5 attempts per email+IP).
- The session is regenerated on login and invalidated on logout.
- Checkout runs in `DB::transaction` and decrements stock.
- The Telescope gate is an empty allow-list, so nobody can open it outside the local environment.
- `TELESCOPE_ENABLED=false` by default.

**No Critical findings.**

---

## High

### H1. Anyone can drain all stock with unpaid guest orders (no throttle, no payment, no stock reservation)
- **Where:** `routes/web.php:19,24` (cart and checkout have no `throttle`), `app/Services/CheckoutService.php:66` (stock decremented at once), `app/Http/Controllers/Admin/OrderController.php:37-44` (cancelling does not restock).
- **Failure scenario:** A script adds every product at maximum quantity to the cart and posts `/checkout` with made-up contact details and "Cash on Delivery". There is no login, no CAPTCHA, no rate limit and no payment step, and stock drops to 0 immediately. The whole catalogue shows as out of stock until an admin manually fixes stock for each product, and cancelling those orders does not return the stock.
- **Fix:**
  - Add `throttle:checkout` with a named limiter, for example 5 per minute per IP plus a per-email limit. Throttle `cart.store` and `register` too.
  - Restock automatically when an order moves to `cancelled` (an `Order` status-transition service).
  - Consider reserving stock with a TTL, or decrementing only on `paid`, for unpaid methods. Add a cap on the quantity of one product per order.

### H2. Checkout charges the price saved in the session, not the current price
- **Where:** `app/DTOs/ShoppingCartDTO.php:51-59` (price, discount and stock copied into the session), `app/Services/CheckoutService.php:58-64` (`'price' => $item->price`, `'discount_price' => $item->discount_price`), `app/Services/ShoppingCartService.php:182-198`.
- **Failure scenario:** A shopper adds an item during a sale (discount 10.00). The admin later ends the sale or raises the price. The shopper checks out days later, up to the session lifetime, and the order is written at the old discounted price. A 0.01 mistake fixed by the admin can still be bought at 0.01 by anyone who already had it in the cart. The cart page also shows the stale `stock`. The client cannot tamper with the session, but the server trusts a stale copy.
- **Fix:** Store only `product_id => quantity` in the session. In `CheckoutService::place()`, read the price from the locked `$product` (`$product->discount_price ?: $product->price`). Rebuild cart display data from one `whereIn` query, and show a "price changed" notice when the values differ.

### H3. Seeded default admin with a known password
- **Where:** `database/seeders/DatabaseSeeder.php:15-18` (`User::factory()->admin()` with `test@example.com`), `database/factories/UserFactory.php:30` (password `password`), and `README.md:91-92`, which documents it.
- **Failure scenario:** A deployment runs `php artisan migrate --seed` or `db:seed` in production, as starter-kit users often do. Anyone who has read the public README can then log in to `/admin` as `test@example.com` / `password`.
- **Fix:**
  - Seed the admin only when `app()->environment('local','testing')`.
  - Otherwise, create the admin with an artisan command (`app:make-admin {email}`) that prompts for the password or generates a random one.
  - Make `DatabaseSeeder` refuse to run in production.

### H4. Translation N+1: every translatable attribute read runs a query (performance)
- **Where:** `app/Traits/HasTranslations.php:17-23` and `:71-83`. `getAttribute()` calls `translate()` → `translations()->where()->value()` with no memoization. It is used by `Product` (title, slug, description), `ProductCategory` (title, slug), `ProductAttribute` (label) and `ProductAttributeValue` (value).
- **Failure scenario:** The query counts above: `/` = 34 queries and `/shop` = 52 queries for 8 and 12 cards (`HomeController.php:13-44`, `ShopController.php:17-39`). Each read of `$product->title` or `$product->category->title` is a query, including the reads in `CartItemDTO` creation, in every place `HasSlug` reads it, and in the exception messages in checkout. Load grows linearly with page size and the number of fields read, on every storefront request.
- **Fix:**
  - Eager-load `translations` (`->with(['translations','category.translations'])`) and resolve from the loaded relation, for example `$this->relationLoaded('translations') ? $this->translations->first(...) : ...`.
  - Skip the lookup when `app()->getLocale() === config('app.fallback_locale')` and no translations exist.
  - Better, switch to JSON translatable columns (spatie/laravel-translatable).

---

## Medium

### M1. Internal exception messages are shown to customers
- **Where:** `app/Http/Controllers/CheckoutController.php:45-49` (`catch (Exception $e) ... ->with('error', $e->getMessage())`), and also `CartController.php:35-38,51-54`.
- **Failure scenario:** A `QueryException` (lock timeout, a constraint violation, an `SQLSTATE[...]` with the SQL text and table names) is caught as a generic `Exception` and shown in the flash banner to anonymous users. Because it is caught, it is also not reported to the logs or Telescope.
- **Fix:** Throw a domain exception (`CheckoutException` / `CartException`) for the expected business errors and catch only that type. Let other exceptions propagate so they are reported, or `report($e)` and show a generic message.

### M2. Insecure environment defaults, and debug tooling installed as production dependencies
- **Where:** `.env.example:2,4` (`APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`), `composer.json` `require` (`laravel/telescope`, `dedoc/scramble` are production dependencies), `bootstrap/providers.php:5` (Telescope provider always registered), plus the laravel/framework advisory GHSA-jh5r-qr3c-85q8 (XSS in the debug page, versions below 13.30.0).
- **Failure scenario:**
  - A server built from `.env.example` with `composer install` (without `--no-dev`) shows Ignition stack traces with environment details.
  - It also turns on Debugbar (`/_debugbar/open` exposes the query and request history of other users, including checkout PII) and runs Telescope's `local` filter, which records everything.
  - The debug-page XSS then also applies.
- **Fix:**
  - Ship `.env.example` with `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, or add a separate `.env.production.example`.
  - Move `laravel/telescope` to `require-dev` and register it conditionally in `AppServiceProvider::register()` when `$this->app->environment('local')`. Remove Scramble, since there are no API routes.
  - Upgrade `laravel/framework` to at least 13.30.0.

### M3. Deleting a product or category destroys order history
- **Where:** `database/migrations/2025_04_11_134433_create_order_items_table.php:17` (`product_id ... onDelete('cascade')`), `2025_04_10_034217_create_products_table.php:16` (`product_category_id ... onDelete('cascade')`), `2025_04_09_085557_create_product_categories_table.php:18` (parent → child cascade).
- **Failure scenario:** An admin or a later maintenance script force-deletes a category, for example through tinker or a future "delete category" feature. That removes its subcategories, then their products, then every `order_items` row that refers to those products. Past orders lose their lines and totals, which breaks accounting and VAT records. Soft deletes help only as long as nothing ever runs `forceDelete`.
- **Fix:** Use `restrictOnDelete()` (or `nullOnDelete()` with a stored `product_title` snapshot) on `order_items.product_id` and `products.product_category_id`. Store `title` and `sku` on `order_items` so orders are self-contained.

### M4. Money is handled as float, and order totals are recomputed on every view instead of being stored
- **Where:** `app/DTOs/CartItemDTO.php:9-10,31-35` (`float $price`), `app/Models/OrderItem.php:32-35` (`'price' => 'float'`), `app/Http/Controllers/OrderController.php:38-40` (total computed when the page renders), `app/Models/Product.php:48-49` (`decimal:2` returns a string, which is then coerced to float). `orders` has no `subtotal`, `discount_total`, `total`, `currency` or `paid_at`.
- **Failure scenario:**
  - `0.1 + 0.2` style rounding errors in line and order totals, which grows with quantity × price.
  - Admin order lists cannot show or sort by total without loading all items.
  - Any future change to how totals are computed (shipping, tax, coupons) silently rewrites the totals of historic orders.
- **Fix:** Store prices as integer minor units (cents) or use a Money value object or brick/math. Persist `subtotal`, `discount_total` and `total` (and `currency`) on `orders` inside the checkout transaction. Format money only in the UI.

### M5. Cancelling an order does not restock, and status changes have no rules
- **Where:** `app/Http/Controllers/Admin/OrderController.php:37-44`, `app/Http/Requests/Admin/UpdateOrderStatusRequest.php:20`.
- **Failure scenario:** An admin can move `shipped` back to `pending`, or `cancelled` to `paid`. Cancelling leaves the stock decremented, and moving out of `cancelled` does not take it again. Inventory drifts with every status change. There is also no payment model: `paid` is just a label.
- **Fix:** Use an `OrderStatus` enum or a code column with an allowed-transition map. Use an `OrderStatusChanged` handler that restocks on `→cancelled` inside a transaction with `lockForUpdate`. Add a `payments` table (or at least `paid_at`) separate from the fulfilment status.

### M6. Outdated axios shipped in the browser bundle
- **Where:** `package.json` (`@inertiajs/react ^2.0.0` resolved to 2.0.4 → axios 1.8.1, form-data 4.0.2).
- **Failure scenario:** axios 1.8.1 has many prototype-pollution gadget advisories, including the XSRF-token leakage gadget in `withXSRFToken`. If a prototype pollution happens anywhere in the bundle, it could redirect Inertia requests or leak the XSRF token.
- **Fix:** `npm update @inertiajs/react @inertiajs/core` (latest 2.x) or `npm audit fix`. Move build-only packages (`vite`, `@vitejs/plugin-react`, `@tailwindcss/vite`, `tailwindcss`, `laravel-vite-plugin`, `concurrently`, `typescript`, `@types/*`, `globals`) to `devDependencies` so `npm audit --omit=dev` shows real runtime risk. Run `composer audit` and `npm audit` in CI.

### M7. Cart quantities can add up beyond stock
- **Where:** `app/DTOs/ShoppingCartDTO.php:39-48` (checks only the new `$quantity` against stock, then `$existingItem->quantity += $quantity`), `ShoppingCartService.php:67`.
- **Failure scenario:** With stock 5, adding 5 twice gives a cart of 10. The cart page and total show 10, and the error only appears at checkout. Combined with H1, quantities are bounded only by stock at checkout time.
- **Fix:** Validate `existing + new <= stock` (and a per-line maximum) in `addItem`. Also re-validate the whole cart against fresh product rows when the cart and checkout pages load (`validateCart()` exists but is never called).

### M8. Checkout stock check is check-then-act. Safe on MySQL/Postgres, not on the default SQLite
- **Where:** `app/Services/CheckoutService.php:47-67`.
- **Failure scenario:**
  - SQLite, the default driver: `lockForUpdate()` does nothing and transactions are `DEFERRED`. Two concurrent checkouts for the last unit both pass `stock < quantity`, and one fails with `SQLITE_BUSY`, which surfaces as M1's leaked SQL message. `stock` is `unsignedInteger`, which SQLite does not enforce, so going negative is possible.
  - MySQL: products are locked in cart order, not ID order, so two carts with the same products in opposite order can deadlock.
- **Fix:** Use an atomic conditional decrement, `Product::whereKey($id)->where('stock','>=',$qty)->decrement('stock',$qty)`, and check the affected row count. Sort items by `product_id` before locking. Wrap in `DB::transaction(..., attempts: 3)`.

---

## Low

| # | Finding | Where | Scenario / fix |
|---|---|---|---|
| L1 | Ziggy exposes every route name and URI to guests | `resources/views/app.blade.php:38` (`@routes`), `HandleInertiaRequests.php:55-58` | Guests see `admin.*`, `_debugbar.*`, `storage.local.upload` and `docs/api` in the page source, which makes discovery easier, though admin access is still enforced. The route list is also sent twice on every full page load (Blade `@routes` and the `ziggy` prop). **Fix:** create `config/ziggy.php` with `'except' => ['admin.*','debugbar.*','telescope*','storage.*','scramble.*']` (or groups by role), and drop one of the two copies. |
| L2 | No throttle on register, confirm-password or forgot-password | `routes/auth.php:17,27,52` | Unlimited account creation, brute force of confirm-password from a hijacked session, and email enumeration by timing. The password broker throttles only per email (60s). **Fix:** `throttle:6,1` on each. |
| L3 | Email verification does nothing | `app/Models/User.php:5` (`MustVerifyEmail` commented out), `routes/web.php:28` | The `verified` middleware lets every user through, and the verify routes exist but are never triggered. **Fix:** implement `MustVerifyEmail`, or remove the routes and middleware to avoid a false sense of security. |
| L4 | Default password rules only | `app/Providers/AppServiceProvider.php:20-23` | `Password::defaults()` is never configured, so passwords only need 8 characters. **Fix:** `Password::defaults(fn () => app()->isProduction() ? Password::min(10)->uncompromised() : Password::min(8))`. |
| L5 | Session and cookie hardening | `config/session.php:50,172` | `SESSION_ENCRYPT=false`, so cart, recent order IDs and PII-adjacent data sit in plaintext in the `sessions` table. `secure` is not forced. **Fix:** set `SESSION_SECURE_COOKIE=true` and `SESSION_ENCRYPT=true` in production, and document them. |
| L6 | The cart stores PHP objects in the session | `ShoppingCartService.php:37,48`, `config/session.php:229` (`serialization => 'php'`) | Any change to a DTO class breaks or corrupts every existing session (unserialize with incompatible properties). It also blocks moving to `serialization => 'json'`, the safer setting, which rules out object injection if the session store is ever writable. **Fix:** store a plain array `[product_id => qty]` (see H2). |
| L7 | Guest order access lasts the whole session | `CheckoutController.php:51-57`, `OrderController.php:14-18` | On a shared computer, the next person in the same session can open `/orders/{id}` (name, phone, address) until logout or expiry. **Fix:** let `recent_order_ids` expire (for example after 1 hour), or use a signed `URL::temporarySignedRoute` for the confirmation page. Add an order history page for logged-in users. |
| L8 | `discount_price` can be higher than `price` | `app/Http/Requests/Admin/StoreProductRequest.php:31` | An admin typo makes the "discount" cost more. **Fix:** `'discount_price' => ['nullable','numeric','min:0','lt:price']`. |
| L9 | Admin `image` accepts any string | `StoreProductRequest.php:35`, `resources/js/pages/shop/show.tsx:57`, `product-card.tsx` | No XSS (React `<img src>`), but images can be hotlinked from any host (tracking, mixed content). **Fix:** `url` plus a scheme or host allow-list, or real uploads to the `public` disk with `image|mimes|max`. |
| L10 | `appearance` cookie written into an inline `<script>` | `resources/views/app.blade.php:2,10` | The cookie is unencrypted (`bootstrap/app.php:17`). Blade escaping stops a breakout, but a `\` can break the script for that user (self-only). **Fix:** whitelist the value in `HandleAppearance` with `in_array($v, ['light','dark','system'])`. |
| L11 | Slug is not regenerated when the title changes; the slug race is unhandled | `app/Traits/HasSlug.php:22-27,36-40` | `generateUniqueSlug` prefers the existing `slug` attribute, so renaming never changes the slug, contrary to the comment. Two concurrent creates with the same title produce a unique-constraint exception. **Fix:** make the intended behaviour explicit, and catch or retry on `UniqueConstraintViolationException`. |
| L12 | `HasTranslations` correctness | `HasTranslations.php:58,62` | Uses `getOriginal()`, so `$p->title = 'X'; $p->title` returns the old value until save. Also `toArray()`/JSON skips translation, so `ShopController.php:41-45` sends raw category titles while product cards send translated ones. Translated slugs can never be resolved by `{product:slug}`. **Fix:** see H4; use `getAttributeFromArray`/dirty values, and make serialization consistent. |
| L13 | Unused or wasted work in shared props | `HandleInertiaRequests.php:41,48` | An `Inspiring` quote is generated on every request. `auth.user` serializes the whole User model (`is_admin`, timestamps) and is not lazy. **Fix:** remove `quote` if it is unused, and share `auth.user` as `fn () => $request->user()?->only('id','name','email','is_admin','email_verified_at')`. |
| L14 | Missing or redundant indexes | migrations | `products.created_at` and `orders.created_at` have no index, but `latest()` sorts on them. On SQLite and Postgres, FKs are not auto-indexed (`order_items.order_id`, `order_items.product_id`, `orders.user_id`, `orders.order_status_id`, pivots). `slug` has both `unique()` and `index()` (redundant). `order_statuses.name` is not unique, but `firstOrCreate(['name'=>'pending'])` relies on it. **Fix:** add the indexes, drop the duplicate, add `unique('name')` (better: a `code` column). |
| L15 | Inertia SSR enabled with no bundle and a hard-coded URL | `config/inertia.php:19-20` | SSR is not actually running (`bootstrap/ssr` is missing, so the gateway skips it). After `build:ssr` without `inertia:start-ssr`, every full page load tries an HTTP call to 127.0.0.1:13714 first. **Fix:** `'enabled' => env('INERTIA_SSR_ENABLED', false)`, `'url' => env('INERTIA_SSR_URL', ...)`. |
| L16 | Bundle: shared chunks could be split further | `vite.config.ts`, `public/build/assets` | `app-*.js` is 325 KB (106 KB gzip), plus a shared 80 KB chunk (`app-logo`, which looks like radix/headless UI and lucide). This is acceptable for an MVP. **Fix:** add `build.rollupOptions.output.manualChunks` for `react`/`react-dom`/`@inertiajs` vs radix/headlessui, and check lucide imports are per-icon. |
| L17 | CI hygiene | `.github/workflows/lint.yml:13-14,32-39`, `tests.yml` | `lint.yml` has `permissions: contents: write`, but the auto-commit step is commented out (more permission than needed). `pint`, `prettier --write` and `eslint --fix` change files instead of failing CI. Actions are pinned by tag, not SHA. There is no `composer audit` or `npm audit` step. **Fix:** `contents: read`, `pint --test`, `npm run format:check`, `eslint` without `--fix`, and add the audit steps. |
| L18 | commonmark advisories | `composer.lock` (league/commonmark 2.10.0) | The app does not render user markdown, so it is not reachable today. **Fix:** `composer update league/commonmark`. |
| L19 | "Bank Transfer" promises emailed instructions that are never sent | `database/seeders/PaymentMethodSeeder.php:28`, `CheckoutService.php` | No order-confirmation mail or notification is sent. **Fix:** dispatch an `OrderPlaced` queued notification after commit (`DB::afterCommit`). |

---

## Technical debt summary
- **Data model:** money as float or decimal strings, no stored order totals, no payment entity, status is a free-text table with a `firstOrCreate('pending')` lookup, and order lines do not snapshot title or SKU.
- **Cart:** a session-serialized PHP object that holds price, title and stock snapshots. It should be `[id => qty]` plus fresh pricing (H2, L6).
- **Translations:** a polymorphic row-per-field design with a `getAttribute` override. This causes N+1 queries (H4), skips serialization (L12) and makes translated slugs unroutable. JSON columns (spatie/laravel-translatable) would be simpler and faster.
- **Tests:** none cover order access control (guest, other user, admin on `/orders/{id}`), checkout failure paths (stock race, inactive product, price change), the admin create/update flows, cancellation restock, or throttling. These belong next to `tests/Feature/Shop/ShopTest.php`.

## Suggested fix order
1. H3 (seeded admin), M2 (environment defaults and debug tooling), and the framework/axios upgrades, all of which are config-only.
2. H1 (throttle checkout and cart, restock on cancel) and M1 (exception leakage).
3. H2 + M7 + M8 + L6 (cart refactor to ids and quantities, price from the DB, atomic decrement).
4. H4 (translations eager-load or redesign) and L14 (indexes).
5. M3/M4/M5 (FK cascades, money in integer cents, stored totals, status transitions), as one migration batch.
