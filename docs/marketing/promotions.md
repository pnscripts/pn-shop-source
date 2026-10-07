# Promotions and coupons

A **promotion** is a cart rule. When all of its **conditions** hold, its **actions** give discounts. It applies automatically, or only when the customer enters one of its **coupon codes**. Promotions are managed under **Marketing → Promotions**, which needs the permission `marketing.promotions.manage`. They can also be managed through the [Admin API](../api/README.md) (`/promotions`).

## Building a promotion

| Setting | |
|---|---|
| Name / "shown to customers as" | The label appears next to the discount in the cart, checkout, order and emails (plus the coupon code, e.g. "Spring (SPRING24)") |
| Active, starts, ends | Outside its dates or when inactive it does nothing |
| Needs a coupon code | Off: automatic for every cart that qualifies. On: only with one of its codes |
| Order, "stop later promotions" | Promotions run from the lowest order up; each works on what is left after the previous ones. A promotion marked *stop* keeps later ones from applying |
| Total uses, uses per customer | Counted when an order is placed, and given back when it is cancelled. Customers are recognised by account or email |

### Conditions (all must hold)

| Type | Holds when |
|---|---|
| Cart subtotal is at least | the subtotal **before discounts** reaches the amount |
| Number of items is at least | the number of units reaches N (optionally counting only some products or categories) |
| Cart contains products | a line is one of the products or in one of the categories (subcategories included) |
| Customer group is | the signed-in customer is in one of the groups (guests never) |
| Shipping country is | the delivery address is in one of the countries. Before checkout no address is known, so it does not hold yet |

### Discounts (actions)

| Type | Gives |
|---|---|
| Percentage off | a percentage off every line, or only the chosen products or categories |
| Fixed amount off | an amount off the order (or the chosen lines), spread over the lines; never more than they cost |
| Buy X, get Y discounted | in every group of X + Y matching units, the Y cheapest are discounted (100% means free) |
| Free shipping | the delivery price of the chosen method |

## Coupons

Each promotion that needs a coupon has a **Coupons** tab:

- **Adding codes:** add codes one by one, or **Generate codes** in bulk. Generated codes are random, avoid look-alike characters, can have a prefix (`NL-…`) and are one use each by default.
- **Matching:** customers can type a code in any case, and codes are stored upper-case.
- **Code limits:** a code can have its own usage limit, on top of the promotion's.

Wrong codes are rate limited: 10 per 10 minutes per visitor. Customers enter a code in the cart, or through the Store API with `POST /cart/coupon`:

- **Unknown or used-up codes** are refused.
- **Valid codes the cart doesn't qualify for yet** (for example below the minimum subtotal) are kept, the cart says why, and the code applies as soon as the cart qualifies.

## How discounts are calculated

Promotions are stages of the `cart.totals` pipeline: `ApplyPromotions` at priority 100, before shipping, and `ApplyShippingPromotions` at 250, after shipping.

- **Per-line amounts:** each discount is recorded per line (`CartTotals::discount()` and `discountOn()`).
- **Tax:** VAT is charged on what the customer actually pays, both with tax-inclusive and tax-exclusive prices.
- **Orders:** the order keeps each line's discount (`order_items.discount_amount`), so refunds return what was paid for the refunded units.
- **Usage counting:** uses are counted inside the checkout transaction with conditional updates, so two simultaneous orders cannot both take a promotion's last use. The second order is stopped with a message asking the customer to review the cart.

## Adding condition and action types (plugins)

```php
use PnShop\Promotion\PromotionRegistry;

public function bootPlugin(): void
{
    app(PromotionRegistry::class)->condition(FirstOrderCondition::class);
    app(PromotionRegistry::class)->action(GiftWrapAction::class);
}
```

A type implements `PnShop\Promotion\Contracts\ConditionType` or `ActionType`, which needs:

- `key()` and `label()`;
- `fields()` (Filament form components for its settings);
- `rules()` (validation of those settings, also used by the Admin API);
- `passes()` (conditions) or `apply()` (actions).

Actions give discounts through `PnShop\Promotion\Discounts` (`off()`, `percentOff()`, `spread()`, `freeShipping()`), which never lets a line go below zero. `PromotionContext` has the lines, the customer, the addresses and a product/category matcher (`inScope()`).

If a plugin that defined a condition is removed, promotions using that condition stop applying (unknown conditions never hold). Unknown actions give nothing.
