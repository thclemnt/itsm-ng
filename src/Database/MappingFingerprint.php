<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/** Content identity shared by derived mapping caches, never a schema declaration. */
final class MappingFingerprint
{
    private static ?string $current = null;
    private static bool $resolved = false;

    /** Loaded PHP classes and their fingerprint have the same request lifetime. */
    public static function current(): ?string
    {
        if (!self::$resolved) {
            self::$current = self::forSource(dirname(__DIR__, 2));
            self::$resolved = true;
        }
        return self::$current;
    }

    public static function forSource(string $sourceRoot): ?string
    {
        // Like PHP's loaded classes, the cache assumes a coherent deployment
        // and opcode-cache reload. Content catches same-mtime source changes.
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message);
        });
        try {
            // Release archives may omit Composer manifests; InstalledVersions
            // below still identifies the dependencies actually shipped.
            $files = is_file($sourceRoot . '/composer.lock') ? [$sourceRoot . '/composer.lock'] : [];
            $sources = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(
                        $sourceRoot . '/src/Database',
                        FilesystemIterator::SKIP_DOTS,
                    ),
                    // Installation history does not define cached runtime mappings.
                    static fn (SplFileInfo $file): bool => $file->getPathname() !== $sourceRoot . '/src/Database/Migration',
                )
            );
            foreach ($sources as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
            sort($files, SORT_STRING);
            $hash = hash_init('sha256');
            hash_update($hash, $sourceRoot . "\0" . PHP_VERSION_ID . "\0" . serialize(InstalledVersions::getAllRawData()));
            foreach ($files as $file) {
                $digest = hash_file('sha256', $file, true);
                if ($digest === false) {
                    return null;
                }
                hash_update($hash, $file . "\0" . $digest);
            }
            return hash_final($hash);
        } catch (Throwable) {
            // A partial/unreadable deployment must never select a stale key.
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
