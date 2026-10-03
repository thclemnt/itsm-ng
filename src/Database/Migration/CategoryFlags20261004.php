<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\BooleanCheckExpression;

/** Frozen category flags; current entity metadata cannot rewrite this upgrade. */
final class CategoryFlags20261004
{
    public const VERSION = '20261004_category_boolean_flags';

    private const COLUMNS = ['is_incident', 'is_request', 'is_problem'];

    public function plan(Connection $connection): array
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return [];
        }
        $platform = $connection->getDatabasePlatform();
        $postgres = $platform instanceof PostgreSQLPlatform;
        $manager = $connection->createSchemaManager();
        $table = $manager->introspectTable('glpi_itilcategories');
        $columns = $manager->listTableColumns('glpi_itilcategories');
        $sql = [];
        $checks = [];
        $ansiQuotes = false;
        if (!$postgres) {
            // One bounded native snapshot for the historical table. MariaDB
            // formats clauses in the current mode and allows duplicate names
            // on different tables, so both interpretation and owner matter.
            $ansiQuotes = in_array('ANSI_QUOTES', explode(',', (string)$connection->fetchOne('SELECT @@SESSION.sql_mode')), true);
            $enforced = $platform instanceof MySQLPlatform ? 'tc.ENFORCED' : "'YES'";
            $query = 'SELECT tc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause, ' . $enforced . ' AS enforced '
                . 'FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc '
                . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ($platform instanceof MySQLPlatform ? '' : 'AND cc.TABLE_NAME = tc.TABLE_NAME ')
                . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'glpi_itilcategories' AND tc.CONSTRAINT_TYPE = 'CHECK'";
            foreach ($connection->fetchAllAssociative($query) as $check) {
                $checks[$check['name']] = $check;
            }
        }
        foreach (self::COLUMNS as $name) {
            $column = $columns[$name] ?? throw new \RuntimeException('Missing category flag: glpi_itilcategories.' . $name);
            $type = Type::lookupName($column->getType());
            if (!in_array($type, [Types::BOOLEAN, Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
                throw new \RuntimeException('Unsupported category flag type: glpi_itilcategories.' . $name . ' (' . $type . ')');
            }
            $field = $platform->quoteIdentifier($name);
            $invalid = $field . ' IS NULL' . ($postgres && $type === Types::BOOLEAN ? '' : ' OR ' . $field . ' NOT IN (0, 1)');
            $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_itilcategories WHERE ' . $invalid);
            if ($count > 0) {
                $samples = $connection->fetchAllAssociative('SELECT id, ' . $field . ' FROM glpi_itilcategories WHERE ' . $invalid . ' ORDER BY id LIMIT 5');
                throw new \RuntimeException('Invalid category flag: glpi_itilcategories.' . $name . ' (' . $count . ' rows); samples: ' . json_encode($samples, JSON_THROW_ON_ERROR));
            }
            $default = $column->getDefault();
            if (!in_array($default, [null, 0, 1, '0', '1', false, true], true)) {
                throw new \RuntimeException('Invalid category flag default: glpi_itilcategories.' . $name);
            }
            if ($postgres) {
                if ($type !== Types::BOOLEAN) {
                    $sql[] = 'ALTER TABLE glpi_itilcategories ALTER COLUMN ' . $field . ' DROP DEFAULT, ALTER COLUMN ' . $field
                        . ' TYPE BOOLEAN USING (' . $field . ' = 1), ALTER COLUMN ' . $field . ' SET DEFAULT TRUE, ALTER COLUMN ' . $field . ' SET NOT NULL';
                } elseif (!$column->getNotnull() || !(bool)(int)$default) {
                    $sql[] = 'ALTER TABLE glpi_itilcategories ALTER COLUMN ' . $field . ' SET DEFAULT TRUE, ALTER COLUMN ' . $field . ' SET NOT NULL';
                }
            } else {
                if ($type !== Types::INTEGER || $column->getUnsigned() || !$column->getNotnull() || !(bool)(int)$default) {
                    $before = clone $table;
                    $after = clone $before;
                    $after->getColumn($name)->setType(Type::getType(Types::INTEGER))->setUnsigned(false)->setNotnull(true)->setDefault(1);
                    $sql = [...$sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after))];
                    $table = $after;
                }
                // Retain historical integer storage while enforcing the flag's
                // domain. A retry inspects each constraint after committed DDL.
                $constraint = 'glpi_itilcategories_' . $name . '_boolean';
                if (isset($checks[$constraint])) {
                    $check = $checks[$constraint];
                    if (!BooleanCheckExpression::matches($check['clause'], $name, false, $ansiQuotes)) {
                        throw new \RuntimeException('Conflicting category flag constraint: ' . $constraint);
                    }
                    if ($platform instanceof MySQLPlatform) {
                        if ($check['enforced'] === 'NO') {
                            $sql[] = 'ALTER TABLE glpi_itilcategories ALTER CHECK ' . $platform->quoteIdentifier($constraint) . ' ENFORCED';
                        } elseif ($check['enforced'] !== 'YES') {
                            throw new \RuntimeException('Cannot establish category flag enforcement: ' . $constraint);
                        }
                    }
                } else {
                    $sql[] = 'ALTER TABLE glpi_itilcategories ADD CONSTRAINT ' . $platform->quoteIdentifier($constraint) . ' CHECK (' . $field . ' IS NOT NULL AND ' . $field . ' IN (0, 1))';
                }
            }
        }
        return $sql;
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return;
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL category flag migration must run outside an application transaction.');
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
