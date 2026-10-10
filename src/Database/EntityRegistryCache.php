<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use __PHP_Incomplete_Class;
use itsmng\Database\Mapping\MappedReference;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\UserReferenceAction;
use Psr\SimpleCache\CacheInterface;
use ReflectionReference;
use Throwable;
use UnexpectedValueException;

/** Persist only the derived, connection-independent mapping projection. */
final class EntityRegistryCache
{
    private readonly string $key;

    public function __construct(private readonly CacheInterface $cache, string $fingerprint)
    {
        $this->key = 'orm_registry_view_' . $fingerprint;
    }

    /** A hit returns the requested view; one cold build populates and returns all views. */
    public function load(string $view, callable $build): array
    {
        try {
            $model = $this->decode($this->cache->get($this->key . '_' . $view));
            if ($model !== null && array_keys($model) === [$view]
                && ($model[$view] === null || is_array($model[$view]))) {
                return $model;
            }
        } catch (Throwable) {
            // An unavailable optional cache does not prevent mapping discovery.
        }
        // Mapping/validation failures belong to the caller, not cache recovery.
        $model = $build();
        try {
            // Even memory/session adapters receive only strings, never objects
            // retained by callers. No manager, metadata or connection is saved.
            $values = [];
            foreach ($model as $name => $value) {
                $serialized = serialize([$name => $value]);
                $values[$this->key . '_' . $name] = '2:' . hash('sha256', $serialized) . ':' . $serialized;
            }
            $this->cache->setMultiple($values);
        } catch (Throwable) {
            // Best-effort population; the authoritative projection is usable.
        }
        return $model;
    }

    private function decode(mixed $payload): ?array
    {
        // Version 2 includes scalar enum hydration facts; version 1 must rebuild.
        if (!is_string($payload) || !str_starts_with($payload, '2:') || ($payload[66] ?? '') !== ':') {
            return null;
        }
        $serialized = substr($payload, 67);
        if (!hash_equals(substr($payload, 2, 64), hash('sha256', $serialized))) {
            return null;
        }
        set_error_handler(static function (int $severity, string $message): never {
            throw new UnexpectedValueException($message);
        });
        try {
            $model = unserialize($serialized, ['allowed_classes' => [
                MappedReference::class, ReferencePolicy::class, NativeTimestamp::class,
                ReferenceKind::class, UserReferenceAction::class, ReferenceMode::class,
            ]]);
            if (!is_array($model)) {
                return null;
            }
            // Without object, enum or reference tokens, arrays contain only
            // scalar values and cannot introduce incomplete objects or cycles.
            if (preg_match('/[OCERr]:/', $serialized) === 0) {
                return $model;
            }
            // Traverse arrays without dispatching a callback for every scalar
            // leaf. Track references on each path: shared arrays are valid,
            // recursive arrays remain a corrupt-cache miss.
            $pending = [[$model, []]];
            while ($pending !== []) {
                [$values, $ancestors] = array_pop($pending);
                foreach ($values as $key => $value) {
                    if ($value instanceof __PHP_Incomplete_Class) {
                        return null;
                    }
                    if (!is_array($value)) {
                        continue;
                    }
                    $path = $ancestors;
                    $reference = ReflectionReference::fromArrayElement($values, $key);
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
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
