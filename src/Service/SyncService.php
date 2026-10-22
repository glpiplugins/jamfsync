<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Glpi\Inventory\Inventory;
use GlpiPlugin\Jamfsync\Api\JamfClient;
use GlpiPlugin\Jamfsync\DeviceMapRepository;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\NotificationTargetSyncRun;
use GlpiPlugin\Jamfsync\SyncRun;
use NotificationEvent;
use RuntimeException;

/**
 * Runs one sync job: pulls the JAMF devices of the matching active instances, submits the
 * changed ones to GLPI's native inventory and logs runs and changes for the monitoring.
 */
class SyncService
{
    private SyncJobQueue $jobs;
    private DeviceMapRepository $deviceMaps;
    private ComputerSnapshot $snapshots;
    private ComputerAdoption $adoption;

    public function __construct(
        ?SyncJobQueue $jobs = null,
        ?DeviceMapRepository $deviceMaps = null,
        ?ComputerSnapshot $snapshots = null,
        ?ComputerAdoption $adoption = null
    ) {
        $this->jobs = $jobs ?? new SyncJobQueue();
        $this->deviceMaps = $deviceMaps ?? new DeviceMapRepository();
        $this->snapshots = $snapshots ?? new ComputerSnapshot();
        $this->adoption = $adoption ?? new ComputerAdoption();
    }

    public function run(string $mode = 'manual', int $onlyInstanceId = 0, int $jobId = 0): array
    {
        $repository = new InstanceRepository();
        $instances = $repository->getActive();
        if ($onlyInstanceId > 0) {
            $instances = array_values(array_filter($instances, static fn(array $row): bool => (int) ($row['id'] ?? 0) === $onlyInstanceId));
        }

        if ($instances === []) {
            if ($jobId > 0) {
                $this->jobs->update($jobId, [
                    'status' => 'error',
                    'error_code' => 'no_matching_instances',
                    'error_message' => __('No matching active JAMF instance configured.', 'jamfsync'),
                    'message' => __('No matching active JAMF instance configured.', 'jamfsync'),
                    'finished_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                    'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
            }
            return [
                'status' => 'error',
                'message' => __('No active JAMF instance configured.', 'jamfsync'),
                'devices_count' => 0,
                'apps_count' => 0,
            ];
        }

        $isFull = str_contains($mode, 'full');
        $totalDevices = 0;
        $totalApps = 0;
        $failed = 0;
        $globalChanged = 0;
        $globalProcessedDevices = 0;
        $globalDiscoveredDevices = 0;
        $totalInstances = count($instances);

        if ($jobId > 0) {
            $now = date('Y-m-d H:i:s'); // real wall-clock for job timing (a worker run freezes glpi_currenttime)
            $this->jobs->update($jobId, [
                'status' => 'running',
                'mode' => $mode,
                'is_full' => $isFull ? 1 : 0,
                'instance_id' => $onlyInstanceId,
                'total_instances' => $totalInstances,
                'processed_instances' => 0,
                'total_devices' => 0,
                'processed_devices' => 0,
                'changed_count' => 0,
                'apps_count' => 0,
                'error_code' => '',
                'error_message' => null,
                'message' => __('Sync started', 'jamfsync'),
                'started_at' => $now,
                'date_mod' => $now,
            ]);
        }

        foreach ($instances as $instanceIndex => $instance) {
            $startedAt = date('Y-m-d H:i:s'); // real wall-clock so the logged run shows a real duration
            $devicesCount = 0;
            $appsCount = 0;
            $processedCount = 0;
            $changedCount = 0;
            $adopted = 0;
            $ambiguous = 0;
            $changeEntries = [];
            $status = 'success';
            $message = __('JAMF pull completed.', 'jamfsync');
            $cursorStart = (string) ($instance['last_cursor'] ?? '');
            $cursorEnd = $cursorStart;
            $client = null;

            try {
                if ($jobId > 0) {
                    // instance_id keeps the requested scope (0 = all instances; used to detect
                    // duplicate requests); instance_name shows the instance being processed.
                    $this->jobs->update($jobId, [
                        'instance_name' => (string) ($instance['name'] ?? ''),
                        'message' => sprintf(__('Processing instance: %s', 'jamfsync'), (string) ($instance['name'] ?? '')),
                        'processed_instances' => $instanceIndex,
                        'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                    ]);
                }

                $includeApps = (int) ($instance['pull_apps'] ?? 0) === 1;
                $pullDevices = (int) ($instance['pull_devices'] ?? 1) === 1;
                $pageSize = (int) ($instance['page_size'] ?? 200);

                $client = new JamfClient($instance);
                // Skip the (paginated) JAMF fetch entirely when the instance pulls neither
                // devices nor apps — nothing would be written.
                $paged = ($pullDevices || $includeApps)
                    ? $client->pullDevicesPaged($includeApps, $pageSize)
                    : ['devices' => [], 'cursor_end' => $cursorStart];
                $devices = $paged['devices'] ?? [];
                $cursorEnd = (string) ($paged['cursor_end'] ?? $cursorStart);

                $processedCount = count($devices);
                $globalDiscoveredDevices += $processedCount;

                foreach ($devices as $device) {
                    if (!is_array($device)) {
                        continue;
                    }

                    $identifiers = InventoryPayloadBuilder::identifiers($device);
                    $map = $this->deviceMaps->find(
                        (int) ($instance['id'] ?? 0),
                        $identifiers['device_id'],
                        $identifiers['udid'],
                        $identifiers['serial']
                    );

                    $deviceSignature = $this->buildDeviceSignature($instance, $device, $identifiers);
                    $appsSignature = $includeApps
                        ? $this->buildAppsSignature(InventoryPayloadBuilder::extractApps($device))
                        : (string) ($map['apps_signature'] ?? '');

                    $deviceChanged = $isFull || !$map || (string) ($map['device_signature'] ?? '') !== $deviceSignature;
                    $appsChanged = $includeApps && ($isFull || !$map || (string) ($map['apps_signature'] ?? '') !== $appsSignature);

                    $computerId = (int) ($map['computers_id'] ?? 0);
                    $needsSubmit = $pullDevices && ($deviceChanged || $appsChanged || $computerId <= 0);

                    // pull_devices disabled, or unchanged since last run: only refresh the map.
                    // (With native inventory, software cannot be attached without submitting the
                    // computer, so pull_devices=0 means nothing is written for this device.)
                    if (!$needsSubmit) {
                        $this->deviceMaps->upsert(
                            (int) ($instance['id'] ?? 0),
                            $identifiers,
                            $computerId,
                            (int) ($instance['entities_id'] ?? 0),
                            $deviceSignature,
                            $appsSignature,
                            InventoryPayloadBuilder::extractLastCheckinAt($device),
                            $startedAt
                        );
                        $globalProcessedDevices++;
                        continue;
                    }

                    $payload = InventoryPayloadBuilder::build($device, $instance, $identifiers, $includeApps);

                    // An existing computer without this device's agent (data import, other
                    // plugin, other school's instance) is taken over by serial number; a serial
                    // shared by several computers is skipped rather than guessed.
                    $adoption = $this->adoption->prepare((string) $payload->deviceid, (string) $payload->tag, $identifiers['serial']);
                    if ($adoption['state'] === ComputerAdoption::AMBIGUOUS) {
                        $ambiguous++;
                        $globalProcessedDevices++;
                        continue;
                    }
                    if ($adoption['state'] === ComputerAdoption::ADOPT) {
                        $adopted++;
                        $computerId = $adoption['computers_id'];
                    }

                    $wasNew = $computerId <= 0;
                    $before = $computerId > 0 ? $this->snapshots->build($computerId) : [];

                    // Native GLPI inventory submission: creates/updates the Computer + OS +
                    // software + storage with native history, locks and de-duplication. The
                    // entity follows GLPI's entity rules (see EntityRuleManager).
                    // GLPI's inventory expects a decoded object (stdClass), not a JSON string.
                    $inventory = new Inventory($payload);
                    if ($inventory->inError()) {
                        throw new RuntimeException(sprintf(
                            __('Inventory import failed: %s', 'jamfsync'),
                            implode('; ', array_map('strval', (array) $inventory->getErrors()))
                        ));
                    }

                    $computerId = $this->resolveComputerAfterInventory(
                        (string) $payload->deviceid,
                        $identifiers['serial'],
                        $identifiers['uuid']
                    );
                    if ($includeApps) {
                        $appsCount += count(InventoryPayloadBuilder::extractApps($device));
                    }

                    $after = $computerId > 0 ? $this->snapshots->build($computerId) : [];
                    $displayName = InventoryPayloadBuilder::resolveComputerNameFromDevice($instance, $device);
                    $entries = ComputerSnapshot::diff($wasNew, $before, $after, $identifiers, $computerId, $displayName);
                    // "Changed" counts computers whose GLPI data really changed (created, core
                    // fields, OS or apps), not every device a full resync submits again.
                    if ($entries !== []) {
                        $changedCount++;
                    }
                    foreach ($entries as $entry) {
                        $changeEntries[] = $entry;
                    }

                    $this->deviceMaps->upsert(
                        (int) ($instance['id'] ?? 0),
                        $identifiers,
                        $computerId,
                        (int) ($instance['entities_id'] ?? 0),
                        $deviceSignature,
                        $appsSignature,
                        InventoryPayloadBuilder::extractLastCheckinAt($device),
                        $startedAt
                    );

                    $globalProcessedDevices++;
                    if ($jobId > 0 && ($globalProcessedDevices % 10 === 0 || $globalProcessedDevices === $globalDiscoveredDevices)) {
                        $this->jobs->update($jobId, [
                            'total_devices' => $globalDiscoveredDevices,
                            'processed_devices' => $globalProcessedDevices,
                            'changed_count' => $globalChanged + $changedCount,
                            'apps_count' => $totalApps + $appsCount,
                            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                        ]);
                    }
                }

                if ($pullDevices) {
                    $devicesCount = $processedCount;
                }

            } catch (\Throwable $exception) {
                // Broad catch: a single inventory/import failure must still be logged as a
                // sync run (visible in Monitoring) and mark the job as errored, instead of
                // killing the async worker silently (which would leave the job "stalled").
                $status = 'error';
                $message = $exception->getMessage();
                $failed++;
                if ($jobId > 0) {
                    // No status change here: the job goes on with the next instance and gets
                    // its final status (error, since $failed > 0) at the end.
                    $this->jobs->update($jobId, [
                        'error_code' => 'instance_sync_failed',
                        'error_message' => $message,
                        'message' => $message,
                        'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                    ]);
                }
            }

            if ($adopted > 0) {
                $message .= ' ' . sprintf(
                    _n('%d existing computer was taken over.', '%d existing computers were taken over.', $adopted, 'jamfsync'),
                    $adopted
                );
            }
            if ($ambiguous > 0) {
                $message .= ' ' . sprintf(
                    _n(
                        '%d device was skipped: its serial number belongs to several computers in GLPI.',
                        '%d devices were skipped: their serial numbers belong to several computers in GLPI.',
                        $ambiguous,
                        'jamfsync'
                    ),
                    $ambiguous
                );
            }

            $retries = $client?->getRetryCount() ?? 0;
            if ($retries > 0) {
                $message .= ' ' . sprintf(
                    _n('%d JAMF request was retried.', '%d JAMF requests were retried.', $retries, 'jamfsync'),
                    $retries
                );
            }

            $finishedAt = date('Y-m-d H:i:s'); // real wall-clock so the logged run shows a real duration
            if ($status === 'success') {
                $repository->updateCheckpoint((int) ($instance['id'] ?? 0), $finishedAt, $cursorEnd);
            }

            $runId = $this->logRun(
                $instance,
                $mode,
                $status,
                $message,
                $devicesCount,
                $appsCount,
                $processedCount,
                $changedCount,
                $isFull,
                $cursorStart,
                $cursorEnd,
                $startedAt,
                $finishedAt
            );

            if ($changeEntries !== []) {
                $this->logRunChanges($runId, $instance, $changeEntries, $startedAt, $finishedAt);
            }

            if ($mode === 'cron' && $status === 'error') {
                $this->notifyScheduledFailure((int) ($instance['id'] ?? 0), $runId);
            }

            $totalDevices += $devicesCount;
            $totalApps += $appsCount;
            $globalChanged += $changedCount;

            if ($jobId > 0) {
                $this->jobs->update($jobId, [
                    'processed_instances' => $instanceIndex + 1,
                    'total_devices' => $globalDiscoveredDevices,
                    'processed_devices' => $globalProcessedDevices,
                    'changed_count' => $globalChanged,
                    'apps_count' => $totalApps,
                    'last_run_id' => $runId,
                    'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }

        $status = $failed > 0 ? 'error' : 'success';
        $message = $failed > 0
            ? sprintf(__('JAMF pull finished with errors (%d failed instance(s)).', 'jamfsync'), $failed)
            : sprintf(__('JAMF pull completed for %d instance(s).', 'jamfsync'), count($instances));

        if ($jobId > 0) {
            $now = date('Y-m-d H:i:s'); // real wall-clock for job timing (a worker run freezes glpi_currenttime)
            $this->jobs->update($jobId, [
                'status' => $status,
                'error_code' => $status === 'success' ? '' : 'sync_failed',
                'error_message' => $status === 'success' ? null : $message,
                'message' => $message,
                'finished_at' => $now,
                'processed_instances' => $totalInstances,
                'total_devices' => max($globalDiscoveredDevices, $globalProcessedDevices),
                'processed_devices' => $globalProcessedDevices,
                'changed_count' => $globalChanged,
                'apps_count' => $totalApps,
                'date_mod' => $now,
            ]);
        }

        return [
            'status' => $status,
            'message' => $message,
            'devices_count' => $totalDevices,
            'apps_count' => $totalApps,
        ];
    }

    private function buildDeviceSignature(array $instance, array $device, array $identifiers): string
    {
        $signatureData = [
            'device_id' => $identifiers['device_id'],
            'udid' => $identifiers['udid'],
            'serial' => $identifiers['serial'],
            'uuid' => $identifiers['uuid'],
            'name' => InventoryPayloadBuilder::resolveComputerNameFromDevice($instance, $device),
            'name_source' => (string) ($instance['name_source'] ?? 'device_name'),
            // Changing the tag or the managed target entity resubmits the devices, so the
            // entity rule (and an automatic entity transfer) applies without a full resync.
            'inventory_tag' => InventoryPayloadBuilder::inventoryTag($instance),
            'target_entity' => (int) ($instance['manage_entity_rule'] ?? 0) === 1 ? (int) ($instance['entities_id'] ?? 0) : null,
            'manufacturer' => InventoryPayloadBuilder::extractNestedScalar($device, ['manufacturer', 'vendor', 'hardware.manufacturer']),
            'model' => InventoryPayloadBuilder::extractNestedScalar($device, ['model', 'modelName', 'hardware.model']),
            'os_name' => InventoryPayloadBuilder::extractNestedScalar($device, ['os.prefix', 'osName', 'os.name', 'operatingSystem', 'platform']),
            'os_version' => InventoryPayloadBuilder::extractNestedScalar($device, ['osVersion', 'os.version', 'operatingSystemVersion', 'systemVersion']),
            'location' => InventoryPayloadBuilder::extractNestedScalar($device, ['location', 'location.name', 'site', 'site.name']),
            'owner' => InventoryPayloadBuilder::extractNestedScalar($device, ['ownerName', 'owner.name', 'user.name']),
            'email' => strtolower(InventoryPayloadBuilder::extractNestedScalar($device, ['email', 'user.email', 'ownerEmail'])),
            'ip' => InventoryPayloadBuilder::extractNestedScalar($device, ['ip', 'ipAddress', 'network.ipAddress']),
            'asset_tag' => InventoryPayloadBuilder::extractNestedScalar($device, ['assetTag', 'asset_tag']),
            'last_active' => InventoryPayloadBuilder::extractLastActiveAt($device),
            'last_checkin' => InventoryPayloadBuilder::extractLastCheckinAt($device),
            'enrollment_date' => InventoryPayloadBuilder::extractEnrollmentDate($device),
            'public_ip' => InventoryPayloadBuilder::extractPublicIp($device),
            'mac' => InventoryPayloadBuilder::extractMacAddress($device),
            'battery_percent' => InventoryPayloadBuilder::extractBatteryPercent($device),
            'storage_total' => InventoryPayloadBuilder::extractStorageTotalBytes($device),
            'storage_free' => InventoryPayloadBuilder::extractStorageFreeBytes($device),
        ];

        return hash('sha256', json_encode($signatureData));
    }

    private function buildAppsSignature(array $apps): string
    {
        $normalized = [];
        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }

            $name = trim((string) ($app['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $version = trim((string) ($app['version'] ?? 'unknown'));
            $normalized[] = strtolower($name) . '::' . strtolower($version);
        }

        sort($normalized);
        return hash('sha256', implode('|', $normalized));
    }

    /**
     * Resolve the GLPI computer id after an inventory submission. Preferred link is the
     * inventory Agent record (deviceid -> items_id); falls back to serial/uuid. The lookup
     * is entity-agnostic because GLPI assigns the entity natively (which may differ from the
     * instance's advisory target entity).
     */
    private function resolveComputerAfterInventory(string $deviceid, string $serial, string $uuid): int
    {
        global $DB;

        if ($deviceid !== '' && $DB->tableExists('glpi_agents')) {
            $agent = $DB->request([
                'FROM' => 'glpi_agents',
                'WHERE' => ['deviceid' => $deviceid, 'itemtype' => 'Computer'],
                'LIMIT' => 1,
            ])->current();
            if ($agent && (int) ($agent['items_id'] ?? 0) > 0) {
                return (int) $agent['items_id'];
            }
        }

        foreach ([['serial', $serial], ['uuid', $uuid]] as [$field, $value]) {
            if ($value === '') {
                continue;
            }
            $row = $DB->request([
                'FROM' => 'glpi_computers',
                'WHERE' => [$field => $value, 'is_deleted' => 0, 'is_template' => 0],
                'ORDER' => ['id DESC'],
                'LIMIT' => 1,
            ])->current();
            if ($row) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }

    private function logRun(
        array $instance,
        string $mode,
        string $status,
        string $message,
        int $devicesCount,
        int $appsCount,
        int $processedCount,
        int $changedCount,
        bool $isFull,
        string $cursorStart,
        string $cursorEnd,
        string $startedAt,
        string $finishedAt
    ): int {
        global $DB;

        $DB->insert('glpi_plugin_jamfsync_syncruns', [
            'jamf_instances_id' => (int) ($instance['id'] ?? 0),
            'instance_name' => (string) ($instance['name'] ?? ''),
            'entities_id' => (int) ($instance['entities_id'] ?? 0),
            'status' => $status,
            'mode' => $mode,
            'message' => $message,
            'devices_count' => $devicesCount,
            'apps_count' => $appsCount,
            'processed_count' => $processedCount,
            'changed_count' => $changedCount,
            'is_full' => $isFull ? 1 : 0,
            'cursor_start' => $cursorStart,
            'cursor_end' => $cursorEnd,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'date_creation' => $startedAt,
            'date_mod' => $finishedAt,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * Raise the GLPI notification "Scheduled sync failed" when a scheduled run of an instance
     * fails after a successful one, not again for every hourly repetition of the same failure.
     */
    private function notifyScheduledFailure(int $instanceId, int $runId): void
    {
        global $DB;

        if ($runId <= 0) {
            return;
        }

        $previous = $DB->request([
            'SELECT' => ['status'],
            'FROM' => SyncRun::getTable(),
            'WHERE' => [
                'jamf_instances_id' => $instanceId,
                'mode' => 'cron',
                'id' => ['<', $runId],
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();
        if ($previous && ($previous['status'] ?? '') === 'error') {
            return;
        }

        $run = new SyncRun();
        if ($run->getFromDB($runId)) {
            NotificationEvent::raiseEvent(NotificationTargetSyncRun::EVENT_FAILED, $run);
        }
    }

    private function logRunChanges(int $runId, array $instance, array $changeEntries, string $startedAt, string $finishedAt): void
    {
        global $DB;

        if ($runId <= 0 || !$DB->tableExists('glpi_plugin_jamfsync_runchanges')) {
            return;
        }

        $maxEntries = 5000;
        $count = 0;

        foreach ($changeEntries as $entry) {
            if ($count >= $maxEntries) {
                break;
            }

            $DB->insert('glpi_plugin_jamfsync_runchanges', [
                'syncruns_id' => $runId,
                'jamf_instances_id' => (int) ($instance['id'] ?? 0),
                'change_type' => (string) ($entry['change_type'] ?? 'device'),
                'jamf_device_id' => (string) ($entry['jamf_device_id'] ?? ''),
                'computers_id' => (int) ($entry['computers_id'] ?? 0),
                'computer_name' => (string) ($entry['computer_name'] ?? ''),
                'object_name' => (string) ($entry['object_name'] ?? ''),
                'details' => (string) ($entry['details'] ?? ''),
                'before_json' => $entry['before_json'] ?? null,
                'after_json' => $entry['after_json'] ?? null,
                'impacted_devices' => $entry['impacted_devices'] ?? null,
                'date_creation' => $startedAt,
                'date_mod' => $finishedAt,
            ]);
            $count++;
        }
    }
}
