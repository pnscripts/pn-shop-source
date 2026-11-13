# PN Shop — Platform Architecture Proposal (Approval Checkpoint)

Status: **approved 2026-10-01** (D1–D7). Implementation follows §20; see [docs/README.md](../README.md) for what has shipped and the upgrade notes for deviations (customers stay in `users`; existing data is migrated in place instead of a new baseline).
Inputs: [01-discovery-and-gap-analysis.md](01-discovery-and-gap-analysis.md), [version-modernization](../research/version-modernization.md), [security-performance-audit](../research/security-performance-audit.md).

PN Shop stays a **standalone, self-hosted** Laravel product. It has no dependency on pnscripts.com and no Marketplace code inside it. A future Marketplace client is only a possible extension *source* (§7.6).

---

## Decisions that need your approval

These choices shape everything else. Each has a recommendation.

| # | Decision | Recommendation | Main alternative |
|---|----------|----------------|------------------|
| D1 | **Admin UI technology** | **Filament 5 panel at `/admin`** (Livewire, pure PHP, precompiled assets). Plugins add admin resources, pages, widgets and settings in PHP, with **no Node build on the merchant's server**. | Custom Inertia/React admin. It keeps one UI stack, but every plugin with admin UI must ship a prebuilt React bundle against a versioned SDK, and the whole CRUD/table/form framework must be built and maintained by us. |
| D2 | **Code organisation** | Core moves into **modules under `core/` with namespace `PnShop\`** (Catalog, Sales, Cms, …). `app/` is left to the merchant. Core is extracted later into the Composer package `pnscripts/pn-shop-core` so updates arrive via Composer. | Keep everything in `app/`, which makes safe core updates impossible later. |
| D3 | **Database migration path** | **New 1.0 schema baseline**, plus a one-off `pnshop:import-legacy` command that converts starter-kit data (products, categories, orders, users). | Dozens of in-place transforming migrations. Fragile, for an installed base that is effectively zero. |
| D4 | **Staff vs customers** | **Separate tables and guards**: `admin_users` (guard `admin`, Filament) and `customers` (guard `web`, storefront). | One `users` table plus roles. Simpler, but one bug can turn a customer into staff. |
| D5 | **Product model** | **Every product has ≥ 1 variant.** SKU, price, stock and weight live on the variant; simple products have exactly one. | Separate code paths for simple and configurable products. |
| D6 | **Storefront rendering** | Keep **Inertia + React** for the default theme (SSR optional). A theme ships a **prebuilt bundle**, so there's no Node in production. | Blade themes. Easier to override, but loses the React product identity. |
| D7 | **Package / product naming** | Composer `pnscripts/pn-shop` (project) and `pnscripts/pn-shop-core` (later), with app name and docs saying "PN Shop". The repo can keep its URL. | Keep the earlier personal repository name. |

**Why Filament for D1:** an extension ecosystem works when a module is drop-in: upload, install, configure, with no toolchain. With a React admin, every third-party admin screen needs a compiled bundle that matches our React/Inertia versions, and an upgrade of either breaks the ecosystem. Filament 5 (current: 5.9, Laravel 11.28+, Livewire ^4.4) lets a plugin register resources, pages, navigation, widgets and render hooks from a PHP class. Its Builder field maps directly onto our page → section → block model. Its assets are precompiled. The storefront, which customers see and theme authors want to own, stays React. The cost is two UI stacks: Livewire/Blade in admin and React in the storefront.

---

## 1. Current architecture

A Laravel 13 monolith built on the official React starter kit. Inertia renders React pages for both the storefront and the small admin. The domain lives in `app/Models` with two services (session cart, checkout). It has no events, API, roles, settings, CMS or extension points. Details are in [01-discovery-and-gap-analysis.md](01-discovery-and-gap-analysis.md).

## 2. Target architecture

A **modular monolith**: one deployable Laravel app, an explicitly layered core, and extension boundaries that plugins and themes use instead of editing core.

```
┌──────────────────────────────────────────────────────────────────────┐
│ Presentation                                                         │
│  Storefront theme (Inertia+React, prebuilt)   Admin (Filament 5)     │
│  Store API /api/store/v1      Admin API /api/admin/v1    CLI         │
├──────────────────────────────────────────────────────────────────────┤
│ Application layer: Actions (CreateOrder, AddToCart, PublishPage…)    │
│  - used identically by Inertia controllers, API, Filament, CLI       │
│  - emit domain events; run extension pipelines                       │
├──────────────────────────────────────────────────────────────────────┤
│ Core modules (core/<Module>, namespace PnShop\<Module>)              │
│  Foundation · Localization · Money · Settings · Acl · Media          │
│  Catalog · Inventory · Pricing · Customer · Cart · Checkout          │
│  Sales(Orders) · Payment · Shipping · Tax · Promotion                │
│  Cms · Navigation · Seo · Extension · Theme · Api · System           │
├──────────────────────────────────────────────────────────────────────┤
│ Extension kernel: registries, pipelines, events, contracts           │
├──────────────────────────────────────────────────────────────────────┤
│ Extensions (plugins)            Themes                               │
│  extensions/<vendor>/<name>     themes/<vendor>/<name>               │
└──────────────────────────────────────────────────────────────────────┘
```

Each core module owns its own `Models/`, `Actions/`, `Events/`, `Contracts/`, `Http/` (storefront + API controllers), `Filament/` (admin resources), `database/migrations`, `lang/` and a `ModuleServiceProvider`. Modules depend downward only (Sales → Catalog, never the reverse). Cross-module reactions go through events. **Business logic never depends on the presentation layer**, which is what makes headless use possible (§10).

## 3. Current technology versions

| | Current |
|---|---|
| PHP | ^8.3 |
| Laravel | 13.29.0 |
| Inertia | 2.0.25 / react 2.0.4 |
| React | 19.0 |
| TypeScript | 5.8 |
| Vite | 6.2.0 |
| Tailwind | 4.0.10 |
| PHPUnit | 12.5 |
| Routing helper | Ziggy 2.6 |
| Static analysis | none |
| Node (CI) | 22 |

## 4. Recommended versions

From [version-modernization.md](../research/version-modernization.md), verified against official sources 2026-10-01.

| Component | Target | Notes |
|---|---|---|
| PHP | **^8.4** (CI 8.4 + 8.5) | |
| Laravel | **^13.34** | |
| Inertia | **^3.4 (laravel) / ^3.7 (react)** + `@inertiajs/vite` | removes axios (fixes audit item), renamed router events |
| React | **^19.3** | |
| TypeScript | **^5.9.3** | **defer 7.x**: typescript-eslint needs < 6.1; repo uses `baseUrl` removed in 7 |
| Vite | 6.4.3 (security) → **^8.3**, plugin-react ^6.1, laravel-vite-plugin ^3.2 | |
| Tailwind | **^4.3** | |
| Filament | **^5.9** (Livewire ^4.4) | new, per D1 |
| spatie/laravel-permission | current major | roles/permissions |
| laravel/sanctum | current | APIs |
| kalnoy/nestedset | current | category trees |
| brick/money | current | money arithmetic |
| Larastan | **^3.12**, level 5 → 7 | new |
| PHPUnit | **13.3** after PHP 8.4 floor | |
| ESLint | 9.39 → 10 | Vite+/Oxlint deferred |
| Node | **24 LTS** | 26 LTS after 2026-10-28 |
| Ziggy → Wayfinder | **defer** | Wayfinder is still 0.x; 28 files use `route()` |
| Telescope | dev-only / gated | |
| Scramble | keep, but only once the API exists (Phase 10) | |

Upgrade order: CI → in-range security updates → tooling (Larastan, Pint config, Node 24) → PHP 8.4 floor + PHPUnit 13 → Inertia 3 → Vite 8 toolchain → (later) Wayfinder, TS 7. Each step is green before the next, and nothing is left half-upgraded.

## 5. CMS architecture

| Entity | Design |
|---|---|
| **Page** | `pages` (status `draft/scheduled/published/archived`, `published_at`, `unpublished_at`, `template`, `author_id`) + `page_translations` (title, slug, excerpt, meta_title, meta_description, og_image_id). Scheduling is done by a scheduled job flipping status. |
| **Revisions** | `page_revisions` stores a full snapshot (translations + section tree) per save. Restore = new revision. Retention setting. |
| **Sections and blocks** | `page_sections` (page_id, position, layout key, settings) → `page_blocks` (section_id, position, `type`, `data` JSON validated against the block type's schema, per-locale translatable fields). JSON is small and typed per block, not a page-wide blob. The same tree can attach to category/product landing content via a `content_areas` owner (page, category, product, or a theme's global areas like homepage/footer). |
| **Block types** | Registered in `BlockRegistry`: `key`, PHP schema (fields + validation), Filament form (from schema), optional **server data resolver** (e.g. "products" block resolves product IDs → product cards in one batched query per page), React renderer key. Core blocks: text/rich text, image, gallery, video, hero, product grid, category grid, CTA, HTML (permission-gated: `cms.html_block`). Plugins add blocks the same way. |
| **Menus** | `menus` (code: header/footer/…) → `menu_items` (nested set; `type` page/category/product/brand/url/custom; target id or URL; per-locale label; open-in-new-tab). Links resolve to localized URLs at render time; results cached per menu and locale and invalidated on save. |
| **Media** | `media` (disk, path, mime, size, width/height, checksum, folder_id, alt/title translations), `media_folders`, `mediables` pivot (model, collection, position). Upload validation by MIME sniffing + extension allowlist, SVG sanitised or disallowed, randomised stored names, private-disk option. Responsive conversions (WebP/AVIF, sizes from theme settings) generated by queued jobs with `spatie/image`. |
| **SEO** | See §5a. |

No visual drag-and-drop canvas in 1.0. Filament's Builder field gives an ordered, nested section/block editor with live validation. A visual editor can come later on the same data model.

### 5a. SEO

- Meta fields live on translation tables (title, description, OG image, robots).
- `url_rewrites` (locale, path, target type/id, canonical flag) generated on save, with automatic **301 redirects** when slugs change, plus a manual `redirects` table.
- JSON-LD: Product (offers, availability, brand, GTIN), BreadcrumbList, Organization, WebSite/SearchAction, Article for CMS pages.
- Sitemap index per locale (queued, chunked); `robots.txt` from settings; hreflang alternates; canonical URLs; image alt metadata.
- `SeoContributor` pipeline so plugins can add or alter meta and structured data.

## 6. E-commerce architecture

### 6.1 Catalog

- **products**: type (`simple`, `variable`, `digital`, `virtual`, `grouped` later; types are strategy classes in `ProductTypeRegistry`, plugins can add them), status, brand_id, tax_class_id, `product_translations` (name, slug, descriptions, meta).
- **product_variants** (D5): sku (unique), barcode/GTIN, weight/dimensions, `requires_shipping`, `is_default`, position; **option values** via `variant_option_values`.
- **Attributes**, split into what defines a variant and what describes a product:
  - *Variant options* (size, colour): `options` + `option_values` + translations. They define variants.
  - *Specification attributes* (material, wattage): `attributes` (type text/number/boolean/select/multiselect, `is_filterable`, `is_comparable`) + `attribute_values` + `product_attribute_values` (typed columns, not EAV-everything). **Attribute sets** decide which attributes a product edit form shows. This replaces today's category-attribute pivot, and the data is imported.
- **categories**: nested set (`kalnoy/nestedset`), translations, image, SEO, status, position. Products ↔ categories is many-to-many, plus `primary_category_id` for breadcrumbs and canonical URLs.
- **brands**: translations, logo, SEO.
- **Relations**: `product_relations` (type related/upsell/cross-sell, position).
- **Digital/downloadable**: `product_downloads` (media on a private disk, download limit/expiry) → signed, expiring download links after payment.
- **Search and filtering**: SQL-backed filtering on filterable attributes, options, price, brand and stock in 1.0, behind a `ProductSearch` contract so a Scout/Meilisearch plugin can replace it.

### 6.2 Inventory

`stock_locations` (one default) → `stock_levels` (variant × location: on_hand, reserved) → `stock_movements` (ledger: reason, reference, delta, actor). Policies per variant: track stock, allow backorders, low-stock threshold. **Reservations** are created at order placement and released on cancel or expiry, which fixes H1 (cancel restocks) together with throttling.

### 6.3 Money and pricing

- All money is **integer minor units + ISO currency** (`Money` value object over `brick/money`).
- `prices` (variant, currency, customer_group nullable, min_qty, amount, compare_at_amount, starts_at/ends_at). The **`PriceResolver` pipeline** picks the price: base → group → tier → scheduled → plugin stages.
- Store-level setting: catalog prices entered **tax-inclusive or exclusive**; display rules per customer group.
- Currencies: `currencies` (code, precision, symbol, format, exchange rate, `is_default`). Prices are explicit per currency, with fallback to converted base.

### 6.4 Customers

`customers` (D4), `customer_addresses`, `customer_groups` (pricing, tax class, visibility), account area (profile, addresses, **order history**, downloads), email verification, password reset, optional guest-to-account conversion after checkout. GDPR: export and anonymise actions.

### 6.5 Cart

DB-persisted `carts` / `cart_lines` keyed by an opaque cart token (cookie for web, header for the Store API) or customer. Carts merge on login, and abandoned carts are pruned by a scheduled job. **Prices are never stored as truth in the cart.** Every read recomputes through the **`CartCalculator` pipeline**: line prices → promotions → shipping → tax → rounding → totals. Each stage is a registered, ordered class that plugins can add to. Quantity rules (min/max/step, stock) are validated on every mutation. This fixes H2 and M7.

### 6.6 Checkout

A stateless, step-agnostic `Checkout` service over the cart: contact → addresses → shipping method → payment method → review → `PlaceOrder` action.

- `PlaceOrder` runs in a transaction: re-price, lock variants **in id order** (fixes M8 deadlocks), reserve stock, write the order snapshot, then hand off to the gateway.
- **Throttling** on cart, checkout and auth routes, and optional honeypot/captcha hooks (fixes H1).
- Errors map to user-safe messages, with no raw exceptions shown (M1).

### 6.7 Orders

- `orders`: human order **number** (pattern setting), channel/locale/currency, customer or guest email, **stored totals** (subtotal, discount, shipping, tax, grand total; minor units), and three statuses:
  - `status`: pending → processing → completed / cancelled
  - `payment_status`: unpaid → authorized → paid → partially_refunded → refunded / failed
  - `fulfillment_status`: unfulfilled → partially_fulfilled → fulfilled → returned
- Each status is a small **state machine** with allowed transitions in code and an event per transition. `order_history` records actor, from, to and a note (fixes M5).
- `order_lines` snapshot product name, SKU, options, unit price, tax lines and discounts. They reference variants with `nullOnDelete`, never cascade (fixes M3). `order_addresses` stores billing and shipping snapshots.
- `payments` / `payment_transactions`, `shipments` (+ lines, tracking), `refunds` (+ lines, restock flag), `invoices` (sequential numbering per setting, PDF via a contract that can be swapped). **Returns (RMA)** is architecture-only in 1.0: tables plus a contract, with UI later.
- Notifications: order confirmation, status changed, shipped, refunded, account emails. Templates are overridable by themes, and channels are extensible via Laravel notifications.

## 7. Plugin architecture

### 7.1 Package format

A plugin is a Composer-compatible package with a `pnshop.json` manifest:

```json
{
  "id": "acme/advanced-seo",
  "name": "Advanced SEO",
  "version": "1.2.0",
  "type": "plugin",
  "requires": { "pnshop": "^1.0", "php": ">=8.4", "plugins": { "acme/core-utils": "^2.0" } },
  "provider": "Acme\\AdvancedSeo\\AdvancedSeoPlugin",
  "provides": ["seo-contributor", "cms-block"],
  "permissions": ["advanced_seo.manage"],
  "settings": "settings.schema.json",
  "storefront": { "bundle": "dist/storefront/manifest.json" },
  "license": "proprietary"
}
```

They're located in `extensions/<vendor>/<name>` (path repository, so a zip upload works) or installed with `composer require` (package type `pnshop-plugin`). The plugin class extends `PnShop\Extension\Plugin` (a ServiceProvider) and implements the lifecycle hooks.

### 7.2 What a plugin can register

Everything goes through Laravel conventions plus typed registries:

- **Laravel conventions:** routes (storefront, API, webhooks); migrations; config; translations; views and mail templates; commands; scheduled tasks; events/listeners; policies.
- **Admin:** Filament plugin (resources, pages, widgets, navigation groups, settings pages, render hooks).
- **Permissions:** declared in the manifest and merged into the permission registry.
- **Commerce drivers and pipeline stages:**
  - payment gateways, shipping carriers and tax providers;
  - cart calculator stages, price resolver stages and promotion conditions/actions;
  - product types and the search driver.
- **Content:** CMS block types, SEO contributors, menu link types.
- **Storefront:** slots (named UI extension points with serialisable props), plus a prebuilt storefront bundle (§8.4).
- **API:** resources and endpoints under the plugin namespace.

Plugins **do not** override core classes or patch files. Swappable behaviour is exposed as **contracts bound in the container** (e.g. `ProductSearch`, `InvoiceRenderer`, `TaxProvider`).

### 7.3 Extension points (events and hooks)

Domain events are immutable and serialisable, so they can become webhooks later:

- **Catalog:** `ProductCreated/Updated/Deleted`, `CategoryCreated/Updated/Deleted`, `StockLevelChanged`.
- **Customer and cart:** `CustomerRegistered`, `CartUpdated`.
- **Checkout:** `CheckoutStarted`, `CheckoutCompleted`.
- **Orders:** `OrderPlaced`, `OrderPaid`, `OrderStatusChanged`, `OrderCompleted`, `OrderCancelled`, `OrderRefunded`, `ShipmentCreated`.
- **Content:** `PagePublished/Updated/Unpublished`, `MediaUploaded`.
- **Extensions:** `PluginEnabled/Disabled`, `ThemeActivated`.

Mutable extension is done only through **named pipelines** with documented payloads, never through events with side effects on the core flow.

### 7.4 Lifecycle

`Discover → Validate → Install → Enable → Configure → Update → Disable → Uninstall`

| Step | Behaviour |
|---|---|
| Discover | Scan `extensions/*/*/pnshop.json` + Composer packages of type `pnshop-plugin`. |
| Validate | Manifest schema; `requires.pnshop`/PHP/plugin constraints (semver); permission/key collisions; optional signature check. |
| Install | Run plugin migrations (tracked per plugin), seed defaults and settings, publish prebuilt assets to `public/extensions/<id>`. **On failure, roll back this run's migrations, set status `failed` and store the error.** |
| Enable | Add to the cached enabled-plugin manifest (`bootstrap/cache/pnshop-plugins.php`). Only enabled plugins boot. |
| Configure | Settings form generated from the schema; isolated namespace `plugin.<id>.*`. |
| Update | Version compare → maintenance mode → backup hook → `upgrade(from, to)` + new migrations → cache rebuild; same rollback rule. |
| Disable | Removed from the boot manifest; data kept; dependants are blocked or offered for disable. |
| Uninstall | `uninstall(keepData)`. Destructive migrations run only when `keepData=false`. Assets and settings are removed. |

All steps are available as artisan commands (`pnshop:plugin:install|enable|disable|update|uninstall|list`) and in admin. **Safe mode** (`PNSHOP_SAFE_MODE=true` or a CLI flag) boots with all third-party plugins off, so a broken plugin can always be removed. Caches (config, route, view, plugin manifest, permission) are rebuilt after every state change.

### 7.5 Trust model (documented explicitly)

PHP plugins run **with full application privileges**, as in any in-process PHP plugin system. PN Shop cannot sandbox them, and the docs will say so plainly.

Mitigations:
- install permission limited to the `system.extensions.manage` permission;
- zip uploads are **off by default** and can be disabled by config;
- archives are extracted with path-traversal protection (no absolute paths, `..` or symlinks), size limits and a file-type allowlist;
- the manifest is validated before any code is loaded;
- optional **Ed25519 package signatures** against trusted public keys;
- installed-file checksums detect tampering;
- an audit log records every extension action.

Composer resolution never runs inside a web request. A future out-of-process "app" model (webhooks + scoped API tokens, apps running outside the shop) is enabled by the serialisable events but isn't built in 1.0.

### 7.6 Marketplace readiness (not built)

The extension manager talks to an `ExtensionSource` contract: local folder, zip upload, or Composer repository. A future "PN Scripts Marketplace" source (catalogue, license key, download URL, update feed) can be added as a plugin without core changes. **Nothing in core references pnscripts.com.**

## 8. Theme architecture

### 8.1 What a theme is

A theme is an extension of type `theme`. It lives in `themes/<vendor>/<name>` with a `pnshop.json` that declares `parent`, `requires.pnshop`, a settings schema, supported page templates and block renderers.

### 8.2 Page contract

Core storefront controllers render **stable page keys** with **typed, versioned props**: `home`, `catalog/category`, `catalog/product`, `catalog/search`, `catalog/brand`, `cart`, `checkout/*`, `account/*`, `auth/*`, `cms/page`, `errors/{404,403,500,503}`. Props are produced by API-style resources, the same ones used by the Store API. TypeScript types are published as `@pnshop/storefront-types`. A theme implements these pages and may add templates (e.g. alternate product layouts selectable per product).

### 8.3 Theme contents

Layouts, header/footer/navigation components, page components, block renderers, CSS, JS, images, fonts, email template overrides, error pages and settings (colours, fonts, logo, layout toggles). Settings become CSS custom properties and props. They're stored in the namespace `theme.<id>.*`.

### 8.4 Building and loading

**No Node on the merchant's server.**
- Each theme is a **self-contained prebuilt Inertia app**: its own Vite build with React, Inertia and the theme code, plus a `manifest.json`. The storefront root Blade view loads the **active theme's** manifest from `public/themes/<id>/build/`.
- **Inheritance** is resolved at build time: a child theme's Vite config aliases unresolved components to the parent's source (`@parent/...`). Its output is still one complete bundle.
- **Plugin storefront bundles** (blocks, slots) are ESM library builds with `react`, `react-dom` and `@inertiajs/react` as externals. The active theme publishes an **import map** pointing those specifiers at shims that re-export its own copies, so there is one React instance. Plugins register renderers via `window.PnShop.registerBlock/registerSlot` from `@pnshop/storefront-sdk`.
- **SSR** stays optional; plugin components are SSR-safe only when they declare it.

### 8.5 Admin and lifecycle

Admin (Filament) is **not themed** beyond branding. Themes are installed, updated and activated with the same lifecycle as plugins. Activation is per store and channel-ready.

### 8.6 Default theme

`themes/pnscripts/default` gets the current storefront pages as its starting point and is rebuilt to serious commercial quality. It includes:
- homepage composed from blocks;
- mega-menu navigation, breadcrumbs and search;
- category pages with filters, sorting and pagination;
- product pages with gallery, variant picker, specs, related products and JSON-LD;
- cart with a slide-over, multi-step checkout and a customer account area;
- CMS pages and error pages;
- responsive design, dark mode and accessibility (WCAG 2.2 AA target).

## 9. Database changes

This is a new 1.0 baseline (D3). The main table groups are:

- **Foundation:** `channels` (single store in 1.0, schema-ready for multi-store), `languages`, `currencies`, `settings` (namespace, key, value JSON, typed by schema), `activity_log`.
- **Access:** `admin_users`, `customers`, `customer_groups`, `customer_addresses`, spatie permission tables (guard-scoped), `personal_access_tokens`.
- **Media:** `media`, `media_folders`, `media_translations`, `mediables`.
- **Catalog:** `products`, `product_translations`, `product_variants`, `options`, `option_values` (+translations), `variant_option_values`, `attributes`, `attribute_values` (+translations), `attribute_sets`, `product_attribute_values`, `categories` (nested set) + translations, `category_product`, `brands` + translations, `product_relations`, `product_downloads`.
- **Inventory and pricing:** `stock_locations`, `stock_levels`, `stock_movements`, `stock_reservations`, `prices`, `tax_classes`.
- **Sales:**
  - cart and orders: `carts`, `cart_lines`, `orders`, `order_lines`, `order_addresses`, `order_history`;
  - payments: `payment_methods` (gateway code, settings, availability rules), `payments`, `payment_transactions`;
  - fulfilment and refunds: `shipments`, `shipment_lines`, `refunds`, `refund_lines`, `invoices`;
  - promotions: `promotions`, `promotion_conditions`, `promotion_actions`, `coupons`, `coupon_redemptions`.
- **Shipping and tax:** `shipping_zones` (+ countries/regions/postcodes), `shipping_methods` (carrier code, zone, rate rules, settings), `countries`, `regions`, `tax_zones`, `tax_rates` (zone, class, rate, priority, compound).
- **CMS and SEO:** `pages` + translations + revisions, `page_sections`, `page_blocks`, `content_areas`, `menus`, `menu_items` (+translations), `url_rewrites`, `redirects`.
- **Extensions:** `extensions` (id, type, version, status, installed_at, error), `extension_migrations`.

**Rules:**
- Core tables are core-only. Plugins own prefixed tables (`<vendor>_<plugin>_*`) and must not alter core tables; they attach data through their own tables keyed by core IDs.
- JSON appears only in small, schema-validated places: block data, settings values and gateway configuration.
- Polymorphism appears only where the relation is genuinely open-ended: media attachments, URL rewrite targets and menu targets.
- Catalog deletes are soft. Orders keep snapshots and never cascade from catalog.

## 10. API architecture

| API | Base | Auth | Purpose |
|---|---|---|---|
| **Store API** | `/api/store/v1` | public + cart token header; Sanctum customer tokens | headless storefronts, mobile apps: catalog, search, CMS pages/menus, cart, checkout, customer account, orders |
| **Admin API** | `/api/admin/v1` | Sanctum tokens whose **abilities map 1:1 to permissions** | integrations, ERP/CRM sync: full CRUD on catalog, orders, customers, CMS, media, settings; extension and theme listing |

How the APIs are built:
- **Shared logic:** controllers are thin over the same Actions used by Inertia and Filament.
- **Resources:** responses use versioned JSON resources. List endpoints support cursor pagination, filtering, sorting and sparse includes.
- **Errors and idempotency:** errors are problem+json. Mutating checkout and payment calls accept idempotency keys.
- **Webhooks:** incoming webhooks live at `/webhooks/{gateway|carrier}/{code}`, with signature verification done by the driver.
- **Protection:** per-token and per-IP rate limits.
- **Documentation:** OpenAPI is generated by Scramble and published in `docs/api`.

GraphQL isn't in scope. The default React storefront is just one consumer, which is what makes PN Shop headless-capable.

## 11. Admin architecture (Filament 5 panel, guard `admin`)

```
Dashboard        (sales, orders, low stock, recent activity widgets — plugins add widgets)
Content          Pages · Blocks/Content areas · Menus · Media
Catalog          Products · Categories · Brands · Attributes & Options · Attribute sets · Inventory
Sales            Orders · Shipments · Refunds · Invoices · Customers · Customer groups
Marketing        Promotions · Coupons
Store            Payments · Shipping zones & methods · Taxes · Currencies · Languages · Countries
Appearance       Themes · Theme settings
Extensions       Installed · Upload/Install · Updates
System           Admin users · Roles · Permissions · Settings · API tokens · Logs (activity/app) · Cache · System info · Updates
```

Every resource and action is gated by permission. Navigation, global search and widgets are filtered automatically. Settings pages are generated from settings schemas: core, theme and plugin each get their own page.

### Roles and permissions

`spatie/laravel-permission` on the `admin` guard. Permissions use a dotted hierarchy (`catalog.products.view|create|update|delete`, `sales.orders.refund`, `system.extensions.manage`, `cms.html_block`, …) that is registered at boot from core modules and enabled plugins. Nothing is hard-coded for future plugins. Seeded roles: Administrator (all), Manager, Content editor, Catalog manager, Order manager, Customer manager. Custom roles are created in admin. Super-admin bypass is limited to the Administrator role via `Gate::before`. Customers get no roles in 1.0; customer groups handle commerce segmentation.

## 12. Installation strategy

1. **Phase A (1.0):** `composer create-project pnscripts/pn-shop` → `php artisan pnshop:install`, which:
   - checks requirements (PHP extensions, writable dirs, DB);
   - writes `.env` interactively or from flags;
   - runs migrations;
   - creates the first admin, interactively — **no default credentials** (fixes H3);
   - sets store basics: name, country, currency, language, tax-inclusive prices;
   - activates the default theme and optionally seeds demo data;
   - writes an `installed` lock.
2. **Phase B:** web installer at `/install`, active only while there is no lock. It runs the same steps through a wizard, and its routes are removed once the lock exists. It's for hosts without SSH and ships with prebuilt assets.

Demo data becomes an optional "demo store" seeder, never with known credentials.

## 13. Update strategy

- `pnshop:update`:
  1. pre-flight: PHP/extensions, `requires.pnshop` of every enabled extension against the target version, and the list of incompatible extensions, which can be auto-disabled with confirmation;
  2. **backup hook** (DB dump + `.env` + `storage/app`, pluggable driver);
  3. maintenance mode;
  4. `composer update pnscripts/pn-shop-core` (CLI) or a prebuilt release archive;
  5. core migrations, then extension migrations;
  6. cache rebuild and asset publish;
  7. smoke checks;
  8. maintenance off.
- `system_versions` records history. Releases follow semver. Breaking changes to extension contracts happen only in majors, with deprecations announced one minor ahead.
- Extensions and themes have their own version, update path and compatibility constraint (§7.4).

## 14. Security strategy

- **Fix audit findings first (Phase 0):** H1 throttling (stock reservations follow in Phase 5), H2 server-side repricing, H3 no default admin, M1 safe errors, M2 production defaults and dependency advisories, M3 no cascades into orders.
- **Separate staff and customer guards** (D4), permission-gated admin, optional 2FA for staff (Filament MFA), session hardening (secure/HTTP-only/SameSite, regenerate on login), password policy (`Password::defaults()` with uncompromised check in production), and throttles on auth/cart/checkout/API.
- **Uploads:** MIME sniffing, allowlists, no executable extensions, SVG sanitising, randomised names, private disk for downloads, signed expiring URLs.
- **Extensions:** the trust model in §7.5, safe archive extraction, signatures, audit log.
- **Output and content:** React escaping by default. Rich text is sanitised server-side (HTML Purifier-class sanitiser), and the HTML block is permission-gated. CSP headers are configurable per theme.
- **Data:** guarded mass assignment on all models, policies on every admin resource and API endpoint, IDs never trusted for ownership (order access by customer or signed guest token).
- **Supply chain:** `composer audit` and `npm audit` in CI, Dependabot, pinned actions.
- **Ops:** Telescope dev-only, `APP_DEBUG=false` in examples, security headers middleware, and a documented responsible-disclosure policy.

## 15. Testing strategy

- **Backend:** PHPUnit (project convention), with feature tests per module action and HTTP layer (storefront, API, Filament resources via Livewire testing) and unit tests for money, pricing, tax, state machines and the pipelines.
- **Contract test kits:** reusable abstract test cases that plugin authors extend: `PaymentGatewayContractTest`, `ShippingCarrierContractTest`, `TaxProviderContractTest`, `BlockTypeContractTest`.
- **Extension lifecycle tests:** fixture plugins (valid, failing migration, missing dependency, incompatible version) prove install, rollback, enable, disable and uninstall.
- **Theme tests:** the default theme must satisfy the page contract; type-check against `@pnshop/storefront-types`.
- **End-to-end:** browser tests for guest checkout, account checkout, admin order fulfilment and plugin enable → storefront change. Pest 4 browser plugin or Playwright, decided in Phase 5.
- **Quality gates in CI (all failing, read-only):** Pint `--test`, Larastan (level 5 → 7), ESLint, Prettier check, `tsc`, PHPUnit, frontend production build, `composer audit`, `npm audit --omit=dev`. Matrix PHP 8.4/8.5 × SQLite/MySQL/PostgreSQL.
- **Performance tests:** query-count assertions on key pages (home, category, product, cart), so N+1 regressions fail CI.

## 16. Documentation strategy

`docs/` as requested: `architecture/`, `cms/`, `ecommerce/`, `plugins/`, `themes/`, `api/` (generated OpenAPI + guides), `installation/`, `administration/`, `development/`, `deployment/`, `upgrades/`, `troubleshooting/`, `security/` (trust model, disclosure).

- Each implementation phase updates its section in the same commit series.
- Plugin and theme docs ship with a **reference plugin** (`pnscripts/example-plugin`: settings, permission, admin page, CMS block, event listener) and a **payment gateway skeleton**.
- An ADR log (`docs/architecture/adr/`) records decisions D1–D7 and later ones.
- README becomes a short product overview linking to docs. The pnscripts.com product page is **not** touched from this repo.

## 17. Migration strategy

1. Work happens on a long-lived `next` branch (or `1.x`) with small focused commits. `main` keeps the working starter until the 1.0 baseline is stable, and phases merge to `main` when green.
2. Phase 0 (security + CI) and Phase 1 (version upgrade) apply to the **current** code on `main` directly, since they're valuable even if the platform work pauses.
3. The 1.0 schema replaces the starter schema (D3). `pnshop:import-legacy` maps:
   - categories (adjacency list → nested set);
   - products → product + default variant + price in minor units + stock level;
   - attributes → spec attributes;
   - polymorphic translations → translation tables;
   - users → customers, with `is_admin` users becoming admin users that must reset their password;
   - orders/items → orders/lines with computed stored totals.
4. Existing storefront pages move into `themes/pnscripts/default` and are adapted to the page contract instead of being rewritten from zero.
5. The existing React admin pages are removed once the Filament resources cover them (Phase 2–4).

## 18. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Scope is very large (comparable platforms took years) | Delays, half-finished modules | Phased delivery; each phase shippable; 1.0 scope frozen at the list in §20; explicit "later" list |
| Two UI stacks (Filament admin + React storefront) | Skill split, two design systems | Clear boundary: admin = Filament only, storefront = theme only; shared Tailwind tokens for branding |
| Prebuilt theme/plugin bundles + import maps | Version skew between theme React and plugin bundles | SDK declares supported React/Inertia ranges in manifest; validator refuses incompatible bundles; plugin storefront code optional |
| Inertia 3 / Vite 8 / Filament 5 churn | Breakage during build-out | Upgrade first (Phase 1), pin minors, Dependabot with CI gates |
| Full-trust PHP plugins | Malicious/buggy plugin compromises store | Trust model docs, signatures, safe mode, uploads off by default, audit log |
| Data model too ambitious (multi-location stock, price lists) | Complexity without demand | Schema-ready but UI minimal in 1.0 (single location, single channel) |
| Performance regressions from pipelines/registries | Slow storefront | Query-count tests, cached registries/manifests, batched block resolvers, Octane-safe services (no static request state) |
| SQLite default vs production DBs | Locking/behaviour differences | CI matrix MySQL/PostgreSQL; docs recommend MySQL/PostgreSQL for production |
| Product page copy on pnscripts.com becomes outdated | Mis-selling | Out of scope here; I'll list required copy changes at each release for you to apply in the pnscripts.com repo |

## 19. Breaking changes

For anyone running the current starter:
- New schema (D3) with a legacy import command, not in-place migrations.
- `users` splits into `customers` + `admin_users`, and admin URLs and auth move to Filament `/admin`.
- Session cart contents are dropped on upgrade, and money becomes minor units in all props and APIs.
- Storefront pages move into the theme, with page component names changing to the page contract.
- Ziggy route names for admin are removed; storefront route names are kept where possible.
- Composer package renamed (D7); PHP floor 8.4; Node 24 for development.
- The seeded `test@example.com` admin is gone.

## 20. Implementation phases

Each phase follows your loop: objective → implement → tests → Larastan → lint/format → build → verify in browser → review → fix → docs → report. Commits use conventional messages (`feat:`, `fix:`, `test:`, `docs:`, `chore:`), with no secrets, `.env`, caches or local config.

| Phase | Scope | Exit criteria |
|---|---|---|
| **0. Safety and CI baseline** (on `main`) | Read-only failing CI; fix H1 (throttles), H2 (reprice at checkout), H3 (no default admin), M1, M2, M3; Larastan level 5 baseline; `pint.json` | CI green and actually gating; audit Highs closed |
| **1. Version upgrade** (on `main`) | In-range security updates → PHP 8.4 floor, PHPUnit 13 → Inertia 3 → Vite 8 toolchain, Tailwind 4.3, React 19.3, ESLint 9.39, Node 24 | Tests, types, build, SSR build green; manual smoke of the full shop flow |
| **2. Core foundation** | `core/` module layout + autoload; extension kernel (registries, pipelines, event conventions); settings system; staff/customer split + spatie permission; Filament 5 panel shell with dashboard, users, roles, settings; activity log | Admin login with roles; permission registry tested; settings typed and cached |
| **3. Localization and money** | Languages, currencies, countries/regions; translation-table pattern + trait (eager, no N+1); Money VO; localized URL strategy (prefix, default language unprefixed); UI string extraction (PHP lang + React i18n) | Query-count tests on catalog pages; BG + EN running |
| **4. Catalog** | Products/variants/options/attributes/attribute sets, categories (nested set), brands, relations, media library + conversions, inventory (locations, levels, movements), prices; Filament resources; legacy import | Admin can build a variable product with gallery; import from starter works |
| **5. Customers, cart, checkout** | Customer accounts, addresses, groups, order history; DB cart + `CartCalculator`; checkout service; reservations; throttles/captcha hook | Guest + account checkout E2E green; repricing and stock tests |
| **6. Orders, payments, shipping, tax** | State machines, history, shipments, refunds, invoices; `PaymentGateway` + manager + COD/bank transfer drivers; `ShippingCarrier` + flat/free/pickup/weight/price drivers + zones; tax classes/zones/rates + `TaxProvider`; notifications | Contract test kits pass for built-in drivers; order lifecycle E2E |
| **7. CMS and SEO** | Pages, revisions, scheduling, sections/blocks + block registry, content areas, menus, URL rewrites/redirects, meta, JSON-LD, sitemap, robots | Homepage composed from blocks; sitemap valid; SEO tests |
| **8. Extension system** | Manifest, discovery, validation, lifecycle commands + admin UI, per-plugin migrations, rollback, safe mode, zip upload (off by default) with safe extraction, signatures (optional), reference plugin + Stripe gateway as first official plugin | Lifecycle fixture tests incl. failure rollback; Stripe test mode works end-to-end on localhost |
| **9. Theme system and default theme** | Page contract + `@pnshop/storefront-types`; prebuilt theme loading; inheritance; import-map plugin bundles + `@pnshop/storefront-sdk`; theme settings; full default theme | Default theme meets contract; child theme override demo; plugin block renders in storefront |
| **10. APIs** | Store API + Admin API, Sanctum, abilities = permissions, OpenAPI via Scramble, rate limits, idempotency | API test suites; generated docs |
| **11. Promotions and returns** | Rule engine (conditions/actions registry), coupons, cart rules; returns (RMA) UI | Promotion pipeline tests |
| **12. Installer and updater** | `pnshop:install`, web installer, `pnshop:update`, backups hook, compatibility checks | Fresh install from zero on MySQL + SQLite; update dry-run |
| **13. Hardening and 1.0 release** | Security review, performance pass (query counts, caching, bundle size), docs completion, release notes, list of product-page copy changes for pnscripts.com | Tagged `v1.0.0` |

**Explicitly later (post-1.0):** multi-store/channels UI, multi-location inventory UI, B2B price lists UI, visual page-builder canvas, GraphQL, out-of-process "apps", Marketplace extension source, Wayfinder, TypeScript 7, and full RMA automation.

---

**Approval requested.** Please confirm D1–D7 (or change any), the phase order, and whether Phase 0 + Phase 1 may start immediately on `main` while the rest goes to a `next` branch.
