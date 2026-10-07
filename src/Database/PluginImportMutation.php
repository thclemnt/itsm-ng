<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;
use Throwable;

/** A plugin aggregate, its lifecycle callbacks and import receipt own one writer frame. */
final class PluginImportMutation
{
    /** Lifecycle hooks can write audit/financial core rows as well as the aggregate. */
    public static function assertTransactionalCore(Connection $connection, string $aggregate): void
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $mapped = EntityRegistry::tables();
        foreach ($connection->fetchAllAssociative('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table) {
            if (isset($mapped[$table['TABLE_NAME']]) && strcasecmp($table['ENGINE'] ?? '', 'InnoDB') !== 0) {
                throw new RuntimeException($aggregate . ' lifecycle import requires transactional core tables: ' . $table['TABLE_NAME'] . ' must use InnoDB; found ' . ($table['ENGINE'] ?? 'no transactional engine') . '. Reconcile this table before importing; audit and hooks cannot roll back otherwise.');
            }
        }
    }

    /** Check the supplied guard after each public lifecycle or progress callback. */
    public static function run(DBAdapter $database, callable $operation): mixed
    {
        $connection = $database->getDoctrineConnection();
        if (($GLOBALS['DB'] ?? null) !== $database || $database->isSlave()) {
            throw new TransactionOwnershipMismatch('Plugin import requires the supplied active writer.');
        }
        TransactionOwnership::assertManaged($connection);
        $frame = null;
        $rolledBack = false;
        $accepted = false;
        $failure = null;
        $notifications = [];
        $result = null;
        $journal = new LifecycleModelJournal();
        $session = $_SESSION;
        $delivery = LifecycleNotifications::begin($connection);
        try {
            $frame = OwnedMutationFrame::begin($connection);
            $assertActive = static function () use ($database, $connection, $frame): void {
                if (($GLOBALS['DB'] ?? null) !== $database || $database->getDoctrineConnection() !== $connection || $database->isSlave()) {
                    throw new TransactionOwnershipMismatch('A plugin import callback changed the supplied active writer.');
                }
                $frame->assertActive();
            };
            $result = $journal->observe($connection, static fn () => $operation($assertActive, $journal));
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
                    // The callback's replacement transaction is never ours to unwind.
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
                    $_SESSION = $session;
                } catch (Throwable $cleanup) {
                    $failure = self::preserveFailure($failure, $cleanup);
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
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
}
