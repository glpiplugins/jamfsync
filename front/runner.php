<?php

declare(strict_types=1);

use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\Ui\ConfigMenu;

include_once __DIR__ . '/../../../inc/includes.php';

if (!Access::canRun()) {
    Html::displayRightError();
}

$repository = new InstanceRepository();
$instances = $repository->getAll();

$runnerInstances = array_map(static function (array $instance): array {
    return [
        'id' => (int) ($instance['id'] ?? 0),
        'name' => (string) ($instance['name'] ?? ''),
        'is_active' => (int) ($instance['is_active'] ?? 0),
    ];
}, $instances);

$runnerLabels = [
    'instance' => __('Instance', 'jamfsync'),
    'all_active_instances' => __('All active instances', 'jamfsync'),
    'run_pull_now' => __('Run pull now', 'jamfsync'),
    'run_full_resync' => __('Run full resync', 'jamfsync'),
    'monitoring' => __('Monitoring', 'jamfsync'),
    'instances' => __('Instances', 'jamfsync'),
    'devices' => __('Devices', 'jamfsync'),
    'changed' => __('Changed', 'jamfsync'),
    'apps' => __('Apps', 'jamfsync'),
    'queued' => __('Job queued', 'jamfsync'),
    'invalid_job_id' => __('Invalid job ID.', 'jamfsync'),
    'unable_start_job' => __('Unable to start job.', 'jamfsync'),
    'unable_load_job_status' => __('Unable to load job status.', 'jamfsync'),
    'sync_completed' => __('Sync completed.', 'jamfsync'),
    'sync_failed' => __('Sync failed.', 'jamfsync'),
    'sync_stalled' => __('Sync stalled.', 'jamfsync'),
    'invalid_json_response' => __('Invalid JSON response from sync.ajax.php.', 'jamfsync'),
];

Html::header(__('JAMF Sync runner', 'jamfsync'), '', 'config', 'plugins');

ConfigMenu::open(ConfigMenu::TAB_RUNNER);

echo "<div class='d-flex align-items-center flex-wrap gap-2 mb-3'>";
echo "<h3 class='card-title d-flex align-items-center gap-2 mb-0'><i class='ti ti-player-play text-green'></i>" . htmlescape(__('Sync runner', 'jamfsync')) . "</h3>";
echo "</div>";

if ($runnerInstances === []) {
    echo "<div class='empty'>";
    echo "<div class='empty-icon'><i class='ti ti-plug-connected-x' style='font-size:2.5rem;'></i></div>";
    echo "<p class='empty-title'>" . htmlescape(__('No JAMF instances configured yet.', 'jamfsync')) . "</p>";
    echo "<p class='empty-subtitle text-muted'>" . htmlescape(__('Add an instance before running a sync.', 'jamfsync')) . "</p>";
    if (Access::canConfig()) {
        echo "<div class='empty-action'><a class='btn btn-primary' href='instance.form.php'><i class='ti ti-plus me-1'></i>" . htmlescape(__('Add instance', 'jamfsync')) . "</a></div>";
    }
    echo "</div>";
} else {
    // The runner UI is mounted by the Vite-built Vue bundle (public/js/dist/jamfsync-vue.js),
    // registered as a footer JS module in setup.php. Config + server-translated labels are
    // handed to it via data-* attributes on the mount node (no inline script, CSP-safe).
    echo "<div id='jamfsync-runner-app'"
        . " data-instances='" . htmlescape(json_encode($runnerInstances, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "'"
        . " data-labels='" . htmlescape(json_encode($runnerLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "'"
        . " data-csrf='" . htmlescape(Session::getNewCSRFToken()) . "'></div>";
}

ConfigMenu::close();

Html::footer();
