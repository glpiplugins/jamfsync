# JAMF Sync (Pull-Only) for GLPI

`jamfsync` synchronizes devices and applications from JAMF School into GLPI.

This plugin is strictly **pull-only**:
- no write operations to JAMF
- no push/webhook requirement
- no delete operations in JAMF

The plugin currently uses JAMF School API `https://api.zuludesk.com` with **API version 1.0**.

## Features

- Multi-instance support (multiple JAMF tenants in one GLPI)
- Scheduled (hourly) and manual sync runs, for all active instances or one instance
- Delta sync using device/app signatures; full resync on demand
- Paged fetch per instance (configurable page size)
- Devices are written through GLPI's **native inventory** (`Glpi\Inventory\Inventory`):
  computer, operating system, software, storage volume and Wi-Fi port (MAC/public IP), with
  native history, locks, de-duplication and entity rules
- Target entity per instance: every device carries the instance's inventory tag, and the plugin
  maintains a matching GLPI entity rule (optional)
- Retry with backoff for HTTP 429/5xx from JAMF (honours `Retry-After`)
- Job queue with a single worker: syncs never overlap, duplicate requests are merged, a crashed
  worker is detected and its job marked as aborted
- "Test connection" on the instance form
- Report of mapped devices that JAMF no longer returns
- Notification when a scheduled sync of an instance starts failing
- Monitoring with filters, metrics, CSV export and a run detail view with the changed items
  (devices/apps/OS, native GLPI search lists)
- Live progress banner on all plugin pages (Vue, reusing GLPI's Vue runtime)

## Requirements

- GLPI `>= 12.0.0` and `< 12.0.99` (for GLPI 11 use plugin version `0.5.1`, branch `glpi11`, tag `v0.5.1`)
- PHP 8.3 – 8.5 (as required by GLPI 12)
- Network access from GLPI server to each JAMF instance (outbound HTTPS; GLPI's proxy settings
  under `Setup > General > System` are applied)

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

### Upgrading from GLPI 11 (plugin 0.5.x)

1. Update GLPI to 12 (`php bin/console db:update`); this deactivates all plugins.
2. Replace the plugin files with the current version (or extract the release archive).
3. `php bin/console plugin:install jamfsync --username=<admin>`, then
   `php bin/console plugin:activate jamfsync`.

The upgrade to 0.7.0 (migration `0005_0.7.0.php`):
- renames the automatic action's itemtype to `GlpiPlugin\Jamfsync\SyncTask` (frequency, run mode
  and state are kept) and adds the new action `ProcessSyncQueue`;
- converts the plugin's `DATETIME` columns to `TIMESTAMP` (as GLPI's own `migration:timestamps`);
- closes sync jobs still marked queued/running by the old version (status "stalled").

The upgrade to 0.8.0 (migration `0006_0.8.0.php`) adds the instance fields `inventory_tag`,
`manage_entity_rule` (0 for existing instances) and `rules_id`, and creates the failure notification.

The upgrade to 0.9.0 adds the retention defaults and the automatic action `PurgeHistory`. The defaults apply at
once: its first run deletes change details older than 90 days and sync runs older than 365 days.

The `activate` step is required: until then GLPI keeps the plugin in "To update" state and does not
serve its JS/CSS. After replacing plugin files on a running GLPI in production mode, run
`php bin/console cache:clear` so changed Twig templates are recompiled.

## Configuration

Per JAMF instance:
- `Instance name`
- `JAMF base URL`
- `JAMF API username (Network ID)`
- `JAMF API key`
- `Target entity`, `Manage entity rule`, `Inventory tag` (see "Target entity" below)
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
- `Test connection` checks URL and credentials with the values currently in the form (also before
  saving; a blank key field uses the stored key) and reports the device count.

### Target entity

GLPI's native inventory decides the entity of an imported computer through its entity rules
(*Administration > Rules > Rules for assigning an item to an entity*). Each instance therefore sends an
**inventory tag** with every device (`Inventory tag`, default `jamfsync-<instance id>`), and with
`Manage entity rule` = Yes the plugin keeps one rule "JAMF Sync: <instance>" with the criterion
*Inventory tag is <tag>* and the action *Entity = target entity*:
- the rule is created at the top of the list; it only matches the instance's own tag, so other
  devices are not affected; it is updated when name, tag or entity change and removed with the
  instance or when the option is switched off (manual edits of the rule are overwritten);
- it applies to newly imported computers; existing computers only move to another entity when an
  *automatic entity transfer model* is configured for their current entity (GLPI behaviour);
- new instances default to Yes; instances created before 0.8.0 keep relying on your own rules until
  the option is switched on. With your own rules, use the inventory tag as criterion (or set it to the
  `TAG` of an entity and use the action *Entity from TAG*).

### Matching devices by serial number

GLPI's default asset import rules try "Computer update (by name)" before "Computer import (by serial)", so
two iPads with the same name in JAMF would end up as one computer. For every instance the plugin therefore
keeps two rules at the top of *Administration > Rules > Rules for import and link equipments*:
- "JAMF Sync: <instance> – update by serial": inventory tag of the instance and serial already present →
  link (GLPI then only considers computers whose agent belongs to the same JAMF device);
- "JAMF Sync: <instance> – import by serial": inventory tag of the instance and serial set → create.

They only match the instance's own devices; devices without serial number fall through to GLPI's default
rules.

### Existing computers (takeover) and devices changing schools

If GLPI already has a computer with the device's serial number but no agent of this JAMF device (created by a
data import or another plugin, or synced by another school's instance), the sync registers the device's agent
for that computer, so the rule above updates it instead of creating a duplicate. From then on its name, model,
OS, software, storage and Wi-Fi port are maintained from JAMF. A serial number held by several computers is
skipped and noted in the run message until the duplicates are merged or deleted. A device that moves to another
school's JAMF instance is therefore taken over by the new instance; the old instance lists it under "Devices no
longer returned by JAMF".

**Check takeover** on the instance form shows, before a sync, which devices are already synced, which existing
computers would be taken over (current GLPI name vs. name from JAMF), which devices would be new and which serial
numbers are ambiguous. It reads JAMF and GLPI only.

### Recommended first rollout

1. Create one entity per school and one instance per JAMF School tenant with its target entity.
2. Set *Model for automatic entity transfer on inventories* (entity → *Assets*) on the root entity, so existing
   and later moved computers follow the target entity.
3. Run **Check takeover** per instance; clean up duplicate serial numbers in GLPI if listed.
4. Start a **full resync** per instance (the first one with transfers takes a few minutes for a few hundred
   devices; then about a minute).
5. Run the automatic actions in CLI mode (`front/cron.php` from the system cron, as the official Docker image does). They are created on install/upgrade and with new instances, updated when name or tag change and
removed with the instance. Deactivating them in GLPI is kept. Computers merged before 0.10.0 are separated by
the next full resync.

## Operation

Manual (`Sync runner` tab, right `UPDATE`):
- `Run pull now`: delta run; `Run full resync`: full run without delta filtering
- For all active instances or the selected one
- The request only queues a job and answers at once; the queue worker is then launched in the same
  PHP process (like GLPI's own `front/cron.php`). If another automatic action holds GLPI's cron lock,
  the job waits for the next run of `ProcessSyncQueue`.
- A second click while an equal job (same mode and instance) is queued or running returns that job
  instead of queueing a duplicate.
- Progress (instances, devices, changes, apps) is shown in the banner on every plugin page.

Automatic actions (`Setup > Automatic actions`, item type "JAMF Sync"):
- `PullFromJamf` (default every 3600 s): queues a scheduled pull and works the queue
- `ProcessSyncQueue` (default every 60 s): works queued manual jobs; does nothing when the queue is
  empty
- Both use one worker lock (MySQL named lock), so two syncs never run at the same time. A job that
  stays "running" without a worker (killed process, fatal error) is marked "stalled".
- Running them in CLI mode (`front/cron.php` from the system cron, as the official GLPI Docker image
  does) is recommended. Existing settings (frequency, run mode, disabled) are kept on plugin
  upgrades.

JAMF requests that answer HTTP 429, 500, 502, 503 or 504 are retried up to three times: after the
`Retry-After` the server sends (at most 60 s), otherwise after 2, 4 and 8 s. The run message notes how
many requests were retried. Other errors (e.g. 401 for wrong credentials) fail at once.

Notification: *Setup > Notifications* contains "JAMF Sync: Scheduled sync failed" (item type
"JAMF sync run", recipient: the administrator address). It is sent when a scheduled run of an instance
fails after a successful one, not for every following hourly failure. GLPI notifications and the mail
mode must be enabled.

Retention (*Setup > Data and Privacy > JAMF Sync*, right `config`):

| Data | Default | Note |
|---|---|---|
| Change details (devices/apps/OS per run, computer names, before/after values) | 90 days | may contain personal data |
| Sync runs (instance, counts, messages) | 365 days | a run's change details go with it |
| Mappings of devices not seen in JAMF | never | GLPI keeps the computers; a returning device is mapped again |
| Finished sync jobs | 30 days | fixed |

0 keeps data forever. The automatic action `PurgeHistory` deletes older entries once a day. The computers and
their history in GLPI follow GLPI's own "Historical logs" settings on the same page.

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
- `Devices no longer returned by JAMF`: mapped devices that the last successful run of their instance
  did not receive (retired, deleted or moved in JAMF); GLPI keeps the computers

## Sync Flow (Simplified)

0. A job is queued (`glpi_plugin_jamfsync_syncjobs`, status `queued`) by the runner
   (`POST /plugins/jamfsync/Sync/Start`) or by `PullFromJamf`; the queue worker
   (`SyncService::processQueue()`) claims jobs oldest first while holding the worker lock.
1. Authenticate per active instance using Basic Auth + `X-Server-Protocol-Version: 1`
   (via `Glpi\Toolbox\HttpClient`)
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
- `glpi_plugin_jamfsync_syncjobs` (job queue: status, progress, result)

(`glpi_plugin_jamfsync_applinks` was dropped in 0.5.0 — software links are now
managed natively by the GLPI inventory import.)

## Project Structure

- `setup.php`: plugin metadata, hooks, install/uninstall (incl. notification), migration dispatcher,
  cron registration, cache reset on (de)activation
- `install/migrations/*`: versioned DB migrations (one file per update version)
- `front/config.form.php`: native search list of the instances, key re-encryption
- `front/instance.form.php`: instance create/edit/delete (native item form)
- `front/runner.php`: sync runner page (mount node for the Vue runner)
- `front/monitoring.php`: entry point of the monitoring page and CSV export
- `src/Controller/SyncController.php`: JSON routes `Sync/Start` (POST), `Sync/Jobs/{id}`,
  `Sync/Jobs/Active` (GET)
- `src/Controller/InstanceController.php`: `Instances/TestConnection` (POST)
- `src/SyncTask.php`: automatic actions `PullFromJamf`, `ProcessSyncQueue` and `PurgeHistory`
- `src/Service/SyncJobQueue.php`: job queue and its single worker (lock, orphan detection)
- `src/Service/SyncService.php`: one sync run: delta logic, native inventory submission, run log,
  failure notification
- `src/Service/ComputerSnapshot.php`: before/after snapshot of a computer and the change entries
- `src/Service/InventoryPayloadBuilder.php`: maps a JAMF device to a GLPI inventory document (incl. tag)
- `src/Service/EntityRuleManager.php`, `src/Service/ImportRuleManager.php`: managed entity rule and
  asset import rules per instance
- `src/Api/JamfClient.php`: JAMF API client (GLPI `HttpClient`, retry/backoff, paging, connection test)
- `src/InstanceRepository.php`, `src/DeviceMapRepository.php`: instances, device mapping, missing devices
- `src/Ui/MonitoringPage.php` + `templates/monitoring.html.twig`: monitoring data and view
- `src/Ui/ConfigMenu.php` + `templates/config_menu.html.twig`: tab bar and status banner node
- `src/Ui/PrivacyTab.php` + `templates/privacy_tab.html.twig`, `src/Controller/RetentionController.php`,
  `src/Service/RetentionPolicy.php`: retention tab on Data and Privacy, saving, purge
- `src/Instance.php`, `src/RunChange.php`, `src/SyncRun.php`: item types (search lists, notification)
- `src/NotificationTargetSyncRun.php`: notification event "Scheduled sync failed" and its tags
- `src/Access.php`: dedicated profile right (`plugin_jamfsync_config`) + seeding
- `src/ProfileTab.php`: rights matrix tab on the Profile form
- `src/vue/*`: Vue runner, status banner and form helpers (built to `public/js/dist/`)
- `tests/Unit/*`, `tests/e2e/*`: unit and end-to-end tests
- `tools/check.sh`, `tools/build-release.sh`, `tools/po2mo.php`: checks, release archive, `.po` -> `.mo`

## Automated Tests

`tools/check.sh` runs all checks; the PHP tools run in the official `glpi/glpi:12.0.0` image (PHP 8.5),
so the host needs Docker, Node.js ≥ 22.12, curl and python3:

```bash
GLPI_CORE_DIR=<GLPI checkout with composer dev dependencies> tools/check.sh          # static + unit
GLPI_CORE_DIR=... tools/check.sh --e2e                                                 # + end-to-end
```

- **PHPUnit** (`tests/Unit`, 34 tests): pure logic without database (JAMF client incl. retry and
  pagination through a fake transport, inventory payload, entity rule, change diff, monitoring filters).
- **PHPStan** level 5 with deprecation rules against the GLPI 12 sources (`phpstan.neon.dist`).
- **PHP-CS-Fixer** with GLPI's rule set (`.php-cs-fixer.dist.php`), **ESLint** for `src/vue`, and a
  check that the committed bundle matches the sources.
- **End-to-end** (`tests/e2e/run.sh`, about 3 minutes): starts GLPI 12, MariaDB and a JAMF mock in
  Docker and checks fresh install, entity rule and sync into the target entity, connection test,
  retry on 503/429, missing devices, notification, access/CSRF, uninstall and the upgrade from 0.5.1
  (fixtures in `tests/e2e/fixtures`, fictional data). `E2E_KEEP=1` keeps the environment.

`GLPI_CORE_DIR` only provides the tool binaries (PHPUnit, PHPStan, PHP-CS-Fixer); in the local
setup that is `glpi11/plugins/glpi-core`.

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
npm ci             # Node.js >= 22.12 (Vite 8, ESLint 10)
npm run build      # vite build -> public/js/dist/jamfsync-vue.{js,css}
npm run lint       # eslint over src/vue (flat config eslint.config.js)
```

GLPI caches plugin assets for 30 days keyed by the plugin version: bump `PLUGIN_JAMFSYNC_VERSION`
whenever a changed bundle is deployed.

## Release

`tools/build-release.sh` rebuilds the bundle and the `.mo` files and writes
`build/jamfsync-<version>.tar.gz` (+ `.sha256`) with the runtime files only (`setup.php`, `hook.php`,
`front`, `install`, `locales`, `public`, `src` without `src/vue`, `templates`, README, CHANGELOG,
LICENSE). Extract it into GLPI's `plugins/` directory. `git archive` excludes the same development
files (`.gitattributes`).

## Security

- API keys are stored encrypted in the plugin database (via GLPI key management).
- Key re-encryption (rotation) is available from the configuration page.
- Disable TLS verification only in test environments.
- JAMF requests go through GLPI's `Glpi\Toolbox\HttpClient` with the context
  `GlpiPlugin\Jamfsync\Instance`. GLPI blocks server-side requests to private networks by default,
  so a JAMF URL that resolves to a private address (e.g. a local test mock) fails with "blocked".
  Allow it explicitly in `config/local_define.php` only where needed:
  `define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [Agent::class, Auth::class, GlpiPlugin\Jamfsync\Instance::class]);`
- CSRF protection relies on GLPI 12's header check (`Sec-Fetch-Site` / `Origin`); the plugin no longer
  sends CSRF tokens.
- Access is gated by a dedicated profile right `plugin_jamfsync_config` (managed on the profile's
  *JAMF Sync* tab): READ = view monitoring, UPDATE = configure instances and run syncs. On first
  install the right is granted to every profile that currently holds the core `config` UPDATE right,
  so existing administrators keep access.

## Known Limitations

- Mapping uses defensive field heuristics (tenant data can vary).
- OS mapping currently focuses on name/version/architecture/kernel.
- No JAMF-side mutations by design (pull-only).

## Status

1.0.0 is the first stable release for GLPI 12.0.x. It was accepted with real JAMF School data from several
schools (several hundred devices per instance) on GLPI 12.0.0 in CLI cron mode, including the takeover of
existing computers, the target entity with an automatic transfer model, the failure notification and the
retention purge. Changes are listed in [CHANGELOG.md](CHANGELOG.md).
