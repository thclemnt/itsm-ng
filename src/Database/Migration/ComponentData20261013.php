<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/** Frozen family-local data preflight; completed older receipts cannot bypass these audits. */
final class ComponentData20261013
{
    public static function plan(Connection $connection, array $snapshot): array
    {
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $table = $quote($snapshot['table']);
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($snapshot['table']);
        $after = clone $before;
        foreach ($snapshot['booleans'] as $column) {
            if (!$before->hasColumn($column)) {
                throw new \RuntimeException('Missing historical component boolean: ' . $snapshot['table'] . '.' . $column);
            }
            $actual = $before->getColumn($column);
            $type = Type::lookupName($actual->getType());
            if (!in_array($type, [Types::BOOLEAN, Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
                throw new \RuntimeException('Unsupported historical component boolean storage: ' . $snapshot['table'] . '.' . $column . ' (' . $type . ')');
            }
            if (!in_array($actual->getDefault(), [null, false, true, 0, 1, '0', '1'], true)) {
                throw new \RuntimeException('Invalid historical component boolean default: ' . $snapshot['table'] . '.' . $column);
            }
            $field = $quote($column);
            $invalid = $field . ' IS NULL';
            if (!$platform instanceof PostgreSQLPlatform || $type !== Types::BOOLEAN) {
                $invalid .= ' OR ' . $field . ' NOT IN (0, 1)';
            }
            self::audit($connection, $snapshot['table'], $column, $invalid);
            $after->getColumn($column)->setType(Type::getType(Types::BOOLEAN))->setNotnull(true)->setDefault(false);
        }
        foreach ($snapshot['references'] as $column => $reference) {
            if (!$before->hasColumn($column)) {
                throw new \RuntimeException('Missing historical component owner: ' . $snapshot['table'] . '.' . $column);
            }
            $field = 'r.' . $quote($column);
            $valid = $reference['policy'] === 'empty'
                ? '(' . $field . ' IS NULL OR ' . $field . ' = 0 OR (' . $field . ' > 0 AND p.id IS NOT NULL))'
                : '(' . $field . ' IS NOT NULL AND ' . $field . ($reference['policy'] === 'root' ? ' >= 0' : ' > 0') . ' AND p.id IS NOT NULL)';
            self::audit(
                $connection,
                $snapshot['table'],
                $column,
                'NOT ' . $valid,
                ' LEFT JOIN ' . $quote($reference['target']) . ' p ON p.id = ' . $field,
                'r.'
            );
        }
        if (!$platform instanceof PostgreSQLPlatform
            && (Ledger::state($connection, BooleanDomains20261008::VERSION)['complete'] ?? false) === true) {
            // Use the already-frozen older declaration, not current entity
            // metadata or a second manually maintained boolean catalogue.
            $checks = \itsmng\Database\BooleanDomainSchema::checks($connection, $snapshot['table'])[$snapshot['table']] ?? [];
            $ansiQuotes = in_array('ANSI_QUOTES', explode(',', (string)$connection->fetchOne('SELECT @@SESSION.sql_mode')), true);
            foreach ($snapshot['booleans'] as $column) {
                $definition = BooleanDomains20261008::definitions()[$snapshot['table']][$column];
                $name = $definition['check'];
                if (!isset($checks[$name]) || $checks[$name]['enforced'] !== 'YES'
                    || !\itsmng\Database\BooleanCheckExpression::matches($checks[$name]['clause'], $column, $definition['nullable'], $ansiQuotes)) {
                    throw new \RuntimeException('Completed historical component boolean CHECK is missing, changed or unenforced: '
                        . $snapshot['table'] . '.' . $column . ' (' . $name . ')');
                }
            }
        }
        $coreComplete = (Ledger::state($connection, LegacyToOrm::VERSION)['complete'] ?? false) === true;
        $ownedReferences = $coreComplete ? $snapshot['references'] : [];
        foreach ($snapshot['targets'] as $target) {
            $column = $target['column'];
            if ($before->hasColumn($column)) {
                $actual = $before->getColumn($column);
                if (Type::lookupName($actual->getType()) !== Types::BIGINT || $actual->getNotnull() || $actual->getDefault() !== null
                    || $actual->getUnsigned() || $actual->getAutoincrement()) {
                    throw new \RuntimeException('Existing historical component subject column is incompatible: ' . $snapshot['table'] . '.' . $column);
                }
            }
            // Absent new FKs are enforced by the frozen staged producer. A
            // preexisting same-named FK is not validation evidence by itself.
            if ($before->hasForeignKey($target['constraint'])) {
                if (!$before->hasColumn($column)) {
                    throw new \RuntimeException('Existing historical component subject FK has no owning column: ' . $snapshot['table'] . '.' . $column);
                }
                $ownedReferences[$column] = $target;
            }
        }
        if ($ownedReferences !== []) {
            // Old adoption cannot be re-run to repair a damaged completed core
            // declaration. Refuse before this family writes any DDL or receipt.
            $native = [];
            if ($platform instanceof PostgreSQLPlatform) {
                foreach ($connection->fetchAllAssociative('SELECT c.conname, c.convalidated, c.condeferrable, c.confdeltype, c.confupdtype, r.relname AS foreign_table, '
                    . 'c.confrelid=to_regclass(quote_ident(r.relname)) AS visible_target FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class r ON r.oid=c.confrelid '
                    . 'WHERE c.conrelid=to_regclass(?) AND c.contype=?', [$table, 'f']) as $row) {
                    $native[$row['conname']] = $row;
                }
            } else {
                if ((int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
                    throw new \RuntimeException('Completed historical component FK enforcement is disabled: ' . $snapshot['table']);
                }
                // DBAL intentionally normalizes MySQL RESTRICT actions to null.
                // Inspect native actions instead of treating that null as proof.
                foreach ($connection->fetchAllAssociative('SELECT CONSTRAINT_NAME AS conname, DELETE_RULE AS delete_rule, UPDATE_RULE AS update_rule, '
                    . 'UNIQUE_CONSTRAINT_SCHEMA AS target_schema, REFERENCED_TABLE_NAME AS foreign_table, DATABASE() AS current_schema '
                    . 'FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?', [$snapshot['table']]) as $row) {
                    $native[$row['conname']] = $row;
                }
            }
            foreach ($ownedReferences as $column => $reference) {
                $actual = $before->getColumn($column);
                $name = $reference['constraint'];
                $role = isset($reference['policy']) ? 'Completed historical component' : 'Existing historical component subject';
                if ($actual->getNotnull() !== !$reference['nullable'] || !$before->hasForeignKey($name)) {
                    throw new \RuntimeException($role . ' owner shape is missing or changed: ' . $snapshot['table'] . '.' . $column . ' (' . $name . ')');
                }
                $foreign = $before->getForeignKey($name);
                if (array_map(static fn (string $value): string => trim($value, '`"'), $foreign->getLocalColumns()) !== [$column]
                    || trim($foreign->getForeignTableName(), '`"') !== $reference['target'] || $foreign->getForeignColumns() !== ['id']
                    || !in_array($foreign->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true)
                    || !in_array($foreign->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)
                    || !isset($native[$name]) || $native[$name]['foreign_table'] !== $reference['target']
                    || ($platform instanceof PostgreSQLPlatform
                        ? !in_array($native[$name]['visible_target'], [true, 1, '1', 't'], true)
                        : $native[$name]['target_schema'] !== $native[$name]['current_schema'])
                    || ($platform instanceof PostgreSQLPlatform
                        ? (!in_array($native[$name]['confdeltype'], ['r', 'a'], true) || !in_array($native[$name]['confupdtype'], ['r', 'a'], true))
                        : (!in_array($native[$name]['delete_rule'], ['RESTRICT', 'NO ACTION'], true) || !in_array($native[$name]['update_rule'], ['RESTRICT', 'NO ACTION'], true)))
                    || ($platform instanceof PostgreSQLPlatform && (!isset($native[$name])
                        || !in_array($native[$name]['convalidated'], [true, 1, '1', 't'], true)
                        || !in_array($native[$name]['condeferrable'], [false, 0, '0', 'f'], true)))) {
                    throw new \RuntimeException($role . ' FK is missing, changed or unvalidated: ' . $snapshot['table'] . '.' . $column . ' (' . $name . ')');
                }
            }
        }
        $changes = $manager->createComparator()->compareTables($before, $after);
        if (!$platform instanceof PostgreSQLPlatform) {
            return $platform->getAlterTableSQL($changes);
        }
        $sql = [];
        foreach ($changes->getChangedColumns() as $change) {
            $actual = $change->getOldColumn();
            $field = $quote($actual->getName());
            if (Type::lookupName($actual->getType()) === Types::BOOLEAN) {
                // The field already has native boolean values; never compare it to integer1.
                $sql[] = 'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $field . ' SET DEFAULT FALSE, ALTER COLUMN ' . $field . ' SET NOT NULL';
            } else {
                // These frozen snapshots do not own a legacy integer CHECK.
                // PostgreSQL cannot retain its integer expression after a
                // boolean cast. Refuse rather than silently rewrite a custom
                // constraint, even if its name resembles a core flag CHECK.
                $checks = $connection->fetchAllAssociative(
                    "SELECT c.conname, pg_get_constraintdef(c.oid) AS definition FROM pg_catalog.pg_constraint c "
                    . "JOIN pg_catalog.pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) "
                    . "WHERE c.conrelid=to_regclass(?) AND c.contype='c' AND a.attname=? ORDER BY c.conname",
                    [$table, $actual->getName()]
                );
                if ($checks !== []) {
                    throw new \RuntimeException('Historical component boolean conversion requires explicit CHECK adoption: '
                        . $snapshot['table'] . '.' . $actual->getName() . '; constraints: ' . json_encode($checks, JSON_THROW_ON_ERROR));
                }
                $sql[] = 'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $field . ' DROP DEFAULT, ALTER COLUMN ' . $field
                    . ' TYPE BOOLEAN USING (' . $field . ' = 1), ALTER COLUMN ' . $field . ' SET DEFAULT FALSE, ALTER COLUMN ' . $field . ' SET NOT NULL';
            }
        }
        return $sql;
    }

    /** Explicit historical empty selections converge without selecting target0. */
    public static function normalization(Connection $connection, array $snapshot): array
    {
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        $sql = [];
        foreach ($snapshot['references'] as $column => $reference) {
            if ($reference['policy'] === 'empty') {
                $sql[] = 'UPDATE ' . $quote($snapshot['table']) . ' SET ' . $quote($column) . ' = NULL WHERE ' . $quote($column) . ' = 0';
            }
        }
        return $sql;
    }

    private static function audit(Connection $connection, string $table, string $column, string $predicate, string $joins = '', string $alias = ''): void
    {
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        $from = $quote($table) . ($alias === '' ? '' : ' ' . rtrim($alias, '.')) . $joins;
        $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $predicate);
        if ($count === 0) {
            return;
        }
        $samples = $connection->fetchAllAssociative('SELECT ' . $alias . $quote('id') . ', ' . $alias . $quote($column)
            . ' FROM ' . $from . ' WHERE ' . $predicate . ' ORDER BY ' . $alias . $quote('id') . ' LIMIT 5');
        throw new \RuntimeException('Invalid historical component data: ' . $table . '.' . $column . ' (' . $count
            . ' rows); samples: ' . json_encode($samples, JSON_THROW_ON_ERROR));
    }
}
