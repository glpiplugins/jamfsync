<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use CommonDBTM;

/**
 * One logged sync run of one instance (glpi_plugin_jamfsync_syncruns). Item type of the
 * "Scheduled sync failed" notification; runs are shown on the monitoring page.
 */
class SyncRun extends CommonDBTM
{
    // Keep in sync with Access::RIGHT ('plugin_jamfsync_config').
    public static string $rightname = 'plugin_jamfsync_config';

    public static function getTypeName($nb = 0): string
    {
        return _n('JAMF sync run', 'JAMF sync runs', $nb, 'jamfsync');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_jamfsync_syncruns';
    }

    public static function getIcon(): string
    {
        return 'ti ti-refresh';
    }

    /**
     * Absolute URL of the run on the monitoring page (for notifications).
     */
    public function getMonitoringUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['url_base'] . '/plugins/jamfsync/front/monitoring.php?run_id=' . (int) $this->getID();
    }
}
