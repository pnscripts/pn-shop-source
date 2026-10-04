# Gift cards and store credit

Since 1.6, customers can pay with **gift cards** and **store credit**, alone or together with a payment method. Refunds and exchanges can return money as store credit.

Both are balances in one currency. Every change is recorded in a ledger (`balance_transactions`), the way payments are recorded in the payment ledger:
- who made the change;
- why it happened;
- which order and payment it relates to;
- the balance afterwards.

A balance never goes below zero.

## Gift cards

Admin → Sales → Gift cards (permission `sales.credit.manage`).

- **Issuing:** an amount, a currency, an optional expiry date, the recipient's email and a note. The code (e.g. `7KQ2-…`, 16 characters without look-alike letters) is shown **once**, in a notice to the staff member. When there is a recipient, it is also emailed to them. PN Shop keeps only a hash of the code and its last four characters, so a lost code cannot be shown again: issue a new card and disable the old one.
- **Finding a card:** search with the full code, or with its last four characters.
- **Changing a card:** *Adjust balance* (positive or negative, with a note), *Active* (a disabled card cannot be spent), and its expiry date. The *History* tab lists every change.

## Store credit

On a customer's page, *Store credit* lists their balance per currency, with *Add store credit* and *Adjust balance*. *Store credit history* lists every change.

Store credit is shared by all [channels](channels.md) that sell in its currency.

## Paying with them

In the cart:
- customers enter gift card codes (wrong codes are limited to 10 tries per 10 minutes);
- signed-in customers with store credit can tick *Use my store credit*;
- the cart and checkout show what each balance pays and the amount *To pay*.

At checkout:

- gift cards pay first, in the order they were entered, then store credit;
- each balance becomes a **paid payment** of the order (gateway `store_credit`);
- the chosen payment method pays the rest, and its limits apply to that rest;
- when the balances pay everything, no payment method is asked for.

An order becomes *paid* only once its payments cover the total. For example, an order paid partly with a gift card and partly by bank transfer stays *unpaid* until the transfer is recorded. Then *Update payment → Paid* records what is left.

A gift card in another currency than the cart's cannot be used. Expired, disabled or empty cards are refused.

## Cancellations and refunds

- **Cancelling an order that was not fully paid** gives its gift card and store credit payments back to their balances (reason *Order cancelled*).
- **Refunding a payment made with a balance** puts the money back on that gift card or store credit.
- **Refund to store credit:** *Refund* on an order or a return can send the money to store credit instead of the payment that took it. No payment provider is involved, so this also works for cash on delivery.
  - a customer's refund goes to their store credit;
  - a guest's refund becomes a new gift card, emailed to the order's address.

## Exchanges

See [Returns → Exchanges](returns.md#exchanges).

## Admin API

| Endpoint | Notes |
|---|---|
| `GET /gift-cards` | codes are never returned; `last4` identifies a card |
| `POST /gift-cards` | `amount`, `currency`, `expires_at`, `recipient_email`, `note`; the response has the `code`, once |
| `POST /gift-cards/lookup` | `code` → the card |
| `PATCH /gift-cards/{id}` | `is_active`, `expires_at`, `note` |
| `POST /gift-cards/{id}/adjustments` | `amount` (signed), `note` |
| `GET /customers/{id}/credit`, `POST /customers/{id}/credit` | balances per currency; `amount` (signed), `currency`, `note` |
| `POST /orders/{id}/refunds` | `to`: `original` (default) or `store_credit` |

All need `sales.credit.manage`, except refunds (`sales.orders.update`).

## Store API

- `POST /cart/gift-cards` (`code`) and `DELETE /cart/gift-cards/{id}`: enter or remove a gift card.
- `PUT /cart/store-credit` (`use`): spend the signed-in customer's store credit.
- The cart has `gift_cards`, `store_credit` and `amount_due`.
- `POST /checkout` does not need `payment_method_id` when the balances pay everything.

## For developers

- `PnShop\Credit\Balances`:
  - `issueGiftCard()`, `findGiftCard()`, `creditAccount()`;
  - `change()`, the only way to change a balance.
- `PnShop\Credit\CartBalances`: the cart's gift cards and store credit (`plan()`, `spend()`).
- The `cart.summary` pipeline (`PnShop\Cart\CartSummary`) lets modules and plugins add to the cart as pages and the Store API show it; the Credit module adds its fields there.
- `PaymentService::amountDue($order)` is what an order still has to pay.

Not included: selling gift cards as products in the shop. Gift cards are issued by staff, by refunds and by exchanges.
