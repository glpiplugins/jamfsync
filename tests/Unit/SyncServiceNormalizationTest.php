<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Service\SyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SyncServiceNormalizationTest extends TestCase
{
    public function testNormalizeMacAddressValidAndInvalid(): void
    {
        $service = new SyncService();

        self::assertSame(
            'AA:BB:CC:DD:EE:FF',
            $this->callPrivate($service, 'normalizeMacAddress', ['aa-bb-cc-dd-ee-ff'])
        );

        self::assertSame('', $this->callPrivate($service, 'normalizeMacAddress', ['ABC']));
    }

    public function testExtractBatteryPercentHandlesFractionsPercentagesAndBounds(): void
    {
        $service = new SyncService();

        self::assertSame(43, $this->callPrivate($service, 'extractBatteryPercent', [['batteryLevel' => '0.43']]));
        self::assertSame(87, $this->callPrivate($service, 'extractBatteryPercent', [['batteryLevel' => '87%']]));
        self::assertSame(100, $this->callPrivate($service, 'extractBatteryPercent', [['battery' => ['charge' => '103']]]));
        self::assertNull($this->callPrivate($service, 'extractBatteryPercent', [['batteryLevel' => 'n/a']]));
    }

    public function testNormalizeSizeToMibHandlesCommonUnitsAndAutoMode(): void
    {
        $service = new SyncService();

        self::assertSame(44032, $this->callPrivate($service, 'normalizeSizeToMib', ['43 GB', 'auto']));
        self::assertSame(512, $this->callPrivate($service, 'normalizeSizeToMib', ['512 MiB', 'auto']));
        self::assertSame(1, $this->callPrivate($service, 'normalizeSizeToMib', ['1048576', 'auto']));
        self::assertSame(0, $this->callPrivate($service, 'normalizeSizeToMib', ['-1', 'auto']));
    }

    public function testNormalizeDateTimeReturnsSqlFormatOrEmptyString(): void
    {
        $service = new SyncService();

        self::assertSame('2026-02-25 08:33:22', $this->callPrivate($service, 'normalizeDateTime', ['2026-02-25 08:33:22']));
        self::assertSame('', $this->callPrivate($service, 'normalizeDateTime', ['not-a-date']));
    }

    private function callPrivate(object $object, string $method, array $args)
    {
        $reflection = new ReflectionClass($object);
        $instanceMethod = $reflection->getMethod($method);

        return $instanceMethod->invokeArgs($object, $args);
    }
}
