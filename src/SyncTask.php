<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use CommonGLPI;
use CronTask;
use GlpiPlugin\Jamfsync\Service\RetentionPolicy;
use GlpiPlugin\Jamfsync\Service\SyncJobQueue;
use GlpiPlugin\Jamfsync\Service\SyncService;

/**
 * Automatic actions of the plugin. Every sync runs through one of them:
 * - PullFromJamf (hourly): queues a scheduled pull and works the queue.
 * - ProcessSyncQueue (every minute): works jobs queued from the sync runner; the runner
 *   also launches it right after queueing (SyncController::start()).
 * - PurgeHistory (daily): deletes history older than the retention periods
 *   (Setup > Data and Privacy > JAMF Sync).
 * SyncJobQueue::work() serialises workers, so two syncs never run at once.
 */
final class SyncTask extends CommonGLPI
{
    public const TASK_PULL  = 'PullFromJamf';
    public const TASK_QUEUE = 'ProcessSyncQueue';
    public const TASK_PURGE = 'PurgeHistory';

    public static function getTypeName($nb = 0): string
    {
        return __('JAMF Sync', 'jamfsync');
    }

    /**
     * @return array{description?: string}
     */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            self::TASK_PULL  => ['description' => __('Pull devices and apps from JAMF', 'jamfsync')],
            self::TASK_QUEUE => ['description' => __('Process queued JAMF sync jobs', 'jamfsync')],
            self::TASK_PURGE => ['description' => __('Purge JAMF Sync history older than the retention periods', 'jamfsync')],
            default          => [],
        };
    }

    public static function cronPullFromJamf(CronTask $task): int
    {
        $queue = new SyncJobQueue();
        $queue->queue('cron');

        return self::workQueue($task, $queue);
    }

    public static function cronProcessSyncQueue(CronTask $task): int
    {
        return self::workQueue($task, new SyncJobQueue());
    }

    public static function cronPurgeHistory(CronTask $task): int
    {
        $deleted = (new RetentionPolicy())->purge();
        $total = array_sum($deleted);
        if ($total === 0) {
            return 0;
        }

        $task->setVolume($total);
        $task->log(sprintf(
            'Deleted: %d run changes, %d sync runs, %d sync jobs, %d device mappings',
            $deleted['runchanges'],
            $deleted['syncruns'],
            $deleted['syncjobs'],
            $deleted['devicemaps']
        ));

        return 1;
    }

    /**
     * @return int CronTask convention: 0 = nothing to do, 1 = done, -1 = done with errors.
     */
    private static function workQueue(CronTask $task, SyncJobQueue $queue): int
    {
        $summary = $queue->work(new SyncService($queue));
        if ($summary['jobs'] === 0) {
            return 0;
        }

        $task->setVolume($summary['devices_count'] + $summary['apps_count']);
        foreach ($summary['messages'] as $message) {
            $task->log($message);
        }

        return $summary['failed'] > 0 ? -1 : 1;
    }
}
