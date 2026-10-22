<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Service\ComputerSnapshot;
use PHPUnit\Framework\TestCase;

final class ComputerSnapshotDiffTest extends TestCase
{
    private const IDS = ['device_id' => 'D1', 'udid' => 'U1', 'serial' => 'S1', 'uuid' => ''];

    public function testNewComputerLogsDeviceAndOsButNotEveryApp(): void
    {
        $after = $this->snapshot(['software' => ['Keynote 14.0', 'Pages 14.0']]);

        $entries = ComputerSnapshot::diff(true, [], $after, self::IDS, 7, 'iPad 1');

        self::assertSame(['device', 'os'], array_column($entries, 'change_type'));
        self::assertSame('Computer created', $entries[0]['details']);
        self::assertNull($entries[0]['before_json']);
        self::assertSame(7, $entries[0]['computers_id']);
    }

    public function testUnchangedComputerLogsNothing(): void
    {
        $snapshot = $this->snapshot(['software' => ['Pages 14.0']]);

        self::assertSame([], ComputerSnapshot::diff(false, $snapshot, $snapshot, self::IDS, 7, 'iPad 1'));
    }

    public function testExistingComputerLogsAppAndOsChanges(): void
    {
        $before = $this->snapshot(['software' => ['Keynote 14.0', 'Pages 14.0']]);
        $after = $this->snapshot([
            'software' => ['Numbers 14.0', 'Pages 14.0'],
            'os' => ['name' => 'iPadOS', 'version' => '18.7'],
        ]);

        $entries = ComputerSnapshot::diff(false, $before, $after, self::IDS, 7, 'iPad 1');

        self::assertSame(
            [['os', 'iPadOS 18.7'], ['app', 'Numbers 14.0'], ['app', 'Keynote 14.0']],
            array_map(static fn(array $entry): array => [$entry['change_type'], $entry['object_name']], $entries)
        );
        self::assertSame(['App added', 'App removed'], array_column(array_slice($entries, 1), 'details'));
    }

    public function testCoreFieldChangeIsLoggedWithBeforeAndAfter(): void
    {
        $before = $this->snapshot(['name' => 'iPad 1']);
        $after = $this->snapshot(['name' => 'iPad 1 (renamed)']);

        $entries = ComputerSnapshot::diff(false, $before, $after, self::IDS, 7, 'iPad 1 (renamed)');

        self::assertSame('device', $entries[0]['change_type']);
        self::assertStringContainsString('"name":"iPad 1"', (string) $entries[0]['before_json']);
        self::assertStringContainsString('renamed', (string) $entries[0]['after_json']);
    }

    public function testEntityMoveIsAChange(): void
    {
        $entries = ComputerSnapshot::diff(false, $this->snapshot(['entities_id' => 0]), $this->snapshot(['entities_id' => 3]), self::IDS, 7, 'iPad 1');

        self::assertSame(['device'], array_column($entries, 'change_type'));
        self::assertStringContainsString('"entities_id":3', (string) $entries[0]['after_json']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'name' => 'iPad 1',
            'entities_id' => 0,
            'serial' => 'S1',
            'udid' => '',
            'uuid' => '',
            'last_boot' => '',
            'comment' => '',
            'os' => ['name' => 'iPadOS', 'version' => '18.6'],
            'storage_total_mib' => 0,
            'storage_free_mib' => 0,
            'battery_percent' => 0,
            'public_ip' => '',
            'mac' => '',
            'software' => [],
        ];
    }
}
