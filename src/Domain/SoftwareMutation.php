<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\LifecycleNotifications;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnedMutationFrame;

/** One prepared software command, including its required public lifecycle work. */
final class SoftwareMutation
{
    public static function run(\DBAdapter $database, \CommonDBTM $model, array $checkpoint, callable $operation): mixed
    {
        if ($database !== ($GLOBALS['DB'] ?? null) || $database->isSlave()) {
            return false;
        }
        $connection = $database->getDoctrineConnection();
        \itsmng\Database\TransactionOwnership::assertManaged($connection);
        $frame = null;
        $frameRequested = false;
        $rolledBack = false;
        $journal = new LifecycleModelJournal();
        $journal->remember($model, $checkpoint);
        $session = $_SESSION;
        $delivery = LifecycleNotifications::begin($connection);
        $accepted = false;
        $result = false;
        $failure = null;
        $cancelled = null;
        $notifications = [];
        try {
            self::assertSupportedIsolation($database);
            $frameRequested = true;
            $frame = OwnedMutationFrame::begin($connection);
            self::assertTransactionalStorage($database, [\Log::getTable(), \QueuedNotification::getTable()]);
            $result = $journal->observe($connection, $operation);
            if ($result !== false) {
                $frame->commit();
                $accepted = true;
            } else {
                $frame->rollBack();
                $rolledBack = true;
            }
        } catch (\Throwable $primary) {
            if ($frame !== null) {
                try {
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (\Throwable $cleanup) {
                    // The replacement frame is not ours. Preserve both actual
                    // errors and do not rewind models/session as if data reverted.
                    $failure = new MutationRollbackFailure($primary, $cleanup);
                }
            }
            $failure ??= $primary;
            if ($primary instanceof SoftwareAssignmentCancelled) {
                $cancelled = $primary;
                $result = false;
            }
        } finally {
            try {
                $notifications = $delivery->finish($accepted);
            } catch (\Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            // Before frame admission only preparation has occurred. Once a
            // frame was requested, rewind requires its proven actual rollback.
            if (!$accepted && ($rolledBack || !$frameRequested)) {
                try {
                    $journal->restore();
                } catch (\Throwable $cleanup) {
                    $failure = self::preserveFailure($failure, $cleanup);
                }
                try {
                    $feedback = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
                    $_SESSION = $session;
                    foreach ([WARNING, ERROR] as $type) {
                        foreach (array_diff($feedback[$type] ?? [], $session['MESSAGE_AFTER_REDIRECT'][$type] ?? []) as $message) {
                            $_SESSION['MESSAGE_AFTER_REDIRECT'][$type][] = $message;
                        }
                    }
                } catch (\Throwable $cleanup) {
                    $failure = self::preserveFailure($failure, $cleanup);
                }
            }
        }
        if ($failure !== null && $failure !== $cancelled) {
            throw $failure;
        }
        // A transport error after commit cannot reverse persisted database work.
        LifecycleNotifications::deliver($notifications);
        return $result;
    }

    private static function preserveFailure(?\Throwable $primary, \Throwable $cleanup): \Throwable
    {
        return $primary === null ? $cleanup : new MutationCleanupFailure(
            $primary,
            $cleanup,
            $primary instanceof MutationCleanupFailure && $primary->rollbackUnproven
        );
    }

    /** PostgreSQL strong snapshots cannot see later allocation phantoms. */
    public static function assertSupportedIsolation(\DBAdapter $database): void
    {
        $connection = $database->getDoctrineConnection();
        if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            // Inspect the actual physical session; DBAL's cached isolation may
            // differ after caller SQL. Never change the caller's isolation.
            $isolation = strtolower((string)$connection->fetchOne("SELECT current_setting('transaction_isolation')"));
            if ($isolation !== 'read committed' && $isolation !== 'read uncommitted') {
                $message = __('Finish the current operation, then retry this software change.');
                \Session::addMessageAfterRedirect($message, true, ERROR, true);
                throw new SoftwareAssignmentCancelled('Software allocation requires PostgreSQL READ COMMITTED; actual isolation is ' . $isolation . '. Retry outside the caller transaction.');
            }
        }
    }

    public static function assertTransactionalStorage(\DBAdapter $database, array $tables): void
    {
        $connection = $database->getDoctrineConnection();
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return;
        }
        foreach (array_unique($tables) as $table) {
            $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
                throw new SoftwareAssignmentCancelled('Software mutation requires InnoDB storage for ' . $table);
            }
        }
    }
}
