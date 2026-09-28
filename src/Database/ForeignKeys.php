<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

/** Explicit relationships: never infer a foreign key from a column's name. */
final class ForeignKeys
{
    /**
     * Required, non-polymorphic associations whose parent purge hooks remove
     * their children before deletion. RESTRICT keeps those hooks authoritative.
     * users_id/suppliers_id on ticket actors are excluded (anonymous email actors).
     * entities_id=0 is a real root entity, not an absent relationship.
     */
    public const RELATIONS = [
        'glpi_groups_users' => ['users_id' => 'glpi_users', 'groups_id' => 'glpi_groups'],
        'glpi_profiles_users' => ['users_id' => 'glpi_users', 'profiles_id' => 'glpi_profiles', 'entities_id' => 'glpi_entities'],
        'glpi_profilerights' => ['profiles_id' => 'glpi_profiles'],
        'glpi_tickets_users' => ['tickets_id' => 'glpi_tickets'],
        'glpi_groups_tickets' => ['tickets_id' => 'glpi_tickets', 'groups_id' => 'glpi_groups'],
        'glpi_suppliers_tickets' => ['tickets_id' => 'glpi_tickets'],
        'glpi_tickettasks' => ['tickets_id' => 'glpi_tickets'],
        'glpi_ticketsatisfactions' => ['tickets_id' => 'glpi_tickets'],
        'glpi_documents_items' => ['documents_id' => 'glpi_documents'],
        'glpi_useremails' => ['users_id' => 'glpi_users'],
    ];

    public static function name(string $table, string $column): string
    {
        return 'fk_' . substr($table, 5) . '_' . $column;
    }

    public function addToSchema(Schema $schema): void
    {
        foreach (self::RELATIONS as $table => $relations) {
            foreach ($relations as $column => $parent) {
                $child = $schema->getTable($table);
                $child->addForeignKeyConstraint($schema->getTable($parent), [$column], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], self::name($table, $column));
            }
        }
    }

    /** Return orphan counts; no application data is ever repaired or deleted. */
    public function audit(Connection $connection): array
    {
        $problems = [];
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        foreach (self::RELATIONS as $table => $relations) {
            foreach ($relations as $column => $parent) {
                $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' c LEFT JOIN ' . $quote($parent) . ' p ON c.' . $quote($column) . ' = p.id WHERE c.' . $quote($column) . ' IS NOT NULL AND p.id IS NULL');
                if ($count > 0) {
                    $problems[$table . '.' . $column] = $count;
                }
            }
        }
        return $problems;
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $sql = [];
        foreach (self::RELATIONS as $table => $relations) {
            $existing = $manager->listTableForeignKeys($table);
            foreach ($relations as $column => $parent) {
                $name = self::name($table, $column);
                foreach ($existing as $constraint) {
                    if ($constraint->getName() === $name) {
                        if ($constraint->getLocalColumns() !== [$column] || $constraint->getForeignTableName() !== $parent || $constraint->getForeignColumns() !== ['id'] || !in_array($constraint->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true) || !in_array($constraint->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                            throw new \RuntimeException('Existing foreign key has a different definition: ' . $name);
                        }
                        continue 2;
                    }
                }
                $foreignKey = new ForeignKeyConstraint([$column], $parent, ['id'], $name, ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT']);
                $sql[] = $platform->getCreateForeignKeySQL($foreignKey, $table);
            }
        }
        return $sql;
    }

    public function apply(Connection $connection): void
    {
        $problems = $this->audit($connection);
        if ($problems) {
            throw new \RuntimeException('Foreign keys were not installed; orphaned references: ' . json_encode($problems));
        }
        // MySQL ALTER TABLE commits implicitly; each statement is idempotent on
        // retry. PostgreSQL callers can wrap the whole install in a transaction.
        foreach ($this->plan($connection) as $sql) {
            $connection->executeStatement($sql);
        }
    }
}
