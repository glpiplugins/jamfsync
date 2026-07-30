<?php

declare(strict_types=1);

function plugin_jamfsync_migration_0_3_8(Migration $migration): void
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_jamfsync_instances')) {
        return;
    }

    $migration->addField('glpi_plugin_jamfsync_instances', 'jamf_api_key_is_encrypted', 'bool', [
        'value' => 0,
        'after' => 'jamf_api_key',
    ]);
    // addField only queues the ADD COLUMN (applied later by executeMigration()), so the
    // column would not yet exist for the backfill UPDATE below. Materialise it now.
    $migration->migrationOneTable('glpi_plugin_jamfsync_instances');

    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_jamfsync_instances',
    ]);

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    $key = new GLPIKey();

    foreach ($iterator as $row) {
        $id = (int) ($row['id'] ?? 0);
        $apiKey = trim((string) ($row['jamf_api_key'] ?? ''));
        $isEncrypted = (int) (($row['jamf_api_key_is_encrypted'] ?? 0) > 0 ? 1 : 0) === 1;

        if ($id <= 0 || $apiKey === '' || $isEncrypted) {
            continue;
        }

        $encrypted = $key->encrypt($apiKey);
        if ($encrypted === '') {
            continue;
        }

        $DB->update('glpi_plugin_jamfsync_instances', [
            'jamf_api_key' => $encrypted,
            'jamf_api_key_is_encrypted' => 1,
            'date_mod' => $now,
        ], ['id' => $id]);
    }
}
