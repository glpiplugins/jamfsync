<?php

declare(strict_types=1);

/**
 * 0.5.0 — asset writes moved to GLPI's native inventory framework.
 *
 * The per-device software link table (glpi_plugin_jamfsync_applinks) is no longer
 * written: GLPI now manages dynamic software links natively (create/update/purge)
 * as part of the inventory import. Drop the now-unused plugin-owned table.
 */
function plugin_jamfsync_migration_0_5_0(Migration $migration): void
{
    global $DB;

    // Plugin-owned table only — never a GLPI core table.
    if ($DB->tableExists('glpi_plugin_jamfsync_applinks')) {
        $DB->dropTable('glpi_plugin_jamfsync_applinks', true);
    }

    unset($migration); // no schema builder work; kept for signature consistency
}
