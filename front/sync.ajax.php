<?php

declare(strict_types=1);

use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\Service\SyncService;

include_once __DIR__ . '/../../../inc/includes.php';

ob_start();

$json = static function (array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    if (!Access::canRun()) {
        $json([
            'ok' => false,
            'message' => __('Access denied'),
        ], 403);
    }

    $service = new SyncService();
    $action = trim((string) ($_REQUEST['action'] ?? 'status'));

    if ($action === 'start') {
        $isFull = (int) ($_POST['full'] ?? 0) === 1;
        $instanceId = max(0, (int) ($_POST['instance_id'] ?? 0));
        $mode = $isFull ? 'manual_full' : 'manual';
        $jobId = $service->createSyncJob($mode, $instanceId);

        if ($jobId <= 0) {
            $json([
                'ok' => false,
                'message' => __('Unable to create sync job.', 'jamfsync'),
            ], 500);
        }

        // Respond immediately, continue processing in background.
        $response = json_encode([
            'ok' => true,
            'accepted' => true,
            'job_id' => $jobId,
            'mode' => $mode,
            '_glpi_csrf_token' => Session::getNewCSRFToken(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code(202);
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        if (is_string($response)) {
            header('Content-Length: ' . strlen($response));
            echo $response;
        } else {
            echo '{"ok":true,"accepted":true}';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        set_time_limit(0);
        // Large first-time syncs (hundreds of devices, native inventory import) need more
        // than the default 128M; GLPI's inventory engine is memory-heavy per submission.
        @ini_set('memory_limit', '1024M');
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            $service->run($mode, $instanceId, $jobId);
        } catch (Throwable $throwable) {
            Toolbox::logInFile('php-errors', 'JAMF async start failed: ' . $throwable->getMessage() . "\n");
        }
        exit;
    }

    if ($action === 'status') {
        $jobId = max(0, (int) ($_GET['job_id'] ?? 0));
        if ($jobId <= 0) {
            $json([
                'ok' => false,
                'message' => __('Missing job ID.', 'jamfsync'),
            ], 400);
        }

        $job = $service->getSyncJob($jobId);
        if (!$job) {
            $json([
                'ok' => false,
                'message' => __('Job not found.', 'jamfsync'),
            ], 404);
        }

        $json([
            'ok' => true,
            'job' => $job,
        ]);
    }

    if ($action === 'active') {
        $json([
            'ok' => true,
            'job' => $service->getLatestActiveJob(),
        ]);
    }

    $json([
        'ok' => false,
        'message' => __('Unsupported action.', 'jamfsync'),
    ], 400);
} catch (Throwable $throwable) {
    $json([
        'ok' => false,
        'message' => $throwable->getMessage(),
    ], 500);
}
