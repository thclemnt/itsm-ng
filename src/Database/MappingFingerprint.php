<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Composer\InstalledVersions;

/** Deployment identity for derived mapping caches; same-release changes use system:clear_cache. */
final class MappingFingerprint
{
    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= self::forRelease(dirname(__DIR__, 2), defined('ITSM_VERSION') ? ITSM_VERSION : '');
    }

    /** Loaded dependency metadata also identifies packaged releases without Composer manifests. */
    public static function forRelease(string $sourceRoot, string $release): string
    {
        return hash('sha256', $sourceRoot . "\0" . $release . "\0" . PHP_VERSION_ID
            . "\0" . serialize(InstalledVersions::getAllRawData()));
    }
}
