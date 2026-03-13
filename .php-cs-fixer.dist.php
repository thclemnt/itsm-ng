<?php

$rootFiles = glob(__DIR__ . '/*.php');

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/ajax',
        __DIR__ . '/front',
        __DIR__ . '/inc',
        __DIR__ . '/install',
        __DIR__ . '/src',
        __DIR__ . '/tools',
        __DIR__ . '/tests/LDAP',
        __DIR__ . '/tests/abstracts',
        __DIR__ . '/tests/database',
        __DIR__ . '/tests/deprecated-searchoptions',
        __DIR__ . '/tests/emails-tests',
        __DIR__ . '/tests/fixtures',
        __DIR__ . '/tests/functionnal',
        __DIR__ . '/tests/imap',
        __DIR__ . '/tests/migrations',
        __DIR__ . '/tests/units',
        __DIR__ . '/tests/web',
    ])
    ->append($rootFiles === false ? [] : $rootFiles)
;

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setCacheFile('.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x1Migration' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'ordered_imports' => ['imports_order' => ['class', 'const', 'function']],
        'no_unused_imports' => true,
        // Mandatory due to xgettext bug, same as upstream GLPI.
        'heredoc_indentation' => false,
        'phpdoc_scalar' => true,
        'phpdoc_types' => true,
        // Disabled in upstream GLPI as well.
        'new_expression_parentheses' => false,
    ])
    ->setFinder($finder)
;
