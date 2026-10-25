# Speedy

Speedy delivery for shops in Bulgaria, through Speedy's REST API (https://api.speedy.bg/v1).

- **Live prices:** each Speedy shipping method asks `/calculate` for the price of the parcel with your contract's service (505, standard 24 hours, by default) to the customer's town (found by postcode with `/location/site`) or to a Speedy office. The weight is the sum of the products' weights (a default per method for products without one). Answers are cached for 10 minutes.
- **Office delivery:** a method set to *A Speedy office the customer picks* shows an office picker at checkout (`/location/office`, offices of the customer's postcode, or a search across Bulgaria). The order is not placed without an office, and the office is kept with the order and shown on the customer's order page. Store API checkouts send `speedy_office_id`.
- **When Speedy does not answer:** the method uses its fallback price, or is not offered when it has none. Errors are logged, never shown to shoppers.
- **Drop-off office:** set it when you hand parcels in at an office; otherwise Speedy prices from your contract address.
- **Tracking:** shipments link to Speedy's tracking page unless the method sets another link.

Speedy has no public sandbox: ask Speedy for a test API account, or use your contract's API user. Not included yet: creating waybills from the admin and cash-on-delivery fees in the quote.

Set up: install and enable the plugin, enter the API user and password in its settings, then add a shipping method with the carrier *Speedy* to a zone that covers Bulgaria (one for address delivery, one for office delivery).
