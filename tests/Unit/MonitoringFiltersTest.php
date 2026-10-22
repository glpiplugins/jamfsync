<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Ui\MonitoringPage;
use PHPUnit\Framework\TestCase;

final class MonitoringFiltersTest extends TestCase
{
    public function testDefaultsForEmptyQuery(): void
    {
        self::assertSame([
            'instance_id' => 0,
            'entity_id' => 0,
            'status' => '',
            'date_from' => '',
            'date_to' => '',
            'limit' => 100,
            'run_id' => 0,
            'show_changes' => false,
            'changes_tab' => 'devices',
            'start' => 0,
            'list_limit' => 50,
        ], MonitoringPage::normalizeFilters([]));
    }

    public function testValidValuesArePassedThrough(): void
    {
        $filters = MonitoringPage::normalizeFilters([
            'instance_id' => '3',
            'entity_id' => '7',
            'status' => 'error',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-22',
            'limit' => '250',
            'run_id' => '42',
            'show_changes' => '1',
            'changes_tab' => 'apps',
            'start' => '100',
            'list_limit' => '20',
        ]);

        self::assertSame(3, $filters['instance_id']);
        self::assertSame(7, $filters['entity_id']);
        self::assertSame('error', $filters['status']);
        self::assertSame('2026-10-01', $filters['date_from']);
        self::assertSame('2026-10-22', $filters['date_to']);
        self::assertSame(250, $filters['limit']);
        self::assertSame(42, $filters['run_id']);
        self::assertTrue($filters['show_changes']);
        self::assertSame('apps', $filters['changes_tab']);
        self::assertSame(100, $filters['start']);
        self::assertSame(20, $filters['list_limit']);
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $filters = MonitoringPage::normalizeFilters([
            'instance_id' => '-5',
            'status' => 'running',
            'date_from' => '2026-02-30',
            'date_to' => "2026-10-22' OR 1=1",
            'limit' => '100000',
            'changes_tab' => 'computers',
            'list_limit' => '1',
            'run_id' => ['1'],
        ]);

        self::assertSame(0, $filters['instance_id']);
        self::assertSame('', $filters['status']);
        self::assertSame('', $filters['date_from']);
        self::assertSame('', $filters['date_to']);
        self::assertSame(500, $filters['limit']);
        self::assertSame('devices', $filters['changes_tab']);
        self::assertSame(10, $filters['list_limit']);
        self::assertSame(0, $filters['run_id']);
    }
}
