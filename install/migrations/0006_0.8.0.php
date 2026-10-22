<?php

declare(strict_types=1);

/**
 * 0.8.0 — inventory tag and managed entity rule per instance.
 *
 * Existing instances get manage_entity_rule = 0: their devices keep going through the
 * entity rules already configured in GLPI until an administrator opts in on the instance
 * form. New instances default to 1 (Instance::post_getEmpty()).
 */
function plugin_jamfsync_migration_0_8_0(Migration $migration): void
{
    $table = 'glpi_plugin_jamfsync_instances';

    $migration->addField($table, 'inventory_tag', 'string', [
        'value' => '',
        'after' => 'name_source',
    ]);
    $migration->addField($table, 'manage_entity_rule', 'bool', [
        'value' => 0,
        'after' => 'inventory_tag',
    ]);
    $migration->addField($table, 'rules_id', 'fkey', [
        'after' => 'manage_entity_rule',
    ]);

    // Missing-devices report: devices of an instance not seen since its last successful run.
    $migration->addKey('glpi_plugin_jamfsync_devicemaps', ['jamf_instances_id', 'last_seen_at'], 'idx_instance_last_seen');
}
