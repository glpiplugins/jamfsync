<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Ui;

use CommonGLPI;
use Config;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Config\DataAndPrivacyConfig;
use GlpiPlugin\Jamfsync\Service\RetentionPolicy;
use GlpiPlugin\Jamfsync\SyncTask;

/**
 * "JAMF Sync" tab on Setup > Data and Privacy: retention periods of the plugin's history,
 * next to GLPI's own "Historical logs" and "Login sessions" tabs (same layout and right).
 */
final class PrivacyTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('JAMF Sync', 'jamfsync');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof DataAndPrivacyConfig || !Config::canUpdate()) {
            return '';
        }

        return self::createTabEntry(__('JAMF Sync', 'jamfsync'), 0, $item::class, 'ti ti-brand-apple');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof DataAndPrivacyConfig || !Config::canUpdate()) {
            return false;
        }

        $crontask = new CronTask();
        $crontask->getFromDBbyName(SyncTask::class, SyncTask::TASK_PURGE);

        TemplateRenderer::getInstance()->display('@jamfsync/privacy_tab.html.twig', [
            'form_path' => ConfigMenu::pluginUrl('/Retention'),
            'settings' => RetentionPolicy::settings(),
            'max_days' => RetentionPolicy::MAX_DAYS,
            'jobs_days' => RetentionPolicy::JOBS_DAYS,
            'crontask' => $crontask,
            'canedit' => true,
        ]);

        return true;
    }

    /**
     * URL of this tab, for redirects after saving and links from the plugin pages.
     */
    public static function getTabUrl(): string
    {
        return DataAndPrivacyConfig::getFormURL() . '?forcetab=' . rawurlencode(self::class . '$1');
    }
}
