<?php

declare(strict_types=1);

function plugin_jamfsync_migration_0_3_0(Migration $migration): void
{
    global $DB;

    $DB->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_jamfsync_instances` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `jamf_base_url` VARCHAR(255) NOT NULL DEFAULT '',
            `jamf_api_username` VARCHAR(255) NOT NULL DEFAULT '',
            `jamf_api_key` VARCHAR(255) NOT NULL DEFAULT '',
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `pull_devices` TINYINT(1) NOT NULL DEFAULT 1,
            `pull_apps` TINYINT(1) NOT NULL DEFAULT 1,
            `name_source` VARCHAR(32) NOT NULL DEFAULT 'device_name',
            `verify_tls` TINYINT(1) NOT NULL DEFAULT 1,
            `request_timeout` INT UNSIGNED NOT NULL DEFAULT 30,
            `page_size` INT UNSIGNED NOT NULL DEFAULT 200,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_success_at` TIMESTAMP NULL DEFAULT NULL,
            `last_cursor` VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_active` (`is_active`),
            KEY `idx_entity` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $DB->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_jamfsync_syncruns` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `jamf_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `instance_name` VARCHAR(255) NOT NULL DEFAULT '',
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'success',
            `mode` VARCHAR(32) NOT NULL DEFAULT 'manual',
            `message` TEXT DEFAULT NULL,
            `devices_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `apps_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `processed_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `changed_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_full` TINYINT(1) NOT NULL DEFAULT 0,
            `cursor_start` VARCHAR(255) NOT NULL DEFAULT '',
            `cursor_end` VARCHAR(255) NOT NULL DEFAULT '',
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_instance` (`jamf_instances_id`),
            KEY `idx_entity` (`entities_id`),
            KEY `idx_date_creation` (`date_creation`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $DB->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_jamfsync_devicemaps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `jamf_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `jamf_device_id` VARCHAR(191) NOT NULL DEFAULT '',
            `jamf_udid` VARCHAR(191) NOT NULL DEFAULT '',
            `jamf_serial` VARCHAR(191) NOT NULL DEFAULT '',
            `computers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `device_signature` VARCHAR(64) NOT NULL DEFAULT '',
            `apps_signature` VARCHAR(64) NOT NULL DEFAULT '',
            `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
            `last_checkin_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_instance_device` (`jamf_instances_id`, `jamf_device_id`),
            KEY `idx_instance_udid` (`jamf_instances_id`, `jamf_udid`),
            KEY `idx_instance_serial` (`jamf_instances_id`, `jamf_serial`),
            KEY `idx_computer` (`computers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $DB->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_jamfsync_applinks` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `jamf_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `jamf_device_id` VARCHAR(191) NOT NULL DEFAULT '',
            `computers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `softwareversions_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_instance_device_softwareversion` (`jamf_instances_id`, `jamf_device_id`, `softwareversions_id`),
            KEY `idx_computer` (`computers_id`),
            KEY `idx_softwareversion` (`softwareversions_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $DB->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_jamfsync_runchanges` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `syncruns_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `jamf_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `change_type` VARCHAR(16) NOT NULL DEFAULT 'device',
            `jamf_device_id` VARCHAR(191) NOT NULL DEFAULT '',
            `computers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `computer_name` VARCHAR(255) NOT NULL DEFAULT '',
            `object_name` VARCHAR(255) NOT NULL DEFAULT '',
            `details` TEXT DEFAULT NULL,
            `before_json` LONGTEXT DEFAULT NULL,
            `after_json` LONGTEXT DEFAULT NULL,
            `impacted_devices` LONGTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_syncrun` (`syncruns_id`),
            KEY `idx_syncrun_type` (`syncruns_id`, `change_type`),
            KEY `idx_instance` (`jamf_instances_id`),
            KEY `idx_computer` (`computers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $DB->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_jamfsync_syncjobs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
            `mode` VARCHAR(32) NOT NULL DEFAULT 'manual',
            `is_full` TINYINT(1) NOT NULL DEFAULT 0,
            `instance_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `instance_name` VARCHAR(255) NOT NULL DEFAULT '',
            `total_instances` INT UNSIGNED NOT NULL DEFAULT 0,
            `processed_instances` INT UNSIGNED NOT NULL DEFAULT 0,
            `total_devices` INT UNSIGNED NOT NULL DEFAULT 0,
            `processed_devices` INT UNSIGNED NOT NULL DEFAULT 0,
            `changed_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `apps_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_run_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `message` TEXT DEFAULT NULL,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_instance` (`instance_id`),
            KEY `idx_date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($DB->tableExists('glpi_plugin_jamfsync_syncruns')) {
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'jamf_instances_id', 'integer', [
            'value' => 0,
            'after' => 'id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'instance_name', 'string', [
            'value' => '',
            'after' => 'jamf_instances_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'entities_id', 'integer', [
            'value' => 0,
            'after' => 'instance_name',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'processed_count', 'integer', [
            'value' => 0,
            'after' => 'apps_count',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'changed_count', 'integer', [
            'value' => 0,
            'after' => 'processed_count',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'is_full', 'bool', [
            'value' => 0,
            'after' => 'changed_count',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'cursor_start', 'string', [
            'value' => '',
            'after' => 'is_full',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncruns', 'cursor_end', 'string', [
            'value' => '',
            'after' => 'cursor_start',
        ]);
        $migration->addKey('glpi_plugin_jamfsync_syncruns', ['jamf_instances_id'], 'idx_instance', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_syncruns', ['entities_id'], 'idx_entity', 'INDEX');
    }

    if ($DB->tableExists('glpi_plugin_jamfsync_instances')) {
        $migration->addField('glpi_plugin_jamfsync_instances', 'is_active', 'bool', [
            'value' => 1,
            'after' => 'request_timeout',
        ]);
        $migration->addField('glpi_plugin_jamfsync_instances', 'name_source', 'string', [
            'value' => 'device_name',
            'after' => 'pull_apps',
        ]);
        $migration->addField('glpi_plugin_jamfsync_instances', 'page_size', 'integer', [
            'value' => 200,
            'after' => 'request_timeout',
        ]);
        $migration->addField('glpi_plugin_jamfsync_instances', 'last_success_at', 'timestamp', [
            'after' => 'is_active',
        ]);
        $migration->addField('glpi_plugin_jamfsync_instances', 'last_cursor', 'string', [
            'value' => '',
            'after' => 'last_success_at',
        ]);
        $migration->addKey('glpi_plugin_jamfsync_instances', ['is_active'], 'idx_active', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_instances', ['entities_id'], 'idx_entity', 'INDEX');
    }

    if ($DB->tableExists('glpi_plugin_jamfsync_devicemaps')) {
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'jamf_device_id', 'string', [
            'value' => '',
            'after' => 'jamf_instances_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'jamf_udid', 'string', [
            'value' => '',
            'after' => 'jamf_device_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'jamf_serial', 'string', [
            'value' => '',
            'after' => 'jamf_udid',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'entities_id', 'integer', [
            'value' => 0,
            'after' => 'computers_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'last_seen_at', 'timestamp', [
            'after' => 'entities_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'last_checkin_at', 'timestamp', [
            'after' => 'last_seen_at',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'device_signature', 'string', [
            'value' => '',
            'after' => 'entities_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_devicemaps', 'apps_signature', 'string', [
            'value' => '',
            'after' => 'device_signature',
        ]);
        $migration->addKey('glpi_plugin_jamfsync_devicemaps', ['jamf_instances_id', 'jamf_udid'], 'idx_instance_udid', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_devicemaps', ['jamf_instances_id', 'jamf_serial'], 'idx_instance_serial', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_devicemaps', ['computers_id'], 'idx_computer', 'INDEX');
    }

    if ($DB->tableExists('glpi_plugin_jamfsync_applinks')) {
        $migration->addField('glpi_plugin_jamfsync_applinks', 'jamf_device_id', 'string', [
            'value' => '',
            'after' => 'jamf_instances_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_applinks', 'computers_id', 'integer', [
            'value' => 0,
            'after' => 'jamf_device_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_applinks', 'softwareversions_id', 'integer', [
            'value' => 0,
            'after' => 'computers_id',
        ]);
        $migration->addKey('glpi_plugin_jamfsync_applinks', ['computers_id'], 'idx_computer', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_applinks', ['softwareversions_id'], 'idx_softwareversion', 'INDEX');
    }

    if ($DB->tableExists('glpi_plugin_jamfsync_runchanges')) {
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'syncruns_id', 'integer', [
            'value' => 0,
            'after' => 'id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'jamf_instances_id', 'integer', [
            'value' => 0,
            'after' => 'syncruns_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'change_type', 'string', [
            'value' => 'device',
            'after' => 'jamf_instances_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'jamf_device_id', 'string', [
            'value' => '',
            'after' => 'change_type',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'computers_id', 'integer', [
            'value' => 0,
            'after' => 'jamf_device_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'computer_name', 'string', [
            'value' => '',
            'after' => 'computers_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'object_name', 'string', [
            'value' => '',
            'after' => 'computer_name',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'details', 'text', [
            'after' => 'object_name',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'before_json', 'longtext', [
            'after' => 'details',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'after_json', 'longtext', [
            'after' => 'before_json',
        ]);
        $migration->addField('glpi_plugin_jamfsync_runchanges', 'impacted_devices', 'longtext', [
            'after' => 'after_json',
        ]);
        $migration->addKey('glpi_plugin_jamfsync_runchanges', ['syncruns_id'], 'idx_syncrun', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_runchanges', ['syncruns_id', 'change_type'], 'idx_syncrun_type', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_runchanges', ['jamf_instances_id'], 'idx_instance', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_runchanges', ['computers_id'], 'idx_computer', 'INDEX');
    }

    if ($DB->tableExists('glpi_plugin_jamfsync_syncjobs')) {
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'status', 'string', [
            'value' => 'queued',
            'after' => 'id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'mode', 'string', [
            'value' => 'manual',
            'after' => 'status',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'is_full', 'bool', [
            'value' => 0,
            'after' => 'mode',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'instance_id', 'integer', [
            'value' => 0,
            'after' => 'is_full',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'instance_name', 'string', [
            'value' => '',
            'after' => 'instance_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'total_instances', 'integer', [
            'value' => 0,
            'after' => 'instance_name',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'processed_instances', 'integer', [
            'value' => 0,
            'after' => 'total_instances',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'total_devices', 'integer', [
            'value' => 0,
            'after' => 'processed_instances',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'processed_devices', 'integer', [
            'value' => 0,
            'after' => 'total_devices',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'changed_count', 'integer', [
            'value' => 0,
            'after' => 'processed_devices',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'apps_count', 'integer', [
            'value' => 0,
            'after' => 'changed_count',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'last_run_id', 'integer', [
            'value' => 0,
            'after' => 'apps_count',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'message', 'text', [
            'after' => 'last_run_id',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'started_at', 'timestamp', [
            'after' => 'message',
        ]);
        $migration->addField('glpi_plugin_jamfsync_syncjobs', 'finished_at', 'timestamp', [
            'after' => 'started_at',
        ]);
        $migration->addKey('glpi_plugin_jamfsync_syncjobs', ['status'], 'idx_status', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_syncjobs', ['instance_id'], 'idx_instance', 'INDEX');
        $migration->addKey('glpi_plugin_jamfsync_syncjobs', ['date_creation'], 'idx_date_creation', 'INDEX');
    }
}
