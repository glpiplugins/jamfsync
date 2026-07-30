<?php

declare(strict_types=1);

function plugin_jamfsync_migration_0_3_7(Migration $migration): void
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
        return;
    }

    $migration->addField('glpi_plugin_jamfsync_syncjobs', 'error_code', 'string', [
        'value' => '',
        'after' => 'last_run_id',
    ]);
    $migration->addField('glpi_plugin_jamfsync_syncjobs', 'error_message', 'text', [
        'after' => 'error_code',
    ]);
}
