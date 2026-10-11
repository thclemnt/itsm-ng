<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Throwable;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\LifecycleNotifications;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;

/** A dictionary merge and its public lifecycle callbacks share one exact writer frame. */
final class DictionaryMutation
{
    /** The operation must check its supplied guard immediately after each public callback. */
    public static function run(Connection $connection, callable $operation): void
    {
        $database = $GLOBALS['DB'] ?? null;
        if (!$database instanceof DBAdapter || $database->isSlave() || $database->getDoctrineConnection() !== $connection) {
            throw new TransactionOwnershipMismatch('Dictionary callbacks require the supplied active writer.');
        }
        TransactionOwnership::assertManaged($connection);
        $frame = null;
        $rolledBack = false;
        $accepted = false;
        $failure = null;
        $notifications = [];
        $journal = new LifecycleModelJournal();
        $session = $_SESSION;
        $delivery = LifecycleNotifications::begin($connection);
        try {
            $frame = OwnedMutationFrame::begin($connection);
            $assertActive = static function () use ($database, $connection, $frame): void {
                if (($GLOBALS['DB'] ?? null) !== $database || $database->getDoctrineConnection() !== $connection || $database->isSlave()) {
                    throw new TransactionOwnershipMismatch('A dictionary callback changed the supplied active writer.');
                }
                $frame->assertActive();
            };
            $journal->observe($connection, static fn () => $operation($assertActive));
            $assertActive();
            $frame->commit();
            $accepted = true;
        } catch (Throwable $primary) {
            $failure = $primary;
            if ($frame !== null) {
                try {
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (Throwable $cleanup) {
                    // A callback's replacement frame is not ours to unwind.
                    $failure = new MutationRollbackFailure($primary, $cleanup);
                }
            }
        } finally {
            try {
                $notifications = $delivery->finish($accepted);
            } catch (Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            if ($rolledBack) {
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
        if ($failure !== null) {
            throw $failure;
        }
        // Releasing a caller's savepoint does not authorize external delivery.
        LifecycleNotifications::deliver($notifications);
    }

    private static function preserveFailure(?Throwable $primary, Throwable $cleanup): Throwable
    {
        return $primary === null ? $cleanup : new MutationCleanupFailure(
            $primary,
            $cleanup,
            $primary instanceof MutationCleanupFailure && $primary->rollbackUnproven
        );
    }
}
