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
        $connection = $database->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $journal = new LifecycleModelJournal();
        // Keep attempted input for diagnostics, without retaining pending writes.
        $journal->remember($model, ['fields' => $storedFields, 'input' => $model->input, 'updates' => [], 'oldvalues' => []]);
        $session = $_SESSION;
        $delivery = LifecycleNotifications::begin($connection);
        $accepted = false;
        try {
            $connection->beginTransaction();
            $accepted = $journal->observe($connection, $operation);
            if ($connection->getTransactionNestingLevel() !== $level + 1) {
                throw new \LogicException('An ownership update hook changed transaction ownership');
            }
            if ($accepted) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }
        } catch (\Throwable $error) {
            $accepted = false;
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
        // A transport failure after physical commit cannot rewind persisted models.
        LifecycleNotifications::deliver($notifications);
        return $accepted;
    }
}
