<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Exception;

/** Health inspection owns disposable configured transports, never an application transaction. */
final class DatabaseHealthProbe
{
    /**
     * @param \Closure(): ?\DBAdapter $master
     * @param null|\Closure(int|string): ?\DBAdapter $replica
     * @param list<int|string> $replicaPositions Configured host keys, in their original order.
     */
    public function __construct(private \Closure $master, private ?\Closure $replica = null, private array $replicaPositions = [])
    {
        if ($replicaPositions !== [] && $replica === null) {
            throw new \InvalidArgumentException('Replica inspection requires its configured transport factory.');
        }
    }

    public static function configured(): self
    {
        $master = static fn (): ?\DBAdapter => class_exists(\DB::class)
            ? (new \ReflectionClass(\DB::class))->newInstanceWithoutConstructor() : null;
        if (!\DBConnection::isDBSlaveActive()) {
            return new self($master);
        }

        include_once GLPI_CONFIG_DIR . '/config_db_slave.php';
        $configuration = (new \ReflectionClass(\DBSlave::class))->newInstanceWithoutConstructor();
        $hosts = is_array($configuration->dbhost) ? $configuration->dbhost : [$configuration->dbhost];
        return new self(
            $master,
            static fn (int|string $choice): \DBAdapter => (new \ReflectionClass(\DBSlave::class))->newInstanceWithoutConstructor(),
            array_keys($hosts)
        );
    }

    /** @return list<int|string> */
    public function replicaPositions(): array
    {
        return $this->replicaPositions;
    }

    public function masterAvailable(): bool
    {
        return $this->inspect($this->master, null, false) !== null;
    }

    public function replicationDelay(int|string $choice): int
    {
        if (!in_array($choice, $this->replicaPositions, true) || $this->replica === null) {
            throw new \OutOfBoundsException('Unknown configured replica position.');
        }
        $master = $this->inspect($this->master, null, true);
        $replica = $this->inspect($this->replica, $choice, true);
        return $master === null || $replica === null ? 10000000000 : $master - $replica;
    }

    private function inspect(\Closure $factory, int|string|null $choice, bool $history): ?int
    {
        $adapter = $choice === null ? $factory() : $factory($choice);
        if ($adapter === null) {
            return null;
        }
        if (!$adapter instanceof \DBAdapter) {
            throw new \TypeError('A health transport factory must return a configured database adapter.');
        }
        $retainedOwner = null;
        try {
            $retainedOwner = $adapter->getDoctrineConnection();
        } catch (\RuntimeException $error) {
            // Both configured adapters expose absence through this pure accessor.
            if ($error->getMessage() !== 'Database connection is not open.') {
                throw $error;
            }
        }
        if ($retainedOwner !== null || $adapter->connected || $adapter === ($GLOBALS['DB'] ?? null)) {
            throw new \LogicException('A health transport factory must return a new disconnected adapter.');
        }
        $value = null;
        $primary = null;
        try {
            if ($adapter->connect($choice)) {
                $connection = $adapter->getDoctrineConnection();
                $value = $history
                    ? (int)$connection->fetchOne('SELECT ' . $adapter->expressions()->epoch('MAX(' . $adapter->quoteName('date_mod') . ')')
                        . ' FROM ' . $adapter->quoteName('glpi_logs'))
                    : (int)$connection->fetchOne('SELECT 1');
            }
        } catch (Exception $error) {
            // Health reports availability; query details and connection parameters stay private.
            $value = null;
        } catch (\Throwable $error) {
            $primary = $error;
        }
        try {
            $adapter->close();
        } catch (Exception $error) {
            $value = null;
        } catch (\Throwable $error) {
            $primary ??= $error;
        }
        if ($primary !== null) {
            throw $primary;
        }
        return $value;
    }
}
