<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\LifecycleNotifications;

/** One prepared software command, including its required public lifecycle work. */
final class SoftwareMutation
{
    public static function run(\DBAdapter $database, \CommonDBTM $model, array $checkpoint, callable $operation): mixed
    {
        if ($database !== ($GLOBALS['DB'] ?? null) || $database->isSlave()) {
            return false;
        }
        $connection = $database->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $journal = new LifecycleModelJournal();
        $journal->remember($model, $checkpoint);
        $session = $_SESSION;
        $delivery = LifecycleNotifications::begin($connection);
        $accepted = false;
        $result = false;
        try {
            self::assertSupportedIsolation($database);
            $connection->beginTransaction();
            self::assertTransactionalStorage($database, [\Log::getTable(), \QueuedNotification::getTable()]);
            $result = $journal->observe($connection, $operation);
            if ($connection->getTransactionNestingLevel() !== $level + 1) {
                throw new \LogicException('A software mutation hook changed transaction ownership');
            }
            if ($result !== false) {
                $connection->commit();
                $accepted = true;
            } else {
                $connection->rollBack();
            }
        } catch (SoftwareAssignmentCancelled) {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
            $result = false;
        } catch (\Throwable $error) {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
            throw $error;
        } finally {
            $notifications = $delivery->finish($accepted);
            if (!$accepted) {
                $journal->restore();
                $feedback = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
                $_SESSION = $session;
                foreach ([WARNING, ERROR] as $type) {
                    foreach (array_diff($feedback[$type] ?? [], $session['MESSAGE_AFTER_REDIRECT'][$type] ?? []) as $message) {
                        $_SESSION['MESSAGE_AFTER_REDIRECT'][$type][] = $message;
                    }
                }
            }
        }
        // A transport error after commit cannot reverse persisted database work.
        LifecycleNotifications::deliver($notifications);
        return $result;
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
