# Testing

PN Shop's tests are PHPUnit 13 feature and unit tests in `tests/`. They run on SQLite in memory by default, and CI runs them again on MySQL 8.4 and PostgreSQL 17.

```bash
./vendor/bin/phpunit                          # everything
./vendor/bin/phpunit tests/Feature/Orders     # one area
./vendor/bin/phpunit --filter test_refunds    # by name
```

## The test environment

`phpunit.xml` sets up a self-contained environment:
- SQLite `:memory:` with array cache, mail and sessions, and a sync queue;
- `PNSHOP_ENFORCE_INSTALL=false`, so pages do not redirect to the installer;
- `TELESCOPE_ENABLED=false`.

`tests/TestCase.php` adds three more things:
- it turns off Vite, so tests need no built assets;
- it points the install lock at a temporary file, so tests never touch the checkout's own lock;
- it provides `checkoutData($paymentMethodId)`, a valid checkout form that includes the spam-protection fields.

## Other databases

Schema and query differences only show on a real server, so test changes to migrations or raw SQL there too. Use a throwaway server, never a database you care about:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=pnshop_test DB_USERNAME=root DB_PASSWORD= ./vendor/bin/phpunit
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=pnshop_test DB_USERNAME=postgres DB_PASSWORD= ./vendor/bin/phpunit
```

The tests refresh the database (`RefreshDatabase`), so the database they point at is wiped.

Keep the schema portable:
- index names of 64 characters at most;
- unique strings of 768 characters at most;
- no comparisons of JSON columns with strings;
- `whereLike` for case-insensitive search;
- no hard-coded ids in tests.

## Conventions

- **Through the app:** test behaviour through HTTP requests, Livewire (admin screens) or the services, the way a shop uses them, not private methods.
- **Admin tests** extend `Tests\Feature\Admin\AdminTestCase`. It has `actingAsAdministrator()` and `actingAsStaff([...permissions])`.
- **Guards:** `actingAs($staff, 'admin')` switches the default guard for the rest of the test, so make customer requests first.
- **API calls:** they call `Auth::forgetGuards()`, so sign in again (`actingAs`) after an API call in the same test.
- **Fresh requests:** the test client reuses the application between requests. To simulate a new request (for example another browser after a password change), call `$this->app['auth']->forgetGuards()` first.
- **Query counts:** `tests/Feature/Core/QueryCountTest.php` fails when a storefront page or API list starts making more queries. Keep it green by eager-loading relations, not by raising the limits.
- **Contract test kits:** a payment gateway or shipping carrier (core or plugin) gets its tests from `PnShop\Payment\Testing\PaymentGatewayContractTests` and `PnShop\Shipping\Testing\ShippingCarrierContractTests`.

## Payment providers

The suite fakes payment providers' APIs. Two optional runs go further:

- **Stripe, real test mode:** `STRIPE_TEST_SECRET_KEY=sk_test_… ./vendor/bin/phpunit --group stripe-live` runs `tests/Feature/Extensions/StripeLiveTest.php` against Stripe's API. It is excluded from the normal suite and CI, and refuses live keys. The full run with Stripe's payment page is in [Stripe end to end](stripe-end-to-end.md).
- **PayPal, local stand-in:** `tests/Support/PayPalStandIn` imitates PayPal's API (see the PayPal plugin's README).

## The other checks

CI runs these on every pull request and every push to `main` and `next`:

```bash
vendor/bin/pint --test                                     # code style
vendor/bin/phpstan analyse                                 # the shop's code, level 5
vendor/bin/phpstan analyse -c phpstan-core.neon            # the core package and bundled plugins, level 7
npm run format:check && npm run lint:check && npm run types
npm run build && node scripts/check-bundle-size.mjs        # storefront bundle budget
```

A `package` job also builds the release trees and installs a shop from them with `composer create-project`, as a merchant would.
