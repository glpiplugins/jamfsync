<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use GlpiPlugin\Jamfsync\Api\JamfClient;
use GlpiPlugin\Jamfsync\InstanceRepository;
use RuntimeException;

class SyncService
{
    public function run(string $mode = 'manual', int $onlyInstanceId = 0, int $jobId = 0): array
    {
        $repository = new InstanceRepository();
        $instances = $repository->getActive();
        if ($onlyInstanceId > 0) {
            $instances = array_values(array_filter($instances, static fn(array $row): bool => (int) ($row['id'] ?? 0) === $onlyInstanceId));
        }

        if ($instances === []) {
            if ($jobId > 0) {
                $this->updateSyncJob($jobId, [
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
            $now = date('Y-m-d H:i:s'); // real wall-clock for job timing (async run freezes glpi_currenttime)
            $this->updateSyncJob($jobId, [
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
            $changeEntries = [];
            $status = 'success';
            $message = __('JAMF pull completed.', 'jamfsync');
            $cursorStart = (string) ($instance['last_cursor'] ?? '');
            $cursorEnd = $cursorStart;

            try {
                if ($jobId > 0) {
                    $this->updateSyncJob($jobId, [
                        'instance_id' => (int) ($instance['id'] ?? 0),
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

                    $identifiers = $this->resolveDeviceIdentifiers($device);
                    $map = $this->findDeviceMap(
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
                        $this->upsertDeviceMap(
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

                    $wasNew = $computerId <= 0;
                    $before = $computerId > 0 ? $this->buildComputerSnapshot($computerId) : [];

                    // Native GLPI inventory submission: creates/updates the Computer + OS +
                    // software + storage with native history, locks and de-duplication. The
                    // target entity is left to GLPI's own inventory entity rules / default.
                    $payload = InventoryPayloadBuilder::build($device, $instance, $identifiers, $includeApps);
                    // GLPI's inventory expects a decoded object (stdClass), not a JSON string.
                    $inventory = new \Glpi\Inventory\Inventory($payload);
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
                    $changedCount++;
                    if ($includeApps) {
                        $appsCount += count(InventoryPayloadBuilder::extractApps($device));
                    }

                    $after = $computerId > 0 ? $this->buildComputerSnapshot($computerId) : [];
                    $displayName = InventoryPayloadBuilder::resolveComputerNameFromDevice($instance, $device);
                    foreach ($this->diffSnapshots($wasNew, $before, $after, $identifiers, $computerId, $displayName) as $entry) {
                        $changeEntries[] = $entry;
                    }

                    $this->upsertDeviceMap(
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
                        $this->updateSyncJob($jobId, [
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
                    $this->updateSyncJob($jobId, [
                        'status' => 'error',
                        'error_code' => 'instance_sync_failed',
                        'error_message' => $message,
                        'message' => $message,
                        'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                    ]);
                }
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

            $totalDevices += $devicesCount;
            $totalApps += $appsCount;
            $globalChanged += $changedCount;

            if ($jobId > 0) {
                $this->updateSyncJob($jobId, [
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
            $now = date('Y-m-d H:i:s'); // real wall-clock for job timing (async run freezes glpi_currenttime)
            $this->updateSyncJob($jobId, [
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

    private function findDeviceMap(int $instanceId, string $deviceId, string $udid, string $serial): ?array
    {
        global $DB;

        if ($deviceId !== '') {
            $map = $DB->request([
                'FROM' => 'glpi_plugin_jamfsync_devicemaps',
                'WHERE' => [
                    'jamf_instances_id' => $instanceId,
                    'jamf_device_id' => $deviceId,
                ],
                'LIMIT' => 1,
            ])->current();
            if ($map) {
                return $map;
            }
        }

        if ($udid !== '') {
            $map = $DB->request([
                'FROM' => 'glpi_plugin_jamfsync_devicemaps',
                'WHERE' => [
                    'jamf_instances_id' => $instanceId,
                    'jamf_udid' => $udid,
                ],
                'LIMIT' => 1,
            ])->current();
            if ($map) {
                return $map;
            }
        }

        if ($serial !== '') {
            $map = $DB->request([
                'FROM' => 'glpi_plugin_jamfsync_devicemaps',
                'WHERE' => [
                    'jamf_instances_id' => $instanceId,
                    'jamf_serial' => $serial,
                ],
                'LIMIT' => 1,
            ])->current();
            if ($map) {
                return $map;
            }
        }

        return null;
    }

    private function upsertDeviceMap(
        int $instanceId,
        array $identifiers,
        int $computerId,
        int $entitiesId,
        string $deviceSignature,
        string $appsSignature,
        string $lastCheckinAt,
        string $seenAt
    ): void {
        global $DB;

        $where = [
            'jamf_instances_id' => $instanceId,
            'jamf_device_id' => $identifiers['device_id'],
        ];

        $payload = [
            'jamf_instances_id' => $instanceId,
            'jamf_device_id' => $identifiers['device_id'],
            'jamf_udid' => $identifiers['udid'],
            'jamf_serial' => $identifiers['serial'],
            'computers_id' => $computerId,
            'entities_id' => $entitiesId,
            'device_signature' => $deviceSignature,
            'apps_signature' => $appsSignature,
            'last_seen_at' => $seenAt,
            'last_checkin_at' => $lastCheckinAt !== '' ? $lastCheckinAt : null,
            'date_mod' => $seenAt,
        ];

        $existing = $DB->request([
            'FROM' => 'glpi_plugin_jamfsync_devicemaps',
            'WHERE' => $where,
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            $DB->update('glpi_plugin_jamfsync_devicemaps', $payload, ['id' => (int) $existing['id']]);
            return;
        }

        $payload['date_creation'] = $seenAt;
        $DB->insert('glpi_plugin_jamfsync_devicemaps', $payload);
    }

    private function resolveDeviceIdentifiers(array $device): array
    {
        $deviceId = InventoryPayloadBuilder::extractScalar($device, ['id', 'deviceId', 'deviceID', 'device_id']);
        $udid = InventoryPayloadBuilder::extractScalar($device, ['udid', 'UDID']);
        $serial = InventoryPayloadBuilder::extractScalar($device, ['serialNumber', 'serialnumber', 'serial']);
        $uuid = InventoryPayloadBuilder::extractScalar($device, ['uuid']);

        if ($deviceId === '') {
            $deviceId = $udid !== ''
                ? 'udid:' . $udid
                : ($serial !== '' ? 'serial:' . $serial : 'hash:' . substr(sha1(json_encode($device)), 0, 24));
        }

        return [
            'device_id' => $deviceId,
            'udid' => $udid,
            'serial' => $serial,
            'uuid' => $uuid,
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

    /**
     * Build monitoring run-change entries by diffing the computer snapshot taken before and
     * after the inventory submission (device core fields, OS, and dynamic software list).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array{device_id: string, udid: string, serial: string, uuid: string} $identifiers
     * @return array<int, array<string, mixed>>
     */
    private function diffSnapshots(bool $wasNew, array $before, array $after, array $identifiers, int $computerId, string $name): array
    {
        $deviceId = (string) ($identifiers['device_id'] ?? '');
        $entries = [];

        $coreBefore = $before;
        $coreAfter = $after;
        unset($coreBefore['os'], $coreBefore['software'], $coreAfter['os'], $coreAfter['software']);

        if ($wasNew || $coreBefore != $coreAfter) {
            $entries[] = [
                'change_type' => 'device',
                'jamf_device_id' => $deviceId,
                'computers_id' => $computerId,
                'computer_name' => $name,
                'object_name' => $name,
                'details' => $wasNew ? __('Computer created', 'jamfsync') : __('Computer updated', 'jamfsync'),
                'before_json' => $this->encodeJsonForLog($wasNew ? [] : $coreBefore),
                'after_json' => $this->encodeJsonForLog($coreAfter),
                'impacted_devices' => null,
            ];
        }

        $osBefore = (array) ($before['os'] ?? []);
        $osAfter = (array) ($after['os'] ?? []);
        if ($osAfter !== [] && $osBefore != $osAfter) {
            $osName = trim((string) ($osAfter['name'] ?? '') . ' ' . (string) ($osAfter['version'] ?? ''));
            $entries[] = [
                'change_type' => 'os',
                'jamf_device_id' => $deviceId,
                'computers_id' => $computerId,
                'computer_name' => $name,
                'object_name' => $osName !== '' ? $osName : __('OS changed', 'jamfsync'),
                'details' => __('OS changed', 'jamfsync'),
                'before_json' => $this->encodeJsonForLog($osBefore),
                'after_json' => $this->encodeJsonForLog($osAfter),
                'impacted_devices' => null,
            ];
        }

        // For a brand-new computer, every app would be a spurious "added" entry (dozens per
        // device) — that is both noise and a memory hog on large first imports. The single
        // "Computer created" entry above already conveys it; only log per-app deltas for
        // computers that already existed.
        if (!$wasNew) {
            $swBefore = (array) ($before['software'] ?? []);
            $swAfter = (array) ($after['software'] ?? []);
            foreach (array_values(array_diff($swAfter, $swBefore)) as $app) {
                $entries[] = $this->appChangeEntry($deviceId, $computerId, $name, (string) $app, __('App added', 'jamfsync'));
            }
            foreach (array_values(array_diff($swBefore, $swAfter)) as $app) {
                $entries[] = $this->appChangeEntry($deviceId, $computerId, $name, (string) $app, __('App removed', 'jamfsync'));
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function appChangeEntry(string $deviceId, int $computerId, string $name, string $appLabel, string $details): array
    {
        return [
            'change_type' => 'app',
            'jamf_device_id' => $deviceId,
            'computers_id' => $computerId,
            'computer_name' => $name,
            'object_name' => $appLabel,
            'details' => $details,
            'before_json' => null,
            'after_json' => null,
            'impacted_devices' => null,
        ];
    }

    private function buildComputerSnapshot(int $computerId): array
    {
        global $DB;

        if ($computerId <= 0) {
            return [];
        }

        $computer = $DB->request([
            'FROM' => 'glpi_computers',
            'WHERE' => ['id' => $computerId],
            'LIMIT' => 1,
        ])->current();
        if (!$computer) {
            return [];
        }

        $os = $DB->request([
            'FROM' => 'glpi_items_operatingsystems',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
            ],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ])->current();
        $disk = $DB->request([
            'FROM' => 'glpi_items_disks',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
                'name' => 'JAMF Storage',
            ],
            'LIMIT' => 1,
        ])->current();
        $battery = $DB->request([
            'FROM' => 'glpi_items_devicebatteries',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
            ],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ])->current();
        $network = $DB->request([
            'FROM' => 'glpi_networkports',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
                'name' => 'JAMF Network',
            ],
            'LIMIT' => 1,
        ])->current();

        // Dynamic (inventory-managed) software, for the monitoring app-change diff.
        $softwareRows = $DB->request([
            'SELECT' => ['glpi_softwares.name AS sname', 'glpi_softwareversions.name AS vname'],
            'FROM' => 'glpi_items_softwareversions',
            'LEFT JOIN' => [
                'glpi_softwareversions' => [
                    'FKEY' => ['glpi_items_softwareversions' => 'softwareversions_id', 'glpi_softwareversions' => 'id'],
                ],
                'glpi_softwares' => [
                    'FKEY' => ['glpi_softwareversions' => 'softwares_id', 'glpi_softwares' => 'id'],
                ],
            ],
            'WHERE' => [
                'glpi_items_softwareversions.itemtype' => 'Computer',
                'glpi_items_softwareversions.items_id' => $computerId,
                'glpi_items_softwareversions.is_dynamic' => 1,
            ],
        ]);
        $software = [];
        foreach ($softwareRows as $swRow) {
            $label = trim((string) ($swRow['sname'] ?? '') . ' ' . (string) ($swRow['vname'] ?? ''));
            if ($label !== '') {
                $software[] = $label;
            }
        }
        sort($software);

        return [
            'id' => (int) ($computer['id'] ?? 0),
            'name' => trim((string) ($computer['name'] ?? '')),
            'serial' => trim((string) ($computer['serial'] ?? '')),
            'udid' => trim((string) ($computer['otherserial'] ?? '')),
            'uuid' => trim((string) ($computer['uuid'] ?? '')),
            'last_boot' => trim((string) ($computer['last_boot'] ?? '')),
            'comment' => trim((string) ($computer['comment'] ?? '')),
            'os' => $this->formatOperatingSystemState($os),
            'storage_total_mib' => (int) ($disk['totalsize'] ?? 0),
            'storage_free_mib' => (int) ($disk['freesize'] ?? 0),
            'battery_percent' => (int) ($battery['real_capacity'] ?? 0),
            'public_ip' => trim((string) ($network['comment'] ?? '')),
            'mac' => trim((string) ($network['mac'] ?? '')),
            'software' => $software,
        ];
    }

    private function formatOperatingSystemState($row): array
    {
        if (!is_array($row) || $row === []) {
            return [];
        }

        return [
            'name' => $this->getNameFromTableId('glpi_operatingsystems', (int) ($row['operatingsystems_id'] ?? 0)),
            'version' => $this->getNameFromTableId('glpi_operatingsystemversions', (int) ($row['operatingsystemversions_id'] ?? 0)),
            'service_pack' => $this->getNameFromTableId('glpi_operatingsystemservicepacks', (int) ($row['operatingsystemservicepacks_id'] ?? 0)),
            'architecture' => $this->getNameFromTableId('glpi_operatingsystemarchitectures', (int) ($row['operatingsystemarchitectures_id'] ?? 0)),
            'kernel' => $this->getNameFromTableId('glpi_operatingsystemkernelversions', (int) ($row['operatingsystemkernelversions_id'] ?? 0)),
            'edition' => $this->getNameFromTableId('glpi_operatingsystemeditions', (int) ($row['operatingsystemeditions_id'] ?? 0)),
            'install_date' => trim((string) ($row['install_date'] ?? '')),
        ];
    }

    private function getNameFromTableId(string $table, int $id): string
    {
        global $DB;

        static $cache = [];
        if ($id <= 0) {
            return '';
        }

        if (isset($cache[$table][$id])) {
            return $cache[$table][$id];
        }

        $row = $DB->request([
            'FROM' => $table,
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        $cache[$table][$id] = trim((string) ($row['name'] ?? ''));
        return $cache[$table][$id];
    }

    private function encodeJsonForLog($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && $value === []) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === 'null' || $encoded === '') {
            return null;
        }

        return $encoded;
    }

    public function createSyncJob(string $mode, int $instanceId = 0): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
            return 0;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $isFull = str_contains($mode, 'full') ? 1 : 0;

        $DB->insert('glpi_plugin_jamfsync_syncjobs', [
            'status' => 'queued',
            'mode' => $mode,
            'is_full' => $isFull,
            'instance_id' => max(0, $instanceId),
            'instance_name' => '',
            'total_instances' => 0,
            'processed_instances' => 0,
            'total_devices' => 0,
            'processed_devices' => 0,
            'changed_count' => 0,
            'apps_count' => 0,
            'last_run_id' => 0,
            'error_code' => '',
            'error_message' => null,
            'message' => __('Job queued', 'jamfsync'),
            'started_at' => null,
            'finished_at' => null,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * The most recent still-active (queued/running) sync job, if any. Used by the UI to
     * show a persistent "sync running" indicator that survives page reloads. Returns the
     * same shape as getSyncJob() (progress_percent + stall detection applied).
     *
     * @return array<string, mixed>|null
     */
    public function getLatestActiveJob(): ?array
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_jamfsync_syncjobs',
            'WHERE' => ['status' => ['queued', 'running']],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return null;
        }

        return $this->getSyncJob((int) $row['id']);
    }

    public function getSyncJob(int $jobId): ?array
    {
        global $DB;

        if ($jobId <= 0 || !$DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
            return null;
        }

        $row = $DB->request([
            'FROM' => 'glpi_plugin_jamfsync_syncjobs',
            'WHERE' => ['id' => $jobId],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return null;
        }

        // Detect stalled jobs: running but no heartbeat update for 3 minutes.
        if (
            (string) ($row['status'] ?? '') === 'running'
            && strtotime((string) ($row['date_mod'] ?? '')) > 0
            && strtotime((string) ($row['date_mod'] ?? '')) < (time() - 180)
        ) {
            $now = date('Y-m-d H:i:s'); // real wall-clock for job timing (async run freezes glpi_currenttime)
            $stalledMessage = __('Sync job appears stalled (no progress heartbeat).', 'jamfsync');
            $this->updateSyncJob($jobId, [
                'status' => 'stalled',
                'error_code' => 'stalled_timeout',
                'error_message' => $stalledMessage,
                'message' => $stalledMessage,
                'finished_at' => $now,
                'date_mod' => $now,
            ]);
            $row['status'] = 'stalled';
            $row['error_code'] = 'stalled_timeout';
            $row['error_message'] = $stalledMessage;
            $row['message'] = $stalledMessage;
            $row['finished_at'] = $now;
            $row['date_mod'] = $now;
        }

        $totalDevices = (int) ($row['total_devices'] ?? 0);
        $processedDevices = (int) ($row['processed_devices'] ?? 0);
        $progressPercent = 0;
        if ($totalDevices > 0) {
            $progressPercent = (int) max(0, min(100, floor(($processedDevices / $totalDevices) * 100)));
        } elseif ((int) ($row['total_instances'] ?? 0) > 0) {
            $progressPercent = (int) max(0, min(100, floor(((int) ($row['processed_instances'] ?? 0) / (int) ($row['total_instances'] ?? 0)) * 100)));
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['status'] ?? 'queued'),
            'mode' => (string) ($row['mode'] ?? 'manual'),
            'is_full' => (int) ($row['is_full'] ?? 0),
            'instance_id' => (int) ($row['instance_id'] ?? 0),
            'instance_name' => (string) ($row['instance_name'] ?? ''),
            'total_instances' => (int) ($row['total_instances'] ?? 0),
            'processed_instances' => (int) ($row['processed_instances'] ?? 0),
            'total_devices' => $totalDevices,
            'processed_devices' => $processedDevices,
            'changed_count' => (int) ($row['changed_count'] ?? 0),
            'apps_count' => (int) ($row['apps_count'] ?? 0),
            'last_run_id' => (int) ($row['last_run_id'] ?? 0),
            'error_code' => (string) ($row['error_code'] ?? ''),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'progress_percent' => $progressPercent,
        ];
    }

    private function updateSyncJob(int $jobId, array $fields): void
    {
        global $DB;

        if ($jobId <= 0 || $fields === [] || !$DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
            return;
        }

        // Async syncs run in a single request, so $_SESSION['glpi_currenttime'] is frozen for
        // the whole run. Stamp the job heartbeat with REAL wall-clock time — otherwise a
        // long-running job never moves date_mod and getSyncJob() falsely flags it "stalled".
        $fields['date_mod'] = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_jamfsync_syncjobs', $fields, ['id' => $jobId]);
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
