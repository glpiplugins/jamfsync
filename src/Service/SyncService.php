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
            $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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
            $startedAt = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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
                $appAggregate = [];
                $osAggregate = [];

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
                        ? $this->buildAppsSignature($this->extractApps($device))
                        : (string) ($map['apps_signature'] ?? '');

                    $deviceChanged = $isFull || !$map || (string) ($map['device_signature'] ?? '') !== $deviceSignature;
                    $appsChanged = $includeApps && ($isFull || !$map || (string) ($map['apps_signature'] ?? '') !== $appsSignature);

                    $computerId = (int) ($map['computers_id'] ?? 0);
                    if (!$pullDevices && $computerId <= 0) {
                        // pull_devices disabled: never create computers from device data, but
                        // resolve an existing one read-only so enabled app-sync can still attach.
                        $computerId = $this->findComputerForDevice(
                            (int) ($instance['id'] ?? 0),
                            $identifiers['device_id'],
                            $identifiers['udid'],
                            $identifiers['serial'],
                            $identifiers['uuid'],
                            (int) ($instance['entities_id'] ?? 0)
                        );
                    }
                    $changeParts = [];
                    $appsSync = [
                        'total' => 0,
                        'added' => 0,
                        'removed' => 0,
                        'changes' => [],
                    ];
                    $deviceBefore = [];
                    $deviceAfter = [];

                    if ($pullDevices && ($deviceChanged || $appsChanged || $computerId <= 0)) {
                        $wasNewComputer = $computerId <= 0;
                        $upsertResult = $this->upsertComputerFromDevice($instance, $device, $identifiers, $computerId);
                        $computerId = (int) ($upsertResult['computer_id'] ?? 0);
                        $deviceBefore = (array) ($upsertResult['before'] ?? []);
                        $deviceAfter = (array) ($upsertResult['after'] ?? []);
                        $changedCount++;

                        if ($isFull && !$wasNewComputer) {
                            $changeParts[] = __('Full resync update', 'jamfsync');
                        } elseif ($wasNewComputer) {
                            $changeParts[] = __('Computer created', 'jamfsync');
                        } else {
                            $changeParts[] = __('Computer updated', 'jamfsync');
                        }

                        if ((bool) ($upsertResult['os_changed'] ?? false)) {
                            $osBefore = (array) ($upsertResult['os_before'] ?? []);
                            $osAfter = (array) ($upsertResult['os_after'] ?? []);
                            $changeParts[] = __('OS updated', 'jamfsync');
                            $osKey = strtolower(json_encode($osBefore)) . '::' . strtolower(json_encode($osAfter));
                            if (!isset($osAggregate[$osKey])) {
                                $osAggregate[$osKey] = [
                                    'name' => trim((string) ($osAfter['name'] ?? '')) !== ''
                                        ? trim((string) ($osAfter['name'] ?? '') . ' ' . (string) ($osAfter['version'] ?? ''))
                                        : __('Unknown', 'jamfsync'),
                                    'before' => $osBefore,
                                    'after' => $osAfter,
                                    'devices' => [],
                                ];
                            }
                            $osAggregate[$osKey]['devices'][] = [
                                'jamf_device_id' => (string) ($identifiers['device_id'] ?? ''),
                                'computers_id' => $computerId,
                                'computer_name' => $this->resolveComputerNameFromDevice($instance, $device),
                            ];
                        }
                    }

                    if ($includeApps && $appsChanged && $computerId > 0) {
                        $appsSync = $this->syncAppsForDevice($instance, $identifiers, $computerId, $device);
                        $appsCount += (int) ($appsSync['total'] ?? 0);
                        $changeParts[] = sprintf(
                            __('Apps synced (+%1$d/-%2$d)', 'jamfsync'),
                            (int) ($appsSync['added'] ?? 0),
                            (int) ($appsSync['removed'] ?? 0)
                        );

                        foreach ((array) ($appsSync['changes'] ?? []) as $appChange) {
                            $appName = trim((string) ($appChange['app_name'] ?? ''));
                            $appVersion = trim((string) ($appChange['version'] ?? ''));
                            $action = trim((string) ($appChange['action'] ?? 'updated'));
                            $appKey = strtolower($action . '|' . $appName . '|' . $appVersion);

                            if (!isset($appAggregate[$appKey])) {
                                $appAggregate[$appKey] = [
                                    'name' => trim($appName . ($appVersion !== '' ? (' ' . $appVersion) : '')),
                                    'action' => $action,
                                    'devices' => [],
                                ];
                            }

                            $appAggregate[$appKey]['devices'][] = [
                                'jamf_device_id' => (string) ($identifiers['device_id'] ?? ''),
                                'computers_id' => $computerId,
                                'computer_name' => $this->resolveComputerNameFromDevice($instance, $device),
                            ];
                        }
                    }

                    $this->upsertDeviceMap(
                        (int) ($instance['id'] ?? 0),
                        $identifiers,
                        $computerId,
                        (int) ($instance['entities_id'] ?? 0),
                        $deviceSignature,
                        $appsSignature,
                        $this->extractLastCheckinAt($device),
                        $startedAt
                    );

                    if ($changeParts !== []) {
                        $displayName = $this->resolveComputerNameFromDevice($instance, $device);
                        if ($computerId > 0 && $deviceBefore === [] && $deviceAfter === []) {
                            $snapshot = $this->buildComputerSnapshot($computerId);
                            $deviceBefore = $snapshot;
                            $deviceAfter = $snapshot;
                        }
                        $changeEntries[] = [
                            'change_type' => 'device',
                            'jamf_device_id' => (string) ($identifiers['device_id'] ?? ''),
                            'computers_id' => $computerId,
                            'computer_name' => $displayName,
                            'object_name' => $displayName,
                            'details' => implode(' | ', $changeParts),
                            'before_json' => $this->encodeJsonForLog($deviceBefore),
                            'after_json' => $this->encodeJsonForLog($deviceAfter),
                            'impacted_devices' => null,
                        ];
                    }

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

                foreach ($appAggregate as $entry) {
                    $changeEntries[] = [
                        'change_type' => 'app',
                        'jamf_device_id' => '',
                        'computers_id' => 0,
                        'computer_name' => '',
                        'object_name' => (string) ($entry['name'] ?? ''),
                        'details' => sprintf(__('App %s', 'jamfsync'), (string) ($entry['action'] ?? 'updated')),
                        'before_json' => null,
                        'after_json' => null,
                        'impacted_devices' => $this->encodeJsonForLog((array) ($entry['devices'] ?? [])),
                    ];
                }

                foreach ($osAggregate as $entry) {
                    $changeEntries[] = [
                        'change_type' => 'os',
                        'jamf_device_id' => '',
                        'computers_id' => 0,
                        'computer_name' => '',
                        'object_name' => (string) ($entry['name'] ?? ''),
                        'details' => __('OS changed', 'jamfsync'),
                        'before_json' => $this->encodeJsonForLog((array) ($entry['before'] ?? [])),
                        'after_json' => $this->encodeJsonForLog((array) ($entry['after'] ?? [])),
                        'impacted_devices' => $this->encodeJsonForLog((array) ($entry['devices'] ?? [])),
                    ];
                }

                if ((int) ($instance['pull_devices'] ?? 0) === 1) {
                    $devicesCount = $processedCount;
                }

            } catch (RuntimeException $exception) {
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

            $finishedAt = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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
            $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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

    private function syncAppsForDevice(array $instance, array $identifiers, int $computerId, array $device): array
    {
        global $DB;

        $instanceId = (int) ($instance['id'] ?? 0);
        $deviceId = (string) ($identifiers['device_id'] ?? '');
        $entitiesId = (int) ($instance['entities_id'] ?? 0);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $desiredVersionIds = [];
        $desiredMeta = [];
        $count = 0;
        $addedCount = 0;
        $removedCount = 0;
        $changeDetails = [];

        foreach ($this->extractApps($device) as $app) {
            $softwareName = trim((string) ($app['name'] ?? ''));
            if ($softwareName === '') {
                continue;
            }

            $versionName = trim((string) ($app['version'] ?? ''));
            if ($versionName === '') {
                $versionName = 'unknown';
            }

            $softwareId = $this->upsertSoftware($entitiesId, $softwareName);
            $softwareVersionId = $this->upsertSoftwareVersion($entitiesId, $softwareId, $versionName);
            $desiredVersionIds[$softwareVersionId] = true;
            $desiredMeta[$softwareVersionId] = [
                'app_name' => $softwareName,
                'version' => $versionName,
            ];
            $count++;
        }

        $existingLinks = $DB->request([
            'FROM' => 'glpi_plugin_jamfsync_applinks',
            'WHERE' => [
                'jamf_instances_id' => $instanceId,
                'jamf_device_id' => $deviceId,
            ],
        ]);

        $existingVersionIds = [];
        foreach ($existingLinks as $row) {
            $existingVersionIds[(int) $row['softwareversions_id']] = (int) $row['id'];
        }

        foreach ($desiredVersionIds as $softwareVersionId => $_) {
            $this->linkSoftwareToComputer($entitiesId, $computerId, (int) $softwareVersionId);

            if (isset($existingVersionIds[(int) $softwareVersionId])) {
                $DB->update('glpi_plugin_jamfsync_applinks', [
                    'computers_id' => $computerId,
                    'date_mod' => $now,
                ], ['id' => $existingVersionIds[(int) $softwareVersionId]]);
                continue;
            }

            $DB->insert('glpi_plugin_jamfsync_applinks', [
                'jamf_instances_id' => $instanceId,
                'jamf_device_id' => $deviceId,
                'computers_id' => $computerId,
                'softwareversions_id' => (int) $softwareVersionId,
                'date_creation' => $now,
                'date_mod' => $now,
            ]);
            $addedCount++;
            $changeDetails[] = [
                'action' => 'added',
                'app_name' => (string) ($desiredMeta[(int) $softwareVersionId]['app_name'] ?? ''),
                'version' => (string) ($desiredMeta[(int) $softwareVersionId]['version'] ?? ''),
            ];
        }

        foreach ($existingVersionIds as $softwareVersionId => $linkId) {
            if (isset($desiredVersionIds[(int) $softwareVersionId])) {
                continue;
            }

            $DB->delete('glpi_items_softwareversions', [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'softwareversions_id' => (int) $softwareVersionId,
                'is_dynamic' => 1,
            ]);
            $DB->delete('glpi_plugin_jamfsync_applinks', [
                'id' => $linkId,
            ]);
            $removedCount++;
            $removedMeta = $this->getSoftwareVersionMeta((int) $softwareVersionId);
            $changeDetails[] = [
                'action' => 'removed',
                'app_name' => (string) ($removedMeta['app_name'] ?? ''),
                'version' => (string) ($removedMeta['version'] ?? ''),
            ];
        }

        return [
            'total' => $count,
            'added' => $addedCount,
            'removed' => $removedCount,
            'changes' => $changeDetails,
        ];
    }

    private function upsertComputerFromDevice(array $instance, array $device, array $identifiers, int $existingComputerId = 0): array
    {
        global $DB;

        $serial = $identifiers['serial'];
        $udid = $identifiers['udid'];
        $uuid = $identifiers['uuid'];
        $name = $this->resolveComputerNameFromDevice($instance, $device);

        $manufacturer = $this->extractNestedScalar($device, [
            'manufacturer',
            'vendor',
            'hardware.manufacturer',
            'hardware.vendor',
            'deviceManufacturer',
            'brand',
            'general.manufacturer',
        ]);
        $model = $this->extractNestedScalar($device, [
            'model',
            'model.name',
            'model.identifier',
            'model.model',
            'modelName',
            'hardware.model',
            'hardware.modelName',
            'model_identifier',
            'modelIdentifier',
            'productName',
            'deviceModel',
            'deviceModelName',
            'general.model',
        ]);
        $platform = strtolower($this->extractNestedScalar($device, [
            'os.prefix',
            'osName',
            'os.name',
            'operatingSystemName',
            'operatingSystem',
            'platform',
        ]));

        if ($manufacturer === '' && preg_match('/\\b(ios|ipados|macos|os x|tvos|watchos)\\b/i', $platform) === 1) {
            $manufacturer = 'Apple';
        }
        // Never store OS names as hardware model.
        if (preg_match('/^(ios|ipados|macos|os\\s*x|android|windows)$/i', trim($model)) === 1) {
            $model = '';
        }
        if ($model === '') {
            // Fallback only to device-model-like fields, never OS/platform fields.
            $model = $this->extractNestedScalar($device, [
                'deviceType',
                'hardware.deviceType',
                'deviceModelType',
                'general.deviceType',
            ]);
            if (preg_match('/^(ios|ipados|macos|os\\s*x|android|windows)$/i', trim($model)) === 1) {
                $model = '';
            }
        }
        $locationName = $this->extractNestedScalar($device, ['location', 'location.name', 'site', 'site.name', 'school', 'school.name']);
        $lastActiveAt = $this->extractLastActiveAt($device);

        $entitiesId = (int) ($instance['entities_id'] ?? 0);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $computerId = $existingComputerId;
        if ($computerId > 0 && !$this->computerMatchesIdentifiers($computerId, $entitiesId, $serial, $udid, $uuid)) {
            $computerId = 0;
        }
        if ($computerId <= 0) {
            $computerId = $this->findComputerForDevice((int) ($instance['id'] ?? 0), $identifiers['device_id'], $udid, $serial, $uuid, $entitiesId);
        }
        $beforeSnapshot = $computerId > 0 ? $this->buildComputerSnapshot($computerId) : [];

        $payload = [
            'entities_id' => $entitiesId,
            'name' => $name,
            'serial' => $serial,
            'otherserial' => $udid,
            'uuid' => $uuid,
            'manufacturers_id' => $this->findOrCreateManufacturer($manufacturer),
            'computermodels_id' => $this->findOrCreateComputerModel($model),
            'locations_id' => $this->findOrCreateLocation($entitiesId, $locationName),
            'users_id' => $this->findUserForDevice($entitiesId, $device),
            'contact' => $this->extractNestedScalar($device, ['ownerName', 'owner.name', 'user.name']),
            'comment' => $this->buildDeviceComment($device),
            'is_dynamic' => 1,
            'is_deleted' => 0,
            'is_template' => 0,
            'last_inventory_update' => $now,
            'last_boot' => $lastActiveAt !== '' ? $lastActiveAt : null,
            'date_mod' => $now,
        ];
        $osResult = [
            'changed' => false,
            'before' => [],
            'after' => [],
        ];
        $isNew = false;

        if ($computerId > 0) {
            $DB->update('glpi_computers', $payload, ['id' => $computerId]);
            $osResult = $this->syncOperatingSystemForComputer($entitiesId, $computerId, $device);
            $this->syncStorageForComputer($entitiesId, $computerId, $device);
            $this->syncBatteryForComputer($entitiesId, $computerId, $device);
            $this->syncNetworkForComputer($entitiesId, $computerId, $device);
            return [
                'computer_id' => $computerId,
                'before' => $beforeSnapshot,
                'after' => $this->buildComputerSnapshot($computerId),
                'os_changed' => (bool) ($osResult['changed'] ?? false),
                'os_before' => (array) ($osResult['before'] ?? []),
                'os_after' => (array) ($osResult['after'] ?? []),
                'is_new' => false,
            ];
        }

        $payload['date_creation'] = $now;
        $DB->insert('glpi_computers', $payload);
        $computerId = (int) $DB->insertId();
        if ($computerId > 0) {
            $isNew = true;
            $osResult = $this->syncOperatingSystemForComputer($entitiesId, $computerId, $device);
            $this->syncStorageForComputer($entitiesId, $computerId, $device);
            $this->syncBatteryForComputer($entitiesId, $computerId, $device);
            $this->syncNetworkForComputer($entitiesId, $computerId, $device);
        }
        return [
            'computer_id' => $computerId,
            'before' => $beforeSnapshot,
            'after' => $computerId > 0 ? $this->buildComputerSnapshot($computerId) : [],
            'os_changed' => (bool) ($osResult['changed'] ?? false),
            'os_before' => (array) ($osResult['before'] ?? []),
            'os_after' => (array) ($osResult['after'] ?? []),
            'is_new' => $isNew,
        ];
    }

    private function computerMatchesIdentifiers(int $computerId, int $entitiesId, string $serial, string $udid, string $uuid): bool
    {
        global $DB;

        if ($computerId <= 0) {
            return false;
        }

        $computer = $DB->request([
            'FROM' => 'glpi_computers',
            'WHERE' => [
                'id' => $computerId,
                'entities_id' => $entitiesId,
                'is_deleted' => 0,
                'is_template' => 0,
            ],
            'LIMIT' => 1,
        ])->current();

        if (!$computer) {
            return false;
        }

        $currentSerial = trim((string) ($computer['serial'] ?? ''));
        $currentUdid = trim((string) ($computer['otherserial'] ?? ''));
        $currentUuid = trim((string) ($computer['uuid'] ?? ''));

        if ($serial !== '' && $currentSerial !== '' && strcasecmp($serial, $currentSerial) !== 0) {
            return false;
        }
        if ($udid !== '' && $currentUdid !== '' && strcasecmp($udid, $currentUdid) !== 0) {
            return false;
        }
        if ($uuid !== '' && $currentUuid !== '' && strcasecmp($uuid, $currentUuid) !== 0) {
            return false;
        }

        return true;
    }

    private function upsertSoftware(int $entitiesId, string $name): int
    {
        global $DB;

        $existing = $DB->request([
            'FROM' => 'glpi_softwares',
            'WHERE' => [
                'entities_id' => $entitiesId,
                'name' => $name,
                'is_deleted' => 0,
                'is_template' => 0,
            ],
            'LIMIT' => 1,
        ])->current();

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        if ($existing) {
            $softwareId = (int) $existing['id'];
            $DB->update('glpi_softwares', ['date_mod' => $now], ['id' => $softwareId]);
            return $softwareId;
        }

        $DB->insert('glpi_softwares', [
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'name' => $name,
            'is_deleted' => 0,
            'is_template' => 0,
            'is_valid' => 1,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function resolveComputerNameFromDevice(array $instance, array $device): string
    {
        $nameSource = trim(strtolower((string) ($instance['name_source'] ?? 'device_name')));
        $deviceName = $this->extractScalar($device, ['name', 'deviceName', 'device_name']);
        $assetTag = $this->extractNestedScalar($device, ['assetTag', 'asset_tag']);
        $serial = $this->extractScalar($device, ['serialNumber', 'serialnumber', 'serial']);
        $udid = $this->extractScalar($device, ['udid', 'UDID']);

        if ($nameSource === 'asset_tag') {
            if ($assetTag !== '') {
                return $assetTag;
            }
            if ($deviceName !== '') {
                return $deviceName;
            }
            return $serial !== '' ? $serial : ($udid !== '' ? $udid : 'JAMF Device');
        }

        if ($deviceName !== '') {
            return $deviceName;
        }
        if ($assetTag !== '') {
            return $assetTag;
        }
        return $serial !== '' ? $serial : ($udid !== '' ? $udid : 'JAMF Device');
    }

    private function upsertSoftwareVersion(int $entitiesId, int $softwareId, string $version): int
    {
        global $DB;

        $existing = $DB->request([
            'FROM' => 'glpi_softwareversions',
            'WHERE' => [
                'entities_id' => $entitiesId,
                'softwares_id' => $softwareId,
                'name' => $version,
            ],
            'LIMIT' => 1,
        ])->current();

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        if ($existing) {
            $versionId = (int) $existing['id'];
            $DB->update('glpi_softwareversions', ['date_mod' => $now], ['id' => $versionId]);
            return $versionId;
        }

        $DB->insert('glpi_softwareversions', [
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'softwares_id' => $softwareId,
            'name' => $version,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function linkSoftwareToComputer(int $entitiesId, int $computerId, int $softwareVersionId): void
    {
        global $DB;

        $existing = $DB->request([
            'FROM' => 'glpi_items_softwareversions',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'softwareversions_id' => $softwareVersionId,
            ],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return;
        }

        $DB->insert('glpi_items_softwareversions', [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'softwareversions_id' => $softwareVersionId,
            'entities_id' => $entitiesId,
            'is_deleted' => 0,
            'is_dynamic' => 1,
            'is_deleted_item' => 0,
            'is_template_item' => 0,
        ]);
    }

    private function findOrCreateManufacturer(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_manufacturers',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_manufacturers', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateComputerModel(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_computermodels',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_computermodels', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateLocation(int $entitiesId, string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_locations',
            'WHERE' => [
                'entities_id' => $entitiesId,
                'locations_id' => 0,
                'name' => $name,
            ],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_locations', [
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'locations_id' => 0,
            'name' => $name,
            'completename' => $name,
            'level' => 1,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findUserForDevice(int $entitiesId, array $device): int
    {
        global $DB;

        $email = strtolower($this->extractNestedScalar($device, ['email', 'user.email', 'ownerEmail', 'owner.email', 'username', 'user.username']));
        if ($email !== '' && str_contains($email, '@')) {
            $userEmail = $DB->request([
                'SELECT' => ['users_id'],
                'FROM' => 'glpi_useremails',
                'WHERE' => ['email' => $email],
                'LIMIT' => 1,
            ])->current();
            if ($userEmail) {
                $usersId = (int) ($userEmail['users_id'] ?? 0);
                if ($usersId > 0) {
                    return $usersId;
                }
            }
        }

        $username = $this->extractNestedScalar($device, ['username', 'user.username', 'owner', 'owner.name']);
        if ($username === '') {
            return 0;
        }

        $user = $DB->request([
            'FROM' => 'glpi_users',
            'WHERE' => [
                'name' => $username,
                'is_deleted' => 0,
                [
                    'OR' => [
                        ['entities_id' => $entitiesId],
                        ['entities_id' => 0],
                    ],
                ],
            ],
            'LIMIT' => 1,
        ])->current();

        return $user ? (int) ($user['id'] ?? 0) : 0;
    }

    private function findComputerForDevice(int $instanceId, string $deviceId, string $udid, string $serial, string $uuid, int $entitiesId): int
    {
        global $DB;

        $map = $this->findDeviceMap($instanceId, $deviceId, $udid, $serial);
        if ($map) {
            return (int) ($map['computers_id'] ?? 0);
        }

        if ($serial !== '') {
            $computer = $DB->request([
                'FROM' => 'glpi_computers',
                'WHERE' => [
                    'entities_id' => $entitiesId,
                    'serial' => $serial,
                    'is_deleted' => 0,
                    'is_template' => 0,
                ],
                'LIMIT' => 1,
            ])->current();
            if ($computer) {
                return (int) ($computer['id'] ?? 0);
            }
        }

        if ($uuid !== '') {
            $computer = $DB->request([
                'FROM' => 'glpi_computers',
                'WHERE' => [
                    'entities_id' => $entitiesId,
                    'uuid' => $uuid,
                    'is_deleted' => 0,
                    'is_template' => 0,
                ],
                'LIMIT' => 1,
            ])->current();
            if ($computer) {
                return (int) ($computer['id'] ?? 0);
            }
        }

        return 0;
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
        $deviceId = $this->extractScalar($device, ['id', 'deviceId', 'deviceID', 'device_id']);
        $udid = $this->extractScalar($device, ['udid', 'UDID']);
        $serial = $this->extractScalar($device, ['serialNumber', 'serialnumber', 'serial']);
        $uuid = $this->extractScalar($device, ['uuid']);

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
            'name' => $this->resolveComputerNameFromDevice($instance, $device),
            'name_source' => (string) ($instance['name_source'] ?? 'device_name'),
            'manufacturer' => $this->extractNestedScalar($device, ['manufacturer', 'vendor', 'hardware.manufacturer']),
            'model' => $this->extractNestedScalar($device, ['model', 'modelName', 'hardware.model']),
            'os_name' => $this->extractNestedScalar($device, ['os.prefix', 'osName', 'os.name', 'operatingSystem', 'platform']),
            'os_version' => $this->extractNestedScalar($device, ['osVersion', 'os.version', 'operatingSystemVersion', 'systemVersion']),
            'location' => $this->extractNestedScalar($device, ['location', 'location.name', 'site', 'site.name']),
            'owner' => $this->extractNestedScalar($device, ['ownerName', 'owner.name', 'user.name']),
            'email' => strtolower($this->extractNestedScalar($device, ['email', 'user.email', 'ownerEmail'])),
            'ip' => $this->extractNestedScalar($device, ['ip', 'ipAddress', 'network.ipAddress']),
            'asset_tag' => $this->extractNestedScalar($device, ['assetTag', 'asset_tag']),
            'last_active' => $this->extractLastActiveAt($device),
            'last_checkin' => $this->extractLastCheckinAt($device),
            'enrollment_date' => $this->extractEnrollmentDate($device),
            'public_ip' => $this->extractPublicIp($device),
            'mac' => $this->extractMacAddress($device),
            'battery_percent' => $this->extractBatteryPercent($device),
            'storage_total' => $this->extractStorageTotalBytes($device),
            'storage_free' => $this->extractStorageFreeBytes($device),
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

    private function extractApps(array $device): array
    {
        foreach (['apps', 'installedApps', 'media', 'installedMedia'] as $key) {
            if (isset($device[$key]) && is_array($device[$key])) {
                $apps = [];
                foreach ($device[$key] as $row) {
                    if (is_string($row) || is_int($row) || is_float($row)) {
                        $name = trim((string) $row);
                        if ($name !== '') {
                            $apps[] = [
                                'name' => $name,
                                'version' => '',
                            ];
                        }
                        continue;
                    }

                    if (!is_array($row)) {
                        continue;
                    }

                    $candidate = $row;
                    if (isset($row['app']) && is_array($row['app'])) {
                        $candidate = $row['app'];
                    }

                    $apps[] = [
                        'name' => $this->extractScalar($candidate, [
                            'name',
                            'appName',
                            'title',
                            'displayName',
                            'bundleName',
                            'identifier',
                            'bundleIdentifier',
                        ]),
                        'version' => $this->extractScalar($candidate, [
                            'version',
                            'appVersion',
                            'shortVersion',
                            'shortVersionString',
                            'bundleVersion',
                            'buildVersion',
                        ]),
                    ];
                }
                return $apps;
            }
        }

        return [];
    }

    private function extractScalar(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (is_string($value) || is_int($value) || is_float($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function extractNestedScalar(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $value = $data;
            $found = true;

            foreach ($segments as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $found = false;
                    break;
                }
                $value = $value[$segment];
            }

            if (!$found) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function buildDeviceComment(array $device): string
    {
        $osPrefix = $this->extractNestedScalar($device, ['os.prefix', 'osName', 'os.name', 'operatingSystem', 'platform']);
        $osVersion = $this->extractNestedScalar($device, ['osVersion', 'os.version', 'operatingSystemVersion', 'systemVersion']);
        $os = trim($osPrefix . ($osPrefix !== '' && $osVersion !== '' ? ' ' : '') . $osVersion);
        $ip = $this->extractNestedScalar($device, ['ip', 'ipAddress', 'network.ip', 'network.ipAddress']);
        $assetTag = $this->extractNestedScalar($device, ['assetTag', 'asset_tag']);
        $lastActive = $this->extractLastActiveAt($device);
        $lastCheckin = $this->extractLastCheckinAt($device);
        $enrollmentDate = $this->extractEnrollmentDate($device);
        $publicIp = $this->extractPublicIp($device);
        $mac = $this->extractMacAddress($device);
        $battery = $this->extractBatteryPercent($device);
        $storageTotal = $this->extractStorageTotalBytes($device);
        $storageFree = $this->extractStorageFreeBytes($device);

        $parts = [];
        if ($os !== '') {
            $parts[] = 'OS: ' . $os;
        }
        if ($ip !== '') {
            $parts[] = 'IP: ' . $ip;
        }
        if ($assetTag !== '') {
            $parts[] = 'AssetTag: ' . $assetTag;
        }
        if ($battery !== null) {
            $parts[] = 'Battery: ' . $battery . '%';
        }
        if ($lastActive !== '') {
            $parts[] = 'LastActive: ' . $lastActive;
        }
        if ($lastCheckin !== '') {
            $parts[] = 'LastCheckin: ' . $lastCheckin;
        }
        if ($enrollmentDate !== '') {
            $parts[] = 'Enrollment: ' . $enrollmentDate;
        }
        if ($publicIp !== '') {
            $parts[] = 'PublicIP: ' . $publicIp;
        }
        if ($mac !== '') {
            $parts[] = 'MAC: ' . $mac;
        }
        if ($storageTotal > 0) {
            $used = max(0, $storageTotal - max(0, $storageFree));
            $parts[] = sprintf('Storage: %.2f/%.2f GB', $used / 1024, $storageTotal / 1024);
        }

        return $parts === [] ? '' : ('JAMF Sync | ' . implode(' | ', $parts));
    }

    private function extractLastActiveAt(array $device): string
    {
        $candidates = [
            $this->extractNestedScalar($device, [
                'lastActivity',
                'lastActivityAt',
                'lastSeenAt',
                'lastCheckIn',
                'lastCheckin',
                'lastCheckInAt',
                'lastContact',
                'lastSync',
            ]),
            $this->extractNestedScalar($device, [
                'last_active',
                'last_seen',
                'last_activity',
                'activity.lastSeenAt',
                'activity.lastContact',
                'activity.lastCheckin',
            ]),
        ];

        foreach ($candidates as $value) {
            $ts = $this->normalizeDateTime($value);
            if ($ts !== '') {
                return $ts;
            }
        }

        return $this->extractLastCheckinAt($device);
    }

    private function extractLastCheckinAt(array $device): string
    {
        $candidates = [
            $this->extractNestedScalar($device, ['lastCheckin', 'lastCheckIn', 'last_checkin']),
            $this->extractNestedScalar($device, ['lastContact', 'lastSeenAt', 'activity.lastCheckin']),
        ];

        foreach ($candidates as $value) {
            $ts = $this->normalizeDateTime($value);
            if ($ts !== '') {
                return $ts;
            }
        }

        return '';
    }

    private function extractEnrollmentDate(array $device): string
    {
        $candidates = [
            $this->extractNestedScalar($device, ['enrollmentDate', 'enrolledAt', 'enrolled', 'dateEnrolled']),
            $this->extractNestedScalar($device, ['dep.enrollmentDate', 'dep.enrolledAt', 'management.enrollmentDate']),
            $this->extractNestedScalar($device, ['created', 'dateCreated']),
        ];

        foreach ($candidates as $value) {
            $ts = $this->normalizeDateTime($value);
            if ($ts !== '') {
                return $ts;
            }
        }

        return '';
    }

    private function extractPublicIp(array $device): string
    {
        return $this->extractNestedScalar($device, [
            'networkInformation.IPAddress',
            'networkInformation.ipAddress',
            'publicIP',
            'publicIp',
            'ip',
            'ipAddress',
            'network.ipAddress',
        ]);
    }

    private function extractMacAddress(array $device): string
    {
        $mac = $this->extractNestedScalar($device, [
            'networkInformation.WiFiMAC',
            'networkInformation.wifiMac',
            'networkInformation.BluetoothMAC',
            'networkInformation.bluetoothMac',
            'WiFiMAC',
            'wifiMac',
            'bluetoothMac',
            'macAddress',
            'mac',
        ]);

        return $this->normalizeMacAddress($mac);
    }

    private function normalizeMacAddress(string $mac): string
    {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac) ?? '');
        if (strlen($mac) !== 12) {
            return '';
        }
        return implode(':', str_split($mac, 2));
    }

    private function extractBatteryPercent(array $device): ?int
    {
        $candidates = [
            $this->extractNestedScalar($device, ['batteryLevel', 'battery_level', 'batteryPercent', 'battery.percent']),
            $this->extractNestedScalar($device, ['battery.charge', 'battery.level', 'power.batteryLevel']),
        ];

        foreach ($candidates as $raw) {
            if ($raw === '') {
                continue;
            }

            $value = str_replace(',', '.', trim((string) $raw));
            $value = rtrim($value, '%');
            if (!is_numeric($value)) {
                continue;
            }

            $num = (float) $value;
            if ($num <= 1.0) {
                $num *= 100.0;
            }

            return (int) max(0, min(100, round($num)));
        }

        return null;
    }

    private function extractStorageTotalBytes(array $device): int
    {
        $jamfGb = $this->extractNestedScalar($device, ['totalCapacity', 'storage.totalCapacity']);
        if ($jamfGb !== '') {
            $mib = $this->normalizeSizeToMib($jamfGb, 'GB');
            if ($mib > 0) {
                return $mib;
            }
        }

        $candidates = [
            $this->extractNestedScalar($device, ['capacity', 'totalSpace', 'totalStorage', 'storage.total', 'storage.totalSpace']),
            $this->extractNestedScalar($device, ['disk.total', 'disk.totalBytes', 'storage.capacity']),
        ];

        foreach ($candidates as $raw) {
            $mib = $this->normalizeSizeToMib($raw, 'auto');
            if ($mib > 0) {
                return $mib;
            }
        }

        return 0;
    }

    private function extractStorageFreeBytes(array $device): int
    {
        $jamfGb = $this->extractNestedScalar($device, ['availableCapacity', 'storage.availableCapacity']);
        if ($jamfGb !== '') {
            $mib = $this->normalizeSizeToMib($jamfGb, 'GB');
            if ($mib > 0) {
                return $mib;
            }
        }

        $candidates = [
            $this->extractNestedScalar($device, ['availableCapacity', 'freeSpace', 'storage.free', 'storage.available', 'storage.availableSpace']),
            $this->extractNestedScalar($device, ['disk.free', 'disk.freeBytes']),
        ];

        foreach ($candidates as $raw) {
            $mib = $this->normalizeSizeToMib($raw, 'auto');
            if ($mib > 0) {
                return $mib;
            }
        }

        return 0;
    }

    private function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= 0) {
            return '';
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeSizeToMib(string $raw, string $assumeUnit = 'auto'): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*(B|KB|MB|GB|TB|KIB|MIB|GIB|TIB)?$/i', $raw, $matches) === 1) {
            $num = (float) str_replace(',', '.', $matches[1]);
            $unit = strtoupper((string) ($matches[2] ?? ''));
            if ($unit === '') {
                $unit = strtoupper($assumeUnit);
            }
            if ($unit === 'AUTO') {
                // JAMF often returns plain numeric storage capacities in GB.
                $unit = $num > 0 && $num < 4096 ? 'GB' : 'B';
            }
            $factorMib = match ($unit) {
                'TIB', 'TB' => 1024 * 1024,
                'GIB', 'GB' => 1024,
                'MIB', 'MB' => 1,
                'KIB', 'KB' => 1 / 1024,
                default => 1 / (1024 * 1024),
            };
            return (int) max(0, round($num * $factorMib));
        }

        if (is_numeric($raw)) {
            $num = (float) $raw;
            if (strtoupper($assumeUnit) === 'GB') {
                return max(0, (int) round($num * 1024));
            }
            if (strtoupper($assumeUnit) === 'AUTO' && $num > 0 && $num < 4096) {
                return max(0, (int) round($num * 1024));
            }
            return max(0, (int) round($num / (1024 * 1024)));
        }

        return 0;
    }

    private function syncOperatingSystemForComputer(int $entitiesId, int $computerId, array $device): array
    {
        global $DB;

        $osName = $this->extractNestedScalar($device, [
            'os.prefix',
            'osName',
            'os.name',
            'operatingSystemName',
            'operatingSystem',
            'platform',
        ]);
        $osVersion = $this->extractNestedScalar($device, [
            'osVersion',
            'os.version',
            'operatingSystemVersion',
            'systemVersion',
        ]);
        $architecture = $this->extractNestedScalar($device, [
            'architecture',
            'os.architecture',
            'arch',
        ]);
        $kernelVersion = $this->extractNestedScalar($device, [
            'kernelVersion',
            'os.kernelVersion',
            'kernel',
        ]);
        $servicePack = $this->extractNestedScalar($device, [
            'servicePack',
            'service_pack',
            'os.servicePack',
            'os.service_pack',
            'operatingSystemServicePack',
        ]);
        $edition = $this->extractNestedScalar($device, [
            'edition',
            'os.edition',
            'operatingSystemEdition',
            'osEdition',
        ]);
        $installDate = $this->extractNestedScalar($device, [
            'os.installDate',
            'os.installedAt',
            'operatingSystemInstallDate',
            'installDate',
            'installedAt',
        ]);
        $installDate = $this->normalizeDateTime($installDate);

        if ($osName === '') {
            if ($osVersion !== '' && preg_match('/^([A-Za-z0-9._\\- ]+)/', $osVersion, $matches) === 1) {
                $osName = trim($matches[1]);
            } else {
                $osName = 'Unknown OS';
            }
        }

        $existing = $DB->request([
            'FROM' => 'glpi_items_operatingsystems',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
            ],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ])->current();
        $beforeState = $this->formatOperatingSystemState($existing);

        $operatingsystemsId = $this->findOrCreateOperatingSystem($osName);
        if ($operatingsystemsId <= 0) {
            return [
                'changed' => false,
                'before' => $beforeState,
                'after' => [],
            ];
        }

        $operatingsystemversionsId = $this->findOrCreateOperatingSystemVersion($osVersion);
        $operatingsystemarchitecturesId = $this->findOrCreateOperatingSystemArchitecture($architecture);
        $operatingsystemkernelversionsId = $this->findOrCreateOperatingSystemKernelVersion($kernelVersion);
        $operatingsystemservicepacksId = $this->findOrCreateOperatingSystemServicePack($servicePack);
        $operatingsystemeditionsId = $this->findOrCreateOperatingSystemEdition($edition);
        $afterState = [
            'name' => $osName,
            'version' => $osVersion,
            'service_pack' => $servicePack,
            'architecture' => $architecture,
            'kernel' => $kernelVersion,
            'edition' => $edition,
            'install_date' => $installDate,
        ];

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $payload = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'operatingsystems_id' => $operatingsystemsId,
            'operatingsystemversions_id' => $operatingsystemversionsId,
            'operatingsystemservicepacks_id' => $operatingsystemservicepacksId,
            'operatingsystemarchitectures_id' => $operatingsystemarchitecturesId,
            'operatingsystemkernelversions_id' => $operatingsystemkernelversionsId,
            'operatingsystemeditions_id' => $operatingsystemeditionsId,
            'install_date' => $installDate !== '' ? $installDate : null,
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'is_deleted' => 0,
            'is_dynamic' => 1,
            'date_mod' => $now,
        ];
        $hasChanged = !$existing
            || (int) ($existing['operatingsystems_id'] ?? 0) !== $operatingsystemsId
            || (int) ($existing['operatingsystemversions_id'] ?? 0) !== $operatingsystemversionsId
            || (int) ($existing['operatingsystemservicepacks_id'] ?? 0) !== $operatingsystemservicepacksId
            || (int) ($existing['operatingsystemarchitectures_id'] ?? 0) !== $operatingsystemarchitecturesId
            || (int) ($existing['operatingsystemkernelversions_id'] ?? 0) !== $operatingsystemkernelversionsId
            || (int) ($existing['operatingsystemeditions_id'] ?? 0) !== $operatingsystemeditionsId
            || trim((string) ($existing['install_date'] ?? '')) !== $installDate;

        if ($existing) {
            $DB->update('glpi_items_operatingsystems', $payload, ['id' => (int) $existing['id']]);
            return [
                'changed' => $hasChanged,
                'before' => $beforeState,
                'after' => $afterState,
            ];
        }

        $payload['date_creation'] = $now;
        $DB->insert('glpi_items_operatingsystems', $payload);
        return [
            'changed' => true,
            'before' => $beforeState,
            'after' => $afterState,
        ];
    }

    private function syncStorageForComputer(int $entitiesId, int $computerId, array $device): void
    {
        global $DB;

        $totalMib = $this->extractStorageTotalBytes($device);
        $freeMib = $this->extractStorageFreeBytes($device);
        if ($totalMib <= 0 && $freeMib <= 0) {
            return;
        }

        if ($totalMib > 0 && $freeMib > $totalMib) {
            $freeMib = $totalMib;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_items_disks',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
                'name' => 'JAMF Storage',
            ],
            'LIMIT' => 1,
        ])->current();

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $payload = [
            'entities_id' => $entitiesId,
            'itemtype' => 'Computer',
            'items_id' => $computerId,
            'name' => 'JAMF Storage',
            'device' => 'internal',
            'mountpoint' => '/',
            'filesystems_id' => 0,
            // GLPI stores disk sizes in MiB.
            'totalsize' => max(0, $totalMib),
            'freesize' => max(0, $freeMib),
            'is_deleted' => 0,
            'is_dynamic' => 1,
            'date_mod' => $now,
        ];

        if ($existing) {
            $DB->update('glpi_items_disks', $payload, ['id' => (int) $existing['id']]);
            return;
        }

        $payload['date_creation'] = $now;
        $DB->insert('glpi_items_disks', $payload);
    }

    private function syncBatteryForComputer(int $entitiesId, int $computerId, array $device): void
    {
        global $DB;

        $batteryPercent = $this->extractBatteryPercent($device);
        if ($batteryPercent === null) {
            return;
        }

        $deviceBatteryId = $this->findOrCreateJamfDeviceBattery($entitiesId);
        if ($deviceBatteryId <= 0) {
            return;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_items_devicebatteries',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
            ],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ])->current();

        $payload = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'devicebatteries_id' => $deviceBatteryId,
            'is_deleted' => 0,
            'is_dynamic' => 1,
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'real_capacity' => max(0, min(100, $batteryPercent)),
        ];

        if ($existing) {
            $DB->update('glpi_items_devicebatteries', $payload, ['id' => (int) $existing['id']]);
            return;
        }

        $DB->insert('glpi_items_devicebatteries', $payload);
    }

    private function syncNetworkForComputer(int $entitiesId, int $computerId, array $device): void
    {
        global $DB;

        $mac = $this->extractMacAddress($device);
        $publicIp = $this->extractPublicIp($device);
        if ($mac === '' && $publicIp === '') {
            return;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_networkports',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
                'is_dynamic' => 1,
                'name' => 'JAMF Network',
            ],
            'LIMIT' => 1,
        ])->current();

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $payload = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'logical_number' => 0,
            'name' => 'JAMF Network',
            'instantiation_type' => 'NetworkPortWifi',
            'mac' => $mac,
            'comment' => $publicIp !== '' ? ('Public IP: ' . $publicIp) : '',
            'is_deleted' => 0,
            'is_dynamic' => 1,
            'date_mod' => $now,
            'ifmtu' => 0,
            'ifspeed' => 0,
            'ifconnectionstatus' => 0,
            'ifinbytes' => 0,
            'ifinerrors' => 0,
            'ifoutbytes' => 0,
            'ifouterrors' => 0,
            'trunk' => 0,
        ];

        if ($existing) {
            $networkPortId = (int) $existing['id'];
            $DB->update('glpi_networkports', $payload, ['id' => $networkPortId]);
            $this->ensureWifiPortSubtype($networkPortId);
            return;
        }

        $payload['date_creation'] = $now;
        $DB->insert('glpi_networkports', $payload);
        $networkPortId = (int) $DB->insertId();
        if ($networkPortId > 0) {
            $this->ensureWifiPortSubtype($networkPortId);
        }
    }

    private function ensureWifiPortSubtype(int $networkPortId): void
    {
        global $DB;

        if ($networkPortId <= 0 || !$DB->tableExists('glpi_networkportwifis')) {
            return;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_networkportwifis',
            'WHERE' => ['networkports_id' => $networkPortId],
            'LIMIT' => 1,
        ])->current();

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if ($existing) {
            $DB->update('glpi_networkportwifis', [
                'date_mod' => $now,
            ], ['id' => (int) $existing['id']]);
            return;
        }

        $DB->insert('glpi_networkportwifis', [
            'networkports_id' => $networkPortId,
            'items_devicenetworkcards_id' => 0,
            'wifinetworks_id' => 0,
            'networkportwifis_id' => 0,
            'version' => null,
            'mode' => null,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
    }

    private function findOrCreateJamfDeviceBattery(int $entitiesId): int
    {
        global $DB;

        $existing = $DB->request([
            'FROM' => 'glpi_devicebatteries',
            'WHERE' => [
                'designation' => 'JAMF Battery',
                'entities_id' => $entitiesId,
            ],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            $id = (int) ($existing['id'] ?? 0);
            if ($id > 0 && (int) ($existing['capacity'] ?? 0) <= 0) {
                $DB->update('glpi_devicebatteries', [
                    'capacity' => 100,
                    'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ], ['id' => $id]);
            }
            return $id;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_devicebatteries', [
            'designation' => 'JAMF Battery',
            'comment' => 'Managed by JAMF Sync plugin',
            'manufacturers_id' => 0,
            'voltage' => null,
            'capacity' => 100,
            'devicebatterytypes_id' => 0,
            'entities_id' => $entitiesId,
            'is_recursive' => 0,
            'devicebatterymodels_id' => null,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);

        return (int) $DB->insertId();
    }

    private function findOrCreateOperatingSystem(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_operatingsystems',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_operatingsystems', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateOperatingSystemVersion(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_operatingsystemversions',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_operatingsystemversions', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateOperatingSystemArchitecture(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_operatingsystemarchitectures',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_operatingsystemarchitectures', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateOperatingSystemKernelVersion(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_operatingsystemkernelversions',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_operatingsystemkernelversions', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateOperatingSystemServicePack(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_operatingsystemservicepacks',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_operatingsystemservicepacks', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function findOrCreateOperatingSystemEdition(string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $DB->request([
            'FROM' => 'glpi_operatingsystemeditions',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert('glpi_operatingsystemeditions', [
            'name' => $name,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    private function getSoftwareVersionMeta(int $softwareVersionId): array
    {
        global $DB;

        if ($softwareVersionId <= 0) {
            return [
                'app_name' => '',
                'version' => '',
            ];
        }

        $row = $DB->request([
            'SELECT' => [
                'glpi_softwareversions.name AS version',
                'glpi_softwares.name AS app_name',
            ],
            'FROM' => 'glpi_softwareversions',
            'LEFT JOIN' => [
                'glpi_softwares' => [
                    'FKEY' => [
                        'glpi_softwareversions' => 'softwares_id',
                        'glpi_softwares' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_softwareversions.id' => $softwareVersionId,
            ],
            'LIMIT' => 1,
        ])->current();

        return [
            'app_name' => trim((string) ($row['app_name'] ?? '')),
            'version' => trim((string) ($row['version'] ?? '')),
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
            $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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
