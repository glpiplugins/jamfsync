<?php

declare(strict_types=1);

use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\Instance;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\Ui\ConfigMenu;

include_once __DIR__ . '/../../../inc/includes.php';

if (!Access::canView()) {
    Html::displayRightError();
}

$repository = new InstanceRepository();
$canConfig = Access::canConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canConfig) {
        Html::displayRightError();
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'rotate_api_key_encryption') {
            $updated = $repository->rotateApiKeyEncryption();
            Session::addMessageAfterRedirect(
                sprintf(__('Re-encrypted API keys: %d', 'jamfsync'), $updated),
                true,
                INFO
            );
        }
    } catch (RuntimeException $exception) {
        Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
    }

    Html::redirect('config.form.php');
}

Html::header(__('JAMF Sync', 'jamfsync'), '', 'config', 'plugins');

ConfigMenu::open(ConfigMenu::TAB_INSTANCES, false);

// Toolbar: title + management actions (native GLPI list itself follows below).
echo "<div class='d-flex align-items-center flex-wrap gap-2 mb-3'>";
echo "<h3 class='card-title d-flex align-items-center gap-2 mb-0'><i class='ti ti-list-details text-muted'></i>" . htmlescape(__('Configured instances', 'jamfsync')) . "</h3>";
if ($canConfig) {
    echo "<div class='btn-list ms-auto'>";
    echo "<form method='post' class='mb-0'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('action', ['value' => 'rotate_api_key_encryption']);
    echo "<button type='submit' class='btn btn-sm btn-outline-secondary' title='" . htmlescape(__('Re-encrypt stored API keys with the current GLPI crypt key.', 'jamfsync')) . "'><i class='ti ti-shield-lock me-1'></i>" . htmlescape(__('Rotate API key encryption', 'jamfsync')) . "</button>";
    echo "</form>";
    echo "<a class='btn btn-sm btn-primary' href='instance.form.php'><i class='ti ti-plus me-1'></i>" . htmlescape(__('Add instance', 'jamfsync')) . "</a>";
    echo "</div>";
}
echo "</div>";

// Native GLPI Search list of the instances (sortable columns, filter, pagination).
$searchParams = [
    'start' => max(0, (int) ($_GET['start'] ?? 0)),
    'sort' => (int) ($_GET['sort'] ?? 1),
    'order' => in_array(strtoupper((string) ($_GET['order'] ?? 'ASC')), ['ASC', 'DESC'], true)
        ? strtoupper((string) ($_GET['order'] ?? 'ASC'))
        : 'ASC',
    'criteria' => is_array($_GET['criteria'] ?? null) ? $_GET['criteria'] : [],
    'reset' => 'reset',
];

Search::showList(Instance::class, $searchParams, [2, 80, 4, 5, 6]);

ConfigMenu::close();

Html::footer();
