# PN Shop — Discovery and Gap Analysis

Status: Phase 1–3 output, 2026-10-01. Read-only audit; no application code was changed.
Companion documents: [02-platform-architecture-proposal.md](02-platform-architecture-proposal.md) · [research/version-modernization.md](../research/version-modernization.md) · [research/security-performance-audit.md](../research/security-performance-audit.md)

## 1. What PN Shop is today

The repository `pnscripts/pn-shop-source` (formerly `pnscripts/laravel-react-shop`) (product page `pnscripts.com/products/pn-shop`) is the official Laravel React starter kit with a working shop MVP added on top. About 11k lines including the shadcn UI kit; 21 commits; last change upgraded Laravel 12 → 13.

### 1.1 Technology (locked versions)

| Area | Current | Notes |
|------|---------|-------|
| PHP | `^8.3` (CI 8.4; local 8.4.25) | |
| Laravel | 13.29.0 | low-severity advisory fixed in 13.30+ |
| Inertia | laravel 2.0.25 / react 2.0.4 | Inertia 3 is stable (3.4 / 3.7) |
| React | 19.0 | |
| TypeScript | 5.8 | TS 7 (Go port) is `latest` but tooling not ready |
| Vite | 6.2.0 | vulnerable; Vite 8 is current |
| Tailwind | 4.0.10 | 4.3.x current |
| Routing helper | Ziggy 2.6 | official starter moved to Wayfinder |
| Tests | PHPUnit 12.5, 68 tests / 218 assertions, all green | |
| Static analysis | none | |
| Lint/format | Pint, ESLint 9, Prettier — CI runs them in *fix* mode, never fails | |
| CI | `tests.yml`, `lint.yml` on `main` + non-existent `develop` | |
| Database | SQLite default | |
| Extras | Telescope (prod dep, off by default), Scramble (no API routes), Debugbar (dev) | |

### 1.2 Structure

```
app/
  DTOs/            CartItemDTO, ShoppingCartDTO (session-serialised cart)
  Http/Controllers Home, Shop, Cart, Checkout, Order, Admin/{Product,Order}, Auth/*, Settings/*
  Http/Middleware  EnsureUserIsAdmin (is_admin flag), HandleInertiaRequests, HandleAppearance
  Http/Requests    Admin/*, Cart/*, Checkout/*, Auth/*, Settings/*
  Models           Product, ProductCategory, ProductAttribute, ProductAttributeValue,
                   Order, OrderItem, OrderStatus, PaymentMethod, Translation, User
  Services         ShoppingCartService (session), CheckoutService (transaction + stock decrement)
  Traits           HasSlug, HasSortOrder, HasTranslations (polymorphic per-field)
resources/js/      pages/{home,shop,cart,checkout,orders,admin/*,auth/*,settings/*}, layouts, shadcn ui
routes/            web.php (storefront + /admin), auth.php, settings.php — no api.php
```

There are no events, listeners, jobs, policies, commands, API routes, mailables or notifications beyond the starter-kit auth ones.

### 1.3 Domain model today

- **Catalog:** `products` (single category FK, `price`/`discount_price` decimal, `stock` int, `image` string URL, `sku`, `barcode`), `product_categories` (adjacency list `parent_id`, `sort_order`), `product_attributes` + `product_attribute_values`, linked to categories and products through pivots. No variants, brands, galleries or media.
- **Translations:** one polymorphic `translations` table (`translatable_type/id`, `field`, `locale`, `value`). `HasTranslations::getAttribute` runs **one query per translatable field read**.
- **Cart:** session object holding product snapshots (price, title, stock), so it can't persist across devices and its prices go stale.
- **Orders:** `orders` (contact + a single free-text `address`, FK status/payment method), `order_items` (price at time of order). There's no order number, no stored totals, no tax or shipping, and no history.
- **Payments:** `payment_methods` rows (COD, bank transfer); `type` is informational only.
- **Users:** `users.is_admin` boolean; customers and staff are the same table with no roles.

### 1.4 What works and should be preserved

- The storefront flow works end-to-end: catalog → product → cart → guest/auth checkout → order page. 68 tests pass.
- Checkout already runs in a DB transaction with `lockForUpdate` and re-validates stock and active status.
- Order view access is sound: owner, same-session recent order, or admin.
- Form Requests are used consistently; there is no raw SQL, no `dangerouslySetInnerHTML` and no CSRF exemptions.
- The Inertia + React + TypeScript + Tailwind + shadcn foundation is current-generation, and pages are already code-split.
- Auth, settings and appearance from the starter kit work.

## 2. Security and performance findings (summary)

Full list with file:line and fixes: [security-performance-audit.md](../research/security-performance-audit.md). Nothing Critical.

| ID | Severity | Finding |
|----|----------|---------|
| H1 | High | Unthrottled guest cart and checkout with no payment step decrement stock immediately, so a script can drain all inventory. Cancelling doesn't restock. |
| H2 | High | Checkout writes the session-cached price/discount, so expired sales and corrected prices are still honoured. |
| H3 | High | Seeder creates a known admin `test@example.com` / `password`, documented in README. |
| H4 | High (perf) | Translation N+1: `/` = 34 queries, `/shop` = 52 queries. |
| M1 | Medium | Raw exception text (possibly SQL) flashed to customers. |
| M2 | Medium | `.env.example` has debug on; Telescope/Scramble are production deps; framework advisory. |
| M3 | Medium | Deleting a product or category cascades into `order_items`, destroying order history. |
| M4 | Medium | Money is float/decimal, mixed. Order totals are never stored. |
| M5 | Medium | Order status is a free FK with no transition rules. |
| M6–M8 | Medium | Outdated axios via Inertia 2, cart quantity can exceed stock via repeat adds, lock ordering/SQLite check-then-act. |
| deps | — | `composer audit`: 3 advisories. `npm audit`: 22 (2 critical, mostly build-time). All fixable within current ranges. |

## 3. Gap analysis against the target platform

Legend: **Have**: usable as is. **Partial**: exists but needs redesign. **Missing**: build new.

### 3.1 Content (CMS)

| Capability | State | Notes |
|---|---|---|
| Pages (draft/publish/schedule/revisions) | Missing | |
| Sections/blocks, block registry | Missing | |
| Menus (nested, linkable) | Missing | storefront header is hard-coded |
| Media library (uploads, folders, conversions, alt text) | Missing | `products.image` is a free-text URL |
| SEO (meta, canonical, OG, JSON-LD, sitemap, redirects) | Missing | `robots.txt` is static |

### 3.2 Commerce

| Capability | State | Notes |
|---|---|---|
| Products | Partial | single table; no types, no variants, float money |
| Categories | Partial | adjacency list exists; no admin UI, no nested queries, no SEO |
| Attributes | Partial | schema exists (category-scoped); no admin, no filtering, no variant axes |
| Variants / SKUs | Missing | |
| Brands | Missing | |
| Inventory | Partial | single `stock` int; no ledger, reservations or locations |
| Pricing | Partial | `discount_price` only; no currencies, groups, schedules |
| Discounts / coupons | Missing | |
| Customers (addresses, groups, history) | Missing | the README itself lists order history as missing |
| Cart | Partial | session only; needs DB cart, server-side totals pipeline |
| Checkout | Partial | works; no shipping, tax, payment step, address model |
| Orders | Partial | no number, totals, history, state machines, invoices, refunds |
| Payments | Missing (architecture) | no gateway contract; 2 manual rows |
| Shipping | Missing | |
| Taxes | Missing | |
| Returns / refunds | Missing | |

### 3.3 System

| Capability | State | Notes |
|---|---|---|
| Users / staff vs customers | Partial | one table, `is_admin` flag |
| Roles & permissions | Missing | |
| Settings system | Missing | only `config/*.php` + `.env` |
| Localization (languages, translated content, localized URLs) | Partial | translation table exists, but N+1 and no admin, no URL locales, UI strings hard-coded English |
| Currencies | Missing | |
| Extensions (plugins) | Missing | |
| Themes | Missing | storefront and admin share one bundle and layout set |
| Events / hooks | Missing | no domain events at all |
| REST API | Missing | Scramble installed with nothing to document |
| Installer | Missing | `composer create-project` + `migrate --seed` |
| Update system | Missing | |
| Logs / cache / system info UI | Missing | Telescope only |
| Mail (order confirmation etc.) | Missing | |
| Queues / scheduler usage | Missing | queue configured, unused |
| Static analysis, strict CI | Missing | |
| Documentation | Partial | README only |

### 3.4 Keep, improve, replace, extract

| Item | Decision | Why |
|---|---|---|
| Laravel 13, React 19, TS, Tailwind 4, shadcn | **Keep** | current-generation; upgrade minors |
| Inertia | **Keep → upgrade to v3** | the storefront remains Inertia-rendered by default |
| Storefront pages (home/shop/product/cart/checkout/order) | **Improve → move into default theme** | they become the `default` theme's page components |
| Auth controllers (starter kit) | **Keep for customers**, add staff guard/permissions | |
| `ShoppingCartService` session cart | **Replace** with DB cart + pricing pipeline | fixes H2, M7; enables API carts |
| `CheckoutService` | **Improve** into action classes + state machines | keep the transaction/lock approach |
| `HasTranslations` polymorphic per-field | **Replace** with per-entity translation tables | fixes H4; enables localized slugs/SEO |
| `HasSlug`, `HasSortOrder` | **Improve** (slugs move to translation tables / URL rewrites) | |
| `is_admin` + `EnsureUserIsAdmin` | **Replace** with roles/permissions | |
| Admin React pages (products, orders) | **Replace** with the admin framework chosen in the proposal | |
| `payment_methods.type` | **Replace** by gateway driver codes | |
| Telescope, Scramble | **Move to dev / remove** until the API exists | |
| Domain code in `app/` | **Extract** into core modules (`PnShop\…`) | so core is updatable separately from the merchant's app |

## 4. Technical debt summary

1. Money as float/decimal mixtures, with no currency.
2. Polymorphic translations with per-attribute queries.
3. Cart holds price snapshots in the session.
4. No order totals, number, addresses model or status rules.
5. Cascading deletes from catalog into order history.
6. Admin authorization is a boolean.
7. No domain events and no extension seams, so everything would need core edits.
8. CI doesn't fail on lint, and there's no static analysis.
9. Hard-coded English strings in PHP and TSX.
10. A known demo admin credential.
11. The old Composer package name (personal vendor, `laravel-react-shop`) doesn't match the product or repo name.
