# Stripe end to end

Checks the Stripe plugin (`extensions/pnshop/stripe`) against Stripe itself, in **test mode**: no real money moves. It needs a Stripe account (test mode works before the account is activated) and its test-mode secret key, `sk_test_…` (Stripe dashboard → Developers → API keys, with *Test mode* on). Never use a live key (`sk_live_…`).

New Stripe accounts have *Managed Payments* on by default; the plugin turns it off for its sessions (the shop is the seller and adds tax itself), so nothing needs changing in the dashboard.

## 1. The API tests

```bash
STRIPE_TEST_SECRET_KEY=sk_test_… ./vendor/bin/phpunit --group stripe-live
```

`tests/Feature/Extensions/StripeLiveTest.php` checks, with real Stripe objects:

- checkout creates a Checkout Session for the order total, currency, order number and payment;
- returning before paying leaves the order unpaid;
- cancelling the order expires the session on Stripe;
- refunds of a card payment (Stripe's test card, paid through the API) reach Stripe, partly and then fully;
- a refund Stripe fails later (test card `pm_card_refundFail`) is undone by the webhook. Stripe may take a few minutes to fail it; the test waits a minute and is marked incomplete if it has not happened yet.

The objects stay in the account's test data; *Developers → Delete all test data* in the dashboard clears them.

## 2. Paying on Stripe's page

Paying on Stripe's hosted page needs a browser. Use a throwaway shop, never your development checkout:

```bash
composer create-project pnscripts/pn-shop stripe-check
cd stripe-check
php artisan pnshop:install --demo        # SQLite is fine; APP_URL e.g. http://127.0.0.1:8124
php artisan serve --host=127.0.0.1 --port=8124
```

1. Admin → Extensions: install and enable *Stripe*.
2. Admin → Settings → Stripe: paste the `sk_test_…` key.
3. Admin → Store → Payment methods: add a method with the gateway *Stripe (cards and wallets)*.
4. In the storefront, add a product to the cart and check out with that method. Stripe's page opens and shows the order total and *Test mode*.
5. Pay with the test card `4242 4242 4242 4242`, any future expiry date, any CVC and postcode.
6. Back in the shop the order page says the payment was received; in the admin the order is *Paid* and its payment's reference is a `pi_…` PaymentIntent.
7. Refund one item from the order page. Stripe's dashboard (test mode → Payments) shows the partial refund.
8. Check out again and cancel on Stripe's page (the back link): the order stays unpaid. Cancel it in the admin: the session expires on Stripe.

Other test cards: `4000 0025 0000 3155` asks for 3-D Secure authentication; `4000 0000 0000 9995` is declined.

## 3. The webhook (optional)

The webhook confirms payments when the customer never comes back to the shop. Stripe cannot reach `127.0.0.1`, so forward events with the [Stripe CLI](https://docs.stripe.com/stripe-cli):

```bash
stripe listen --api-key sk_test_… --forward-to http://127.0.0.1:8124/stripe/webhook
```

It prints a signing secret (`whsec_…`): paste it in Admin → Settings → Stripe. Then pay as in step 2 but close the tab on Stripe's confirmation page instead of returning. The order becomes *Paid* within seconds, and `stripe listen` shows `checkout.session.completed` answered with `200`.
