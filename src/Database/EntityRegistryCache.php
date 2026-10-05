<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Composer\InstalledVersions;
use Psr\SimpleCache\CacheInterface;

/** Persist only the derived, connection-independent mapping projection. */
final class EntityRegistryCache
{
    private readonly ?string $key;

    public function __construct(private readonly CacheInterface $cache, string $sourceRoot)
    {
        $this->key = $this->sourceKey($sourceRoot);
    }

    private function sourceKey(string $sourceRoot): ?string
    {
        // Like PHP's loaded classes, the cache assumes a coherent deployment
        // and opcode-cache reload. Content catches same-mtime source changes.
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException($message);
        });
        try {
            $files = [$sourceRoot . '/composer.lock'];
            $sources = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $sourceRoot . '/src/Database',
                \FilesystemIterator::SKIP_DOTS,
            ));
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
            return 'orm_registry_' . hash_final($hash);
        } catch (\Throwable) {
            // A partial/unreadable deployment must never select a stale key.
            return null;
        } finally {
            restore_error_handler();
        }
    }

    public function load(callable $build): array
    {
        if ($this->key === null) {
            return $build();
        }
        try {
            $model = $this->decode($this->cache->get($this->key));
            if ($model !== null) {
                return $model;
            }
        } catch (\Throwable) {
            // An unavailable optional cache does not prevent mapping discovery.
        }
        // Mapping/validation failures belong to the caller, not cache recovery.
        $model = $build();
        try {
            // Even memory/session adapters receive only strings, never objects
            // retained by callers. No manager, metadata or connection is saved.
            $serialized = serialize($model);
            $this->cache->set($this->key, '1:' . hash('sha256', $serialized) . ':' . $serialized);
        } catch (\Throwable) {
            // Best-effort population; the authoritative projection is usable.
        }
        return $model;
    }

    private function decode(mixed $payload): ?array
    {
        if (!is_string($payload) || !str_starts_with($payload, '1:') || ($payload[66] ?? '') !== ':') {
            return null;
        }
        $serialized = substr($payload, 67);
        if (!hash_equals(substr($payload, 2, 64), hash('sha256', $serialized))) {
            return null;
        }
        set_error_handler(static function (int $severity, string $message): never {
            throw new \UnexpectedValueException($message);
        });
        try {
            $model = unserialize($serialized, ['allowed_classes' => [
                Mapping\MappedReference::class, Mapping\ReferencePolicy::class, Mapping\NativeTimestamp::class,
                Mapping\ReferenceKind::class, Mapping\UserReferenceAction::class, ReferenceMode::class,
            ]]);
            if (!is_array($model)) {
                return null;
            }
            array_walk_recursive($model, static function (mixed $value): void {
                if ($value instanceof \__PHP_Incomplete_Class) {
                    throw new \UnexpectedValueException('Unrecognized cached mapping value');
                }
            });
            return $model;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
