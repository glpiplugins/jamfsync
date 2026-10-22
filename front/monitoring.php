<?php

declare(strict_types=1);

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\Ui\ConfigMenu;
use GlpiPlugin\Jamfsync\Ui\MonitoringPage;

if (!Access::canView()) {
    throw new AccessDeniedHttpException();
}

$page = MonitoringPage::fromQuery($_GET);

if (($_GET['export'] ?? '') === 'csv') {
    $page->sendCsv();
    exit;
}

Html::header(__('JAMF Sync Monitoring', 'jamfsync'), '', 'config', 'plugins');

ConfigMenu::open(ConfigMenu::TAB_MONITORING, false);
$page->display();
ConfigMenu::close();

Html::footer();
