# Stripe for PN Shop

Card and wallet payments through [Stripe Checkout](https://stripe.com/payments/checkout).

1. Install and enable the plugin in Admin → Extensions.
2. In Admin → Settings → Stripe, paste your **secret key** (start with a test key, `sk_test_…`). Keys are stored encrypted and never shown again.
3. In the Stripe dashboard create a webhook to `https://<your shop>/stripe/webhook` for the events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` and `refund.updated`, and paste its **signing secret** (`whsec_…`) in the settings.
4. In Admin → Store → Payment methods, add a method using the gateway *Stripe (cards and wallets)*.

How it works:

- At checkout the customer is sent to Stripe's payment page. The amount is the order total in the order currency.
- The order becomes *paid* when the customer returns (the session is read back from Stripe's API, never trusted from the URL) or when the signed webhook arrives, whichever is first. A paid amount that does not match the order is refused.
- Refunds from the order page are sent to Stripe for the payment. A refund Stripe accepts but cannot complete later (it reports `refund.updated` with status *failed*) is undone in the shop: the order shows the money was not returned, with a note for staff.
- Requests to Stripe use idempotency keys, so retries never charge or refund twice.

Test locally with the Stripe CLI: `stripe listen --forward-to localhost:8000/stripe/webhook` and the card 4242 4242 4242 4242.

Developers: `docs/development/stripe-end-to-end.md` in the source repository runs the plugin against Stripe's test mode, with API tests and a browser checklist.
