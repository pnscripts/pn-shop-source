# Orders

Orders live in `PnShop\Sales`. Customers see their orders under *Account → Orders*; staff manage them in Admin → Sales → Orders.

## Order number

Each order gets a human number when it is created: the prefix and the order id padded with zeros (`ORD-000042`). Set the prefix and the number of digits in Admin → Settings → Orders. A change applies to new orders only.

## Three states

An order has three independent states. Each is a small state machine, and the admin only offers the transitions allowed from the current state.

| State | Values | Allowed transitions |
|---|---|---|
| **Status** | pending, processing, completed, cancelled | pending → processing / cancelled; processing → completed / cancelled; completed → processing; cancelled → pending (reopen) |
| **Payment** | unpaid, authorized, paid, partially refunded, refunded, failed | unpaid → authorized / paid / failed; authorized → paid / unpaid / failed; paid → partially refunded / refunded; partially refunded → partially refunded / refunded; failed → unpaid / authorized / paid |
| **Fulfillment** | not shipped, partially shipped, shipped, returned | not shipped → partially shipped / shipped; partially shipped → shipped; shipped → returned |

Rules applied on top of the state machines:

- **Payment:** recording a payment on a *pending* order moves it to *processing*.
- **Set by hand or by records:** staff set status and "paid" / "failed" by hand (*Update status*, *Update payment status*). Refunds and shipping are never set by hand: *Refund* returns the money and records a refund, *Create shipment* records a shipment and takes the stock, and returned goods come back through a return request. The order's state then follows from those records.
- **Payments from gateways:** the same answer from a provider (a retried webhook, or the return page and the webhook both arriving) is recorded once, and a paid payment never goes back to failed or pending. Marking an order paid by hand settles its open payments only when no payment is paid yet.
- **Refunds:** the refund is saved as *pending* before the payment provider is asked, then marked *completed* or *failed*. A refund left *pending* means the provider was asked but the shop could not finish recording it: check the provider's dashboard.
- **Cancelled orders:** they can't be shipped until they are reopened. Payments and refunds can still be recorded.
- **Stock:**
  - checkout reserves stock;
  - *Shipped* takes it off the shelf;
  - cancelling releases a reservation or puts shipped goods back;
  - reopening reserves the stock again, and fails when it is gone.

## History

Every change is written to the order's **History** tab with the old and new value, an optional note and who made it (staff member, customer or *System*). Checkout writes the first entry. Use *Add note* for anything else worth keeping.

## Invoices

Each order can have one invoice. When it is issued depends on Admin → Settings → Orders → *Issue invoices*: when the order is paid (default), when it is placed, or only when staff click *Issue invoice*.

- **Numbering:** prefix plus a counter (`INV-000001`). The counter is locked while an invoice is created, so numbers are sequential without gaps or duplicates.
- **Frozen content:** an invoice keeps a copy of the seller, buyer, lines, tax and totals. Changing the store details or the order later never changes an issued invoice.
- **Seller details:** legal name (defaults to the store name), tax / VAT number and footer are set in the same settings. Address, email and phone come from the store settings.
- **Who can open it:**
  - customers open their invoice from the order page;
  - staff open it from the admin with a link that is valid for 30 minutes;
  - it is printed in the language the order was placed in.
- **PDF:** the core renders printable HTML through `PnShop\Sales\Invoices\InvoiceRenderer`. A PDF extension binds its own renderer.
- **Not yet covered:** credit notes for refunds are not issued yet. Refunds are listed on the order.

## Emails

Customers and the store are emailed about orders. Each email can be switched off in Admin → Settings → Emails.

| Email | To | When |
|---|---|---|
| Order confirmation | customer | the order is placed. Includes lines, total, delivery method and payment instructions. |
| Shipping update | customer | each shipment, with its lines and the tracking number or link |
| Cancellation | customer | the order is cancelled |
| Refund | customer | money is refunded |
| New order | store (*Send store alerts to*, or the store email) | the order is placed |

- **Language and branding:** customer emails are written in the language the order was placed in and carry the store name.
- **Order link:** customer emails link to the order with a signed link, so guests can open it from their mail client. Anyone with the link sees the order, like a parcel tracking link.
- **Delivery:** emails are queued and sent after the order change is saved, by the scheduler's cron entry (within a minute) or a queue worker (`php artisan queue:work`). Configure mail in `.env`. An email the mail server refuses is retried after 1, 5, 15 and 60 minutes, then kept in `failed_jobs` (`php artisan queue:failed`, `queue:retry all`).
- **Templates:** emails use Laravel's notification layout. Publish it with `php artisan vendor:publish --tag=laravel-notifications` to change the look. Theme-provided templates follow in Phase 9.

## For developers

```php
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\{OrderStatus, PaymentStatus, FulfillmentStatus};

$workflow = app(OrderWorkflow::class);
$workflow->transition($order, PaymentStatus::Paid, $admin, 'Bank transfer received');
$workflow->transition($order, FulfillmentStatus::Fulfilled);
$workflow->addNote($order, 'Customer asked for evening delivery.', $admin);
```

- Never write `status`, `payment_status` or `fulfillment_status` directly: the workflow checks the transition, moves stock, records history and dispatches events.
- A transition that isn't allowed throws `PnShop\Sales\Exceptions\InvalidOrderTransition`. All order exceptions extend `OrderException`, whose message is safe to show staff.
- **Events (after the transaction commits):**
  - `OrderPlaced($order)` when checkout has created the order;
  - `OrderStateChanged($order, $from, $to, $note, $actor)` for every transition;
  - `PnShop\Shipping\Events\ShipmentCreated($shipment)`;
  - `PnShop\Payment\Events\RefundCompleted($refund)`.
- `PnShop\Sales\OrderLinks::signedShow($order)` gives the signed, localized order link used in emails.
- The state enums implement Filament's `HasLabel` and `HasColor`, so they render as badges and filter options without extra code.
