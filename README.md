# JAMF Sync (Pull-Only) for GLPI

`jamfsync` synchronizes devices and applications from JAMF School into GLPI.

This plugin is strictly **pull-only**:
- no write operations to JAMF
- no push/webhook requirement
- no delete operations in JAMF

The plugin currently uses JAMF School API `https://api.zuludesk.com` with **API version 1.0**.

## Features

- Multi-instance support (multiple JAMF tenants in one GLPI)
- Entity mapping per JAMF instance
- Manual and cron-based sync runs
- Manual run for all active instances or one specific instance
- Delta sync using device/app signatures
- Paged fetch per instance (configurable page size)
- Base asset upsert in GLPI:
- JAMF device -> `glpi_computers`
- OS mapping -> `glpi_items_operatingsystems` (+ OS master data for name/version/architecture/kernel/edition/service pack/install date)
- JAMF apps -> `glpi_softwares` / `glpi_softwareversions` / `glpi_items_softwareversions`
- Last activity from JAMF -> `glpi_computers.last_boot`
- Storage from JAMF -> `glpi_items_disks` (dynamic `JAMF Storage` entry)
- Battery level from JAMF -> `glpi_items_devicebatteries` (dynamic entry)
- Last check-in / enrollment date / public IP / MAC in JAMF-related comment data
- MAC + public IP also synced as dynamic Wi-Fi port `JAMF Network` (`NetworkPortWifi`)
- App reconciliation (removed JAMF apps are removed from JAMF-managed links)
- Monitoring with filters, metrics, CSV export, and run detail view
- Run detail view with changed items (devices/apps/OS)
- Asynchronous runner (AJAX + Vue) with live progress for manual runs

## Requirements

- GLPI `>= 11.0.0` and `< 12.0.0`
- PHP with cURL extension
- Network access from GLPI server to each JAMF instance

## JAMF Credentials (Per Instance)

- `JAMF base URL` (for example `https://tenant-a.jamfcloud.com`)
- `JAMF API username (Network ID)`
- `JAMF API key`
- Target GLPI entity

Recommended JAMF permissions (read-only):
- read device inventory
- read application data

## Installation

1. Copy plugin to `glpi/plugins/jamfsync`.
2. Install and enable it in GLPI (`Setup > Plugins`).
3. Configure instances in `Setup > Plugins > JAMF Sync > Configuration`.
4. Optionally start the first manual run (`Run pull now`).

## Configuration

Per JAMF instance:
- `Instance name`
- `JAMF base URL`
- `JAMF API username (Network ID)`
- `JAMF API key`
- `Target entity`
- `Computer name source`: `JAMF device name` (default) or `JAMF asset tag` (fallback to device name/serial/UDID)
- `Pull devices` / `Pull apps`
- `Verify TLS`
- `Request timeout (sec)`
- `Page size` (50-1000, default 200)
- `Active`
- API key handling:
  - keys are stored encrypted at rest
  - during edit, leaving the key field blank keeps the existing key
  - use `Rotate API key encryption` to re-encrypt all stored keys with the current GLPI key

## Operation

Manual:
- `Run pull now`: delta run
- `Run full resync`: full run without delta filtering
- Per-instance quick actions (`Run`, `Full run`) are available in the instance list
- Runs start asynchronously and show live progress:
  - processed instances / total
  - processed devices / total
  - changed count / app count

Automatic:
- Cron task: `PluginJamfsyncSyncTask::PullFromJamf`
- Default frequency: 3600 seconds

Monitoring:
- `Monitoring` page from plugin configuration
- Filters by instance/entity/status/time range
- Metrics (`runs`, `processed`, `changed`, `success/error`)
- CSV export of filtered runs
- Run detail view with collapsible `Changed items`
- Changed items tabs:
  - `Devices`: changed devices including before/after JSON
  - `Apps`: changed apps including impacted devices
  - `OS`: changed OS entries including impacted devices
- All tabs use GLPI search tables (paging/sorting/integrated search)

## Sync Flow (Simplified)

1. Authenticate per active instance using Basic Auth + `X-Server-Protocol-Version: 1`
2. Pull devices with paging (`/api/devices`, optional `includeApps=true`)
3. Build signatures per device/app
4. Run delta decision (process only changed records)
5. Map each changed device to a GLPI **native inventory** document
   (`GlpiPlugin\Jamfsync\Service\InventoryPayloadBuilder`) and submit it via
   `Glpi\Inventory\Inventory` — GLPI creates/updates the Computer, OS, software
   and storage with native **history**, locks and de-duplication. The target
   entity is assigned by GLPI's own inventory entity rules (the instance's
   "target entity" is advisory only). Battery is not synced (JAMF reports a
   charge percentage, which has no lossless inventory mapping).
6. Diff a before/after computer snapshot to record device/OS/app changes for the
   monitoring UI
7. Log to `glpi_plugin_jamfsync_syncruns` and update per-instance checkpoint

## Data Model (Plugin)

- `glpi_plugin_jamfsync_instances`
- `glpi_plugin_jamfsync_syncruns`
- `glpi_plugin_jamfsync_devicemaps`
- `glpi_plugin_jamfsync_runchanges`
- `glpi_plugin_jamfsync_syncjobs` (async job status/progress)

(`glpi_plugin_jamfsync_applinks` was dropped in 0.5.0 — software links are now
managed natively by the GLPI inventory import.)

## Project Structure

- `setup.php`: installation, migration dispatcher, cron, autoloader
- `install/migrations/*`: versioned DB migrations (one file per update version)
- `front/config.form.php`: instance CRUD and run controls
- `front/sync.ajax.php`: async start/status endpoint for manual runs
- `front/monitoring.php`: monitoring, CSV export, run details
- `src/Api/JamfClient.php`: JAMF API client + paging logic
- `src/InstanceRepository.php`: instance management + checkpoint update
- `src/Service/SyncService.php`: delta logic, native inventory submission, monitoring logging
- `src/Service/InventoryPayloadBuilder.php`: maps a JAMF device to a GLPI inventory JSON document
- `src/PluginJamfsyncSyncTask.php`: cron entrypoint
- `src/Access.php`: dedicated profile right (`plugin_jamfsync_config`) + seeding
- `src/ProfileTab.php`: rights matrix tab on the Profile form
- `src/vue/*`: Vue runner sources (built to `public/js/dist/`)
- `composer.json` / `vite.config.js` / `package.json`: PSR-4 + frontend build config
- `tools/po2mo.php`: gettext `.po` -> `.mo` compiler
- `tests/Unit/*`: automated unit tests
- `tests/bootstrap.php` + `phpunit.xml.dist`: test bootstrap and suite config

## Automated Tests

A PHPUnit unit test suite is included.

Prerequisites:
- GLPI core dependencies installed (`plugins/glpi-core/vendor`)
- If `vendor/` is missing, run Composer in container first:
  - `docker compose exec -u root glpi sh -lc 'cd /var/www/glpi/plugins/glpi-core && php /var/www/glpi/composer.phar install --no-interaction'`

Run tests (from repo root):
- `plugins/glpi-core/vendor/bin/phpunit -c plugins/jamfsync/phpunit.xml.dist`

## Localization

- Default UI copy is English.
- GLPI localization is supported via plugin gettext files in `locales/`.
- Currently included (each as a `.po` source + compiled `.mo`):
  - `en_GB`
  - `de_DE`
  - `fr_FR`
- Displayed plugin labels/messages follow the language configured for the logged-in GLPI user.
- GLPI loads the compiled `.mo` files at runtime; recompile after editing a `.po`:

```bash
php tools/po2mo.php locales/de_DE.po locales/de_DE.mo
```

## Frontend (build)

The sync runner on the configuration page is a Vue 3 component that **reuses GLPI's own Vue
runtime** (`window._vue`, exposed by core's `build/vue/app.js`) instead of bundling its own.
`vite.config.js` marks `vue` as an external mapped to `window._vue`, and the bundle is
registered as a deferred JS module (`javascript_module`) so it runs after core's `app.js`.

Sources live in `src/vue/`; the built artifacts in `public/js/dist/` are committed and shipped
(GLPI does not build plugins at runtime). Server-translated labels are handed to the component via
`data-*` attributes on the mount node (no inline script, CSP-safe).

```bash
npm install
npm run build      # vite build -> public/js/dist/jamfsync-vue.{js,css}
npm run lint       # eslint over src/vue
```

## Security

- API keys are stored encrypted in the plugin database (via GLPI key management).
- Key re-encryption (rotation) is available from the configuration page.
- Disable TLS verification only in test environments.
- Access is gated by a dedicated profile right `plugin_jamfsync_config` (managed on the profile's
  *JAMF Sync* tab): READ = view monitoring, UPDATE = configure instances and run syncs. On first
  install the right is granted to every profile that currently holds the core `config` UPDATE right,
  so existing administrators keep access.

## Known Limitations

- Mapping uses defensive field heuristics (tenant data can vary).
- OS mapping currently focuses on name/version/architecture/kernel.
- No JAMF-side mutations by design (pull-only).

## Roadmap

1. Extend app reconciliation with optional retention/history.
2. Improve error classification and retry/backoff for transient API failures.
3. Expand test coverage for end-to-end sync and DB integration paths.
4. Add monitoring trends and more device-level error insights.
