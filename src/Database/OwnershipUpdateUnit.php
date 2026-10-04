<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Parent persistence and required scope forwarding own one database frame. */
final class OwnershipUpdateUnit
{
    public static function assertTransactionalStorage(\DBAdapter $database, string $table): void
    {
        $connection = $database->getDoctrineConnection();
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return;
        }
        $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
            throw new \RuntimeException('Ownership update requires InnoDB storage for ' . $table);
        }
    }

    public static function run(\DBAdapter $database, \CommonDBTM $model, array $storedFields, callable $operation): bool
    {
        $database->assertManagedTransaction();
        $connection = $database->getDoctrineConnection();
        $frame = null;
        $rolledBack = false;
        $rollbackAttempted = false;
        $journal = new LifecycleModelJournal();
        // Keep attempted input for diagnostics, without retaining pending writes.
        $checkpoint = LifecycleModelJournal::state($model);
        $checkpoint['fields'] = $storedFields;
        $checkpoint['updates'] = [];
        $checkpoint['oldvalues'] = [];
        $journal->remember($model, $checkpoint);
        $session = $_SESSION;
        $delivery = LifecycleNotifications::begin($connection);
        $accepted = false;
        $failure = null;
        $notifications = [];
        try {
            $frame = OwnedMutationFrame::begin($connection);
            if ($journal->observe($connection, $operation)) {
                $frame->commit();
                $accepted = true;
            } else {
                $rollbackAttempted = true;
                $frame->rollBack();
                $rolledBack = true;
            }
        } catch (\Throwable $primary) {
            $failure = $primary;
            if ($frame !== null && !$rollbackAttempted) {
                try {
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (\Throwable $cleanup) {
                    $failure = new MutationRollbackFailure($primary, $cleanup);
                }
            }
        } finally {
            try {
                $notifications = $delivery->finish($accepted);
            } catch (\Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            // Only a completed rollback of our exact frame authorizes a rewind.
            if (!$accepted && $rolledBack) {
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
        if ($failure !== null) {
            throw $failure;
        }
        // A transport failure after physical commit cannot rewind persisted models.
        LifecycleNotifications::deliver($notifications);
        return $accepted;
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
