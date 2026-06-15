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
  are exported (variant children are nested as variations).
- **Sync**: a daily `ScheduledTask` (`waiter24.export`) pushes the catalog; run
  `bin/console waiter24:export` for an immediate push.
- **Widget**: `base.html.twig` is extended to inject `widget.js` (with the public
  widget key) before `</body>` when enabled.

## Install

```bash
# copy this repository's contents into custom/plugins/Waiter24Export, then:
bin/console plugin:refresh
bin/console plugin:install --activate Waiter24Export
bin/console cache:clear
```

Or via Composer if packaged: `composer require waiter24/shopware-export`.

## Configure (merchant)

**Settings → System → Plugins → Waiter24Export → ⋯ → Config**

1. **Import Token** — paste the secret from your Waiter24 dashboard
   (Site Settings → Automatic menu import).
2. **Widget Key** — paste the public widget key (same screen).
3. **Storefront Base URL** — your store URL, used to build product links.
4. **Simple Stock Mode** — leave on to export everything as available.
5. **Enable Chat Widget** — turn on to show the assistant on the storefront.
6. Run `bin/console waiter24:export` to push immediately and verify; the daily
   task keeps it in sync afterwards.

Multi-sales-channel: settings are sales-channel aware (config is read per
channel), so different channels can feed different Waiter24 tenants.

## Files

```
Waiter24Export/
  composer.json
  src/Waiter24Export.php
  src/Resources/config/{config.xml, services.xml}
  src/Resources/views/storefront/base.html.twig
  src/Service/{PluginConfig.php, MenuExporter.php}
  src/ScheduledTask/{ExportTask.php, ExportTaskHandler.php}
  src/Command/ExportCommand.php
```

## Follow-ups

- Admin button for manual export (currently CLI) via an administration module.
- Entity-written subscriber for near-real-time pushes (debounced).
- Richer SEO product URLs (currently `/detail/{id}`).
- Per-currency pricing (currently the product's default price).
