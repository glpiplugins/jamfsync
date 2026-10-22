<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use CommonDBTM;

class RunChange extends CommonDBTM
{
    // Keep in sync with Access::RIGHT ('plugin_jamfsync_config').
    public static string $rightname = 'plugin_jamfsync_config';

    public static function getTypeName($nb = 0): string
    {
        return __('JAMF Sync run change', 'jamfsync');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_jamfsync_runchanges';
    }

    public function rawSearchOptions(): array
    {
        return [
            [
                'id' => 'common',
                'name' => __('JAMF Sync', 'jamfsync'),
            ],
            [
                'id' => 1,
                'table' => self::getTable(),
                'field' => 'id',
                'name' => __('ID'),
                'datatype' => 'number',
            ],
            [
                'id' => 2,
                'table' => self::getTable(),
                'field' => 'syncruns_id',
                'name' => __('Run ID', 'jamfsync'),
                'datatype' => 'number',
            ],
            [
                'id' => 3,
                'table' => self::getTable(),
                'field' => 'change_type',
                'name' => __('Type', 'jamfsync'),
                'datatype' => 'string',
            ],
            [
                'id' => 4,
                'table' => self::getTable(),
                'field' => 'jamf_device_id',
                'name' => __('Device ID', 'jamfsync'),
                'datatype' => 'string',
            ],
            [
                'id' => 5,
                'table' => self::getTable(),
                'field' => 'computer_name',
                'name' => __('Computer', 'jamfsync'),
                'datatype' => 'string',
            ],
            [
                'id' => 6,
                'table' => self::getTable(),
                'field' => 'object_name',
                'name' => __('Object', 'jamfsync'),
                'datatype' => 'string',
            ],
            [
                'id' => 7,
                'table' => self::getTable(),
                'field' => 'details',
                'name' => __('Details', 'jamfsync'),
                'datatype' => 'text',
            ],
            [
                'id' => 8,
                'table' => self::getTable(),
                'field' => 'before_json',
                'name' => __('Before (JSON)', 'jamfsync'),
                'datatype' => 'text',
                'massiveaction' => false,
            ],
            [
                'id' => 9,
                'table' => self::getTable(),
                'field' => 'after_json',
                'name' => __('After (JSON)', 'jamfsync'),
                'datatype' => 'text',
                'massiveaction' => false,
            ],
            [
                'id' => 10,
                'table' => self::getTable(),
                'field' => 'impacted_devices',
                'name' => __('Impacted devices', 'jamfsync'),
                'datatype' => 'text',
                'massiveaction' => false,
            ],
            [
                'id' => 11,
                'table' => self::getTable(),
                'field' => 'date_creation',
                'name' => __('Date', 'jamfsync'),
                'datatype' => 'datetime',
            ],
        ];
    }
}
