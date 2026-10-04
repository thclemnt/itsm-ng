<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\IncomingProjectionReferences;
use itsmng\Database\Migration\LegacyToOrm;

/** A real second-ordinal incoming FK must refuse destructive projection adoption. */
final class ComponentIncomingProjection
{
    public static function verify(Connection $connection, object $migration, string $table, int $binding, int $subject): void
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $consumer = 'port_component_projection_consumer';
        $unique = 'port_component_projection_pair';
        $foreign = 'port_component_projection_foreign';
        if ($connection->isTransactionActive() || $manager->tablesExist([$consumer])
            || $manager->introspectTable($table)->hasIndex($unique)) {
            throw new LogicException('Idle disposable source and absence of every owned projection fixture required.');
        }
        $uniqueOwned = $consumerOwned = false;
        $primary = null;
        $cleanup = [];
        try {
            $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT ' . $quote($unique) . ' UNIQUE (id, items_id)');
            $uniqueOwned = true;
            $connection->executeStatement('CREATE TABLE ' . $quote($consumer) . ' (id BIGINT NOT NULL PRIMARY KEY, binding_id BIGINT NOT NULL, subject_id BIGINT NOT NULL, CONSTRAINT '
                . $quote($foreign) . ' FOREIGN KEY (binding_id, subject_id) REFERENCES ' . $quote($table) . ' (id, items_id))');
            $consumerOwned = true;
            $connection->insert($consumer, ['id' => 1, 'binding_id' => $binding, 'subject_id' => $subject]);
            $incoming = new IncomingProjectionReferences($connection);
            $schema = (string)$connection->fetchOne($platform instanceof PostgreSQLPlatform ? 'SELECT current_schema()' : 'SELECT DATABASE()');
            verify($incoming->has($schema, $table), 'Actual composite FK inventory finds compatibility projection in its second ordinal');
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id');
            $receipt = $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            $sourceDdl = $platform->getCreateTableSQL($manager->introspectTable($table));
            $consumerDdl = $platform->getCreateTableSQL($manager->introspectTable($consumer));
            try {
                $migration->plan($connection, $incoming);
                throw new LogicException('Composite incoming compatibility FK accepted destructive adoption');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'Incoming typed legacy item foreign key'), 'Actual incoming projection diagnostic refuses the supplied graph');
            }
            verify(
                $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id') === $rows
                && $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $receipt
                && $connection->fetchAllAssociative('SELECT * FROM ' . $quote($consumer)) === [['id' => 1, 'binding_id' => $binding, 'subject_id' => $subject]]
                && $platform->getCreateTableSQL($manager->introspectTable($table)) === $sourceDdl
                && $platform->getCreateTableSQL($manager->introspectTable($consumer)) === $consumerDdl,
                'Incoming FK refusal preserves actual source/consumer rows, every raw receipt and both structural definitions'
            );
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            if ($consumerOwned) {
                try {
                    $manager->dropTable($consumer);
                    $consumerOwned = false;
                } catch (Throwable $error) {
                    $cleanup[] = $error;
                }
            }
            if ($uniqueOwned && !$consumerOwned) {
                try {
                    $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' DROP ' . ($platform instanceof PostgreSQLPlatform ? 'CONSTRAINT ' : 'INDEX ') . $quote($unique));
                } catch (Throwable $error) {
                    $cleanup[] = $error;
                }
            }
        }
        if ($primary !== null) {
            foreach ($cleanup as $error) {
                try {
                    fwrite(STDERR, 'Additional owned composite fixture cleanup failure: ' . (string)$error . "\n");
                } catch (Throwable) {
                    // Reporting cannot replace the actual primary.
                }
            }
            throw $primary;
        }
        if ($cleanup !== []) {
            throw new RuntimeException('Owned composite projection fixture cleanup failed.', previous: $cleanup[0]);
        }
    }
}
