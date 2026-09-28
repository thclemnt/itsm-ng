<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Integration contract. Run only with a dedicated, freshly installed test DB. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/run.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\BaselineSchema;
use itsmng\Database\ForeignKeys;
use itsmng\Database\LegacySql;
use itsmng\Database\BooleanColumns;

if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Refusing to use a database not named itsm_port_*.');
}
$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$connection = $DB->getDoctrineConnection();
$connection->setNestTransactionsWithSavepoints(true);
$platform = $connection->getDatabasePlatform();
$schema = (new BaselineSchema())->build($platform);
check(count($schema->getTables()) === 355, 'Baseline must include all 355 distinct tables.');
check(count($schema->getTable('glpi_profiles_users')->getForeignKeys()) === 3, 'Profile membership schema foreign keys.');
check((new ForeignKeys())->audit($connection) === [], 'Seeded database must not contain orphaned associations.');
check((new ForeignKeys())->plan($connection) === [], 'Installed foreign keys must be idempotent.');
check($DB->tableExists('glpi_entities'), 'Schema introspection.');
check($DB->fieldExists('glpi_users', 'name'), 'Column introspection.');
check($DB->listFields('glpi_users')['id']['Key'] === 'PRI', 'Primary-key metadata.');
check((int)$DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['id' => 0]])->count() === 1, 'Root entity id zero is real.');

$name = 'glpi_portability_contract';
check(!$DB->tableExists($name, false), 'Contract table must not already exist.');
$table = new Table($name);
$table->addColumn('id', 'integer', ['autoincrement' => true]);
$table->addColumn('name', 'string', ['length' => 255]);
$table->addColumn('flag', 'integer', ['default' => 0]);
$table->addColumn('optional', 'string', ['notnull' => false]);
$table->setPrimaryKey(['id']);
$connection->createSchemaManager()->createTable($table);
$DB->clearSchemaCache();
try {
    $values = ["O'Reilly", "two  spaces", "C:\\new\\test", "x'); DROP TABLE glpi_users; --", 'é € 日本語', "a\nb", 'GROUP_CONCAT(`name`) ? # --'];
    foreach ($values as $value) {
        check((bool)$DB->insert($name, ['name' => $DB->escape($value)]), 'Legacy insert.');
        $id = $DB->insertId();
        check(is_int($id) && $id > 0, 'Generated IDs must be native positive integers.');
        $row = $DB->request(['FROM' => $name, 'WHERE' => ['id' => $id]])->next();
        check($row['name'] === $value, 'Escaped values must round-trip byte-for-byte.');
        check($row['optional'] === null, 'NULL round-trip.');
    }
    $statement = $DB->prepare($DB->buildInsert($name, ['name' => new QueryParam(), 'flag' => new QueryParam()]));
    $value = "prepared ' ? \\ literal";
    $flag = 3;
    $statement->bind_param('si', $value, $flag);
    check($statement->execute(), 'Prepared statement execution.');
    $value = 'second prepared row';
    $flag = 1;
    check($statement->execute(), 'Bound variables must be read at execution time.');
    $value = 'boolean prepared flag';
    $flag = false;
    check($statement->execute(), 'Integer parameter coerces false to zero.');
    check($DB->request(['FROM' => $name, 'WHERE' => ['name' => $value]])->next()['flag'] === 0, 'Boolean integer binding round-trip.');
    $value = 'second prepared row';
    $statement->close();
    check(count($DB->request(['FROM' => $name, 'WHERE' => ['flag' => ['&', 2]]])) === 1, 'Bitmask criteria must produce a predicate.');
    check(count($DB->request(['FROM' => $name, 'WHERE' => ['name' => $value]])) === 1, 'Prepared binding by reference.');
    $rows = $DB->request(['FROM' => $name, 'ORDER' => 'id ASC', 'LIMIT' => 2, 'START' => 1]);
    check(count($rows) === 2 && count(iterator_to_array($rows)) === 2, 'Pagination and iteration.');
    check(count(iterator_to_array($rows)) === 2, 'Iterator rewind.');
    check((bool)$DB->update($name, ['flag' => 8], ['WHERE' => ['flag' => 0], 'ORDER' => 'id ASC', 'LIMIT' => 1]), 'Limited update.');
    check(count($DB->request(['FROM' => $name, 'WHERE' => ['flag' => 8]])) === 1, 'Limited update changes one row.');
    check((bool)$DB->delete($name, ['flag' => 8]), 'Portable delete.');
    check($DB->affectedRows() === 1, 'Affected rows.');
    try {
        $DB->buildDelete($name, []);
        throw new LogicException('Unrestricted delete was accepted.');
    } catch (RuntimeException $e) {
        check(true, 'Unrestricted delete prevented.');
    }

    // DBAL and legacy queries must observe and roll back the same transaction.
    $DB->beginTransaction();
    check($connection->isTransactionActive(), 'Legacy transaction visible to DBAL.');
    $DB->insert($name, ['name' => 'legacy transaction']);
    $connection->insert($name, ['name' => 'DBAL transaction']);
    check((int)$connection->fetchOne("SELECT COUNT(*) FROM $name WHERE name LIKE '%transaction'") === 2, 'Both APIs share one physical connection.');
    $DB->rollBack();
    check((int)$connection->fetchOne("SELECT COUNT(*) FROM $name WHERE name LIKE '%transaction'") === 0, 'Shared transaction rollback.');
    check(!$DB->inTransaction(), 'Rollback clears transaction state.');

    $DB->beginTransaction();
    $DB->insert($name, ['name' => 'committed']);
    check($DB->commit(), 'Commit.');
    check((int)$connection->fetchOne("SELECT COUNT(*) FROM $name WHERE name = 'committed'") === 1, 'Commit persists.');
    check($DB->getLock('portability-contract'), 'Advisory lock acquired.');
    check((bool)$DB->releaseLock('portability-contract'), 'Advisory lock released.');

    // Every audited relationship rejects an orphan, including raw SQL callers.
    foreach (ForeignKeys::RELATIONS as $child => $relations) {
        foreach ($relations as $column => $parent) {
            $connection->beginTransaction();
            try {
                $references = [];
                foreach ($relations as $reference => $target) {
                    if ($target === 'glpi_entities') {
                        $references[$reference] = 0;
                        continue;
                    }
                    $DB->insertOrDie($target, $DB->fieldExists($target, 'name') ? ['name' => 'Foreign key contract parent'] : ['comment' => 'Foreign key fixture']);
                    $references[$reference] = $DB->insertId();
                }
                $DB->insertOrDie($child, $references);
                $childId = $DB->insertId();
                $connection->update($child, [$column => 2147483647], ['id' => $childId]);
                throw new LogicException('Missing foreign-key enforcement: ' . $child . '.' . $column);
            } catch (ForeignKeyConstraintViolationException $e) {
                check(true, 'Orphan rejected for ' . $child . '.' . $column);
            } finally {
                $connection->rollBack();
            }
        }
    }

    // A referenced user cannot disappear without the application purge hooks.
    $connection->beginTransaction();
    try {
        $connection->delete('glpi_users', ['id' => 2]);
        throw new LogicException('Referenced user deletion was accepted.');
    } catch (\Doctrine\DBAL\Exception\DriverException $e) {
        check(in_array($e->getSQLState(), ['23503', '23001', '23000'], true), 'Parent deletion is restricted.');
    } finally {
        $connection->rollBack();
    }

    $expression = $DB->expressions()->dateAdd("'2024-02-28'", 1, 'DAY');
    check(str_starts_with((string)$connection->fetchOne('SELECT ' . $expression), '2024-02-29'), 'Portable leap-day arithmetic.');
    $result = $DB->query("SELECT 1 AS duplicate, 2 AS duplicate, CURRENT_TIMESTAMP AS moment");
    $row = $DB->fetchAssoc($result);
    check((int)$row['duplicate'] === 2 && preg_match('/^\d{4}-\d{2}-\d{2}/', $row['moment']) === 1, 'Duplicate result columns retain correct types.');
    if ($DB instanceof DBpgsql) {
        try {
            \itsmng\Database\Installer::installPostgres($DB, 'en_GB');
            throw new LogicException('PostgreSQL reinstallation overwrote an existing schema.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'empty schema'), 'Installer refuses a populated PostgreSQL schema.');
        }
        try {
            $DB->queryParams('SELECT $1::text', ["before\0after"]);
            throw new LogicException('NUL parameter was silently accepted.');
        } catch (InvalidArgumentException $error) {
            check(true, 'NUL parameter rejected before silent truncation.');
        }
        $DB->setTimezone('Europe/Paris');
        check($connection->fetchOne('SHOW TIMEZONE') === 'Europe/Paris', 'Session timezone.');
        check(LegacySql::postgres("SELECT 'GROUP_CONCAT(`x`)  ?' AS `value`", true) === "SELECT 'GROUP_CONCAT(`x`)  ?' AS \"value\"", 'SQL values must not be rewritten.');
        $columnTypes = $connection->fetchAllKeyValue("SELECT table_name || '.' || column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema()");
        foreach (BooleanColumns::TABLES as $tableName => $columns) {
            foreach ($columns as $column) {
                check(($columnTypes[$tableName . '.' . $column] ?? '') === 'boolean', 'Native boolean column: ' . $tableName . '.' . $column);
            }
        }
        foreach (['glpi_savedsearches.do_count', 'glpi_calendarsegments.day', 'glpi_itilfollowups.timeline_position'] as $column) {
            check($columnTypes[$column] === 'smallint', 'Enums remain integers: ' . $column);
        }
        $bools = $DB->fetchAssoc($DB->query('SELECT TRUE AS yes, FALSE AS no, NULL::boolean AS optional'));
        check($bools === ['yes' => 1, 'no' => 0, 'optional' => null], 'Legacy boolean result contract preserves NULL.');
        $connection->beginTransaction();
        ob_start();
        try {
            $DB->insert('glpi_useremails', ['users_id' => 2147483647, 'email' => 'orphan@example.invalid']);
        } finally {
            ob_end_clean();
        }
        check($DB->commit() === false, 'Failed PostgreSQL transactions must not report commit success.');
        $DB->rollBack();
    }

    // Existing installations can contain orphans. Audit must stop before DDL
    // without changing data, and a subsequent repaired run must be repeatable.
    $registry = new ForeignKeys();
    $constraint = ForeignKeys::name('glpi_useremails', 'users_id');
    $connection->executeStatement($platform->getDropForeignKeySQL($constraint, 'glpi_useremails'));
    $orphanEmail = 'portability-orphan-' . bin2hex(random_bytes(6)) . '@example.invalid';
    try {
        $connection->insert('glpi_useremails', ['users_id' => 2147483647, 'email' => $orphanEmail]);
        check($registry->audit($connection) === ['glpi_useremails.users_id' => 1], 'Audit identifies the orphaned relationship.');
        try {
            $registry->apply($connection);
            throw new LogicException('Foreign-key upgrade accepted orphaned data.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'orphaned references'), 'Orphan audit prevents DDL.');
        }
        check(count($registry->plan($connection)) === 1, 'Failed audit leaves the schema unchanged.');
        check((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_useremails WHERE email = ?', [$orphanEmail]) === 1, 'Failed audit preserves orphaned data for review.');
    } finally {
        $connection->delete('glpi_useremails', ['email' => $orphanEmail]);
        $registry->apply($connection);
    }
    check($registry->plan($connection) === [], 'Repaired foreign-key upgrade is idempotent.');
} finally {
    while ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    $connection->createSchemaManager()->dropTable($name);
    $DB->clearSchemaCache();
}
echo $DB->getProvider() . ': ' . $assertions . " portability assertions passed.\n";
