# Changelog

All notable changes to the JAMF Sync plugin. Format based on
[Keep a Changelog](https://keepachangelog.com/); versions follow the plugin version in `setup.php`.

## [1.0.0] – 2026-10-22

First stable release, for GLPI 12.0.x only (GLPI 11 stays on 0.5.1, branch `glpi11`). It contains all changes
since 0.5.1 listed below; 0.6.0 to 0.10.1 were internal pre-releases and were not published. Accepted with real
JAMF School data from several schools on GLPI 12.0.0 in CLI cron mode.

### Added
- Takeover of computers that already exist in GLPI without the JAMF device's agent (data import, another
  plugin, or the JAMF instance of another school when a device changes schools): the sync registers the
  device's agent for the one computer with its serial number, so the managed import rule updates it instead of
  creating a duplicate. A serial number shared by several computers is skipped and noted in the run message.
- "Check takeover" on the instance form: dry run against JAMF and GLPI showing already synced devices,
  computers that would be taken over (current GLPI name vs. name from JAMF), new devices and ambiguous serial
  numbers. Nothing is written.

### Changed
- Moving a computer to another entity counts as a change and is logged (the snapshot includes the entity).
- The inventory tag and the managed target entity are part of the device signature, so after changing them a
  normal (delta) run resubmits the devices. The first run after the upgrade therefore resubmits all devices
  once.

### Fixed
- When one instance of a multi-instance run failed, the job was set to "error" while the other instances were
  still running, so the runner and the banner stopped showing the progress.

## [0.10.1] – 2026-10-22

### Changed
- "Changed" in the run log, the progress banner and the monitoring counts only computers whose GLPI data really
  changed (created, core fields, OS or apps). A full resync used to count every resubmitted device.

## [0.10.0] – 2026-10-22

### Fixed
- iPads that share a name in JAMF were merged into one GLPI computer: GLPI's default rule "Computer update
  (by name)" is ranked before "Computer import (by serial)", so the second device overwrote the serial of the
  first on every sync. Each instance now gets two managed asset import rules at the top of *Rules for import
  and link equipments* ("JAMF Sync: <instance> – update by serial" / "– import by serial") that match its
  devices (by inventory tag) by serial number only. They are created on install/upgrade for all instances.

### Upgrade note
- Already merged devices are separated by the next **full resync** (delta runs skip unchanged devices).

## [0.9.0] – 2026-10-22

### Added
- Retention periods on *Setup > Data and Privacy > JAMF Sync* (right `config`, like GLPI's own retention tabs):
  change details 90 days, sync runs 365 days (with their change details), mappings of devices not seen in JAMF
  "never" by default; finished sync jobs are kept 30 days. 0 keeps data forever.
- Automatic action `PurgeHistory` (daily) deleting older entries in batches; the monitoring page shows the
  periods with a link to the settings.

### Changed
- "Test connection" is now a regular, aligned field ("Connection test") of the connection section; singular
  and plural device counts in its message.

### Upgrade note
- The default periods apply right after the upgrade: the first `PurgeHistory` run deletes change details older
  than 90 days and sync runs older than 365 days. Set other values (or 0) on the new tab before that if needed.

## [0.8.0] – 2026-10-22

### Added
- Inventory tag per JAMF instance (default `jamfsync-<instance id>`), sent with every device.
- Managed GLPI entity rule per instance ("inventory tag → target entity"), placed first in
  *Administration > Rules > Rules for assigning an item to an entity*. On by default for new instances,
  opt-in for instances upgraded from older versions. The target entity is therefore no longer advisory.
- Retry of JAMF requests on HTTP 429/500/502/503/504: up to 3 retries, honouring `Retry-After`,
  otherwise 2/4/8 s backoff. Retries are noted in the run message.
- "Test connection" button on the instance form (works before saving; a blank API key uses the stored one).
- Monitoring: list of mapped devices that the last successful run no longer received from JAMF.
- Notification "Scheduled sync failed" (to the GLPI administrator address), sent when a scheduled run of an
  instance fails after a successful one.
- Automated checks: PHPUnit (34 tests), PHPStan level 5 with deprecation rules, PHP-CS-Fixer (GLPI rule set),
  ESLint, and an end-to-end suite in Docker (`tests/e2e/run.sh`); `tools/check.sh` runs them.
- Release components: `LICENSE` (GPLv3), this changelog, `tools/build-release.sh`.

### Changed
- `SyncService` split into `SyncJobQueue` (queue and worker), `ComputerSnapshot` (change diff) and
  `DeviceMapRepository`.
- Frontend toolchain: Vite 8, ESLint 10 (flat config), eslint-plugin-vue 10.

### Fixed
- The stored schema version fallback (`Config::getConfigurationValue()` has no default parameter in GLPI 12).

## [0.7.1] – 2026-10-22

### Fixed
- Plugin activation/deactivation now clears GLPI's caches: in production mode the Symfony route cache could
  miss the plugin's controller routes, so the sync runner got HTTP 404 ("Invalid JSON response").
- Version bump so browsers load the rebuilt JavaScript bundle instead of a cached one.

## [0.7.0] – 2026-10-22

### Added
- Job queue with a single worker (MySQL named lock) and the automatic action `ProcessSyncQueue`; duplicate
  requests are merged, a crashed worker is detected and its job marked as aborted.
- GLPI controller routes `Sync/Start`, `Sync/Jobs/{id}`, `Sync/Jobs/Active` (replacing `front/sync.ajax.php`);
  a manual run starts right away after the response, like GLPI's own `front/cron.php`.

### Changed
- Monitoring page rendered from a Twig template, with validated filters and translated labels.
- Automatic actions use the namespaced class `GlpiPlugin\Jamfsync\SyncTask`; existing task settings are kept.
- Plugin columns converted from `DATETIME` to `TIMESTAMP`.
- Uninstall also removes the profile right.

### Fixed
- `plugin_init` no longer re-registers the automatic action on every request (which reset a disabled or errored
  task to "waiting"); uninstall now removes the automatic action.

## [0.6.0] – 2026-10-22

### Changed
- Port to GLPI 12 (requires GLPI 12.0.x, PHP 8.3–8.5); GLPI 11 stays on 0.5.1.
- CSRF tokens removed (GLPI 12 checks `Sec-Fetch-Site`/`Origin`); `Plugin::getWebDir()`,
  `Html::displayRightError()` and `inc/includes.php` replaced; asset paths without `public/`.
- JAMF requests through `Glpi\Toolbox\HttpClient` (GLPI proxy settings, private-network protection).
- Typed `$rightname` properties (required by GLPI 12); PHP 8.5 deprecations fixed.

## [0.5.1] – 2026-07-31

### Fixed
- Per-device duplication of the OS-as-software version.

## [0.5.0] – 2026-07-31

### Changed
- Devices are written through GLPI's native inventory; persistent sync status banner.

## [0.4.0] – 2026-07-30

- Initial import.
