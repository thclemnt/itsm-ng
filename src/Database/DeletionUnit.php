<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** Database lifecycle boundary; nested operations share the supplied writer connection. */
final class DeletionUnit
{
    /** @var \WeakMap<Connection, array>|null */
    private static ?\WeakMap $units = null;

    public static function isActive(Connection $connection): bool
    {
        return !empty(self::$units[$connection]);
    }

    public static function run(Connection $connection, callable $operation): DeletionResult
    {
        self::$units ??= new \WeakMap();
        $frames = self::$units[$connection] ?? [];
        $frames[] = ['cancelled' => false];
        self::$units[$connection] = $frames;
        $level = $connection->getTransactionNestingLevel();
        $outcome = DeletionOutcome::Cancelled;
        $notifications = [];
        $delivery = LifecycleNotifications::begin($connection);
        try {
            $connection->beginTransaction();
            $outcome = $operation();
            if ($connection->getTransactionNestingLevel() !== $level + 1) {
                throw new \LogicException('A deletion hook changed the lifecycle transaction ownership');
            }
            $frames = self::$units[$connection];
            $frame = end($frames);
            if ($outcome === DeletionOutcome::Cancelled || $frame['cancelled']) {
                $outcome = DeletionOutcome::Cancelled;
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (DeletionCancelled) {
            $outcome = DeletionOutcome::Cancelled;
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
        } catch (\Throwable $error) {
            $outcome = DeletionOutcome::Cancelled;
            $notifications = [];
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
            throw $error;
        } finally {
            $frames = self::$units[$connection];
            array_pop($frames);
            if ($frames) {
                $last = array_key_last($frames);
                if ($outcome === DeletionOutcome::Cancelled) {
                    $frames[$last]['cancelled'] = true;
                }
            }
            self::$units[$connection] = $frames;
            $notifications = $delivery->finish($outcome !== DeletionOutcome::Cancelled);
        }
        // A released savepoint is not a commit. Caller-owned transactions keep
        // their persisted queue rows for cron, including subsequent caller rollback.
        return new DeletionResult($outcome, $level === 0 ? $notifications : []);
    }

    /** Refused child mutations cannot leave a parent purge partially committed. */
    public static function requireSuccess(Connection $connection, bool $result): void
    {
        if (!$result && !empty(self::$units[$connection])) {
            throw new DeletionCancelled('A related lifecycle operation was cancelled');
        }
    }
}
