<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Repair partial sequence adoption even when the original width migration completed. */
final class IdentifierSequences20261007
{
    public const VERSION = '20261007_identifier_sequence_widths';

    public function plan(Connection $connection): array
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return [];
        }
        // Reuse the immutable original identifier scope, never current ORM metadata.
        return WideIdentifiers::planOwnedSequences($connection, IdentifierColumns::history()['identifiers']);
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return;
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL identifier sequence migration must run outside an application transaction.');
        }
        $sql = $this->plan($connection);
        $apply = static function () use ($connection, $progress, $sql): void {
            Ledger::save($connection, self::VERSION, ['complete' => false]);
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
                $progress && $progress($statement);
            }
            Ledger::save($connection, self::VERSION, ['complete' => true]);
        };
        $postgres ? $connection->transactional($apply) : $apply();
    }
}
