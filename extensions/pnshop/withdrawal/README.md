# Withdrawal button

The online withdrawal function that EU law asks of shops selling at a distance from 2026: Directive 2023/2673 (new art. 11a of Directive 2011/83/EU), in Bulgaria the Consumer Protection Act (ЗЗП) чл. 52а, in force from 24.09.2026.

- **The link:** "Withdraw from contract here" ("Откажете се от договора тук") in the storefront footer on every page and on each order page, so it is visible and available throughout the withdrawal period.
- **Step 1, the statement:** name, order number, email for the confirmation and, optionally, the products concerned. Signed-in customers find their name and email filled in; the link on an order page fills in its number. Guests can use it too.
- **Step 2, the confirmation:** the customer checks the statement and sends it with "Confirm withdrawal" ("Потвърждавам отказа").
- **Confirmation on a durable medium:** an email is sent at once with the statement, the reference and the date and time it was sent (store timezone). Staff get a copy.
- **Every statement is accepted:** also one that matches no order or comes late. The admin list (Sales → Withdrawals, permission "See and handle withdrawals from contracts") shows whether it matches an order (number + email) and whether it looks late (after the withdrawal period, counted from the last shipment). Staff mark statements handled and add notes; they cannot edit or delete them.
- **Abuse protection:** the form uses the shop's bot trap (honeypot, time check, CAPTCHA if configured) and rate limits.

What the shop still does itself: say in the pre-contract information and terms where the function is (ЗЗП чл. 47, ал. 1, т. 8), and handle the return and refund (for example with a return request in the admin).

Install with `php artisan pnshop:plugin install pnshop/withdrawal` and `php artisan pnshop:plugin enable pnshop/withdrawal`, or from Admin → Extensions. It needs PN Shop 1.11 or later.
