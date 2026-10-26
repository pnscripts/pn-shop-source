# Econt

Econt Express delivery for shops in Bulgaria, through Econt's JSON services (https://ee.econt.com/services/).

- **Live prices:** each Econt shipping method asks Econt for the price of the parcel (label service, mode `calculate`) from your sending office or address to the customer's address, or to an Econt office. The weight is the sum of the products' weights (a default per method for products without one). Answers are cached for 10 minutes.
- **Office delivery:** a method set to *An Econt office the customer picks* shows an office picker at checkout (offices of the customer's postcode, or a search across Bulgaria). The order is not placed without an office, and the office is kept with the order and shown on the customer's order page. Store API checkouts send `econt_office_code`.
- **When Econt does not answer:** the method uses its fallback price, or is not offered when it has none. Errors are logged, never shown to shoppers.
- **Demo first:** the *Demo* environment uses `https://demo.econt.com/ee/services/`. Enter Econt's public demo account from the service documentation to try it, then switch to *Production* with your e-Econt user. Cities and offices are cached per environment (a week and 12 hours).
- **Cash on delivery:** when the customer picks the cash-on-delivery payment method, the price includes Econt's fee for collecting the money (service `cdAmount`, `cdType: get`), so checkout shows the final delivery price. Econt collects cash on delivery in euro only: shops selling in another currency get prices without the service, and waybills for such orders are refused with a clear message.
- **Waybills:** *Create shipment* on the order page books the parcel with Econt (label service, mode `create`): to the customer's address or the office kept with the order, with the order number, the contents, the weight and, for cash-on-delivery orders, the amount still due. The waybill number becomes the tracking number, and *Waybill* in the shipments list opens Econt's print page (it opens in e-Econt for the same user). Econt's refusals (for example a missing phone number) are shown to staff and nothing is shipped.
- **Tracking:** shipments link to Econt's tracking page unless the method sets another link.

Checked against Econt's public demo service on 26.10.2026: prices with and without cash on delivery, and a waybill to an address in Plovdiv. Not included: requesting a courier pickup and cancelling waybills from the admin; do those in e-Econt.

Set up: install and enable the plugin, fill in the sender data and credentials in its settings, then add a shipping method with the carrier *Econt* to a zone that covers Bulgaria (one for address delivery, one for office delivery).
