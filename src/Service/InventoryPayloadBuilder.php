<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

/**
 * Maps a JAMF School device (raw associative array from the JAMF API) to a GLPI
 * native inventory document (the `glpi-project/inventory_format` JSON schema).
 *
 * The document is handed to \Glpi\Inventory\Inventory, which performs the actual
 * asset create/update with native GLPI history, locks and de-duplication — the
 * plugin no longer writes to core tables directly.
 *
 * All field extractors are pure and `public static` so SyncService can reuse them
 * for its change-signature/snapshot logic without duplicating the JAMF key maps.
 *
 * Notes:
 * - Entity is intentionally NOT forced here: GLPI assigns it via its own entity
 *   rules / inventory default. The instance's target entity is advisory only.
 * - Battery is deliberately omitted: JAMF reports a charge percentage while the
 *   inventory `batteries.real_capacity` expects a capacity (mWh); there is no
 *   lossless mapping.
 */
final class InventoryPayloadBuilder
{
    /** Identifies the inventory source (agent software) in the payload. */
    private const SOURCE = 'JAMFSync-Plugin';

    /**
     * @param array<string, mixed> $device      Raw JAMF device.
     * @param array<string, mixed> $instance     Instance row (for name_source).
     * @param array{device_id: string, udid: string, serial: string, uuid: string} $identifiers
     * @param bool $includeApps When false, the softwares section is omitted (pull_apps off).
     */
    public static function build(array $device, array $instance, array $identifiers, bool $includeApps = true): \stdClass
    {
        $version = defined('PLUGIN_JAMFSYNC_VERSION') ? (string) PLUGIN_JAMFSYNC_VERSION : '0';

        $content = new \stdClass();
        $content->versionclient = self::SOURCE . '_v' . $version;

        // --- hardware ---
        $hardware = new \stdClass();
        $hardware->name = self::resolveComputerNameFromDevice($instance, $device);
        if ($identifiers['uuid'] !== '') {
            $hardware->uuid = $identifiers['uuid'];
        }
        $content->hardware = $hardware;

        // --- bios / motherboard (serial, model, manufacturer, asset tag) ---
        $bios = new \stdClass();
        if ($identifiers['serial'] !== '') {
            $bios->ssn = $identifiers['serial'];
        }
        $model = self::resolveModel($device);
        if ($model !== '') {
            $bios->smodel = $model;
        }
        $manufacturer = self::resolveManufacturer($device);
        if ($manufacturer !== '') {
            $bios->smanufacturer = $manufacturer;
        }
        $assetTag = self::extractNestedScalar($device, ['assetTag', 'asset_tag']);
        if ($assetTag !== '') {
            $bios->assettag = $assetTag;
        }
        if ($bios != new \stdClass()) {
            $content->bios = $bios;
        }

        // --- operating system ---
        $os = self::buildOperatingSystem($device);
        if ($os !== null) {
            $content->operatingsystem = $os;
        }

        // --- softwares ---
        $softwares = [];
        foreach ($includeApps ? self::extractApps($device) : [] as $app) {
            $name = trim((string) ($app['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entry = new \stdClass();
            $entry->name = $name;
            $version_str = trim((string) ($app['version'] ?? ''));
            $entry->version = $version_str !== '' ? $version_str : 'unknown';
            $softwares[] = $entry;
        }
        if ($softwares !== []) {
            $content->softwares = $softwares;
        }

        // --- storage (as a logical volume: total/free in MB) ---
        $totalMib = self::extractStorageTotalBytes($device);
        $freeMib = self::extractStorageFreeBytes($device);
        if ($totalMib > 0) {
            if ($freeMib > $totalMib) {
                $freeMib = $totalMib;
            }
            $drive = new \stdClass();
            $drive->volumn = 'JAMF Storage';
            $drive->label = 'JAMF Storage';
            $drive->total = $totalMib;
            if ($freeMib > 0) {
                $drive->free = $freeMib;
            }
            $content->drives = [$drive];
        }

        // --- network (MAC + public IP) ---
        $mac = self::extractMacAddress($device);
        $ip = self::extractPublicIp($device);
        if ($mac !== '' || $ip !== '') {
            $net = new \stdClass();
            $net->description = 'JAMF Network';
            $net->type = 'wifi';
            if ($mac !== '') {
                $net->mac = $mac;
            }
            if ($ip !== '') {
                $net->ipaddress = $ip;
            }
            $content->networks = [$net];
        }

        $payload = new \stdClass();
        $payload->deviceid = self::deviceId($instance, $identifiers);
        $payload->action = 'inventory';
        $payload->itemtype = 'Computer';
        $payload->content = $content;

        return $payload;
    }

    public static function deviceId(array $instance, array $identifiers): string
    {
        $instanceId = (int) ($instance['id'] ?? 0);
        $ref = (string) ($identifiers['device_id'] ?? '');
        if ($ref === '') {
            $ref = (string) ($identifiers['udid'] ?? '');
        }
        if ($ref === '') {
            $ref = (string) ($identifiers['serial'] ?? '');
        }
        if ($ref === '') {
            $ref = 'unknown';
        }

        return 'jamfsync-' . $instanceId . '-' . $ref;
    }

    private static function buildOperatingSystem(array $device): ?\stdClass
    {
        $name = self::extractNestedScalar($device, [
            'os.prefix', 'osName', 'os.name', 'operatingSystemName', 'operatingSystem', 'platform',
        ]);
        $version = self::extractNestedScalar($device, [
            'osVersion', 'os.version', 'operatingSystemVersion', 'systemVersion',
        ]);
        if ($name === '' && $version !== '' && preg_match('/^([A-Za-z0-9._\- ]+)/', $version, $m) === 1) {
            $name = trim($m[1]);
        }
        if ($name === '') {
            return null;
        }

        $os = new \stdClass();
        $os->name = $name;
        if ($version !== '') {
            $os->version = $version;
        }
        $arch = self::extractNestedScalar($device, ['architecture', 'os.architecture', 'arch']);
        if ($arch !== '') {
            $os->arch = $arch;
        }
        $kernel = self::extractNestedScalar($device, ['kernelVersion', 'os.kernelVersion', 'kernel']);
        if ($kernel !== '') {
            $os->kernel_version = $kernel;
        }
        $installDate = self::normalizeDateTime(self::extractNestedScalar($device, [
            'os.installDate', 'os.installedAt', 'operatingSystemInstallDate', 'installDate', 'installedAt',
        ]));
        if ($installDate !== '') {
            $os->install_date = $installDate;
        }
        $boot = self::extractLastActiveAt($device);
        if ($boot !== '') {
            $os->boot_time = $boot;
        }

        return $os;
    }

    private static function resolveManufacturer(array $device): string
    {
        $manufacturer = self::extractNestedScalar($device, [
            'manufacturer', 'vendor', 'hardware.manufacturer', 'hardware.vendor',
            'deviceManufacturer', 'brand', 'general.manufacturer',
        ]);
        $platform = strtolower(self::extractNestedScalar($device, [
            'os.prefix', 'osName', 'os.name', 'operatingSystemName', 'operatingSystem', 'platform',
        ]));
        if ($manufacturer === '' && preg_match('/\b(ios|ipados|macos|os x|tvos|watchos)\b/i', $platform) === 1) {
            return 'Apple';
        }

        return $manufacturer;
    }

    private static function resolveModel(array $device): string
    {
        $model = self::extractNestedScalar($device, [
            'model', 'model.name', 'model.identifier', 'model.model', 'modelName',
            'hardware.model', 'hardware.modelName', 'model_identifier', 'modelIdentifier',
            'productName', 'deviceModel', 'deviceModelName', 'general.model',
        ]);
        // Never store OS names as hardware model.
        if (preg_match('/^(ios|ipados|macos|os\s*x|android|windows)$/i', trim($model)) === 1) {
            $model = '';
        }
        if ($model === '') {
            $model = self::extractNestedScalar($device, [
                'deviceType', 'hardware.deviceType', 'deviceModelType', 'general.deviceType',
            ]);
            if (preg_match('/^(ios|ipados|macos|os\s*x|android|windows)$/i', trim($model)) === 1) {
                $model = '';
            }
        }

        return $model;
    }

    public static function resolveComputerNameFromDevice(array $instance, array $device): string
    {
        $nameSource = trim(strtolower((string) ($instance['name_source'] ?? 'device_name')));
        $deviceName = self::extractScalar($device, ['name', 'deviceName', 'device_name']);
        $assetTag = self::extractNestedScalar($device, ['assetTag', 'asset_tag']);
        $serial = self::extractScalar($device, ['serialNumber', 'serialnumber', 'serial']);
        $udid = self::extractScalar($device, ['udid', 'UDID']);

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

    /**
     * @return array<int, array{name: string, version: string}>
     */
    public static function extractApps(array $device): array
    {
        foreach (['apps', 'installedApps', 'media', 'installedMedia'] as $key) {
            if (isset($device[$key]) && is_array($device[$key])) {
                $apps = [];
                foreach ($device[$key] as $row) {
                    if (is_string($row) || is_int($row) || is_float($row)) {
                        $name = trim((string) $row);
                        if ($name !== '') {
                            $apps[] = ['name' => $name, 'version' => ''];
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
                        'name' => self::extractScalar($candidate, [
                            'name', 'appName', 'title', 'displayName', 'bundleName',
                            'identifier', 'bundleIdentifier',
                        ]),
                        'version' => self::extractScalar($candidate, [
                            'version', 'appVersion', 'shortVersion', 'shortVersionString',
                            'bundleVersion', 'buildVersion',
                        ]),
                    ];
                }
                return $apps;
            }
        }

        return [];
    }

    public static function extractScalar(array $data, array $keys): string
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

    public static function extractNestedScalar(array $data, array $paths): string
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

    public static function extractLastActiveAt(array $device): string
    {
        $candidates = [
            self::extractNestedScalar($device, [
                'lastActivity', 'lastActivityAt', 'lastSeenAt', 'lastCheckIn', 'lastCheckin',
                'lastCheckInAt', 'lastContact', 'lastSync',
            ]),
            self::extractNestedScalar($device, [
                'last_active', 'last_seen', 'last_activity', 'activity.lastSeenAt',
                'activity.lastContact', 'activity.lastCheckin',
            ]),
        ];
        foreach ($candidates as $value) {
            $ts = self::normalizeDateTime($value);
            if ($ts !== '') {
                return $ts;
            }
        }

        return self::extractLastCheckinAt($device);
    }

    public static function extractLastCheckinAt(array $device): string
    {
        $candidates = [
            self::extractNestedScalar($device, ['lastCheckin', 'lastCheckIn', 'last_checkin']),
            self::extractNestedScalar($device, ['lastContact', 'lastSeenAt', 'activity.lastCheckin']),
        ];
        foreach ($candidates as $value) {
            $ts = self::normalizeDateTime($value);
            if ($ts !== '') {
                return $ts;
            }
        }

        return '';
    }

    public static function extractEnrollmentDate(array $device): string
    {
        $candidates = [
            self::extractNestedScalar($device, ['enrollmentDate', 'enrolledAt', 'enrolled', 'dateEnrolled']),
            self::extractNestedScalar($device, ['dep.enrollmentDate', 'dep.enrolledAt', 'management.enrollmentDate']),
            self::extractNestedScalar($device, ['created', 'dateCreated']),
        ];
        foreach ($candidates as $value) {
            $ts = self::normalizeDateTime($value);
            if ($ts !== '') {
                return $ts;
            }
        }

        return '';
    }

    public static function extractPublicIp(array $device): string
    {
        return self::extractNestedScalar($device, [
            'networkInformation.IPAddress', 'networkInformation.ipAddress',
            'publicIP', 'publicIp', 'ip', 'ipAddress', 'network.ipAddress',
        ]);
    }

    public static function extractMacAddress(array $device): string
    {
        $mac = self::extractNestedScalar($device, [
            'networkInformation.WiFiMAC', 'networkInformation.wifiMac',
            'networkInformation.BluetoothMAC', 'networkInformation.bluetoothMac',
            'WiFiMAC', 'wifiMac', 'bluetoothMac', 'macAddress', 'mac',
        ]);

        return self::normalizeMacAddress($mac);
    }

    public static function normalizeMacAddress(string $mac): string
    {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac) ?? '');
        if (strlen($mac) !== 12) {
            return '';
        }

        return implode(':', str_split($mac, 2));
    }

    public static function extractBatteryPercent(array $device): ?int
    {
        $candidates = [
            self::extractNestedScalar($device, ['batteryLevel', 'battery_level', 'batteryPercent', 'battery.percent']),
            self::extractNestedScalar($device, ['battery.charge', 'battery.level', 'power.batteryLevel']),
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

    public static function extractStorageTotalBytes(array $device): int
    {
        $jamfGb = self::extractNestedScalar($device, ['totalCapacity', 'storage.totalCapacity']);
        if ($jamfGb !== '') {
            $mib = self::normalizeSizeToMib($jamfGb, 'GB');
            if ($mib > 0) {
                return $mib;
            }
        }
        $candidates = [
            self::extractNestedScalar($device, ['capacity', 'totalSpace', 'totalStorage', 'storage.total', 'storage.totalSpace']),
            self::extractNestedScalar($device, ['disk.total', 'disk.totalBytes', 'storage.capacity']),
        ];
        foreach ($candidates as $raw) {
            $mib = self::normalizeSizeToMib($raw, 'auto');
            if ($mib > 0) {
                return $mib;
            }
        }

        return 0;
    }

    public static function extractStorageFreeBytes(array $device): int
    {
        $jamfGb = self::extractNestedScalar($device, ['availableCapacity', 'storage.availableCapacity']);
        if ($jamfGb !== '') {
            $mib = self::normalizeSizeToMib($jamfGb, 'GB');
            if ($mib > 0) {
                return $mib;
            }
        }
        $candidates = [
            self::extractNestedScalar($device, ['availableCapacity', 'freeSpace', 'storage.free', 'storage.available', 'storage.availableSpace']),
            self::extractNestedScalar($device, ['disk.free', 'disk.freeBytes']),
        ];
        foreach ($candidates as $raw) {
            $mib = self::normalizeSizeToMib($raw, 'auto');
            if ($mib > 0) {
                return $mib;
            }
        }

        return 0;
    }

    public static function normalizeDateTime(string $value): string
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

    public static function normalizeSizeToMib(string $raw, string $assumeUnit = 'auto'): int
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
}
