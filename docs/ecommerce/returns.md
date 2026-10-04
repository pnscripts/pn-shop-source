# Returns (RMA)

Customers can ask to send items back, and staff handle the requests under **Sales → Returns**, which needs the permission `sales.returns.manage`.

## Customers

The order page (for the customer, or the browser with the order's email link) shows a **Request a return** button while returns are possible:

- **When:** the order has shipped (fully or partly) and is not cancelled.
- **Return period:** it lasts *Return period* days after the last shipment (Admin → Settings → Returns, default 14). In the EU, consumers have at least 14 days.
- **What:** only units that were shipped, are not refunded, and are not in another open request.

The customer chooses the quantities, a reason (damaged or faulty, wrong item, not as described, no longer needed, other) and an optional note. Each request gets a number (`RMA-000001`; the prefix is a setting) and appears on the order page with its status.

Headless clients use the Store API:

- **Reading:** `GET /orders/{id}` includes `returns` and `returnable` (the allowed units per order line, reasons and deadline).
- **Requesting:** `POST /orders/{id}/returns`.

## Staff

| Status | Next steps |
|---|---|
| Requested | **Approve** (optionally with instructions, e.g. where to send the parcel) or **Reject** (with a reason) |
| Approved | **Create return label** (when the order's carrier issues them, see below). **Mark received**: units that arrived, and whether they go back into stock. Or **Close** if nothing came back |
| Received | **Refund received items**: the paid price of the received units, to the order's payment or to [store credit](gift-cards-and-store-credit.md). **Exchange**: a new order for other items (below). Or **Close** for a repair handled outside the shop |
| Refunded, Exchanged | **Close** |

Each step works like the rest of the order system:

- **Order history:** every step is written to the order's history and the activity log.
- **Customer emails:** the customer gets an email at each step, in the order's language (setting *Emails → Return request updates*). A refund sends the usual refund email instead.
- **Stock:** received units put back into stock are recorded in the stock history (reason *Return*, with the return number).
- **Refund amounts:** they come from `RefundService`, so they follow the same rules as manual refunds, including promotion discounts and tax added on top. The refund does not restock again.

The Admin API offers:
- `GET /returns` and `GET /returns/{id}`;
- `POST /returns/{id}/transitions`, with `action` approve, reject, receive, refund (`to`: `original` or `store_credit`) or close;
- `POST /returns/{id}/exchange`;
- `POST /returns/{id}/label`.

## Exchanges

*Exchange* on a received return places a **new order** for other items, for example another size.

- **Paying for it:** the received items' value is refunded as store credit (for a guest, a gift card) and spent on the new order first.
- **The new order** goes through checkout like any other order: the customer's prices, stock reservation, tax and the order confirmation email. The new items ship with the original order's delivery method unless another is chosen (Admin API `shipping_method_id`).
- **Price difference:**
  - when the new items cost more, the difference is paid with the payment method staff choose; the new order stays *unpaid* until then;
  - when they cost less, the difference stays as store credit. A guest is emailed the gift card's code.
- **All or nothing:** if the new order cannot be placed (for example a product is out of stock), nothing changes. No refund is made and the return stays *received*.
- **Status:** the return becomes *Exchanged* and links to the new order. The customer sees "Exchanged for order …" on their order page.

## Return labels

When the order shipped with a carrier that issues return labels (a carrier plugin implementing `ProvidesReturnLabels`), approved returns get **Create return label**.
- The carrier receives the return number, the customer's address, the return address and the items with their weights. The return address is the stock location the items shipped from.
- The label link is emailed to the customer and shown on their order page; staff see it on the return.

The built-in carriers (flat rate, free shipping, pickup, tables) do not issue labels.

## For developers

`PnShop\Returns\ReturnService` holds the rules:

- `eligibility()`;
- `request()`;
- `approve()` and `reject()`;
- `receive()`;
- `refund()`;
- `close()`.

`ReturnStatus` declares the allowed transitions. Requests are created under a lock on the order, so two submissions cannot claim the same units. Exchanges, return shipping labels and store credit are not built in yet.
