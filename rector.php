<?php

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/inc',
        __DIR__ . '/templates',
        __DIR__ . '/tests/functionnal',
        __DIR__ . '/tests/units',
        __DIR__ . '/tests/imap',
        __DIR__ . '/tests/LDAP',
        __DIR__ . '/tests/web',
        __DIR__ . '/front',
    ])
    ->withPhpSets(
        php81: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        privatization: true,
    )
    ->withComposerBased(
        twig: true,
        symfony: true,
    )
    ->withParallel(240, 8, 8);
