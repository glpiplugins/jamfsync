<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Service\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function testDefaults(): void
    {
        self::assertSame([
            'retention_runchanges_days' => 90,
            'retention_syncruns_days' => 365,
            'retention_missing_devicemaps_days' => 0,
        ], RetentionPolicy::normalize([]));
    }

    public function testValidValuesAreKeptAndClamped(): void
    {
        self::assertSame([
            'retention_runchanges_days' => 30,
            'retention_syncruns_days' => 0,
            'retention_missing_devicemaps_days' => RetentionPolicy::MAX_DAYS,
        ], RetentionPolicy::normalize([
            'retention_runchanges_days' => '30',
            'retention_syncruns_days' => '0',
            'retention_missing_devicemaps_days' => '99999',
        ]));
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $settings = RetentionPolicy::normalize([
            'retention_runchanges_days' => '-1',
            'retention_syncruns_days' => 'forever',
            'retention_missing_devicemaps_days' => ['7'],
        ]);

        self::assertSame(RetentionPolicy::DEFAULTS, $settings);
    }

    public function testCutoffIsWholeDaysBack(): void
    {
        $now = mktime(12, 0, 0, 10, 22, 2026);

        self::assertSame(date('Y-m-d H:i:s', mktime(12, 0, 0, 7, 24, 2026)), RetentionPolicy::cutoff($now, 90));
        self::assertSame(date('Y-m-d H:i:s', $now), RetentionPolicy::cutoff($now, 0));
    }
}
