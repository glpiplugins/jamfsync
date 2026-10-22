<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Controller;

use CronTask;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\Service\SyncJobQueue;
use GlpiPlugin\Jamfsync\SyncTask;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoints of the sync runner (mounted under /plugins/jamfsync/Sync/...).
 *
 * Starting a sync queues a job for the automatic action ProcessSyncQueue and then
 * triggers that action right away (see start()). Errors are answered as JSON (not GLPI's
 * HTML error page) because the callers are the runner and status banner fetch() calls.
 */
final class SyncController extends AbstractController
{
    #[Route('/Sync/Start', name: 'sync_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        if (!Access::canRun()) {
            return $this->error(__('Access denied'), 403);
        }

        $instanceId = max(0, $request->request->getInt('instance_id'));
        if ($instanceId > 0) {
            $instance = (new InstanceRepository())->getById($instanceId);
            if ($instance === null || (int) ($instance['is_active'] ?? 0) !== 1) {
                return $this->error(__('No matching active JAMF instance configured.', 'jamfsync'), 422);
            }
        }

        $mode = $request->request->getInt('full') === 1 ? 'manual_full' : 'manual';
        $queued = (new SyncJobQueue())->queue($mode, $instanceId);
        if ($queued['job_id'] <= 0) {
            return $this->error(__('Unable to create sync job.', 'jamfsync'), 500);
        }

        $payload = (string) json_encode([
            'ok' => true,
            'accepted' => true,
            'job_id' => $queued['job_id'],
            'deduplicated' => $queued['deduplicated'],
            'mode' => $mode,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Same pattern as GLPI's own front/cron.php: send the complete answer first
        // (Content-Length + Connection: close let the browser finish the request), then
        // launch the queue action in this process, so a manual run starts at once even when
        // the automatic actions run in "GLPI" mode (triggered every few minutes only).
        // CronTask::launch() is a no-op while another cron run holds the global cron lock
        // or the action is already running; the job then waits for the next regular run.
        return new StreamedResponse(
            static function () use ($payload): void {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                echo $payload;
                flush();

                // launch() switches $_SESSION to the cron context (root entity); keep
                // that out of the user's stored session.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                ignore_user_abort(true);
                CronTask::launch(-CronTask::MODE_INTERNAL, 1, SyncTask::TASK_QUEUE);
            },
            202,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Length' => (string) strlen($payload),
                'Cache-Control' => 'no-cache,no-store',
                'Connection' => 'close',
            ]
        );
    }

    #[Route('/Sync/Jobs/Active', name: 'sync_job_active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        if (!Access::canView()) {
            return $this->error(__('Access denied'), 403);
        }

        return new JsonResponse([
            'ok' => true,
            'job' => (new SyncJobQueue())->getLatestActive(),
        ]);
    }

    #[Route('/Sync/Jobs/{id}', name: 'sync_job', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function job(int $id): JsonResponse
    {
        if (!Access::canView()) {
            return $this->error(__('Access denied'), 403);
        }

        $job = (new SyncJobQueue())->get($id);
        if ($job === null) {
            return $this->error(__('Job not found.', 'jamfsync'), 404);
        }

        return new JsonResponse([
            'ok' => true,
            'job' => $job,
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'message' => $message,
        ], $status);
    }
}
