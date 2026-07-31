<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Ui;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Jamfsync\Access;

/**
 * Shared vertical tab bar for the JAMF Sync back-office pages.
 *
 * Each page calls {@see self::open()} right after Html::header() and {@see self::close()}
 * right before Html::footer(). open() renders a two-column layout: a left sidebar holding
 * the tab navigation (only tabs the current profile may reach are shown) and an open right
 * content column that the page then fills with its own cards.
 */
final class ConfigMenu
{
    public const TAB_INSTANCES  = 'instances';
    public const TAB_RUNNER     = 'runner';
    public const TAB_MONITORING = 'monitoring';

    /**
     * Open the native (item-type style) tab layout and render the vertical tab bar.
     *
     * @param bool $contentCard When true (default) the content pane is wrapped in the native
     *                          `tab-content ... card border-start-0` panel — right for a page
     *                          whose body is a single block (instances list, runner). Pass false
     *                          for pages that already render several cards of their own
     *                          (monitoring), so they are not nested inside another card.
     */
    public static function open(string $active, bool $contentCard = true): void
    {
        $items = [];
        foreach (self::items() as $key => $item) {
            if (!$item['visible']) {
                continue;
            }

            $items[] = [
                'url'    => $item['url'],
                'icon'   => $item['icon'],
                'label'  => $item['label'],
                'active' => $key === $active,
            ];
        }

        echo "<div class='container-fluid px-0'>";

        // Persistent "sync running" indicator (Vue, mounted by the plugin bundle). Present on
        // every back-office page so a running sync's progress is visible and survives reloads.
        $bannerLabels = [
            'sync_running' => __('Sync running', 'jamfsync'),
            'instances'    => __('Instances', 'jamfsync'),
            'devices'      => __('Devices', 'jamfsync'),
            'changed'      => __('Changed', 'jamfsync'),
            'apps'         => __('Apps', 'jamfsync'),
            'monitoring'   => __('Monitoring', 'jamfsync'),
        ];
        echo "<div id='jamfsync-status-banner' data-labels='"
            . htmlescape(json_encode($bannerLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . "'></div>";

        echo "<div class='d-flex card-tabs flex-column flex-md-row vertical'>";

        TemplateRenderer::getInstance()->display('@jamfsync/config_menu.html.twig', [
            'items' => $items,
        ]);

        if ($contentCard) {
            echo "<div class='tab-content p-2 flex-grow-1 card border-start-0' style='min-height: 150px'>";
        } else {
            echo "<div class='tab-content flex-grow-1 ps-md-3' style='min-height: 150px'>";
        }
        echo "<div class='tab-pane fade show active' role='tabpanel'>";
    }

    public static function close(): void
    {
        echo "</div>"; // .tab-pane
        echo "</div>"; // .tab-content
        echo "</div>"; // .card-tabs
        echo "</div>"; // .container-fluid
    }

    /**
     * @return array<string, array{url: string, icon: string, label: string, visible: bool}>
     */
    private static function items(): array
    {
        return [
            self::TAB_INSTANCES => [
                'url'     => 'config.form.php',
                'icon'    => 'ti-list-details',
                'label'   => __('Instances', 'jamfsync'),
                'visible' => Access::canView(),
            ],
            self::TAB_RUNNER => [
                'url'     => 'runner.php',
                'icon'    => 'ti-player-play',
                'label'   => __('Sync runner', 'jamfsync'),
                'visible' => Access::canRun(),
            ],
            self::TAB_MONITORING => [
                'url'     => 'monitoring.php',
                'icon'    => 'ti-activity-heartbeat',
                'label'   => __('Monitoring', 'jamfsync'),
                'visible' => Access::canView(),
            ],
        ];
    }
}
