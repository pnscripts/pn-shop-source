# Catalog and inventory

Everything below lives in `PnShop\Catalog`, `PnShop\Inventory` and `PnShop\Media`.

## Products and variants

| Concept | Model | Notes |
|---|---|---|
| Product | `Product` | Title, description, categories, brand, images, attributes. `type` is `simple` or `variable`. |
| Variant | `ProductVariant` | What is actually sold: SKU, barcode, price, sale price, weight, stock settings. Every product has at least one; the first is the *default* variant. |
| Option | `Option`, `OptionValue` | Variant axes such as Size or Color, with translated names and values (Admin → Catalog → Variant options). |

- **Simple products** have exactly one variant. Their admin form edits price, sale price, stock, SKU, barcode and weight directly.
  - In code these are shortcuts on `Product` that read and write the default variant:
    ```php
    Product::create(['title' => 'Desk lamp', 'price' => '49.90', 'stock' => 12, 'product_category_id' => $id]);
    $product->price;  // Brick\Money\Money of the default variant
    $product->stock;  // units available across active variants (null when not tracked)
    ```
  - Load `variants.stockLevels` (see `ProductCardPresenter::RELATIONS`) before reading the shortcuts in lists.
- **Variable products** choose options on the product form and manage variants in the *Variants* table on the edit page.
  - *Generate variants* creates every missing combination, priced like the default variant, up to 200 at a time.
  - A combination can exist only once.
  - A product always keeps one variant; deleting the default variant makes another one the default.
- **Featured products:** *Featured on the home page* (product form, Visibility) puts the product first in the home page's product list, which shows eight products: featured first, then the newest. A CMS page marked as the homepage replaces that list.
- **Prices:**
  - Money is stored in minor units.
  - A sale price applies only when it is set and lower than the price (`PnShop\Money\Prices`).
  - Order lines store the price actually charged.
- **Listing:** a product appears in the store when it is visible *and* has at least one active variant (`Product::active()`). Listings show the cheapest active variant, prefixed with "from" when variant prices differ.

## Categories and brands

- Categories form a tree (nested set, `kalnoy/nestedset`). In the admin you reorder them with up/down and pick a parent; a category cannot be moved under itself or its descendants.
- A product has a **primary category** (breadcrumbs, main URL) and can appear in **more categories**. The primary category is always among them.
- Filtering by a category includes its subcategories.
- Brands have translated names, slugs and descriptions, and can be used as a shop filter (`?brand=slug`).

## Attributes (specifications)

`ProductAttribute` / `ProductAttributeValue`, managed in Admin → Catalog → Attributes.

- Attributes are linked to categories, and products in those categories show them on the product page.
- *Filterable* attributes become shop filters (`?filter[<attribute id>][]=<value id>`):
  - values of one attribute combine with OR, and different attributes with AND;
  - only values that occur in the current category and brand selection are offered.
- Variant options (Size) and attributes (Material) are different things: options create purchasable variants, attributes describe the product.

## Related products

Ordered lists chosen on the product form:

| Type | Where it shows |
|---|---|
| Upsells | product page, first |
| Related products | product page |
| Cross-sells | cart ("Customers also bought") |

## Images

- Images live in the media library (Admin → Content → Media) and are attached to products as an ordered *gallery*; the first image is the main one.
- Uploads accept JPEG, PNG, WebP, AVIF and GIF up to 10 MB. SVG is refused because it can contain scripts.
- A queued job (`GenerateConversions`) creates `thumb` (320 px), `medium` (800 px) and `large` (1600 px) WebP versions, never enlarged, which the storefront uses in `srcset`.
- Run a queue worker in production, and `php artisan storage:link` for the default `public` disk. The disk and sizes are set under `media` in `config/pnshop.php`.
- Any model can use `PnShop\Media\Concerns\HasMedia` for its own collections.

## Inventory

| Table | Purpose |
|---|---|
| `stock_locations` | Where stock is kept. One default location ("Main warehouse") is created; add warehouses and shops in Admin → Catalog → Stock locations. See [Stock locations](stock-locations.md). |
| `stock_levels` | `on_hand` and `reserved` per variant and location. *Available* = on hand − reserved. |
| `stock_movements` | Append-only ledger: quantity, resulting on-hand, reason, the order (or other record) that caused it, the admin user, a note. |

All changes go through `PnShop\Inventory\InventoryService`:

```php
$inventory->adjust($variant, -2, StockMovementReason::Order, $order); // throws InsufficientStock if it would oversell
$inventory->setOnHand($variant, 40, $admin, 'Stock take');            // records the difference as an adjustment
$inventory->available($variant);                                      // online locations; null when the variant does not track stock
$inventory->transfer($variant, $from, $to, 3, $admin);                 // between locations, recorded at both
```

- Decrements are a single conditional `UPDATE`, so concurrent checkouts can never oversell.
- Variants can allow backorders (sell below zero) or not track stock at all.
- Checkout **reserves** stock (`reserve()`); it leaves the shelf when the order ships (`commit()`, reason `order_fulfilled`), and cancelling releases it (`release()`). See [stock reservations](customers-cart-and-checkout.md#stock-reservations).
- Movement reasons:
  - shipping an order: `order_fulfilled`;
  - cancelling a shipped order: `order_cancelled`, returning stock;
  - reopening a cancelled order straight to shipped: `order_reopened`;
  - admin edits: `adjustment`;
  - moving stock between locations: `transfer`;
  - orders placed before Phase 5 recorded `order` at checkout.
- **Low stock:** the dashboard's *Low stock* widget lists tracked variants at or below their *Low stock at* value (variant form), or 5 units when it is empty.
- **Stock history:** the *Stock history* tab of a product lists every movement of its variants, with the reason, the change, the on-hand count after it and who made it.
- **Changing stock:** needs the `catalog.inventory.manage` permission, in the product and variant forms and in the Admin API.

## Browsing the shop

- **Search:** the search box on `/shop` (and `q` in the Store API) matches product titles in the visitor's language.
- **Sorting:** newest (default), price low to high or high to low, or name. Price uses the default variant's price, or its sale price when lower. Name uses the title in the default language.
- **Filters:** categories at any depth (the rows follow the chosen category's path), brands and filterable attributes. Values of one attribute widen the choice; different attributes narrow it.
- **Product cards:** they show how many units are left. A product that sells on backorder shows *Available to order* instead of *Out of stock*.
