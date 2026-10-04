# APIs

PN Shop has two JSON APIs:

| API | Base | Who | For |
|---|---|---|---|
| **Store API** | `/api/store/v1` | anyone; customers with a token | headless storefronts, mobile apps: catalog, pages, menus, cart, checkout, customer accounts |
| **Admin API** | `/api/admin/v1` | staff tokens | integrations (ERP, accounting, PIM): catalog, stock, orders, customers, pages, settings |

The OpenAPI documents are [store-v1.json](store-v1.json) and [admin-v1.json](admin-v1.json). In a local environment they can also be browsed at `/docs/api/store` and `/docs/api/admin`. Regenerate them with `composer api-docs` after changing an endpoint; this is a development task that needs a migrated database.

The default React storefront does not use these APIs. It shares the same services (cart, checkout, product listing and presenters), so both always behave the same.

## Common rules

- **Authentication:** bearer tokens: `Authorization: Bearer <token>`. No cookies and no session, so there is no CSRF.
  - Tokens look like `<id>|pnshop_<secret>`. The prefix lets secret scanners spot leaked tokens.
  - A customer token works only on the Store API, and a staff token only on the Admin API.
- **Language:** set it with `?locale=bg` or `Accept-Language`, using one of the store's languages; otherwise the store default is used. The response says which language was used in `Content-Language`.
- **Money:** amounts are returned as `{"amount": "19.90", "minor": 1990, "currency": "EUR", "formatted": "€19.90"}`. Admin API input takes decimal amounts in the store currency (`"price": "19.90"`).
- **Channels:** each request is served by the [channel](../ecommerce/channels.md) of its domain or path, also on the APIs: the Store API shows that channel's catalog, in its currency and languages. Admin API orders carry their `channel` (filter with `filter[channel]=<code>`), and products their `channel_ids`.
- **Prices:** Store API product prices are those of the token's customer (their group's price lists and tax display), or a guest's without a token. `price_includes_tax` says whether they include tax, and prices are `null` for guests when the shop shows prices to signed-in customers only. See [Pricing](../ecommerce/pricing.md).
- **Dates:** ISO 8601.
- **Lists:** cursor-paginated.
  - The response is `{data, links: {next, prev}, meta: {per_page, next_cursor, prev_cursor}}`.
  - Use `?per_page=` (1 to 100, default 20) and follow `links.next`.
  - Where a list supports sorting, `?sort=id` sorts ascending and `?sort=-id` descending.
- **Errors:** errors use [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details (`Content-Type: application/problem+json`):

  ```json
  {"type": "about:blank", "title": "Unprocessable Content", "status": 422, "detail": "…", "code": "validation_failed", "errors": {"email": ["…"]}}
  ```

  Branch on `code`. Its values are `validation_failed`, `cart_rejected` (including unknown coupon codes), `checkout_rejected`, `unauthenticated`, `forbidden`, `not_found`, `too_many_requests`, `idempotency_in_progress`, `idempotency_key_reused`, `invalid_idempotency_key`, `bad_request` and `server_error`.
- **Rate limits:** each token (or IP address without a token) gets 120 requests a minute on the Store API and 300 on the Admin API.
  - Change these with `PNSHOP_STORE_API_RATE_LIMIT` and `PNSHOP_ADMIN_API_RATE_LIMIT`.
  - Cart changes, checkout and sign-in also have the storefront's stricter limits.
  - When a limit is hit the response is `429`, with a `Retry-After` header.
- **CORS:** browsers may call the APIs from any origin, which is safe because authentication is by header, not cookie. To allow only your own front ends, publish the settings with `php artisan config:publish cors` and set `allowed_origins`.
- **Bot protection:** the storefront's honeypot does not apply to API checkouts, which are protected by rate limits (per IP and per email address). Put a bot check in your front end if needed.
- **Idempotency:** placing an order and the Admin API's order actions accept an `Idempotency-Key` header, such as a UUID.
  - A retry with the same key and body within 24 hours returns the first response, marked with `Idempotency-Replayed: true`.
  - While the first request is still running, a retry gets `409`.
  - Reusing a key for a different body gets `422`.

## Store API

| Endpoint | |
|---|---|
| `GET /store` | Name, contact details, languages, currency, countries |
| `GET /products` | Product cards. Filters: `category`, `brand`, `filter[<attribute id>][]`, `q`; sort `-id` or `id` |
| `GET /products/{slug}` | Product page data: variants, options, gallery, breadcrumbs, related products |
| `GET /categories`, `GET /brands` | Category tree and brands |
| `GET /pages`, `GET /pages/{slug}`, `GET /menus/{code}` | Published pages with their blocks, and menus |
| `GET /cart`, `POST /cart/items`, `PATCH`/`DELETE /cart/items/{variant}` | The cart |
| `POST /cart/coupon`, `DELETE /cart/coupon` | Enter or remove a coupon code (`data.coupon` tells whether it applies) |
| `POST /cart/gift-cards`, `DELETE /cart/gift-cards/{id}`, `PUT /cart/store-credit` | Pay with gift cards and the customer's store credit (`gift_cards`, `store_credit`, `amount_due`); checkout then needs no `payment_method_id` when they pay everything |
| `GET /checkout/payment-methods`, `POST /checkout/quote`, `POST /checkout` | Payment methods, shipping options and totals, placing the order |
| `POST /auth/register`, `POST /auth/login`, `POST /auth/logout` | Customer tokens |
| `GET`/`PATCH /account`, `GET /account/orders`, `/account/addresses` (CRUD) | The signed-in customer |
| `GET /orders/{id}` | An order, for its customer or through the signed `links.order` URL, with `returns` and `returnable` |
| `POST /orders/{id}/returns` | Request a return of shipped items |

### A guest checkout

```bash
# 1. Add to the cart. The first change creates a guest cart; keep data.token.
curl -s -X POST https://shop.example/api/store/v1/cart/items \
  -H 'Content-Type: application/json' -d '{"product_id": 12, "quantity": 1}'

# 2. Choose a payment method (and a shipping option via POST /checkout/quote).
curl -s https://shop.example/api/store/v1/checkout/payment-methods -H 'X-Cart-Token: <token>'

# 3. Place the order. The Idempotency-Key makes a retry after a timeout safe.
curl -s -X POST https://shop.example/api/store/v1/checkout \
  -H 'Content-Type: application/json' -H 'X-Cart-Token: <token>' -H 'Idempotency-Key: 6f1c…' \
  -d '{"email": "jane@example.com", "payment_method_id": 1, "billing_same_as_shipping": true,
       "shipping": {"first_name": "Jane", "last_name": "Doe", "line1": "1 Main St", "city": "Sofia",
                    "postcode": "1000", "country_code": "BG", "phone": "0888123456"}}'
```

The response holds the order (`data`), the payment outcome and a signed `links.order` URL for reading the order later.

- **Payment redirect:** when `payment.outcome` is `redirect`, send the customer to `payment.redirect_url`, which is a hosted payment page such as Stripe Checkout.
- **Customers:** a customer sends their token instead of `X-Cart-Token`.
- **Signing in:** signing in with `X-Cart-Token` merges the guest cart into the customer's cart.

Customer tokens are valid for 90 days. `POST /auth/logout` revokes the token in use.

## Admin API

### Tokens

Staff create tokens under **System → API tokens** in the admin, or on the command line:

```bash
php artisan pnshop:api-token ops@example.com --name="ERP" --ability=catalog.products.view --ability=catalog.products.update --ability=catalog.inventory.manage --days=365
```

- **Abilities:** a token's abilities are **permission keys**, the same ones that roles use.
- **Owner's permissions:** a token can only carry permissions its owner holds, and every call also checks the owner's *current* permissions. Taking a role away from an admin therefore narrows their tokens too.
- **All abilities:** `*` (`--all`, or "All of my permissions" in the admin) means everything the owner may do.
- **Shown once:** the token is shown only when it is created. Creating and revoking tokens is recorded in the activity log.
- **Expiry:** a token stops working when it expires, is revoked, or its owner is deactivated.

### Endpoints

| Endpoint | Permission |
|---|---|
| `GET /me` | the token's owner and abilities |
| `GET/POST /products`, `GET/PATCH/DELETE /products/{id}` | `catalog.products.view` / `.create` / `.update` / `.delete` |
| `GET /variants?filter[sku]=`, `POST /products/{id}/variants`, `PATCH/DELETE /variants/{id}` | `catalog.products.view` / `.update` |
| `POST /variants/{id}/stock` (`on_hand` or `adjust`, optional `location`), `POST /variants/{id}/stock/transfers` | `catalog.inventory.manage` |
| `/stock-locations` (CRUD) | `catalog.inventory.manage` |
| `/categories`, `/brands` (CRUD) | `catalog.categories.manage`, `catalog.brands.manage` |
| `POST /media` (multipart `file`) | `content.media.manage` |
| `GET /orders`, `GET /orders/{id}` | `sales.orders.view` |
| `POST /orders/{id}/transitions`, `/notes`, `/shipments`, `/refunds` | `sales.orders.update` |
| `GET /customers`, `GET /customers/{id}`, `PATCH /customers/{id}` | `customers.view` / `customers.manage` |
| `/pages` (CRUD, blocks per language) | `content.pages.manage` (+ `cms.html_block` for HTML blocks) |
| `GET /returns`, `GET /returns/{id}`, `POST /returns/{id}/transitions` | `sales.returns.manage` |
| `/price-lists` (CRUD, `currency` on create), `GET/PUT /price-lists/{id}/prices` (by SKU, up to 1000 rows) | `catalog.prices.manage` |
| `/promotions` (CRUD), `POST /promotions/{id}/coupons` (generate codes) | `marketing.promotions.manage` |
| `/gift-cards` (issue, list, lookup, update, adjustments), `/customers/{id}/credit` | `sales.credit.manage` |
| `POST /returns/{id}/exchange`, `POST /returns/{id}/label` | `sales.returns.manage` |
| `GET /settings`, `GET/PATCH /settings/{namespace}` | `system.settings.manage` |
| `GET /extensions`, `GET /themes` | `system.extensions.manage`, `appearance.themes.manage` |

The Admin API goes through the same rules as the admin panel:

- **Order states:** changes go through the order workflow, so only allowed transitions are accepted, stock moves, history is written and emails are sent. Refunds and shipping are not state changes: `POST /orders/{id}/refunds` (`items`, optional `extra`, `restock`, `reason`) and `POST /orders/{id}/shipments` (`items`, `tracking_number`, `note`, optional stock `location`) record them (refunds take `to`: `original` or `store_credit`), and a transition to *refunded*, *shipped* or *returned* is refused (422).
- **Stock:** changes are recorded in the stock history with the staff member who made them.
- **Settings:** values are validated by their definitions, and secret values are never returned.
- **Pages:** every save records a revision, and staff without `cms.html_block` cannot add or change HTML blocks.

Two things cannot be done through the API: installing or enabling extensions (this runs third-party code), and managing staff and roles. Both stay in the admin panel and the CLI.

### Syncing

List endpoints accept `filter[updated_since]=<ISO 8601>` for incremental syncs. A typical ERP job:

1. Read `GET /variants?filter[sku]=ABC-1`.
2. Change the price with `PATCH /variants/{id}` `{"price": "18.50"}`.
3. Send a stock count with `POST /variants/{id}/stock` `{"on_hand": 40}`.
4. Collect new work with `GET /orders?filter[updated_since]=…&filter[status]=processing`.
