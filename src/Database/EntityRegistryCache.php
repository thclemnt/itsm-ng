<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Composer\InstalledVersions;
use Psr\SimpleCache\CacheInterface;

/** Persist only the derived, connection-independent mapping projection. */
final class EntityRegistryCache
{
    private readonly string $key;

    public function __construct(private readonly CacheInterface $cache, string $sourceRoot)
    {
        // Content, rather than mtimes or a manually maintained mapping version,
        // invalidates in-place deployments and changes to mapping helpers too.
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
            hash_update($hash, $file . "\0" . hash_file('sha256', $file, true));
        }
        $this->key = 'orm_registry_' . hash_final($hash);
    }

    public function load(callable $build): array
    {
        $serialized = $this->cache->get($this->key);
        if (is_string($serialized)) {
            $model = unserialize($serialized);
            if (is_array($model)) {
                return $model;
            }
        }
        $model = $build();
        // Even an in-memory/session cache must not retain a mutable object from
        // a caller. Managers, metadata factories and connections are never saved.
        $this->cache->set($this->key, serialize($model));
        return $model;
    }
}
