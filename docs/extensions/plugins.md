# Plugins

Plugins add features without changing PN Shop's own code. The extension manager lives in `PnShop\Extension`. Staff with **system.extensions.manage** manage plugins in Admin → Extensions; the same actions exist on the command line.

> **Trust.** A plugin is PHP code that runs with full access to the shop, its database and its files. PN Shop cannot sandbox it. Install only plugins from people you trust. Signatures (below) prove who published a plugin and that it was not changed; they say nothing about whether the code is safe.

## Where plugins come from

- **A folder:** `extensions/<vendor>/<name>/` with a `pnshop.json` manifest. Upload or unpack it there.
- **Composer:** a package of type `pnshop-plugin` with `pnshop.json` at its root (`composer require acme/store-notice`). Composer never runs from the admin.
- **Zip upload in the admin:** off by default; turn it on with `PNSHOP_EXTENSION_UPLOADS=true`. Every entry is checked before anything is written:
  - no absolute paths, `..` or symbolic links;
  - only known file types (no `.sh`, `.phar`, executables);
  - at most 5,000 files and 50 MB unpacked;
  - the manifest must be valid.

## Manifest

```json
{
    "id": "acme/store-notice",
    "name": "Store notice",
    "description": "A banner above the storefront.",
    "version": "1.2.0",
    "type": "plugin",
    "requires": { "pnshop": "^1.1", "php": ">=8.4", "plugins": { "acme/core": "^1.0" } },
    "provider": "Acme\\StoreNotice\\StoreNoticePlugin",
    "autoload": { "psr-4": { "Acme\\StoreNotice\\": "src/" } },
    "permissions": [{ "key": "store_notice.manage", "label": "Manage the store notice" }],
    "settings": [{ "key": "text", "type": "text", "label": "Notice", "default": "" }],
    "author": "Acme Ltd",
    "license": "MIT"
}
```

- **Validation:** the manifest is checked before any plugin code is loaded:
  - id format;
  - semantic version;
  - version constraints;
  - the provider must be inside an autoload namespace;
  - permission keys are dotted;
  - setting types are known.
- **Settings:** they appear as a tab in Admin → Settings, stored under `plugin.<vendor_name>` (e.g. `plugin.acme_store_notice.text`).
- **Permissions:** they join the role editor like core permissions.

## Lifecycle

| Action | What happens |
|---|---|
| Install | Checks requirements (PN Shop and PHP versions, required plugins installed and enabled, signature), runs the plugin's migrations, calls `install()` and records file checksums. **If anything fails, the migrations of this run are rolled back** and the plugin is marked *failed* with the error. |
| Enable | Adds the plugin to the boot list (`bootstrap/cache/pnshop-plugins.php`). Only enabled plugins run. |
| Update | When a newer version is in the folder: runs its new migrations and `upgrade($from, $to)`, with the same rollback rule. |
| Disable | Stops it from running; its data stays. Refused while enabled plugins require it. |
| Uninstall | Calls `uninstall($keepData)` and forgets the plugin. With *delete its data*, its migrations are rolled back and its settings deleted. The files stay on disk. |
| Check files | Lists files added, changed or removed since installation. |

Every action is written to the activity log (`extensions`).

```bash
php artisan pnshop:plugin:list
php artisan pnshop:plugin install acme/store-notice
php artisan pnshop:plugin enable acme/store-notice
php artisan pnshop:plugin update acme/store-notice
php artisan pnshop:plugin disable acme/store-notice
php artisan pnshop:plugin uninstall acme/store-notice --purge
php artisan pnshop:plugin verify acme/store-notice
```

## Safe mode

Set `PNSHOP_SAFE_MODE=true` (in `.env`, or for one command: `PNSHOP_SAFE_MODE=true php artisan …`) and no plugin runs. Use it when a plugin breaks the shop:

1. Turn safe mode on.
2. Disable or uninstall the plugin.
3. Turn safe mode off.

## Signatures

Plugin authors can sign their plugin with an Ed25519 key:

```bash
php artisan pnshop:plugin:keygen ~/acme-signing.key       # prints the public key
php artisan pnshop:plugin:sign extensions/acme/store-notice --key-id=acme-2026 --secret-file=~/acme-signing.key
```

- **Trusting keys:** a shop trusts a key by adding it to `pnshop.extensions.trusted_keys` (`'acme-2026' => '<public key>'`).
- **What is checked:** a signed plugin is verified on install, update and enable. Any changed file breaks the signature.
- **Requiring signatures:** `PNSHOP_REQUIRE_SIGNATURES=true` refuses unsigned plugins.

## Reference plugin

`extensions/pnshop/handling-fee` ships with PN Shop. It is available but not installed. It adds a handling fee to small orders and shows every part of a plugin:

- a manifest with a permission and settings;
- a migration;
- an admin screen;
- a `cart.totals` stage;
- translations.

Read its README, then install it from Admin → Extensions to try it.

## Storefront code (blocks and slots)

A plugin can add UI to the storefront with a prebuilt ES module, declared as `"storefront": "dist/storefront.js"` in `pnshop.json`.

- **Loading:** on enable or update the file's folder is published to `public/extensions/<id>/`, and the module is loaded on every storefront page after the app, with the plugin version as cache buster.
- **What it uses:** the module uses the shop's own React and Inertia through `window.PnShop`.
- **Without a bundler:** see `extensions/pnshop/handling-fee/storefront/storefront.js`.
- **With a bundler:** alias React and Inertia to `@pnshop/storefront-sdk` (`storefront-sdk/` in the core package: `vendor/pnscripts/pn-shop-core/storefront-sdk`).

```js
window.PnShop.registerSlot('cart.after_totals', ({ cart }) => …);   // add UI to a named slot
window.PnShop.registerBlock('testimonial', ({ quote }) => …);       // render a CMS block type
```

| Slot | Props |
|---|---|
| `product.after_price` | `product`, `variant` |
| `cart.after_totals` | `cart` |
| `checkout.before_submit` | `cart`, `totals` |
| `order.after_totals` | `order` |
| `footer.top` | — |

- **Errors:** a component that throws is caught and left out.
- **Data:** plugins share their own data with `Inertia::share()` in `bootPlugin()`.
- **Translations:** a plugin's `lang/<locale>.json` strings reach the storefront's `t()`.

## Stripe

`extensions/pnshop/stripe` is the first official payment plugin. It provides card and wallet payments through Stripe Checkout:

- a redirect to Stripe;
- confirmation from Stripe's API on return and by signed webhook;
- a check that the amount matches the order;
- refunds from the admin;
- idempotency keys on every request.

Setup steps are in its README.

## Secrets

Plugin settings of type `secret` (API keys, passwords) are:

- stored encrypted;
- never sent back to the browser;
- kept when the field is left empty.

Gateway and carrier settings are stored as plain JSON on payment and shipping methods, so they must not hold secrets; the contract test kits check this.

## Writing a plugin

```php
namespace Acme\StoreNotice;

use PnShop\Extension\Plugin;

class StoreNoticePlugin extends Plugin
{
    public function register(): void
    {
        // Bind services as in any Laravel service provider.
    }

    protected function bootPlugin(): void
    {
        app(\PnShop\Cms\Blocks\BlockRegistry::class)->register(NoticeBlock::class);
        app(\PnShop\Foundation\Extension\PipelineRegistry::class)->stage('seo.meta', AddNoticeMeta::class);
    }

    public function install(): void { /* seed defaults */ }
    public function upgrade(string $from, string $to): void { /* migrate data */ }
    public function uninstall(bool $keepData): void { /* clean up beyond its tables */ }
}
```

Loaded from the plugin folder when present:

| Path | Becomes |
|---|---|
| `database/migrations` | run on install and update (never on boot); use anonymous migration classes |
| `routes/web.php` | storefront routes with the `web` middleware, available under every language prefix |
| `resources/views` | views as `acme_store_notice::view` |
| `lang` | translations as `acme_store_notice::file.key`, and JSON translations |
| `src/Filament/{Resources,Pages,Widgets}` | admin screens, discovered like core modules |

- **Admin screens need authorization:** give every resource's model a policy (`Gate::policy()` in `bootPlugin()`), and every custom page a `canAccess()` check against your plugin's permissions. A resource without a policy is open to every staff account.
- **Commands, scheduled tasks and event listeners:** a plugin is a service provider, so register them in `bootPlugin()`:

  ```php
  protected function bootPlugin(): void
  {
      if ($this->app->runningInConsole()) {
          $this->commands([SyncStockCommand::class]);
      }

      $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('acme:sync-stock')->hourly());

      Event::listen(OrderPlaced::class, NotifyWarehouse::class);
  }
  ```

- **Route and admin caches:** enabling, disabling, updating or removing a plugin clears cached routes and admin components. Run `php artisan optimize` again afterwards on a production server.

- **Extension points:**
  - payment gateways (`PaymentGatewayManager`), shipping carriers (`ShippingCarrierManager`) and the tax provider (`TaxProvider`);
  - CMS blocks (`BlockRegistry`);
  - pipelines `cart.totals`, `cart.summary`, `catalog.price` (see [Pricing](../ecommerce/pricing.md#for-plugin-authors)) and `seo.meta`;
  - return labels for carriers (`ProvidesReturnLabels`, see [Returns](../ecommerce/returns.md#return-labels));
  - channels: `Channels::current()`, amounts from settings converted with `CurrencyConverter` (see [Channels](../ecommerce/channels.md#for-plugin-and-theme-authors));
  - the invoice renderer (`InvoiceRenderer`) and the CAPTCHA verifier (`CaptchaVerifier`);
  - events: `OrderPlaced`, `OrderStateChanged`, `ShipmentCreated`, `RefundCompleted`, and `OrderReopening` (inside the reopening transaction; throw an `OrderException` to refuse).
- **Overriding core:** plugins should use these extension points and never patch or override core classes.
- **Testing:** test your gateway or carrier with the contract test kits in `PnShop\Payment\Testing` and `PnShop\Shipping\Testing`.
