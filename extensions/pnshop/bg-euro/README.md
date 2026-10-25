# Euro prices (Bulgaria)

For shops in Bulgaria after the euro changeover (euro from 01.01.2026; the lev stopped being legal tender on 31.01.2026). Based on the Euro Introduction Act (Закон за въвеждане на еврото в Република България, ЗВЕРБ).

- **Euro check:** a dashboard card (staff with "Manage languages, currencies and countries") and `php artisan pnshop:euro-check` say whether the default currency is EUR, whether any storefront still sells in leva, and whether an active lev uses the fixed rate 1.95583. The command exits with an error when a check fails, so it can run in deployment checks.
- **Prices in leva too (optional):** *Also show prices in leva* adds the lev amount after every euro amount the storefront and APIs show, in the same text (same size, font and colour, ЗВЕРБ чл. 16), e.g. "10,00 € (19,56 лв.)". The amount is the euro amount × 1.95583 rounded to the stotinka on the third decimal (чл. 12–13). It was mandatory from 08.08.2025 to 08.08.2026 (чл. 15, ал. 2) and is optional since; *Stop showing leva after* ends it on a date.
- **Customers always pay in euro:** orders, invoices and payments stay in euro; the lev amount is information only.

`PnShop\Plugins\BgEuro\Lev` converts both ways at the fixed rate (`Lev::toEuro('19.56')` for old lev prices in imports).

Install with `php artisan pnshop:plugin install pnshop/bg-euro` and `php artisan pnshop:plugin enable pnshop/bg-euro`, or from Admin → Extensions. It needs PN Shop 1.11 or later.
