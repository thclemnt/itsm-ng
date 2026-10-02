<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Reusable journaled DDL phases; versioned subclasses retain one frozen table and its targets. */
abstract class StagedTypedItemMigration extends TypedItemMigration
{
    abstract protected function version(): string;

    abstract protected function table(): string;

    final protected function tables(): array
    {
        return [$this->table()];
    }

    public function plan(Connection $connection): array
    {
        if ((Ledger::state($connection, $this->version())['complete'] ?? false) === true) {
            return [];
        }
        $manager = $connection->createSchemaManager();
        $identity = isset($manager->listTableColumns($this->table())['items_id']) ? 'items_id' : 'NULL AS items_id';
        $unsupported = $connection->fetchAllAssociative('SELECT id, itemtype, ' . $identity
            . ' FROM ' . $this->table() . ' WHERE itemtype IS NOT NULL AND itemtype NOT IN (?) LIMIT 5', [array_keys(static::targets())], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        if ($unsupported) {
            throw new \RuntimeException('Unsupported typed relationship kinds in ' . $this->table() . '; samples: ' . json_encode($unsupported, JSON_THROW_ON_ERROR)
                . '. Resolve these links before adoption. Legacy appliance plugin import requires a compatible historical application and legacy MySQL schema before switching to modernized source and db:migrate. A canonical ORM importer requires completed migration history and cannot be used to bypass this legacy-data preflight.');
        }
        $entry = parent::plan($connection)[$this->table()];
        return [$this->table() => [
            'columns' => $entry['sql'],
            'copy' => $entry['copy_legacy'] ? [$this->copySql()] : [],
            'projection' => $entry['key_sql'],
            'constraints' => $entry['constraint_sql'],
        ]];
    }

    /** Replan each completed DDL phase on retry using frozen inputs and the same ledger. */
    public function apply(Connection $connection, ?callable $progress = null): array
    {
        $plan = $this->plan($connection);
        if (!$plan) {
            return [];
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL typed relationship adoption must run outside an application transaction.');
        }
        $apply = function () use ($connection, $progress, $plan): array {
            $state = Ledger::state($connection, $this->version());
            if ($state === null) {
                $columns = $connection->createSchemaManager()->listTableColumns($this->table());
                $state = ['complete' => false, 'phase' => 'audited', 'items_comment' => ($columns['items_id'] ?? null)?->getComment() ?? ''];
                Ledger::save($connection, $this->version(), $state);
            }
            foreach (['columns', 'copy', 'projection', 'constraints'] as $phase) {
                $sql = $this->plan($connection)[$this->table()][$phase];
                foreach ($sql as $statement) {
                    $connection->executeStatement($statement);
                    $progress && $progress($phase, $statement);
                }
                $state['phase'] = $phase;
                Ledger::save($connection, $this->version(), $state);
            }
            // Recover the original comment even if a retry found the projection
            // absent; no runtime metadata can rewrite this historical declaration.
            $platform = $connection->getDatabasePlatform();
            $table = $connection->createSchemaManager()->introspectTable($this->table());
            if ($table->getColumn('items_id')->getComment() !== $state['items_comment']) {
                if ($platform->supportsInlineColumnComments()) {
                    static::configureTable($table);
                    $connection->executeStatement('ALTER TABLE ' . $this->table() . ' MODIFY COLUMN items_id '
                        . $table->getColumn('items_id')->getColumnDefinition() . ' ' . $platform->getInlineColumnCommentSQL($state['items_comment']));
                } else {
                    $connection->executeStatement($platform->getCommentOnColumnSQL($this->table(), 'items_id', $state['items_comment']));
                }
            }
            Ledger::save($connection, $this->version(), ['complete' => true]);
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }

    private function copySql(): string
    {
        $assignments = [];
        foreach (static::targets() as $kind => $target) {
            $assignments[] = static::column($target) . " = CASE WHEN itemtype = '" . $kind . "' THEN items_id ELSE NULL END";
        }
        return 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $assignments);
    }

}
