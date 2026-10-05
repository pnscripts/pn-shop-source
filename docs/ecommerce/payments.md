# Payments

Payments live in `PnShop\Payment`. Staff manage payment methods in Admin → Store → Payment methods, and see each order's payments on its *Payments* tab.

## Payment methods and gateways

A **gateway** is code that knows how to take a payment. A **payment method** is what customers choose at checkout: a gateway plus the merchant's settings and rules.

| Built-in gateway | What happens |
|---|---|
| Cash on delivery (`cash_on_delivery`) | The order waits as *unpaid* until staff record the payment. Instructions can mention `:amount` and `:order`. |
| Bank transfer (`bank_transfer`) | The same, and the order page shows the account holder, IBAN, BIC and bank with the amount and order number as the reference. |
| Invoice (`invoice`) | Pay later on payment terms (days, also `:days` in the instructions). Offered to signed-in customers only, and the order is never cancelled as unpaid. See [Pricing and business customers](pricing.md#paying-by-invoice). |

Card and wallet providers are added as gateways by plugins. PN Shop ships two, available in Admin → Extensions: **Stripe** (cards and wallets) and **PayPal**. See [plugins](../extensions/plugins.md#stripe).

Each method has:

- a name and description, translatable per language;
- gateway settings, which start from the gateway's defaults when a gateway is chosen;
- availability rules:
  - offered or not;
  - display position;
  - minimum and maximum order total;
  - shipping countries;
  - customer groups (guests count as the default group).

Checkout lists only the methods that pass their rules and whose gateway is installed. It checks the choice again when the order is placed. A method whose gateway is not installed is marked *not installed* in the admin and never offered.

## Payments and transactions

Every order has one or more **payments** (attempts to pay). Each payment has **transactions**: every exchange with the gateway, or an entry staff recorded.

| Event | Payment | Order payment state |
|---|---|---|
| Checkout, manual gateway | pending | unpaid |
| Gateway reports paid / authorized / failed | paid / authorized / failed | paid / authorized / failed |
| Gateway asks for a redirect | pending | unpaid (the customer is sent to the provider) |
| Gateway throws an error | failed | failed (the order is kept; the customer sees a friendly message) |
| Staff set the payment state to *Paid* | open payments become paid (`manual` transaction) | paid |
| Order cancelled | open payments become cancelled; gift card and store credit payments of an order that was not fully paid go back to their balances | — |

The order page shows the gateway's payment instructions while the payment is pending.

**Several payments:** gift cards and store credit pay part of an order as payments of their own (gateway `store_credit`). The payment method then pays the rest. An order is *paid* once its payments cover the total (`PaymentService::amountDue()`). See [Gift cards and store credit](gift-cards-and-store-credit.md).

## Refunds

*Refund* on the order page (for paid or partly refunded orders) returns money through the gateway of the payment that took it. For the manual gateways, staff return the money themselves and the refund is recorded.

- **What can be refunded:** units of each line (at the price charged) plus an optional additional amount (shipping, goodwill). The total can't exceed what is left on one payment.
- **Refund to:** the payment that took the money (default), or **store credit**. The customer's store credit, or a new gift card emailed to a guest; this needs no payment provider.
- **Net prices:** with tax added on top, a line's tax is refunded with it.
- **Stock:**
  - refunded units that had **not shipped** are cancelled: their reservation is released and they never ship;
  - refunded units that **had shipped** are returns, put back on the shelf (movement `return`) when *Put returned items back in stock* is on.
- **States:** the payment and the order become *partially refunded* or *refunded*. Every refund, including a gateway's refusal, is kept on the order's *Refunds* tab.
- **Customer view:** customers see completed refunds under the order totals.
- **Staff overrides:** *Update payment → Refunded* only changes the state and moves no money. Use *Refund* to return money.

```php
app(PnShop\Payment\RefundService::class)->refund($order, [$orderItemId => 1], Money::of('5.00', 'EUR'), restock: true, reason: 'Damaged', actor: $admin);
```

## Writing a gateway

```php
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\PaymentResult;

final class AcmePay implements PaymentGateway
{
    public function code(): string { return 'acme_pay'; }
    public function label(): string { return 'Acme Pay'; }
    public function settings(): array { return [new SettingDefinition('api_key', SettingType::String, 'API key', required: true)]; }
    public function isAvailable(PaymentContext $context, PaymentMethod $method): bool { return $context->total->getCurrency()->getCurrencyCode() === 'EUR'; }
    public function initiate(Payment $payment, PaymentMethod $method): PaymentResult
    {
        $session = /* call the provider with $method->setting('api_key') */;

        return PaymentResult::redirect($session->url, $session->id);
    }
    // supportsRefunds(), refund(), instructions() …
}

app(PaymentGatewayManager::class)->register(AcmePay::class);
```

- **Recording results:** after the provider redirects back or calls a webhook, record its answer with `PaymentService::apply($payment, PaymentResult::paid($reference), 'webhook')`. The order's payment state follows.
- **Safety:**
  - `PaymentResult` messages can be shown to customers, so keep secrets out of them;
  - put provider details in `data`; staff see them on the transaction.
- **Contract tests:** check every gateway with the kit:

  ```php
  class AcmePayTest extends TestCase
  {
      use RefreshDatabase, PnShop\Payment\Testing\PaymentGatewayContractTests;

      protected function gateway(): PaymentGateway { return new AcmePay(); }
  }
  ```
