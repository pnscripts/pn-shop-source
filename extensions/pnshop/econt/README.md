# Econt

Econt Express delivery for shops in Bulgaria, through Econt's JSON services (https://ee.econt.com/services/).

- **Live prices:** each Econt shipping method asks Econt for the price of the parcel (label service, mode `calculate`) from your sending office or address to the customer's address, or to an Econt office. The weight is the sum of the products' weights (a default per method for products without one). Answers are cached for 10 minutes.
- **Office delivery:** a method set to *An Econt office the customer picks* shows an office picker at checkout (offices of the customer's postcode, or a search across Bulgaria). The order is not placed without an office, and the office is kept with the order and shown on the customer's order page. Store API checkouts send `econt_office_code`.
- **When Econt does not answer:** the method uses its fallback price, or is not offered when it has none. Errors are logged, never shown to shoppers.
- **Demo first:** the *Demo* environment uses `https://demo.econt.com/ee/services/`. Enter Econt's public demo account from the service documentation to try it, then switch to *Production* with your e-Econt user. Cities and offices are cached per environment (a week and 12 hours).
- **Tracking:** shipments link to Econt's tracking page unless the method sets another link.

Not included yet: creating waybills (labels) from the admin and cash-on-delivery fees in the quote; create waybills in e-Econt for now.

Set up: install and enable the plugin, fill in the sender data and credentials in its settings, then add a shipping method with the carrier *Econt* to a zone that covers Bulgaria (one for address delivery, one for office delivery).
