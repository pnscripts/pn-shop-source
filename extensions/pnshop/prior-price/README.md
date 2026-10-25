# 30-day prior price

Shows the lowest price of the 30 days before a reduction as the struck-through ("was") price, as EU price-indication rules ask: Directive 98/6/EC art. 6a (added by the Omnibus Directive 2019/2161), in Bulgaria the Consumer Protection Act (ЗЗП) art. 65.

- **History:** from installation on, every change of a variant's public price (its sale price when one applies, else its price) is recorded, whether it was made in the admin, the Admin API or an import.
- **Prior price:** when a sale price applies, the struck-through price is the lowest public price in the 30 days (setting) before the sale started. Back-to-back reductions count as one sale (setting), so the prior price stays the one from before the first reduction.
- **No reduction to show:** when that lowest price is not above the sale price, the product shows its price without a struck-through price or Sale badge.
- **Sales older than the history:** for a sale that started before the plugin was installed, the shop shows the price without a reduction (default) or strikes through the regular price (setting).
- **Not covered:** lower prices from price lists, quantity tiers or customer groups are not announced reductions and keep the regular price. Perishable goods and goods on the market for less than 30 days have their own rules; adjust the days if they apply to you.

Install with `php artisan pnshop:plugin install pnshop/prior-price` and `php artisan pnshop:plugin enable pnshop/prior-price`, or from Admin → Extensions. It needs PN Shop 1.11 or later.
