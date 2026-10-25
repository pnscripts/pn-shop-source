# Sales documents and NRA audit file (Н-18)

For e-shops in Bulgaria that sell under the alternative regime of Наредба № Н-18 for online shops (чл. 52м–52у) instead of issuing fiscal receipts, and for cash on delivery settled as a postal money order.

## What it does

- **Sale document (чл. 52о):** when an order paid by card through a virtual POS of the shop's own account (code 2) or a payment service provider (code 4) becomes paid, the customer is emailed a "Документ за регистриране на продажба". It carries a 10-digit number rising by one and never reused (row-locked counter, unique index), the date and time, the seller's name, ЕИК, address and contact, the order number, the payment's transaction reference, each line with its tax group (А 0 %, Б 20 %, Г 9 %), quantity, unit price and value, the totals and payment method, and the QR code of Приложение 18а: `<e-shop number>*<order number>*<transaction reference>*<date>*<time>*<total>`.
- **Cash on delivery (чл. 3, ал. 1, т. 5):** a payment made as a postal money order through a licensed courier needs no fiscal receipt; the customer gets a document with the seller, the goods and the total (чл. 26, ал. 1, т. 1, 4, 7, 8). It is emailed when the parcel is shipped (чл. 25, ал. 3).
- **Monthly audit file (Приложение 38, чл. 52т, ал. 2):** Admin → Sales → Sales documents → *NRA audit file*, or `php artisan pnshop:n18-audit-file 2026-10 --output=audit-2026-10.xml`. It lists the orders delivered in the month (first shipment in the month, or the sale document for orders with nothing to ship) with their lines, totals, payment code, virtual POS number, transaction reference and payment provider, and the refunds made in the month. Amounts in euro. Submit it to NRA by the 15th of the next month.
- **Records stay as issued:** documents are frozen when issued; staff with "See sales documents and download the NRA audit file" can list and download them, nobody can change or delete them.

## Settings

Seller data, the e-shop number NRA gave at registration (Приложение 33), the payment code of each gateway (`stripe=4`, `paypal=4`, `bank_transfer=1`, `cod=3` …), the payment providers' identifiers for the audit file, the virtual POS number, and the first document number when continuing from another program. Until the seller data and e-shop number are filled in, no document is issued and an error is logged.

## What the shop still does

- Register the e-shop with NRA (Приложение 33) and keep that data current (чл. 52р, чл. 52т, ал. 1), and file the audit file monthly.
- Check with its accountant that the regime fits: it needs card payments through a virtual POS linked to its own account or a payment service provider (чл. 52п), and cash on delivery settled as a postal money order. Cash paid to a courier that is not a postal money order needs a fiscal receipt from a fiscal device instead.
- Before the first filing, validate a generated file against NRA's current XSD for Приложение 38 (the element names follow the appendix; NRA can change the schema).

Install with `php artisan pnshop:plugin install pnshop/bg-n18` and `php artisan pnshop:plugin enable pnshop/bg-n18`, or from Admin → Extensions. It needs PN Shop 1.11 or later.
