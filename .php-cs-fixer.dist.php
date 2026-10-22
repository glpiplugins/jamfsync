<?php

declare(strict_types=1);

// Same rule set as GLPI core (glpi-core/.php-cs-fixer.dist.php), e.g.:
//   php <glpi-core>/vendor/bin/php-cs-fixer check   (tools/check.sh runs it in the GLPI 12 image)
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude([
        'node_modules',
        'public',
        'tests/e2e/fixtures',
    ])
    ->append([__DIR__ . '/setup.php', __DIR__ . '/hook.php']);

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS3.0' => true,
        '@PHP84Migration' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'ordered_imports' => ['imports_order' => ['class', 'const', 'function']],
        'no_unused_imports' => true,
        'heredoc_indentation' => false,
        'new_expression_parentheses' => false,
        'phpdoc_scalar' => true,
        'phpdoc_types' => true,
    ])
    ->setFinder($finder);
