# Customers, cart and checkout

Customer code lives in `PnShop\Customer`, the cart in `PnShop\Cart` (`ShoppingCartService`), checkout in `PnShop\Sales\Checkout` and form protection in `PnShop\Security`.

## Customers

- Customer accounts are the `users` table, separate from staff accounts (`admin_users`, see [staff and roles](../administration/staff-and-roles.md)).
- **Customer groups** (Admin → Customers → Customer groups) segment customers. New accounts join the default group, *Retail*. Groups can have their own prices (price lists), see prices with or without tax, have a minimum order and their own payment methods: see [Pricing and business customers](pricing.md).
- **Address books:** customers keep several addresses under *Account → Addresses* and choose a default for shipping and one for billing. Staff see them on the customer's page in the admin.
- **The account area** (`/dashboard`, `/account/orders`, `/account/addresses`) lists the customer's orders and addresses.
- **Email verification** is off by default. With *Settings → Customers → Require a verified email address*, new customers get a verification link, and unverified accounts are sent to the "verify your email" page instead of the account area and cannot order through the Store API (`403 email_not_verified`). The link in the email works without signing in on the website, so customers of a headless storefront can use it too. Store API apps read `email_verified` from `GET /account` and send the link again with `POST /account/email/verification-notification`.
- **Passwords:** a new or reset password signs the customer out everywhere else and revokes their Store API tokens.

## Cart

| Table | Purpose |
|---|---|
| `carts` | One per guest (random `token`) or per customer (`user_id`). |
| `cart_lines` | Variant id and quantity only. Prices, titles and stock are always read from the catalog. |

- A guest's cart is found through the session and a 30-day, HTTP-only cookie (`pnshop_cart`). Browsing never creates a cart; the first *Add to cart* does.
- A signed-in customer's cart belongs to the account, so it is the same on every device.
- On sign-in, the guest cart is merged into the customer's cart. Quantities of the same variant add up.
- `php artisan pnshop:carts:prune` runs daily from the scheduler. It deletes guest carts unchanged for 30 days and customer carts unchanged for 180 days (`--guest-days`, `--customer-days`).

### Totals pipeline

`PnShop\Cart\Totals\CartCalculator` adds up the lines into a **subtotal**, then runs the `cart.totals` pipeline. Each stage receives a `CartTotals` and may add `TotalLine`s:

```php
use PnShop\Cart\Totals\{CartCalculator, CartTotals, TotalLine};

app(PipelineRegistry::class)->stage(CartCalculator::PIPELINE, function (CartTotals $totals, Closure $next) {
    $totals->add(new TotalLine('shipping', __('Courier'), Money::of('5.00', $totals->currency())));

    return $next($totals);
}, priority: 200);
```

- **Line amounts:** negative for discounts. Set `included: true` for amounts already inside the prices (VAT in gross prices); those are shown but not added.
- **Total:** the subtotal plus every non-included line, never below zero.
- **Built-in priorities:** promotions 100, shipping 200, free-shipping promotions 250, fees 300, tax 400.
- **Discounts per line:** a stage that discounts a line records it with `$totals->discount('item:<variant id>' | 'shipping', $amount)` and adds the negative `TotalLine`. Tax uses `discountOn()` to tax what is actually paid. See [promotions](../marketing/promotions.md).
- **Context:** the context always holds `coupon_code` and `user` (the cart fills them in), and at checkout also the addresses, `shipping_method` and `email`.

Cart, checkout, the order page and the admin all show the same breakdown. The order stores it (`orders.subtotal`, `total`, `totals`), so later rule changes never alter a placed order.

## Checkout

1. **Contact email and shipping address:**
   - the address is entered with the address fields;
   - signed-in customers can pick a saved address instead;
   - the phone number is required for couriers.
2. **Billing address:** the same as shipping unless the customer unticks the box.
3. **Payment method:** manual methods for now (Phase 6 adds gateways).

When the order is placed:

- the variant rows are locked;
- stock is reserved (see below);
- lines are priced from the database;
- the addresses are copied into `order_addresses`, so editing an address book never changes an order;
- totals are calculated and stored.

Signed-in customers can save a new address to their address book. It is not saved twice.

## Stock reservations

| Order state | Stock |
|---|---|
| Placed | **Reserved**: still on hand, no longer available |
| Fulfillment *Shipped* | **Fulfilled**: taken off hand, with an `order_fulfilled` movement |
| Status *Cancelled* | **Released**: a reservation is dropped; shipped stock is put back (`order_cancelled` movement) |

- Reopening a cancelled order reserves the stock again. It fails when the stock is gone.
- See [orders](orders.md) for the state machines that drive these changes.
- The admin's *Stock on hand* field shows the shelf quantity, including reserved units, so saving a product never loses reservations.

## Spam protection

The `bot-trap` route middleware (`PnShop\Security`) protects checkout and registration:

- **Honeypot:** a hidden `contact_website` field that people never see and bots fill in.
- **Time trap:** `form_started` is an encrypted timestamp issued with the page. Submissions under `pnshop.security.bot_trap.min_seconds` (default 2), older than 24 hours, or without the timestamp are refused with a friendly message.
- **CAPTCHA:**
  - `PnShop\Security\Captcha\CaptchaVerifier` is checked last;
  - the core binds a verifier that accepts everything;
  - a Turnstile, hCaptcha or reCAPTCHA extension binds its own and adds its widget.

To protect another form:

1. Add the `bot-trap` middleware to its route.
2. Pass `'botTrap' => BotTrap::fields()` to the page.
3. Render `<BotTrapFields>` with the fields spread into `useForm`.
