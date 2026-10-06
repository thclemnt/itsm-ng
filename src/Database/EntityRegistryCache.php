<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Psr\SimpleCache\CacheInterface;

/** Persist only the derived, connection-independent mapping projection. */
final class EntityRegistryCache
{
    private readonly ?string $key;

    public function __construct(private readonly CacheInterface $cache, ?string $fingerprint)
    {
        $this->key = $fingerprint === null ? null : 'orm_registry_' . $fingerprint;
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
            // Traverse arrays without dispatching a callback for every scalar
            // leaf. Track references on each path: shared arrays are valid,
            // recursive arrays remain a corrupt-cache miss.
            $pending = [[$model, []]];
            while ($pending !== []) {
                [$values, $ancestors] = array_pop($pending);
                foreach ($values as $key => $value) {
                    if ($value instanceof \__PHP_Incomplete_Class) {
                        return null;
                    }
                    if (!is_array($value)) {
                        continue;
                    }
                    $path = $ancestors;
                    $reference = \ReflectionReference::fromArrayElement($values, $key);
                    if ($reference !== null) {
                        $id = $reference->getId();
                        if (isset($path[$id])) {
                            return null;
                        }
                        $path[$id] = true;
                    }
                    $pending[] = [$value, $path];
                }
            }
            return $model;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
