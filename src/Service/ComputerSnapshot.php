<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

/**
 * Before/after view of a synced computer (core fields, OS, dynamic software) and the
 * monitoring change entries derived from two such snapshots.
 */
final class ComputerSnapshot
{
    public function build(int $computerId): array
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
            'entities_id' => (int) ($computer['entities_id'] ?? 0),
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

    /**
     * Build monitoring run-change entries by diffing the computer snapshot taken before and
     * after the inventory submission (device core fields, OS, and dynamic software list).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array{device_id: string, udid: string, serial: string, uuid: string} $identifiers
     * @return array<int, array<string, mixed>>
     */
    public static function diff(bool $wasNew, array $before, array $after, array $identifiers, int $computerId, string $name): array
    {
        $deviceId = $identifiers['device_id'];
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
                'before_json' => self::encodeJsonForLog($wasNew ? [] : $coreBefore),
                'after_json' => self::encodeJsonForLog($coreAfter),
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
                'before_json' => self::encodeJsonForLog($osBefore),
                'after_json' => self::encodeJsonForLog($osAfter),
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
                $entries[] = self::appChangeEntry($deviceId, $computerId, $name, (string) $app, __('App added', 'jamfsync'));
            }
            foreach (array_values(array_diff($swBefore, $swAfter)) as $app) {
                $entries[] = self::appChangeEntry($deviceId, $computerId, $name, (string) $app, __('App removed', 'jamfsync'));
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function appChangeEntry(string $deviceId, int $computerId, string $name, string $appLabel, string $details): array
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

    private static function encodeJsonForLog($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && $value === []) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === 'null') {
            return null;
        }

        return $encoded;
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
}
