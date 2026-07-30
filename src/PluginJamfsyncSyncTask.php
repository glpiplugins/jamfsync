<?php

declare(strict_types=1);

use GlpiPlugin\Jamfsync\Service\SyncService;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginJamfsyncSyncTask extends CommonGLPI
{
    public static function cronPullFromJamf(CronTask $task): int
    {
        $service = new SyncService();
        $result = $service->run('cron');

        $task->setVolume((int) ($result['devices_count'] ?? 0) + (int) ($result['apps_count'] ?? 0));
        $task->log((string) ($result['message'] ?? ''));

        return ((string) ($result['status'] ?? 'error')) === 'success' ? 1 : 0;
    }
}
