<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace itsmng\Cache;

use ArrayAccess;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Traversable;

/** Expiring cache entries isolated from the existing authentication session. */
final class SessionAdapter implements CacheInterface
{
    private const SESSION_KEY = 'itsmng_cache_v2';

    public function __construct(
        private readonly string $namespace = '',
        private readonly (ArrayAccess&Traversable)|null $container = null,
        private readonly int $defaultLifetime = 0,
    ) {
        if ($defaultLifetime < 0) {
            throw new InvalidArgumentException('Default cache lifetime must be nonnegative.');
        }
    }

    public function get($key, $default = null): mixed
    {
        self::validateKey($key);
        $entry = $this->entry($key);
        return $entry === null ? $default : $entry['value'];
    }

    public function has($key): bool
    {
        self::validateKey($key);
        return $this->entry($key) !== null;
    }

    public function set($key, $value, $ttl = null): bool
    {
        self::validateKey($key);
        $explicit = $ttl !== null;
        $ttl = $explicit ? self::ttlSeconds($ttl) : $this->defaultLifetime;
        if ($explicit && $ttl <= 0) {
            return $this->delete($key);
        }
        $values = $this->values();
        $values[$key] = ['value' => $value, 'expires' => $ttl > 0 ? microtime(true) + $ttl : null];
        $this->replace($values);
        return true;
    }

    public function delete($key): bool
    {
        self::validateKey($key);
        $values = $this->values();
        unset($values[$key]);
        $this->replace($values);
        return true;
    }

    /** Clear this namespace only; other cache pools and authentication remain intact. */
    public function clear(): bool
    {
        $this->replace([]);
        return true;
    }

    public function getMultiple(iterable $keys, $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, $ttl = null): bool
    {
        $pending = [];
        foreach ($values as $key => $value) {
            // PHP coerces decimal string array keys to integers.
            if (is_int($key)) {
                $key = (string)$key;
            }
            self::validateKey($key);
            $pending[$key] = $value;
        }
        if ($pending === []) {
            return true;
        }
        $explicit = $ttl !== null;
        $ttl = $explicit ? self::ttlSeconds($ttl) : $this->defaultLifetime;
        if ($explicit && $ttl <= 0) {
            return $this->deleteMultiple(array_map(static fn ($key): string => (string)$key, array_keys($pending)));
        }
        $expires = $ttl > 0 ? microtime(true) + $ttl : null;
        foreach ($pending as $key => $value) {
            $pending[$key] = ['value' => $value, 'expires' => $expires];
        }
        $this->replace(array_replace($this->values(), $pending));
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $pending = [];
        foreach ($keys as $key) {
            self::validateKey($key);
            $pending[] = $key;
        }
        $values = $this->values();
        foreach ($pending as $key) {
            unset($values[$key]);
        }
        $this->replace($values);
        return true;
    }

    /** Expire only the requested entry; unrelated entries cost no lookup work. */
    private function entry(string $key): ?array
    {
        $values = $this->values();
        if (!array_key_exists($key, $values)) {
            return null;
        }
        $entry = $values[$key];
        if ($entry['expires'] !== null && $entry['expires'] <= microtime(true)) {
            unset($values[$key]);
            $this->replace($values);
            return null;
        }
        return $entry;
    }

    private function values(): array
    {
        return $this->container !== null
            ? ($this->container[self::SESSION_KEY][$this->namespace] ?? [])
            : ($_SESSION[self::SESSION_KEY][$this->namespace] ?? []);
    }

    private function replace(array $values): void
    {
        if ($this->container !== null) {
            $bucket = $this->container[self::SESSION_KEY] ?? [];
            if ($values === []) {
                unset($bucket[$this->namespace]);
            } else {
                $bucket[$this->namespace] = $values;
            }
            if ($bucket === []) {
                unset($this->container[self::SESSION_KEY]);
            } else {
                $this->container[self::SESSION_KEY] = $bucket;
            }
        } elseif ($values === []) {
            unset($_SESSION[self::SESSION_KEY][$this->namespace]);
        } else {
            $_SESSION[self::SESSION_KEY][$this->namespace] = $values;
        }
    }

    private static function validateKey(mixed $key): void
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidArgumentException('Cache keys must be nonempty strings without reserved characters {}()/\\@: .');
        }
    }

    private static function ttlSeconds(mixed $ttl): ?int
    {
        if ($ttl !== null && !is_int($ttl) && !$ttl instanceof DateInterval) {
            throw new InvalidArgumentException('Cache TTL must be null, an integer or a DateInterval.');
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }
        return $ttl;
    }
}
