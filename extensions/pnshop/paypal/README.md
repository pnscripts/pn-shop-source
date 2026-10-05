# PayPal for PN Shop

PayPal payments through [PayPal Checkout](https://developer.paypal.com/docs/checkout/) (Orders API v2).

1. Install and enable the plugin in Admin → Extensions.
2. On [developer.paypal.com](https://developer.paypal.com) → Apps & Credentials, create an app. Start in the **sandbox**.
3. In Admin → Settings → PayPal:
   - choose the **environment** (Sandbox or Live);
   - paste the app's **Client ID** and **Secret**. The secret is stored encrypted and never shown again.
4. In the app, add a webhook to `https://<your shop>/paypal/webhook` for the events `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.DECLINED` and `PAYMENT.CAPTURE.DENIED`, and paste its **Webhook ID** in the settings.
5. In Admin → Store → Payment methods, add a method using the gateway *PayPal*.

## How it works

- **At checkout** the customer is sent to PayPal to approve the payment. The amount is the order's amount due, in the order currency.
- **Capture:** when the customer comes back, the shop captures the payment through PayPal's API. The return URL's PayPal order id must match the payment; nothing in the URL is trusted otherwise. If the customer never comes back, the `CHECKOUT.ORDER.APPROVED` webhook captures it.
- **Paid:** the order becomes paid when the capture is completed for the exact amount and currency. A capture under review at PayPal (*pending*) waits for `PAYMENT.CAPTURE.COMPLETED`.
- **Cancelled orders are never charged:** nothing is captured for a payment that was closed (the order was cancelled while the customer was on PayPal). If a capture still reaches a closed payment, it is recorded and flagged in the order history for staff to refund in PayPal.
- **Refunds** from the order page are sent to PayPal for the capture.
- **No double charges:** requests carry a `PayPal-Request-Id`, so retries never capture or refund twice.
- **Webhook signatures** are checked in the shop with PayPal's certificate, which is only fetched from paypal.com.

## Currencies

PayPal is offered for the currencies PayPal accepts with decimal amounts: AUD, BRL, CAD, CNY, CZK, DKK, EUR, HKD, ILS, JPY, MYR, MXN, NZD, NOK, PHP, PLN, GBP, SGD, SEK, CHF, THB and USD. In a [channel](../../../docs/ecommerce/channels.md) selling in another currency, PayPal is not offered.

## Testing

Use sandbox credentials and a sandbox buyer account from the PayPal developer dashboard. Webhooks need a public URL: expose your local shop with a tunnel and add that URL as the sandbox webhook.

For development without a PayPal account, the plugin can talk to a local stand-in of PayPal's API instead. Set its address in `config/services.php`:

```php
'paypal' => ['api_url' => env('PAYPAL_API_URL')],
```

The source repository has such a stand-in: `tests/Support/PayPalStandIn/server.php`, with an approval page, request log and PayPal's capture and refund rules.

Leave `PAYPAL_API_URL` unset in a real shop: the plugin then uses PayPal's sandbox or live API, as chosen in the settings.
