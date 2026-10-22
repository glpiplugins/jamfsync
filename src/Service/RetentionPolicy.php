<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Config;
use GlpiPlugin\Jamfsync\DeviceMapRepository;
use GlpiPlugin\Jamfsync\RunChange;
use GlpiPlugin\Jamfsync\SyncRun;

/**
 * Retention periods of the plugin's history (set on Setup > Data and Privacy > JAMF Sync)
 * and the purge run by the automatic action PurgeHistory.
 *
 * Run changes hold computer names (which may contain personal data) and before/after
 * snapshots, so they get the shortest period; sync runs only hold counts and messages.
 */
final class RetentionPolicy
{
    public const CONTEXT = 'plugin:jamfsync';

    /** Config name => default retention in days (0 = keep forever). */
    public const DEFAULTS = [
        'retention_runchanges_days' => 90,
        'retention_syncruns_days' => 365,
        'retention_missing_devicemaps_days' => 0,
    ];

    public const MAX_DAYS = 3650;

    /** Finished jobs are only progress records; they are not configurable. */
    public const JOBS_DAYS = 30;

    /** Rows deleted per statement, so a large purge does not lock the tables for long. */
    private const BATCH = 1000;

    /**
     * @return array<string, int> Effective retention periods (stored values, else defaults).
     */
    public static function settings(): array
    {
        $stored = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));

        return self::normalize($stored + self::DEFAULTS);
    }

    /**
     * Validate submitted or stored values: whole days between 0 and MAX_DAYS, defaults for
     * anything unusable.
     *
     * @param array<string, mixed> $values
     * @return array<string, int>
     */
    public static function normalize(array $values): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $name => $default) {
            $value = $values[$name] ?? $default;
            $settings[$name] = is_numeric($value) && (int) $value >= 0
                ? min((int) $value, self::MAX_DAYS)
                : $default;
        }

        return $settings;
    }

    /**
     * Store the defaults that are not set yet (install and upgrade).
     */
    public static function installDefaults(): void
    {
        $stored = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
        $missing = array_diff_key(self::DEFAULTS, $stored);
        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /**
     * Delete history older than the retention periods.
     *
     * @return array{runchanges: int, syncruns: int, syncjobs: int, devicemaps: int}
     */
    public function purge(?int $now = null): array
    {
        $now ??= time();
        $settings = self::settings();
        $deleted = ['runchanges' => 0, 'syncruns' => 0, 'syncjobs' => 0, 'devicemaps' => 0];

        if ($settings['retention_syncruns_days'] > 0) {
            $cutoff = self::cutoff($now, $settings['retention_syncruns_days']);
            // A run's changes go with the run.
            foreach ($this->idBatches(SyncRun::getTable(), ['date_creation' => ['<', $cutoff]]) as $runIds) {
                $deleted['runchanges'] += $this->delete(RunChange::getTable(), ['syncruns_id' => $runIds]);
                $deleted['syncruns'] += $this->delete(SyncRun::getTable(), ['id' => $runIds]);
            }
        }

        if ($settings['retention_runchanges_days'] > 0) {
            $cutoff = self::cutoff($now, $settings['retention_runchanges_days']);
            foreach ($this->idBatches(RunChange::getTable(), ['date_creation' => ['<', $cutoff]]) as $ids) {
                $deleted['runchanges'] += $this->delete(RunChange::getTable(), ['id' => $ids]);
            }
        }

        $cutoff = self::cutoff($now, self::JOBS_DAYS);
        $where = ['status' => ['success', 'error', 'stalled'], 'date_creation' => ['<', $cutoff]];
        foreach ($this->idBatches(SyncJobQueue::TABLE, $where) as $ids) {
            $deleted['syncjobs'] += $this->delete(SyncJobQueue::TABLE, ['id' => $ids]);
        }

        if ($settings['retention_missing_devicemaps_days'] > 0) {
            // Not seen for that long: the device is gone from JAMF (or its instance stopped
            // syncing). A device that comes back is mapped again by the next sync.
            $cutoff = self::cutoff($now, $settings['retention_missing_devicemaps_days']);
            foreach ($this->idBatches(DeviceMapRepository::TABLE, ['last_seen_at' => ['<', $cutoff]]) as $ids) {
                $deleted['devicemaps'] += $this->delete(DeviceMapRepository::TABLE, ['id' => $ids]);
            }
        }

        return $deleted;
    }

    /**
     * Timestamp string of "$days days before $now", in the same local-time format the plugin
     * writes its date columns with.
     */
    public static function cutoff(int $now, int $days): string
    {
        return date('Y-m-d H:i:s', $now - $days * 86400);
    }

    /**
     * @param array<string, mixed> $where
     * @return \Generator<int, list<int>>
     */
    private function idBatches(string $table, array $where): \Generator
    {
        global $DB;

        // Keyset pagination on id: terminates even if a batch could not be deleted.
        $lastId = 0;
        while (true) {
            $ids = [];
            $iterator = $DB->request([
                'SELECT' => ['id'],
                'FROM' => $table,
                'WHERE' => $where + [['id' => ['>', $lastId]]],
                'ORDER' => ['id ASC'],
                'LIMIT' => self::BATCH,
            ]);
            foreach ($iterator as $row) {
                $ids[] = (int) $row['id'];
            }
            if ($ids === []) {
                return;
            }
            $lastId = max($ids);
            yield $ids;
            if (count($ids) < self::BATCH) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $where
     */
    private function delete(string $table, array $where): int
    {
        global $DB;

        $DB->delete($table, $where);

        return $DB->getAffectedRows();
    }
}
