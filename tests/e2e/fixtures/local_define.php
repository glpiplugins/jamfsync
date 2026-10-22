<?php
// tests/e2e/run.sh only: allow the plugin to reach the local JAMF mock.
define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    \Agent::class,
    \Auth::class,
    \GlpiPlugin\Jamfsync\Instance::class,
]);
