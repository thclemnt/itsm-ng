<?php

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/inc',
        __DIR__ . '/tests/functional',
        __DIR__ . '/tests/units',
        __DIR__ . '/tests/imap',
        __DIR__ . '/tests/LDAP',
        __DIR__ . '/tests/web',
        __DIR__ . '/front',
        __DIR__ . '/ajax',
    ])
    ->withSkip([
        __DIR__ . '/src/Database/Migration',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php82: true)
    // These PHP 8.5 deprecations have replacements available on PHP 8.2.
    ->withConfiguredRule(RenameMethodRector::class, [
        new MethodCallRename(SplObjectStorage::class, 'contains', 'offsetExists'),
        new MethodCallRename(SplObjectStorage::class, 'attach', 'offsetSet'),
        new MethodCallRename(SplObjectStorage::class, 'detach', 'offsetUnset'),
    ]);
