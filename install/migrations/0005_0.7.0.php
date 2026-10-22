<?php

declare(strict_types=1);

/**
 * 0.7.0 — namespaced automatic actions and TIMESTAMP columns.
 *
 * - The cron itemtype moves from the legacy global class PluginJamfsyncSyncTask to
 *   GlpiPlugin\Jamfsync\SyncTask; existing task settings (frequency, mode, state) are kept.
 *   The new ProcessSyncQueue task is registered by plugin_jamfsync_register_cron().
 * - Jobs still queued/running from 0.6.x or older are closed: those versions ran a job in
 *   the request that created it, so the new queue worker must not pick them up later.
 * - DATETIME columns become TIMESTAMP, as GLPI requires for time-zone support (same
 *   conversion as the core "migration:timestamps" command). Idempotent: only columns that
 *   are still DATETIME are changed.
 */
function plugin_jamfsync_migration_0_7_0(Migration $migration): void
{
    global $DB;

    $DB->update(
        'glpi_crontasks',
        ['itemtype' => 'GlpiPlugin\\Jamfsync\\SyncTask'],
        ['itemtype' => 'PluginJamfsyncSyncTask']
    );

    if ($DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
        // addField() calls of earlier migrations are only queued; on a fresh install the
        // error_code/error_message columns (0.3.7) do not exist yet without this flush.
        $migration->migrationOneTable('glpi_plugin_jamfsync_syncjobs');

        $message = __('Aborted by the plugin upgrade to 0.7.0.', 'jamfsync');
        $DB->update('glpi_plugin_jamfsync_syncjobs', [
            'status' => 'stalled',
            'error_code' => 'upgrade_aborted',
            'error_message' => $message,
            'message' => $message,
        ], [
            'status' => ['queued', 'running'],
        ]);
    }

    // Plugin-owned tables only — never a GLPI core table.
    $tables = [
        'glpi_plugin_jamfsync_instances',
        'glpi_plugin_jamfsync_syncruns',
        'glpi_plugin_jamfsync_devicemaps',
        'glpi_plugin_jamfsync_runchanges',
        'glpi_plugin_jamfsync_syncjobs',
    ];

    $columns = $DB->request([
        'SELECT' => ['table_name AS TABLE_NAME', 'column_name AS COLUMN_NAME'],
        'FROM'   => 'information_schema.columns',
        'WHERE'  => [
            'table_schema' => $DB->dbdefault,
            'table_name'   => $tables,
            'data_type'    => 'datetime',
        ],
    ]);

    foreach ($columns as $column) {
        $table = (string) $column['TABLE_NAME'];
        $field = (string) $column['COLUMN_NAME'];

        // TIMESTAMP cannot hold dates before 1970; such values can only be invalid
        // zero dates here, as every column is nullable.
        $DB->update(
            $table,
            [$field => null],
            [
                ['NOT' => [$field => null]],
                [$field => ['<', '1970-01-01 00:00:01']],
            ]
        );

        $migration->changeField($table, $field, $field, 'timestamp');
    }
}
