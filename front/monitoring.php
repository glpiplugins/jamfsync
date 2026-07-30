<?php

declare(strict_types=1);

use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\RunChange;
use GlpiPlugin\Jamfsync\Ui\ConfigMenu;

include_once __DIR__ . '/../../../inc/includes.php';
global $DB;

if (!Access::canView()) {
    Html::displayRightError();
}

$repository = new InstanceRepository();
$instances = $repository->getAll();

$instanceId = max(0, (int) ($_GET['instance_id'] ?? 0));
$entityId = max(0, (int) ($_GET['entity_id'] ?? 0));
$status = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 100)));
$runId = max(0, (int) ($_GET['run_id'] ?? 0));
$export = trim((string) ($_GET['export'] ?? ''));
$showChanges = (int) ($_GET['show_changes'] ?? 0) === 1;
$changesTab = trim((string) ($_GET['changes_tab'] ?? 'devices'));
if (!in_array($changesTab, ['devices', 'apps', 'os'], true)) {
    $changesTab = 'devices';
}

$where = [];
if ($instanceId > 0) {
    $where['jamf_instances_id'] = $instanceId;
}
if ($entityId > 0) {
    $where['entities_id'] = $entityId;
}
if (in_array($status, ['success', 'error'], true)) {
    $where['status'] = $status;
}
if ($dateFrom !== '') {
    $where[] = [
        'started_at' => ['>=', $dateFrom . ' 00:00:00'],
    ];
}
if ($dateTo !== '') {
    $where[] = [
        'started_at' => ['<=', $dateTo . ' 23:59:59'],
    ];
}

$rows = [];
$summary = [
    'runs' => 0,
    'success' => 0,
    'error' => 0,
    'devices' => 0,
    'apps' => 0,
    'processed' => 0,
    'changed' => 0,
];

$iterator = $DB->request([
    'FROM' => 'glpi_plugin_jamfsync_syncruns',
    'WHERE' => $where,
    'ORDER' => ['started_at DESC', 'id DESC'],
    'LIMIT' => $limit,
]);

foreach ($iterator as $row) {
    $rows[] = $row;

    $summary['runs']++;
    if (($row['status'] ?? '') === 'success') {
        $summary['success']++;
    } else {
        $summary['error']++;
    }
    $summary['devices'] += (int) ($row['devices_count'] ?? 0);
    $summary['apps'] += (int) ($row['apps_count'] ?? 0);
    $summary['processed'] += (int) ($row['processed_count'] ?? 0);
    $summary['changed'] += (int) ($row['changed_count'] ?? 0);
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jamfsync-monitoring-' . date('Ymd-His') . '.csv"');

    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fputcsv($out, [
            'id',
            'started_at',
            'finished_at',
            'instance_name',
            'jamf_instances_id',
            'entities_id',
            'status',
            'mode',
            'devices_count',
            'apps_count',
            'processed_count',
            'changed_count',
            'is_full',
            'cursor_start',
            'cursor_end',
            'message',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                (int) ($row['id'] ?? 0),
                (string) ($row['started_at'] ?? ''),
                (string) ($row['finished_at'] ?? ''),
                (string) ($row['instance_name'] ?? ''),
                (int) ($row['jamf_instances_id'] ?? 0),
                (int) ($row['entities_id'] ?? 0),
                (string) ($row['status'] ?? ''),
                (string) ($row['mode'] ?? ''),
                (int) ($row['devices_count'] ?? 0),
                (int) ($row['apps_count'] ?? 0),
                (int) ($row['processed_count'] ?? 0),
                (int) ($row['changed_count'] ?? 0),
                (int) ($row['is_full'] ?? 0),
                (string) ($row['cursor_start'] ?? ''),
                (string) ($row['cursor_end'] ?? ''),
                (string) ($row['message'] ?? ''),
            ]);
        }

        fclose($out);
    }
    exit;
}

$runDetail = null;
$runChanges = [];
if ($runId > 0) {
    $runDetail = $DB->request([
        'FROM' => 'glpi_plugin_jamfsync_syncruns',
        'WHERE' => ['id' => $runId],
        'LIMIT' => 1,
    ])->current();

    if ($runDetail && $DB->tableExists('glpi_plugin_jamfsync_runchanges')) {
        $runChanges = iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_jamfsync_runchanges',
            'WHERE' => ['syncruns_id' => (int) ($runDetail['id'] ?? 0)],
            'ORDER' => ['id ASC'],
            'LIMIT' => 500,
        ]), false);
    }
}

$baseParams = [
    'instance_id' => $instanceId,
    'entity_id' => $entityId,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'limit' => $limit,
];

Html::header(__('JAMF Sync Monitoring', 'jamfsync'), '', 'config', 'plugins');

ConfigMenu::open(ConfigMenu::TAB_MONITORING, false);

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>" . htmlescape(__('Filter', 'jamfsync')) . "</h3></div>";
echo "<div class='card-body'>";
echo "<form method='get' class='row g-3'>";

echo "<div class='col-md-3'>";
echo "<label class='form-label' for='instance_id'>" . htmlescape(__('Instance', 'jamfsync')) . "</label>";
echo "<select class='form-select' id='instance_id' name='instance_id'>";
echo "<option value='0'>" . htmlescape(__('All')) . "</option>";
foreach ($instances as $instance) {
    $id = (int) ($instance['id'] ?? 0);
    $selected = $instanceId === $id ? " selected" : "";
    echo "<option value='" . $id . "'" . $selected . ">" . htmlescape((string) ($instance['name'] ?? '')) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label' for='entity_id'>" . htmlescape(__('Entity ID', 'jamfsync')) . "</label>";
echo "<input class='form-control' id='entity_id' name='entity_id' type='number' min='0' value='" . $entityId . "'>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label' for='status'>" . htmlescape(__('Status')) . "</label>";
echo "<select class='form-select' id='status' name='status'>";
echo "<option value=''>" . htmlescape(__('All')) . "</option>";
echo "<option value='success'" . ($status === 'success' ? ' selected' : '') . ">success</option>";
echo "<option value='error'" . ($status === 'error' ? ' selected' : '') . ">error</option>";
echo "</select>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label' for='date_from'>" . htmlescape(__('From', 'jamfsync')) . "</label>";
echo "<input class='form-control' id='date_from' name='date_from' type='date' value='" . htmlescape($dateFrom) . "'>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label' for='date_to'>" . htmlescape(__('To', 'jamfsync')) . "</label>";
echo "<input class='form-control' id='date_to' name='date_to' type='date' value='" . htmlescape($dateTo) . "'>";
echo "</div>";

echo "<div class='col-md-1'>";
echo "<label class='form-label' for='limit'>Limit</label>";
echo "<input class='form-control' id='limit' name='limit' type='number' min='20' max='500' value='" . $limit . "'>";
echo "</div>";

echo "<div class='col-12 d-flex gap-2'>";
echo "<button type='submit' class='btn btn-primary'>" . htmlescape(__('Apply', 'jamfsync')) . "</button>";
echo "<a class='btn btn-outline-secondary' href='monitoring.php'>" . htmlescape(__('Reset')) . "</a>";
echo "<a class='btn btn-outline-success ms-auto' href='monitoring.php?" . htmlescape(http_build_query(array_merge($baseParams, ['export' => 'csv']))) . "'>" . htmlescape(__('Export CSV', 'jamfsync')) . "</a>";
echo "</div>";

echo "</form>";
echo "</div>";
echo "</div>";

if ($runDetail) {
    $duration = '';
    $started = strtotime((string) ($runDetail['started_at'] ?? ''));
    $finished = strtotime((string) ($runDetail['finished_at'] ?? ''));
    if ($started > 0 && $finished >= $started) {
        $duration = (string) ($finished - $started) . 's';
    }

    echo "<div class='card mb-3'>";
    echo "<div class='card-header d-flex align-items-center'>";
    echo "<h3 class='card-title mb-0'>" . htmlescape(__('Run details', 'jamfsync')) . " #" . (int) ($runDetail['id'] ?? 0) . "</h3>";
    echo "<a class='btn btn-sm btn-outline-secondary ms-auto' href='monitoring.php?" . htmlescape(http_build_query($baseParams)) . "'>" . htmlescape(__('Close details', 'jamfsync')) . "</a>";
    echo "</div>";
    echo "<div class='card-body'>";
    echo "<div class='row g-3'>";

    $fields = [
        __('Instance', 'jamfsync') => (string) ($runDetail['instance_name'] ?? ''),
        'Instance ID' => (string) ((int) ($runDetail['jamf_instances_id'] ?? 0)),
        'Entity ID' => (string) ((int) ($runDetail['entities_id'] ?? 0)),
        __('Status') => (string) ($runDetail['status'] ?? ''),
        __('Mode', 'jamfsync') => (string) ($runDetail['mode'] ?? ''),
        __('Started', 'jamfsync') => (string) ($runDetail['started_at'] ?? ''),
        __('Finished', 'jamfsync') => (string) ($runDetail['finished_at'] ?? ''),
        __('Duration', 'jamfsync') => $duration,
        __('Devices', 'jamfsync') => (string) ((int) ($runDetail['devices_count'] ?? 0)),
        __('Apps', 'jamfsync') => (string) ((int) ($runDetail['apps_count'] ?? 0)),
        __('Processed', 'jamfsync') => (string) ((int) ($runDetail['processed_count'] ?? 0)),
        __('Changed', 'jamfsync') => (string) ((int) ($runDetail['changed_count'] ?? 0)),
        'Full sync' => ((int) ($runDetail['is_full'] ?? 0) === 1 ? 'yes' : 'no'),
        'Cursor start' => (string) ($runDetail['cursor_start'] ?? ''),
        'Cursor end' => (string) ($runDetail['cursor_end'] ?? ''),
    ];

    foreach ($fields as $label => $value) {
        echo "<div class='col-md-4'>";
        echo "<div class='text-muted small'>" . htmlescape($label) . "</div>";
        echo "<div>" . htmlescape($value) . "</div>";
        echo "</div>";
    }

    echo "<div class='col-12'>";
    echo "<div class='text-muted small'>" . htmlescape(__('Message', 'jamfsync')) . "</div>";
    echo "<pre class='bg-light border rounded p-2 mb-0' style='white-space: pre-wrap;'>" . htmlescape((string) ($runDetail['message'] ?? '')) . "</pre>";
    echo "</div>";

    if ($runChanges !== []) {
        $tabToType = [
            'devices' => 'device',
            'apps' => 'app',
            'os' => 'os',
        ];
        $currentType = $tabToType[$changesTab] ?? 'device';
        $forcedDisplay = [1, 4, 5, 7, 8, 9, 11];
        if ($changesTab === 'apps') {
            $forcedDisplay = [1, 6, 7, 10, 11];
        } elseif ($changesTab === 'os') {
            $forcedDisplay = [1, 6, 8, 9, 10, 11];
        }

        $changeSearchParams = [
            'criteria' => [
                [
                    'field' => 2,
                    'searchtype' => 'equals',
                    'value' => (string) ((int) ($runDetail['id'] ?? 0)),
                ],
                [
                    'link' => 'AND',
                    'field' => 3,
                    'searchtype' => 'equals',
                    'value' => $currentType,
                ],
            ],
            'sort' => 11,
            'order' => 'DESC',
            'start' => max(0, (int) ($_GET['start'] ?? 0)),
            'list_limit' => max(10, min(200, (int) ($_GET['list_limit'] ?? 50))),
        ];

        $changeFilterParams = array_merge($baseParams, [
            'run_id' => (int) ($runDetail['id'] ?? 0),
            'show_changes' => 1,
            'changes_tab' => $changesTab,
        ]);

        echo "<div class='col-12'>";
        echo "<button class='btn btn-outline-secondary btn-sm mb-2' type='button' data-bs-toggle='collapse' data-bs-target='#jamf-run-changes' aria-expanded='" . ($showChanges ? 'true' : 'false') . "' aria-controls='jamf-run-changes'>";
        echo htmlescape(__('Changed items', 'jamfsync')) . " (" . (int) ($runDetail['changed_count'] ?? 0) . ")";
        echo "</button>";
        echo "<div id='jamf-run-changes' class='collapse" . ($showChanges ? ' show' : '') . "'>";

        echo "<ul class='nav nav-tabs mb-2'>";
        $tabs = [
            'devices' => __('Devices', 'jamfsync'),
            'apps' => __('Apps', 'jamfsync'),
            'os' => __('OS', 'jamfsync'),
        ];
        foreach ($tabs as $tabKey => $tabLabel) {
            $tabUrl = 'monitoring.php?' . http_build_query(array_merge($changeFilterParams, ['changes_tab' => $tabKey]));
            $activeClass = $changesTab === $tabKey ? ' active' : '';
            echo "<li class='nav-item'><a class='nav-link" . $activeClass . "' href='" . htmlescape($tabUrl) . "'>" . htmlescape($tabLabel) . "</a></li>";
        }
        echo "</ul>";

        Search::showList(RunChange::class, $changeSearchParams, $forcedDisplay);

        echo "</div>";
        echo "</div>";
    }

    echo "</div>";
    echo "</div>";
    echo "</div>";
}

echo "<div class='row g-3 mb-3'>";
$cards = [
    __('Runs', 'jamfsync') => (string) $summary['runs'],
    __('Success', 'jamfsync') => (string) $summary['success'],
    __('Error', 'jamfsync') => (string) $summary['error'],
    __('Devices', 'jamfsync') => (string) $summary['devices'],
    __('Apps', 'jamfsync') => (string) $summary['apps'],
    __('Processed', 'jamfsync') => (string) $summary['processed'],
    __('Changed', 'jamfsync') => (string) $summary['changed'],
];
foreach ($cards as $label => $value) {
    echo "<div class='col-md-3 col-lg-2'>";
    echo "<div class='card h-100'><div class='card-body py-3'>";
    echo "<div class='text-muted small'>" . htmlescape($label) . "</div>";
    echo "<div class='fs-3 fw-bold'>" . htmlescape($value) . "</div>";
    echo "</div></div>";
    echo "</div>";
}
echo "</div>";

echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>" . htmlescape(__('Recent sync runs', 'jamfsync')) . "</h3></div>";
echo "<div class='table-responsive'>";
echo "<table class='table table-striped table-hover mb-0'>";
echo "<thead><tr>";
echo "<th>#</th><th>Started</th><th>Instance</th><th>Entity</th><th>Status</th><th>Mode</th><th>Devices</th><th>Apps</th><th>Processed</th><th>Changed</th><th>Cursor</th><th>Message</th><th>Details</th>";
echo "</tr></thead><tbody>";

if ($rows === []) {
    echo "<tr><td colspan='13' class='text-muted'>" . htmlescape(__('No sync runs found for current filter.', 'jamfsync')) . "</td></tr>";
} else {
    foreach ($rows as $row) {
        $statusLabel = (string) ($row['status'] ?? '');
        $statusClass = $statusLabel === 'success' ? 'success' : 'danger';
        $cursor = trim((string) ($row['cursor_start'] ?? '') . ' -> ' . (string) ($row['cursor_end'] ?? ''));
        $detailUrl = 'monitoring.php?' . http_build_query(array_merge($baseParams, ['run_id' => (int) ($row['id'] ?? 0)]));
        echo "<tr>";
        echo "<td>" . (int) ($row['id'] ?? 0) . "</td>";
        echo "<td>" . htmlescape((string) ($row['started_at'] ?? '')) . "</td>";
        echo "<td>" . htmlescape((string) ($row['instance_name'] ?? '')) . "</td>";
        echo "<td>" . (int) ($row['entities_id'] ?? 0) . "</td>";
        echo "<td><span class='badge bg-" . htmlescape($statusClass) . "'>" . htmlescape($statusLabel) . "</span></td>";
        echo "<td>" . htmlescape((string) ($row['mode'] ?? '')) . "</td>";
        echo "<td>" . (int) ($row['devices_count'] ?? 0) . "</td>";
        echo "<td>" . (int) ($row['apps_count'] ?? 0) . "</td>";
        echo "<td>" . (int) ($row['processed_count'] ?? 0) . "</td>";
        echo "<td>" . (int) ($row['changed_count'] ?? 0) . "</td>";
        echo "<td><code>" . htmlescape($cursor) . "</code></td>";
        echo "<td>" . htmlescape((string) ($row['message'] ?? '')) . "</td>";
        echo "<td><a class='btn btn-sm btn-outline-secondary' href='" . htmlescape($detailUrl) . "'>" . htmlescape(__('View', 'jamfsync')) . "</a></td>";
        echo "</tr>";
    }
}

echo "</tbody></table>";
echo "</div>";
echo "</div>";

ConfigMenu::close();

Html::footer();
