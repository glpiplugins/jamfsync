<?php

declare(strict_types=1);

/**
 * 0.9.0 — retention. The periods (Config context plugin:jamfsync) and the automatic action
 * PurgeHistory are set up by plugin_jamfsync_install(); this adds the index the purge of
 * run changes by age needs.
 */
function plugin_jamfsync_migration_0_9_0(Migration $migration): void
{
    $migration->addKey('glpi_plugin_jamfsync_runchanges', 'date_creation', 'idx_date_creation');
}
