<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use CommonDBTM;
use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;
use Throwable;

/** Parent persistence and required scope forwarding own one database frame. */
final class OwnershipUpdateUnit
{
    /** @var list<array{writer: DBAdapter, connection: Connection, scope: ManagedTransactionScope}> */
    private static array $writerGuards = [];

    /** An opted-in command must reject a changed route before a model producer uses it. */
    public static function assertWriter(DBAdapter $database): void
    {
        if (self::$writerGuards === []) {
            return;
        }
        foreach (self::$writerGuards as $guard) {
            if ($database !== $guard['writer'] || ($GLOBALS['DB'] ?? null) !== $guard['writer']) {
                throw new TransactionOwnershipMismatch('The owned lifecycle changed its supplied writer.');
            }
        }
        // A virtual getter can change the global route while returning the old
        // connection. Validate its result and the route after it has returned.
        self::assertResolvedWriter($database, $database->getDoctrineConnection());
    }

    /** Validate the connection a real producer resolved without calling its getter again. */
    public static function assertResolvedWriter(DBAdapter $database, Connection $connection): void
    {
        foreach (self::$writerGuards as $guard) {
            // Explicit readers retain their own route. Only a captured writer's
            // resolved connection belongs to this mutation's producer contract.
            if ($database !== $guard['writer']) {
                continue;
            }
            if (($GLOBALS['DB'] ?? null) !== $guard['writer'] || $connection !== $guard['connection']) {
                throw new TransactionOwnershipMismatch('The owned lifecycle changed its supplied writer.');
            }
            // A legitimate nested unit may hold a descendant frame. The original
            // captured parent must still exist, including its physical identity.
            $guard['scope']->assertActive();
        }
    }

    /** Guard core lifecycle producers inside a caller's already-owned frame. */
    public static function withWriterGuard(DBAdapter $database, Connection $connection, callable $operation): mixed
    {
        self::registerWriterGuard($database, $connection);
        try {
            self::assertWriter($database);
            $result = $operation();
            self::assertWriter($database);
            return $result;
        } finally {
            array_pop(self::$writerGuards);
        }
    }

    private static function registerWriterGuard(DBAdapter $database, Connection $connection): void
    {
        if (($GLOBALS['DB'] ?? null) !== $database) {
            throw new TransactionOwnershipMismatch('The owned lifecycle requires its supplied writer.');
        }
        TransactionOwnership::assertManaged($connection);
        $scope = $connection->captureManagedTransactionScope();
        self::$writerGuards[] = ['writer' => $database, 'connection' => $connection, 'scope' => $scope];
    }

    public static function assertTransactionalStorage(DBAdapter $database, string $table): void
    {
        $connection = $database->getDoctrineConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $engine = $connection->fetchOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
            throw new RuntimeException('Ownership update requires InnoDB storage for ' . $table);
        }
    }

    public static function run(
        DBAdapter $database,
        CommonDBTM $model,
        array $storedFields,
        callable $operation,
        bool $guardWriter = false
    ): bool {
        self::assertWriter($database);
        if ($guardWriter && ($GLOBALS['DB'] ?? null) !== $database) {
            throw new TransactionOwnershipMismatch('The owned lifecycle requires its supplied writer.');
        }
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
        $guardRegistered = false;
        try {
            $frame = OwnedMutationFrame::begin($connection);
            if ($guardWriter) {
                self::registerWriterGuard($database, $connection);
                $guardRegistered = true;
            }
            $completed = $journal->observe($connection, $operation);
            self::assertWriter($database);
            if ($completed) {
                $frame->commit();
                $accepted = true;
            } else {
                $rollbackAttempted = true;
                $frame->rollBack();
                $rolledBack = true;
            }
        } catch (Throwable $primary) {
            $failure = $primary;
            if ($frame !== null && !$rollbackAttempted) {
                try {
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (Throwable $cleanup) {
                    $failure = new MutationRollbackFailure($primary, $cleanup);
                }
            }
        } finally {
            if ($guardRegistered) {
                array_pop(self::$writerGuards);
            }
            try {
                $notifications = $delivery->finish($accepted);
            } catch (Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            // Only a completed rollback of our exact frame authorizes a rewind.
            if (!$accepted && $rolledBack) {
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
        // A transport failure after physical commit cannot rewind persisted models.
        LifecycleNotifications::deliver($notifications);
        return $accepted;
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
