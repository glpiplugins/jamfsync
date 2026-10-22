<?php

declare(strict_types=1);

use GlpiPlugin\Jamfsync\Instance;

$instance = new Instance();

if (isset($_POST['add'])) {
    $instance->check(-1, CREATE, $_POST);
    $newId = $instance->add($_POST);
    if ($newId) {
        Session::addMessageAfterRedirect(
            sprintf(__('JAMF instance saved (#%d).', 'jamfsync'), $newId),
            true,
            INFO
        );
    }
    Html::redirect('config.form.php');
} elseif (isset($_POST['update'])) {
    $instance->check((int) ($_POST['id'] ?? 0), UPDATE, $_POST);
    $instance->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $instance->check((int) ($_POST['id'] ?? 0), PURGE, $_POST);
    $instance->delete($_POST, true);
    Session::addMessageAfterRedirect(__('JAMF instance deleted.', 'jamfsync'), true, INFO);
    Html::redirect('config.form.php');
} else {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $instance->check($id, READ);
    } else {
        $instance->check(-1, CREATE);
    }

    Html::header(__('JAMF Sync', 'jamfsync'), '', 'config', 'plugins');
    $instance->showForm($id);
    Html::footer();
}
