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
        $frames = self::$units[$connection] ?? [];
        if (!$frames) {
            return false;
        }
        $frame = end($frames);
        $frame['scope']->assertActive();
        return true;
    }

    /** Model/session rewind is authorized only by rollback of this exact owner frame. */
    public static function run(Connection $connection, callable $operation, ?callable $afterRollback = null): DeletionResult
    {
        TransactionOwnership::assertManaged($connection);
        self::isActive($connection); // A stale parent cannot mint a fresh deletion authority.
        self::$units ??= new \WeakMap();
        $level = $connection->getTransactionNestingLevel();
        $outcome = DeletionOutcome::Cancelled;
        $frame = null;
        $registered = false;
        $rolledBack = false;
        $rollbackAttempted = false;
        $accepted = false;
        $failure = null;
        $notifications = [];
        $journal = new LifecycleModelJournal();
        $delivery = LifecycleNotifications::begin($connection);
        try {
            $frame = OwnedMutationFrame::begin($connection);
            $frames = self::$units[$connection] ?? [];
            $frames[] = ['scope' => $connection->captureManagedTransactionScope(), 'cancelled' => false];
            self::$units[$connection] = $frames;
            $registered = true;
            $outcome = $journal->observe($connection, $operation);
            if (!$outcome instanceof DeletionOutcome) {
                throw new \LogicException('A deletion operation must return a structured outcome');
            }
            $frame->assertActive();
            $frames = self::$units[$connection];
            $current = end($frames);
            if ($outcome === DeletionOutcome::Cancelled || $current['cancelled']) {
                $outcome = DeletionOutcome::Cancelled;
                $rollbackAttempted = true;
                $frame->rollBack();
                $rolledBack = true;
            } else {
                $frame->commit();
                $accepted = true;
            }
        } catch (\Throwable $primary) {
            $outcome = DeletionOutcome::Cancelled;
            $failure = $primary instanceof DeletionCancelled ? null : $primary;
            if ($frame !== null && !$rollbackAttempted) {
                try {
                    $rollbackAttempted = true;
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (\Throwable $cleanup) {
                    $failure = new MutationRollbackFailure($primary, $cleanup);
                }
            }
        } finally {
            if ($registered) {
                $frames = self::$units[$connection];
                array_pop($frames);
                if ($frames && !$accepted) {
                    $last = array_key_last($frames);
                    $frames[$last]['cancelled'] = true;
                }
                self::$units[$connection] = $frames;
            }
            try {
                $notifications = $delivery->finish($accepted);
            } catch (\Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            // Depth alone cannot prove rollback after commit/reopen or reconnect.
            // Each cleanup remains independent; none may replace the first failure.
            if ($rolledBack) {
                try {
                    $journal->restore();
                } catch (\Throwable $cleanup) {
                    $failure = self::preserveFailure($failure, $cleanup);
                }
                if ($afterRollback !== null) {
                    try {
                        $afterRollback();
                    } catch (\Throwable $cleanup) {
                        $failure = self::preserveFailure($failure, $cleanup);
                    }
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        // A released savepoint is not a physical commit or permission to deliver.
        return new DeletionResult($outcome, $level === 0 ? $notifications : []);
    }

    /** Refused child mutations cannot leave a parent purge partially committed. */
    public static function requireSuccess(Connection $connection, bool $result): void
    {
        if (!$result && self::isActive($connection)) {
            throw new DeletionCancelled('A related lifecycle operation was cancelled');
        }
    }

    private static function preserveFailure(?\Throwable $primary, \Throwable $cleanup): \Throwable
    {
        return $primary === null ? $cleanup : new MutationCleanupFailure(
            $primary,
            $cleanup,
            $primary instanceof MutationCleanupFailure && $primary->rollbackUnproven
        );
    }
}
