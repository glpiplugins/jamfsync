<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Ui;

use Computer;
use Config;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Jamfsync\DeviceMapRepository;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\RunChange;
use GlpiPlugin\Jamfsync\Service\RetentionPolicy;
use Search;

/**
 * Monitoring page (front/monitoring.php): sync run history with filters, summary cards,
 * a run detail view with its changed items, and the CSV export. Data is prepared here;
 * the HTML lives in templates/monitoring.html.twig.
 */
final class MonitoringPage
{
    private const RUNS_TABLE = 'glpi_plugin_jamfsync_syncruns';
    private const CHANGES_TABLE = 'glpi_plugin_jamfsync_runchanges';

    /** Changed-items tab => runchanges.change_type */
    private const CHANGE_TABS = [
        'devices' => 'device',
        'apps' => 'app',
        'os' => 'os',
    ];

    /** Columns (RunChange search option ids) shown per changed-items tab. */
    private const CHANGE_COLUMNS = [
        'devices' => [1, 4, 5, 7, 8, 9, 11],
        'apps' => [1, 6, 7, 10, 11],
        'os' => [1, 6, 8, 9, 10, 11],
    ];

    /**
     * @param array{instance_id: int, entity_id: int, status: string, date_from: string, date_to: string, limit: int, run_id: int, show_changes: bool, changes_tab: string, start: int, list_limit: int} $filters
     */
    private function __construct(private readonly array $filters) {}

    /**
     * @param array<string, mixed> $query Raw query string ($_GET).
     */
    public static function fromQuery(array $query): self
    {
        return new self(self::normalizeFilters($query));
    }

    /**
     * Validate the raw query string into typed filters; invalid values fall back to the
     * defaults instead of reaching the database.
     *
     * @param array<string, mixed> $query
     * @return array{instance_id: int, entity_id: int, status: string, date_from: string, date_to: string, limit: int, run_id: int, show_changes: bool, changes_tab: string, start: int, list_limit: int}
     */
    public static function normalizeFilters(array $query): array
    {
        $int = static fn(string $key, int $default = 0): int => is_scalar($query[$key] ?? null)
            ? (int) $query[$key]
            : $default;
        $date = static function (string $key) use ($query): string {
            $value = is_string($query[$key] ?? null) ? trim($query[$key]) : '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
                return '';
            }

            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
        };

        $status = is_string($query['status'] ?? null) ? $query['status'] : '';
        $tab = is_string($query['changes_tab'] ?? null) ? $query['changes_tab'] : '';

        return [
            'instance_id' => max(0, $int('instance_id')),
            'entity_id' => max(0, $int('entity_id')),
            'status' => in_array($status, ['success', 'error'], true) ? $status : '',
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
            'limit' => max(20, min(500, $int('limit', 100))),
            'run_id' => max(0, $int('run_id')),
            'show_changes' => $int('show_changes') === 1,
            'changes_tab' => array_key_exists($tab, self::CHANGE_TABS) ? $tab : 'devices',
            'start' => max(0, $int('start')),
            'list_limit' => max(10, min(200, $int('list_limit', 50))),
        ];
    }

    public function display(): void
    {
        ['rows' => $rows, 'summary' => $summary] = $this->loadRuns();
        $runDetail = $this->loadRunDetail();
        $baseParams = $this->baseParams();

        foreach ($rows as &$row) {
            $row['detail_url'] = self::url($baseParams + ['run_id' => (int) $row['id']]);
            $row['mode_label'] = self::modeLabel((string) ($row['mode'] ?? ''));
            $row['status_label'] = self::statusLabel((string) ($row['status'] ?? ''));
        }
        unset($row);

        $instances = (new InstanceRepository())->getAll();

        TemplateRenderer::getInstance()->display('@jamfsync/monitoring.html.twig', [
            'filters' => $this->filters,
            'instances' => $instances,
            'missing' => $this->loadMissingDevices($instances),
            'retention' => RetentionPolicy::settings(),
            'retention_url' => Config::canUpdate() ? PrivacyTab::getTabUrl() : '',
            'rows' => $rows,
            'summary' => $summary,
            'run' => $runDetail,
            'export_url' => self::url($baseParams + ['export' => 'csv']),
            'reset_url' => self::url([]),
            'close_url' => self::url($baseParams),
        ]);
    }

    /**
     * Stream the filtered run list as CSV (RFC 4180, no escape character).
     */
    public function sendCsv(): void
    {
        $rows = $this->loadRuns()['rows'];
        $columns = [
            'id', 'started_at', 'finished_at', 'instance_name', 'jamf_instances_id', 'entities_id',
            'status', 'mode', 'devices_count', 'apps_count', 'processed_count', 'changed_count',
            'is_full', 'cursor_start', 'cursor_end', 'message',
        ];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="jamfsync-monitoring-' . date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }
        fputcsv($out, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn(string $column) => (string) ($row[$column] ?? ''), $columns), ',', '"', '');
        }
        fclose($out);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    private function loadRuns(): array
    {
        global $DB;

        $rows = [];
        $summary = ['runs' => 0, 'success' => 0, 'error' => 0, 'devices' => 0, 'apps' => 0, 'processed' => 0, 'changed' => 0];

        $iterator = $DB->request([
            'FROM' => self::RUNS_TABLE,
            'WHERE' => $this->runsWhere(),
            'ORDER' => ['started_at DESC', 'id DESC'],
            'LIMIT' => $this->filters['limit'],
        ]);
        foreach ($iterator as $row) {
            $rows[] = $row;
            $summary['runs']++;
            $summary[($row['status'] ?? '') === 'success' ? 'success' : 'error']++;
            $summary['devices'] += (int) ($row['devices_count'] ?? 0);
            $summary['apps'] += (int) ($row['apps_count'] ?? 0);
            $summary['processed'] += (int) ($row['processed_count'] ?? 0);
            $summary['changed'] += (int) ($row['changed_count'] ?? 0);
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function runsWhere(): array
    {
        $where = [];
        if ($this->filters['instance_id'] > 0) {
            $where['jamf_instances_id'] = $this->filters['instance_id'];
        }
        if ($this->filters['entity_id'] > 0) {
            $where['entities_id'] = $this->filters['entity_id'];
        }
        if ($this->filters['status'] !== '') {
            $where['status'] = $this->filters['status'];
        }
        if ($this->filters['date_from'] !== '') {
            $where[] = ['started_at' => ['>=', $this->filters['date_from'] . ' 00:00:00']];
        }
        if ($this->filters['date_to'] !== '') {
            $where[] = ['started_at' => ['<=', $this->filters['date_to'] . ' 23:59:59']];
        }

        return $where;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRunDetail(): ?array
    {
        global $DB;

        if ($this->filters['run_id'] <= 0) {
            return null;
        }

        $run = $DB->request([
            'FROM' => self::RUNS_TABLE,
            'WHERE' => ['id' => $this->filters['run_id']],
            'LIMIT' => 1,
        ])->current();
        if (!$run) {
            return null;
        }

        $started = strtotime((string) ($run['started_at'] ?? ''));
        $finished = strtotime((string) ($run['finished_at'] ?? ''));
        $runParams = $this->baseParams() + [
            'run_id' => (int) $run['id'],
            'show_changes' => 1,
        ];

        $tabs = [];
        foreach (['devices' => __('Devices', 'jamfsync'), 'apps' => __('Apps', 'jamfsync'), 'os' => __('OS', 'jamfsync')] as $key => $label) {
            $tabs[] = [
                'label' => $label,
                'url' => self::url($runParams + ['changes_tab' => $key]),
                'active' => $key === $this->filters['changes_tab'],
            ];
        }

        return [
            'id' => (int) $run['id'],
            'message' => (string) ($run['message'] ?? ''),
            'changed_count' => (int) ($run['changed_count'] ?? 0),
            'fields' => [
                __('Instance', 'jamfsync') => (string) ($run['instance_name'] ?? ''),
                __('Instance ID', 'jamfsync') => (string) (int) ($run['jamf_instances_id'] ?? 0),
                __('Entity ID', 'jamfsync') => (string) (int) ($run['entities_id'] ?? 0),
                __('Status') => self::statusLabel((string) ($run['status'] ?? '')),
                __('Mode', 'jamfsync') => self::modeLabel((string) ($run['mode'] ?? '')),
                __('Started', 'jamfsync') => (string) ($run['started_at'] ?? ''),
                __('Finished', 'jamfsync') => (string) ($run['finished_at'] ?? ''),
                __('Duration', 'jamfsync') => $started > 0 && $finished >= $started ? sprintf(__('%d s', 'jamfsync'), $finished - $started) : '',
                __('Devices', 'jamfsync') => (string) (int) ($run['devices_count'] ?? 0),
                __('Apps', 'jamfsync') => (string) (int) ($run['apps_count'] ?? 0),
                __('Processed', 'jamfsync') => (string) (int) ($run['processed_count'] ?? 0),
                __('Changed', 'jamfsync') => (string) (int) ($run['changed_count'] ?? 0),
                __('Full sync', 'jamfsync') => (int) ($run['is_full'] ?? 0) === 1 ? __('Yes') : __('No'),
                __('Cursor start', 'jamfsync') => (string) ($run['cursor_start'] ?? ''),
                __('Cursor end', 'jamfsync') => (string) ($run['cursor_end'] ?? ''),
            ],
            'show_changes' => $this->filters['show_changes'],
            'tabs' => $tabs,
            'changes_html' => $this->hasChanges((int) $run['id']) ? $this->renderChanges((int) $run['id']) : null,
        ];
    }

    /**
     * Devices that the last successful run of each instance no longer received from JAMF.
     * Instances that fetch nothing (neither devices nor apps) are skipped.
     *
     * @param list<array<string, mixed>> $instances
     * @return list<array{instance: string, last_run: string, total: int, rows: list<array<string, mixed>>}>
     */
    private function loadMissingDevices(array $instances): array
    {
        $repository = new DeviceMapRepository();
        $missing = [];
        foreach ($instances as $instance) {
            if ((int) ($instance['pull_devices'] ?? 0) !== 1 && (int) ($instance['pull_apps'] ?? 0) !== 1) {
                continue;
            }

            $found = $repository->findMissing((int) $instance['id'], 200);
            if ($found['total'] === 0) {
                continue;
            }

            foreach ($found['rows'] as &$row) {
                $row['computer_url'] = (int) $row['computers_id'] > 0
                    ? Computer::getFormURLWithID((int) $row['computers_id'])
                    : '';
            }
            unset($row);

            $missing[] = [
                'instance' => (string) ($instance['name'] ?? ''),
                'last_run' => $found['last_run'],
                'total' => $found['total'],
                'rows' => $found['rows'],
            ];
        }

        return $missing;
    }

    private function hasChanges(int $runId): bool
    {
        global $DB;

        return $DB->tableExists(self::CHANGES_TABLE)
            && countElementsInTable(self::CHANGES_TABLE, ['syncruns_id' => $runId]) > 0;
    }

    /**
     * Native search list of the run's changed items for the active tab. Captured so Twig
     * can place it; the HTML comes from GLPI's own search renderer.
     */
    private function renderChanges(int $runId): string
    {
        $tab = $this->filters['changes_tab'];
        $params = [
            'criteria' => [
                ['field' => 2, 'searchtype' => 'equals', 'value' => (string) $runId],
                ['link' => 'AND', 'field' => 3, 'searchtype' => 'equals', 'value' => self::CHANGE_TABS[$tab]],
            ],
            'sort' => 11,
            'order' => 'DESC',
            'start' => $this->filters['start'],
            'list_limit' => $this->filters['list_limit'],
        ];

        ob_start();
        Search::showList(RunChange::class, $params, self::CHANGE_COLUMNS[$tab]);

        return (string) ob_get_clean();
    }

    /**
     * Filter parameters carried over into detail, export and tab links.
     *
     * @return array<string, int|string>
     */
    private function baseParams(): array
    {
        return [
            'instance_id' => $this->filters['instance_id'],
            'entity_id' => $this->filters['entity_id'],
            'status' => $this->filters['status'],
            'date_from' => $this->filters['date_from'],
            'date_to' => $this->filters['date_to'],
            'limit' => $this->filters['limit'],
        ];
    }

    /**
     * @param array<string, int|string> $params
     */
    private static function url(array $params): string
    {
        return 'monitoring.php' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => __('Success', 'jamfsync'),
            'error' => __('Error', 'jamfsync'),
            default => $status,
        };
    }

    private static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'manual' => __('Manual', 'jamfsync'),
            'manual_full' => __('Manual (full resync)', 'jamfsync'),
            'cron' => __('Scheduled', 'jamfsync'),
            default => $mode,
        };
    }
}
