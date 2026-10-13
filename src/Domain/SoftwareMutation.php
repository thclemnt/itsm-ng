<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Closure;
use CommonDBTM;
use DBAdapter;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\LifecycleNotifications;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\MySQLConnection;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;
use Log;
use QueuedNotification;
use Session;
use Throwable;

/** One prepared software command, including its required public lifecycle work. */
final class SoftwareMutation
{
    /** A public preload cannot replace the writer inherited by the ensuing mutation. */
    public static function loadForMutation(DBAdapter $database, CommonDBTM $model, mixed $id, ?callable $admission = null): bool
    {
        if ($database->isSlave()) {
            return false;
        }
        $assertOwner = self::writerContinuity($database);
        $loaded = false;
        $failure = null;
        try {
            $loaded = ($admission === null || $admission()) && $model->getFromDB($id);
        } catch (Throwable $error) {
            $failure = $error;
        }
        try {
            $assertOwner();
        } catch (Throwable $cleanup) {
            $failure = $failure === null ? $cleanup : new MutationCleanupFailure($failure, $cleanup, true);
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $loaded;
    }

    /** Capture this mutation's supplied owner before an overridable preload. */
    public static function writerContinuity(DBAdapter $database): Closure
    {
        if (($GLOBALS['DB'] ?? null) !== $database) {
            throw new TransactionOwnershipMismatch('Software mutation changed its supplied writer.');
        }
        $connection = $database->getDoctrineConnection();
        TransactionOwnership::assertManaged($connection);
        $level = $connection->getTransactionNestingLevel();
        $scope = $level > 0 ? $connection->captureManagedTransactionScope() : null;
        return static function () use ($database, $connection, $scope, $level): void {
            if (($GLOBALS['DB'] ?? null) !== $database || $database->getDoctrineConnection() !== $connection) {
                throw new TransactionOwnershipMismatch('Software mutation changed its supplied writer.');
            }
            TransactionOwnership::assertManaged($connection);
            if ($connection->getTransactionNestingLevel() !== $level) {
                throw new TransactionOwnershipMismatch('Software preload or callback changed its managed nesting.');
            }
            $scope?->assertActive();
        };
    }

    public static function run(DBAdapter $database, CommonDBTM $model, array $checkpoint, callable $operation): mixed
    {
        if ($database !== ($GLOBALS['DB'] ?? null) || $database->isSlave()) {
            return false;
        }
        $connection = $database->getDoctrineConnection();
        TransactionOwnership::assertManaged($connection);
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
            self::assertTransactionalStorage($database, [Log::getTable(), QueuedNotification::getTable()]);
            $result = $journal->observe($connection, $operation);
            if ($result !== false) {
                $frame->commit();
                $accepted = true;
            } else {
                $frame->rollBack();
                $rolledBack = true;
            }
        } catch (Throwable $primary) {
            if ($frame !== null) {
                try {
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (Throwable $cleanup) {
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
            } catch (Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            // Before frame admission only preparation has occurred. Once a
            // frame was requested, rewind requires its proven actual rollback.
            if (!$accepted && ($rolledBack || !$frameRequested)) {
                try {
                    $journal->restore();
                } catch (Throwable $cleanup) {
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
                } catch (Throwable $cleanup) {
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

    private static function preserveFailure(?Throwable $primary, Throwable $cleanup): Throwable
    {
        return $primary === null ? $cleanup : new MutationCleanupFailure(
            $primary,
            $cleanup,
            $primary instanceof MutationCleanupFailure && $primary->rollbackUnproven
        );
    }

    /** PostgreSQL strong snapshots cannot see later allocation phantoms. */
    public static function assertSupportedIsolation(DBAdapter $database): void
    {
        $connection = $database->getDoctrineConnection();
        try {
            MySQLConnection::assertCurrentReads($connection);
        } catch (CurrentReadUnavailable $error) {
            Session::addMessageAfterRedirect(__('Finish the current operation, then retry this software change.'), true, ERROR, false);
            throw new SoftwareAssignmentCancelled($error->getMessage(), previous: $error);
        }
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // Inspect the actual physical session; DBAL's cached isolation may
            // differ after caller SQL. Never change the caller's isolation.
            $isolation = strtolower((string)$connection->fetchOne("SELECT current_setting('transaction_isolation')"));
            if ($isolation !== 'read committed' && $isolation !== 'read uncommitted') {
                $message = __('Finish the current operation, then retry this software change.');
                Session::addMessageAfterRedirect($message, true, ERROR, false);
                throw new SoftwareAssignmentCancelled('Software allocation requires PostgreSQL READ COMMITTED; actual isolation is ' . $isolation . '. Retry outside the caller transaction.');
            }
        }
    }

    public static function assertTransactionalStorage(DBAdapter $database, array $tables): void
    {
        $connection = $database->getDoctrineConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
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
