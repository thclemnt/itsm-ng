<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Import\FullyQualifiedStrictTypesFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Operator\NewExpressionParenthesesFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocScalarFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocTypesFixer;
use PhpCsFixer\Fixer\Whitespace\HeredocIndentationFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

$paths = [
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
];

return ECSConfig::configure()
    ->withPaths($paths)
    ->withRootFiles()
    ->withEditorConfig()
    ->withParallel(timeoutSeconds: 300)
    ->withCache(
        directory: __DIR__ . '/.ecs_cache',
        namespace: 'itsm-main'
    )
    ->withPhpCsFixerSets(
        perCS30: true,
        php81Migration: true
    )
    ->withConfiguredRule(FullyQualifiedStrictTypesFixer::class, [
        'import_symbols' => true,
    ])
    ->withConfiguredRule(OrderedImportsFixer::class, [
        'imports_order' => ['class', 'const', 'function'],
    ])
    ->withRules([
        NoUnusedImportsFixer::class,
        PhpdocScalarFixer::class,
        PhpdocTypesFixer::class,
    ])
    ->withSkip([
        HeredocIndentationFixer::class,
        NewExpressionParenthesesFixer::class,
    ])
;
