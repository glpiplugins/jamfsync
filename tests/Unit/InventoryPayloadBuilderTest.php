<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Service\InventoryPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class InventoryPayloadBuilderTest extends TestCase
{
    /** @return array{device_id: string, udid: string, serial: string, uuid: string} */
    private function ids(string $deviceId = 'D1', string $udid = 'UDID-1', string $serial = 'SER1', string $uuid = 'UUID-1'): array
    {
        return ['device_id' => $deviceId, 'udid' => $udid, 'serial' => $serial, 'uuid' => $uuid];
    }

    public function testBuildsCoreInventoryDocument(): void
    {
        $device = [
            'name' => 'Lab iPad 3',
            'assetTag' => 'AT-100',
            'model' => 'iPad13,4',
            'osName' => 'iPadOS',
            'osVersion' => '17.5.1',
            'architecture' => 'arm64',
            'totalCapacity' => 128,     // GB
            'availableCapacity' => 64,  // GB
            'wifiMac' => 'a1b2c3d4e5f6',
            'ip' => '10.0.0.5',
            'apps' => [
                ['name' => 'Pages', 'version' => '13.2'],
                ['name' => 'Numbers'],
            ],
        ];

        $doc = InventoryPayloadBuilder::build($device, ['id' => 2, 'name_source' => 'device_name'], $this->ids());

        self::assertSame('jamfsync-2-D1', $doc->deviceid);
        self::assertSame('inventory', $doc->action);
        self::assertSame('Computer', $doc->itemtype);

        $c = $doc->content;
        self::assertStringStartsWith('JAMFSync-Plugin_v', $c->versionclient);
        self::assertSame('Lab iPad 3', $c->hardware->name);
        self::assertSame('UUID-1', $c->hardware->uuid);
        self::assertSame('SER1', $c->bios->ssn);
        self::assertSame('iPad13,4', $c->bios->smodel);
        self::assertSame('Apple', $c->bios->smanufacturer); // inferred from iPadOS platform
        self::assertSame('AT-100', $c->bios->assettag);

        self::assertSame('iPadOS', $c->operatingsystem->name);
        self::assertSame('17.5.1', $c->operatingsystem->version);
        self::assertSame('arm64', $c->operatingsystem->arch);

        self::assertCount(2, $c->softwares);
        self::assertSame('Pages', $c->softwares[0]->name);
        self::assertSame('13.2', $c->softwares[0]->version);
        self::assertSame('unknown', $c->softwares[1]->version);

        self::assertSame(128 * 1024, $c->drives[0]->total); // GB -> MiB
        self::assertSame(64 * 1024, $c->drives[0]->free);

        self::assertSame('A1:B2:C3:D4:E5:F6', $c->networks[0]->mac);
        self::assertSame('10.0.0.5', $c->networks[0]->ipaddress);
    }

    public function testAssetTagNameSourcePrefersAssetTag(): void
    {
        $device = ['name' => 'DeviceName', 'assetTag' => 'AT-777'];
        $doc = InventoryPayloadBuilder::build($device, ['id' => 1, 'name_source' => 'asset_tag'], $this->ids());
        self::assertSame('AT-777', $doc->content->hardware->name);
    }

    public function testOsNameIsNeverStoredAsHardwareModel(): void
    {
        // model field carrying an OS name must be dropped, not used as smodel.
        $device = ['name' => 'X', 'model' => 'macOS', 'osName' => 'macOS', 'osVersion' => '14.5'];
        $doc = InventoryPayloadBuilder::build($device, ['id' => 1, 'name_source' => 'device_name'], $this->ids());
        self::assertFalse(isset($doc->content->bios->smodel), 'OS name must not become a model');
        self::assertSame('macOS', $doc->content->operatingsystem->name);
    }

    public function testMinimalDeviceStillProducesValidRoot(): void
    {
        $doc = InventoryPayloadBuilder::build([], ['id' => 5], $this->ids('', '', '', ''));
        // Root requires deviceid + content.versionclient.
        self::assertSame('jamfsync-5-unknown', $doc->deviceid);
        self::assertNotEmpty($doc->content->versionclient);
        self::assertFalse(isset($doc->content->operatingsystem));
        self::assertFalse(isset($doc->content->drives));
    }

    public function testSoftwaresOmittedWhenAppsDisabled(): void
    {
        $device = ['name' => 'X', 'apps' => [['name' => 'Pages', 'version' => '1']]];
        $doc = InventoryPayloadBuilder::build($device, ['id' => 1, 'name_source' => 'device_name'], $this->ids(), false);
        self::assertFalse(isset($doc->content->softwares), 'softwares must be omitted when pull_apps is off');
    }

    public function testDeviceIdFallsBackToUdidThenSerial(): void
    {
        self::assertSame('jamfsync-3-udid:U9', InventoryPayloadBuilder::deviceId(
            ['id' => 3],
            ['device_id' => '', 'udid' => 'udid:U9', 'serial' => 'S', 'uuid' => '']
        ));
    }

    public function testNormalizeMacAddressValidAndInvalid(): void
    {
        self::assertSame('AA:BB:CC:DD:EE:FF', InventoryPayloadBuilder::normalizeMacAddress('aa-bb-cc-dd-ee-ff'));
        self::assertSame('', InventoryPayloadBuilder::normalizeMacAddress('ABC'));
    }

    public function testExtractBatteryPercentHandlesFractionsPercentagesAndBounds(): void
    {
        self::assertSame(43, InventoryPayloadBuilder::extractBatteryPercent(['batteryLevel' => '0.43']));
        self::assertSame(87, InventoryPayloadBuilder::extractBatteryPercent(['batteryLevel' => '87%']));
        self::assertSame(100, InventoryPayloadBuilder::extractBatteryPercent(['battery' => ['charge' => '103']]));
        self::assertNull(InventoryPayloadBuilder::extractBatteryPercent(['batteryLevel' => 'n/a']));
    }

    public function testNormalizeSizeToMibHandlesCommonUnitsAndAutoMode(): void
    {
        self::assertSame(44032, InventoryPayloadBuilder::normalizeSizeToMib('43 GB', 'auto'));
        self::assertSame(512, InventoryPayloadBuilder::normalizeSizeToMib('512 MiB', 'auto'));
        self::assertSame(1, InventoryPayloadBuilder::normalizeSizeToMib('1048576', 'auto'));
        self::assertSame(0, InventoryPayloadBuilder::normalizeSizeToMib('-1', 'auto'));
    }

    public function testNormalizeDateTimeReturnsSqlFormatOrEmptyString(): void
    {
        self::assertSame('2026-02-25 08:33:22', InventoryPayloadBuilder::normalizeDateTime('2026-02-25 08:33:22'));
        self::assertSame('', InventoryPayloadBuilder::normalizeDateTime('not-a-date'));
    }
}
