<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use LogicException;
use QueuedNotification;
use WeakMap;

/** One shared delivery barrier for deletion and required ownership updates. */
final class LifecycleNotifications
{
    private static ?WeakMap $scopes = null;
    private array $notifications = [];

    private function __construct(private Connection $connection, private int $level)
    {
    }

    public static function begin(Connection $connection): self
    {
        self::$scopes ??= new WeakMap();
        $scope = new self($connection, $connection->getTransactionNestingLevel());
        $scopes = self::$scopes[$connection] ?? [];
        $scopes[] = $scope;
        self::$scopes[$connection] = $scopes;
        return $scope;
    }

    public static function defer(Connection $connection, string $type, int $id): bool
    {
        $scopes = self::$scopes[$connection] ?? [];
        if ($scopes) {
            $scope = end($scopes);
            $scope->notifications[$type . ':' . $id] = [$type, $id];
            return true;
        }
        return $connection->isTransactionActive();
    }

    public function finish(bool $accepted): array
    {
        $scopes = self::$scopes[$this->connection];
        if (array_pop($scopes) !== $this) {
            throw new LogicException('Lifecycle notification scopes closed out of order');
        }
        self::$scopes[$this->connection] = $scopes;
        if (!$accepted) {
            return [];
        }
        if ($scopes) {
            $parent = end($scopes);
            $parent->notifications += $this->notifications;
        }
        // A caller's savepoint release does not permit external delivery.
        return $this->level === 0 ? $this->notifications : [];
    }

    public static function deliver(array $notifications): void
    {
        foreach ($notifications as [$type, $id]) {
            QueuedNotification::forceSendFor($type, $id);
        }
    }
}
