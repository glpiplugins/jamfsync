<?php

declare(strict_types=1);

// PHPStan bootstrap: load the GLPI 12 core (classes, functions, constants) of the image
// the plugin is analysed in. GLPI_DIR overrides the default path of the official image.
$glpiDir = getenv('GLPI_DIR') ?: '/var/www/glpi';
require_once $glpiDir . '/vendor/autoload.php';
