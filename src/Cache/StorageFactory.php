<?php

declare(strict_types=1);

namespace itsmng\Cache;

use ArrayAccess;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Traversable;

/** Build the configured backend; namespaces and footprints belong to the caller. */
final class StorageFactory
{
    public static function create(array $configuration): CacheItemPoolInterface|SessionAdapter
    {
        if (array_diff_key($configuration, array_flip(['adapter', 'options', 'plugins']))
            || !is_array($configuration['options'] ?? []) || !is_array($configuration['plugins'] ?? [])) {
            throw new InvalidArgumentException('Invalid cache configuration structure.');
        }
        if (is_array($configuration['adapter'] ?? null)) {
            $adapter = $configuration['adapter'];
            if (!is_array($adapter['options'] ?? [])) {
                throw new InvalidArgumentException('Cache adapter options must be an array.');
            }
            $configuration['adapter'] = $adapter['name'] ?? null;
            $configuration['options'] = array_replace($adapter['options'] ?? [], $configuration['options'] ?? []);
        }
        if (!is_string($configuration['adapter'] ?? 'filesystem')) {
            throw new InvalidArgumentException('Cache adapter must be a name.');
        }
        $adapter = strtolower($configuration['adapter'] ?? 'filesystem');
        $options = $configuration['options'] ?? [];
        // Symfony marshals stored values itself. Accept the documented old PHP
        // serializer declaration, but never silently discard other plugins.
        foreach ($configuration['plugins'] ?? [] as $key => $plugin) {
            $plugin = is_string($key) ? ['name' => $key, 'options' => $plugin]
                : (is_string($plugin) ? ['name' => $plugin] : $plugin);
            if (!is_array($plugin) || ($plugin['name'] ?? null) !== 'serializer'
                || array_diff_key($plugin, array_flip(['name', 'options', 'priority']))
                || (isset($plugin['priority']) && !is_numeric($plugin['priority']))
                || !is_array($plugin['options'] ?? [])
                || array_diff_key($plugin['options'] ?? [], ['serializer' => true])
                || (($plugin['options']['serializer'] ?? 'phpserialize') !== 'phpserialize')) {
                throw new InvalidArgumentException('Cache plugins must be migrated to Symfony cache configuration.');
            }
        }
        $allowed = match ($adapter) {
            'filesystem' => ['cache_dir'],
            'redis' => ['server', 'database', 'password', 'user', 'persistent', 'persistent_id'],
            'session' => ['session_container'],
            'memory', 'apcu' => [],
            default => throw new InvalidArgumentException('Unsupported cache adapter: ' . $adapter),
        };
        if (array_diff_key($options, array_flip(['namespace', 'ttl', ...$allowed]))) {
            throw new InvalidArgumentException('Unsupported options for the ' . $adapter . ' cache; migrate the backend configuration.');
        }
        $namespace = $options['namespace'] ?? '';
        $ttl = $options['ttl'] ?? 0;
        if (!is_string($namespace) || !is_int($ttl) || $ttl < 0) {
            throw new InvalidArgumentException('Cache namespace must be a string and TTL a nonnegative integer.');
        }
        if (isset($options['cache_dir']) && !is_string($options['cache_dir'])) {
            throw new InvalidArgumentException('Cache directory must be a string.');
        }
        if (isset($options['session_container'])
            && (!$options['session_container'] instanceof ArrayAccess || !$options['session_container'] instanceof Traversable)) {
            throw new InvalidArgumentException('Session cache container must implement ArrayAccess and Traversable.');
        }
        return match ($adapter) {
            'filesystem' => new FilesystemAdapter($namespace, $ttl, $options['cache_dir'] ?? null),
            'memory' => new ArrayAdapter($ttl, storeSerialized: ($configuration['plugins'] ?? []) !== []),
            'apcu' => new ApcuAdapter($namespace, $ttl),
            'redis' => new RedisAdapter(self::redisConnection($options), $namespace, $ttl),
            'session' => new SessionAdapter($namespace, $options['session_container'] ?? null, $ttl),
        };
    }

    private static function redisConnection(array $options): mixed
    {
        $server = $options['server'] ?? ['host' => '127.0.0.1'];
        $user = $options['user'] ?? null;
        $password = $options['password'] ?? null;
        $timeout = 30;
        if (is_array($server)) {
            if (array_is_list($server) && count($server) <= 3 && $server !== []) {
                $server = ['host' => $server[0], 'port' => $server[1] ?? 6379, 'timeout' => $server[2] ?? 30];
            }
            if (!array_key_exists('host', $server)) {
                throw new InvalidArgumentException('Redis server configuration requires a host.');
            }
            if (array_diff_key($server, array_flip(['host', 'port', 'timeout', 'user', 'pass']))) {
                throw new InvalidArgumentException('Unsupported Redis server options.');
            }
            $host = $server['host'];
            $port = $server['port'] ?? 6379;
            $timeout = $server['timeout'] ?? 30;
            $user ??= $server['user'] ?? null;
            $password ??= $server['pass'] ?? null;
            if (!is_string($host) || $host === '' || !is_int($port) || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Redis requires a nonempty host and valid integer port.');
            }
            $dsn = str_starts_with($host, '/') ? 'redis:' . $host : 'redis://' . $host . ':' . $port;
        } elseif (is_string($server) && $server !== '') {
            $dsn = str_starts_with($server, '/') ? 'redis:' . $server
                : (str_contains($server, '://') || str_starts_with($server, 'redis:') ? $server : 'redis://' . $server);
        } else {
            throw new InvalidArgumentException('Redis server must be a nonempty address or host configuration.');
        }
        if ((!is_int($timeout) && !is_float($timeout)) || $timeout < 0
            || ($user !== null && !is_string($user)) || ($password !== null && !is_string($password))
            || !is_int($options['database'] ?? 0) || ($options['database'] ?? 0) < 0
            || !is_bool($options['persistent'] ?? false)
            || (isset($options['persistent_id']) && !is_string($options['persistent_id']))) {
            throw new InvalidArgumentException('Invalid Redis connection options.');
        }
        return RedisAdapter::createConnection($dsn, [
            'dbindex' => $options['database'] ?? 0,
            'auth' => $user !== null ? [$user, $password ?? ''] : $password,
            'persistent' => $options['persistent'] ?? false,
            'persistent_id' => $options['persistent_id'] ?? null,
            'timeout' => $timeout,
        ]);
    }
}
