# Waiter24 — Shopware 6 plugin (`Waiter24Export`)

Exports the Shopware 6 catalog to a Waiter24 tenant and embeds the AI chat
widget on the storefront. Like Magento and WooCommerce, Shopware allows
installable PHP plugins, so this is an in-store plugin (admin config, scheduled
task, manual CLI export, storefront widget injection).

Uses the shared import endpoint (`POST /api/integrations/menu`, Bearer = tenant
import token) and the same JSON schema as the other integrations
(see [`examples/menu-import-sample.json`](examples/menu-import-sample.json)).

## How it works

- **Mapping** (`Service/MenuExporter`): Shopware categories are a tree, so the
  shallowest → `category` and the next → `subcategory`. Simple products map
  directly; variant children become `variations` (named from their options).
  A `listPrice` above the price becomes `sale_price`. Stock drives
  `is_available` unless Simple Stock Mode is on. Only parent/standalone products
  are exported (variant children are nested as variations). Prices carry the
  store's default-currency ISO code (e.g. `EUR`), resolved from the context.
- **Sync**: a daily `ScheduledTask` (`waiter24.export`) pushes the catalog; run
  `bin/console waiter24:export` for an immediate push.
- **Widget**: `base.html.twig` is extended to inject `widget.js` (with the public
  widget key) before `</body>` when enabled.
- **Cart bridge** (`Storefront/Controller/CartBridgeController`): two storefront
  endpoints implementing the platform-neutral contract the chat widget speaks on
  every CMS. They act on the visitor's own cart token (session cookie), so
  add-to-cart from chat is theme-independent:
  - `POST /waiter24/cart/add` — body `{"product_id": "<uuid>", "qty": 2}` →
    `{"success": true}`. `product_id` may be a variant id (the variation picked
    in chat) — variants are ordinary Shopware products.
  - `GET /waiter24/cart` — → `{"items": [{"name", "qty", "price"}]}`, read-only,
    feeds the AI's cart-aware upsell.
  Both return 404 while **Enable Chat Widget** is off. The export announces them
  via `site_config` (`platform_preset: shopware`, `ajax_add_url`,
  `cart_read_url`), so the tenant panel is prefilled automatically.

## Requirements

- Shopware **6.6.x** (`shopware/core: ~6.6.0`)
- PHP **8.2+** (Shopware 6.6 baseline)
- Message queue / scheduled-task worker running (for the daily export task)
- Outbound HTTPS from the store to `https://waiter24.ai`

## Install

```bash
# copy this repository's contents into custom/plugins/Waiter24Export, then:
bin/console plugin:refresh
bin/console plugin:install --activate Waiter24Export
bin/console cache:clear
```

### Composer

The package (`waiter24/shopware-export`, type `shopware-platform-plugin`) is a
private repo, **not on public Packagist**. Register it as a VCS source, then
require it from the Shopware root:

```bash
composer config repositories.waiter24 vcs git@github.com:waiter24ai/waiter24-shopware.git
composer require waiter24/shopware-export:dev-main
bin/console plugin:refresh
bin/console plugin:install --activate Waiter24Export
bin/console cache:clear
```

## Configure (merchant)

**Settings → System → Plugins → Waiter24Export → ⋯ → Config**

1. **Import Token** — paste the secret from your Waiter24 dashboard
   (Widget Settings → Menu auto-import).
2. **Widget Key** — paste the public widget key (same screen).
3. **Storefront Base URL** — your store URL, used to build product links.
4. **Simple Stock Mode** — leave on to export everything as available.
5. **Enable Chat Widget** — turn on to show the assistant on the storefront.
6. *(optional)* **Demo Mode** — hides the chat from regular visitors; it appears
   only on URLs carrying `?waiter24_demo=1`. The parameter is remembered for the
   session and re-applied to in-chat links, so the chat stays visible while
   clicking around. Useful for showing the assistant to a client before going live.
7. Run `bin/console waiter24:export` to push immediately and verify; the daily
   task keeps it in sync afterwards.

Multi-sales-channel: settings are sales-channel aware (config is read per
channel), so different channels can feed different Waiter24 tenants.

> **URLs.** `config.xml` ships production defaults (`https://waiter24.ai/…`) for
> the **Import Endpoint URL** and **Widget Script URL**. To point a sales channel
> at another Waiter24 instance (e.g. an OSPanel dev host at `http://waiter.loc`),
> override them in the plugin config rather than editing the shipped defaults.

## Files

```
Waiter24Export/
  composer.json
  src/Waiter24Export.php
  src/Resources/config/{config.xml, services.xml, routes.xml}
  src/Resources/views/storefront/base.html.twig
  src/Service/{PluginConfig.php, MenuExporter.php}
  src/Storefront/Controller/CartBridgeController.php
  src/ScheduledTask/{ExportTask.php, ExportTaskHandler.php}
  src/Command/ExportCommand.php
```

## Limitations

- The storefront header cart badge does not refresh automatically after a
  bridge add — it updates on the next page navigation. If live refresh is
  needed, a theme-specific snippet can be set in the Waiter24 panel
  (Site Settings → After-add JavaScript).

## Follow-ups

- Admin button for manual export (currently CLI) via an administration module.
- Entity-written subscriber for near-real-time pushes (debounced).
- Richer SEO product URLs (currently `/detail/{id}`).
- Per-currency pricing (currently the product's default price).
