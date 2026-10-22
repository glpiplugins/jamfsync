<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Throwable;
use Toolbox;

/**
 * Sync job queue (glpi_plugin_jamfsync_syncjobs) and its single worker.
 *
 * Jobs are queued by the sync runner (SyncController) and by the automatic action
 * PullFromJamf, and run by the automatic actions through work(). Job states:
 * queued -> running -> success | error | stalled (worker died or plugin upgrade).
 */
final class SyncJobQueue
{
    public const TABLE = 'glpi_plugin_jamfsync_syncjobs';

    /**
     * MySQL named lock held by the (single) queue worker for its whole loop. MySQL releases
     * it when the worker's connection ends, so a crashed worker never blocks the queue, and
     * "status running while the lock is free" reliably means the worker died.
     */
    private const WORKER_LOCK = 'plugin_jamfsync_worker';

    /**
     * Queue a sync job for the automatic action ProcessSyncQueue. Returns the already
     * pending job instead when one with the same mode and instance is queued or running
     * (double clicks, several admins, cron overlap).
     *
     * @return array{job_id: int, deduplicated: bool}
     */
    public function queue(string $mode, int $instanceId = 0): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return ['job_id' => 0, 'deduplicated' => false];
        }

        $pending = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::TABLE,
            'WHERE' => [
                'status' => ['queued', 'running'],
                'mode' => $mode,
                'instance_id' => max(0, $instanceId),
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();
        if ($pending) {
            return ['job_id' => (int) $pending['id'], 'deduplicated' => true];
        }

        return ['job_id' => $this->create($mode, $instanceId), 'deduplicated' => false];
    }

    /**
     * Work the job queue, oldest job first, running each job through SyncService::run().
     * Called by the automatic actions only; returns immediately when another worker already
     * holds the queue.
     *
     * @return array{jobs: int, failed: int, devices_count: int, apps_count: int, messages: list<string>}
     */
    public function work(SyncService $service, int $maxJobs = 10): array
    {
        global $DB;

        $summary = ['jobs' => 0, 'failed' => 0, 'devices_count' => 0, 'apps_count' => 0, 'messages' => []];
        if (!$DB->tableExists(self::TABLE) || !$DB->getLock(self::WORKER_LOCK)) {
            return $summary;
        }

        try {
            $this->abortOrphanedJobs();
            $this->prepareRuntime();

            while ($summary['jobs'] < $maxJobs && ($job = $this->claimNext()) !== null) {
                $jobId = (int) $job['id'];
                try {
                    $result = $service->run((string) $job['mode'], (int) $job['instance_id'], $jobId);
                } catch (Throwable $exception) {
                    // run() catches per-instance failures itself; this only guards against
                    // errors outside that loop so the job does not stay "running".
                    $result = ['status' => 'error', 'message' => $exception->getMessage()];
                    $this->update($jobId, [
                        'status' => 'error',
                        'error_code' => 'worker_exception',
                        'error_message' => $result['message'],
                        'message' => $result['message'],
                        'finished_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                $summary['jobs']++;
                if (($result['status'] ?? 'error') !== 'success') {
                    $summary['failed']++;
                }
                $summary['devices_count'] += (int) ($result['devices_count'] ?? 0);
                $summary['apps_count'] += (int) ($result['apps_count'] ?? 0);
                $summary['messages'][] = sprintf('#%d (%s): %s', $jobId, (string) $job['mode'], (string) ($result['message'] ?? ''));
            }
        } finally {
            $DB->releaseLock(self::WORKER_LOCK);
        }

        return $summary;
    }

    public function get(int $jobId): ?array
    {
        global $DB;

        if ($jobId <= 0 || !$DB->tableExists(self::TABLE)) {
            return null;
        }

        $row = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['id' => $jobId],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return null;
        }

        // A job is only "running" while the worker holds its lock; a free lock means the
        // worker died (killed process, fatal error) and the job will never finish.
        if ((string) ($row['status'] ?? '') === 'running' && !$this->isWorkerAlive()) {
            $now = date('Y-m-d H:i:s');
            $stalledMessage = __('The sync worker stopped unexpectedly; the job was aborted.', 'jamfsync');
            $this->update($jobId, [
                'status' => 'stalled',
                'error_code' => 'worker_aborted',
                'error_message' => $stalledMessage,
                'message' => $stalledMessage,
                'finished_at' => $now,
                'date_mod' => $now,
            ]);
            $row['status'] = 'stalled';
            $row['error_code'] = 'worker_aborted';
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

    /**
     * The most recent still-active (queued/running) sync job, if any. Used by the UI to
     * show a persistent "sync running" indicator that survives page reloads. Returns the
     * same shape as get() (progress_percent + stall detection applied).
     *
     * @return array<string, mixed>|null
     */
    public function getLatestActive(): ?array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }

        // Prefer the running job; otherwise the most recently queued one.
        foreach (['running', 'queued'] as $status) {
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM' => self::TABLE,
                'WHERE' => ['status' => $status],
                'ORDER' => ['id DESC'],
                'LIMIT' => 1,
            ])->current();
            if ($row) {
                return $this->get((int) $row['id']);
            }
        }

        return null;
    }

    public function update(int $jobId, array $fields): void
    {
        global $DB;

        if ($jobId <= 0 || $fields === [] || !$DB->tableExists(self::TABLE)) {
            return;
        }

        // A worker run keeps $_SESSION['glpi_currenttime'] frozen for its whole duration;
        // stamp the job with real wall-clock time so progress timestamps stay meaningful.
        $fields['date_mod'] = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, $fields, ['id' => $jobId]);
    }

    private function create(string $mode, int $instanceId = 0): int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $isFull = str_contains($mode, 'full') ? 1 : 0;

        $DB->insert(self::TABLE, [
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

    /**
     * @return array<string, mixed>|null The claimed job row, now in status "running".
     */
    private function claimNext(): ?array
    {
        global $DB;

        $job = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['status' => 'queued'],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ])->current();
        if (!$job) {
            return null;
        }

        $DB->update(self::TABLE, [
            'status' => 'running',
            'message' => __('Sync started', 'jamfsync'),
            'date_mod' => date('Y-m-d H:i:s'),
        ], [
            'id' => (int) $job['id'],
            'status' => 'queued',
        ]);

        return $DB->getAffectedRows() === 1 ? $job : null;
    }

    /**
     * Only called while holding the worker lock: any job still "running" then belongs to a
     * worker that died (killed process, fatal error, memory exhaustion).
     */
    private function abortOrphanedJobs(): void
    {
        global $DB;

        $message = __('The sync worker stopped unexpectedly; the job was aborted.', 'jamfsync');
        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, [
            'status' => 'stalled',
            'error_code' => 'worker_aborted',
            'error_message' => $message,
            'message' => $message,
            'finished_at' => $now,
            'date_mod' => $now,
        ], [
            'status' => 'running',
        ]);
    }

    private function isWorkerAlive(): bool
    {
        global $DB;

        if ($DB->getLock(self::WORKER_LOCK)) {
            $DB->releaseLock(self::WORKER_LOCK);
            return false;
        }

        return true;
    }

    /**
     * Large first-time syncs (hundreds of devices) go through GLPI's memory-heavy native
     * inventory engine; make sure the worker has room and no execution time limit.
     */
    private function prepareRuntime(): void
    {
        $required = 1024 * 1024 * 1024;
        $current = Toolbox::getMemoryLimit();
        if ($current > 0 && $current < $required) {
            ini_set('memory_limit', '1024M');
        }
        set_time_limit(0);
    }
}
