<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

/**
 * JAMF device <-> GLPI computer mapping per instance (glpi_plugin_jamfsync_devicemaps),
 * with the signatures used for delta detection.
 */
final class DeviceMapRepository
{
    public const TABLE = 'glpi_plugin_jamfsync_devicemaps';

    public function find(int $instanceId, string $deviceId, string $udid, string $serial): ?array
    {
        global $DB;

        if ($deviceId !== '') {
            $map = $DB->request([
                'FROM' => self::TABLE,
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
                'FROM' => self::TABLE,
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
                'FROM' => self::TABLE,
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

    public function upsert(
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
            'FROM' => self::TABLE,
            'WHERE' => $where,
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            $DB->update(self::TABLE, $payload, ['id' => (int) $existing['id']]);
            return;
        }

        $payload['date_creation'] = $seenAt;
        $DB->insert(self::TABLE, $payload);
    }

    /**
     * Devices of an instance that its last successful run no longer returned from JAMF
     * (retired, deleted or moved to another JAMF instance). Every run that fetched the
     * device list stamps last_seen_at with its start time, so "seen before the last
     * successful run started" means "missing from that run".
     *
     * @return array{total: int, rows: list<array<string, mixed>>, last_run: string}
     */
    public function findMissing(int $instanceId, int $limit = 500): array
    {
        global $DB;

        $result = ['total' => 0, 'rows' => [], 'last_run' => ''];
        $lastRun = $DB->request([
            'SELECT' => ['started_at'],
            'FROM' => SyncRun::getTable(),
            'WHERE' => [
                'jamf_instances_id' => $instanceId,
                'status' => 'success',
            ],
            'ORDER' => ['started_at DESC'],
            'LIMIT' => 1,
        ])->current();
        if (!$lastRun || (string) ($lastRun['started_at'] ?? '') === '') {
            return $result;
        }

        $where = [
            self::TABLE . '.jamf_instances_id' => $instanceId,
            self::TABLE . '.last_seen_at' => ['<', $lastRun['started_at']],
        ];
        $result['last_run'] = (string) $lastRun['started_at'];
        $result['total'] = countElementsInTable(self::TABLE, $where);
        if ($result['total'] === 0) {
            return $result;
        }

        $iterator = $DB->request([
            'SELECT' => [
                self::TABLE . '.jamf_device_id',
                self::TABLE . '.jamf_serial',
                self::TABLE . '.computers_id',
                self::TABLE . '.last_seen_at',
                self::TABLE . '.last_checkin_at',
                'glpi_computers.name AS computer_name',
                'glpi_computers.is_deleted AS computer_is_deleted',
            ],
            'FROM' => self::TABLE,
            'LEFT JOIN' => [
                'glpi_computers' => [
                    'FKEY' => [self::TABLE => 'computers_id', 'glpi_computers' => 'id'],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => [self::TABLE . '.last_seen_at DESC'],
            'LIMIT' => $limit,
        ]);
        foreach ($iterator as $row) {
            $result['rows'][] = $row;
        }

        return $result;
    }
}
