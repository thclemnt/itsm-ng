<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use itsmng\Database\MySQLGeneratedColumnInspection;
use itsmng\Database\OwnedMutationFrame;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php mysql-generated-column-inspection.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}

foreach (['VIRTUAL GENERATED', 'STORED GENERATED', 'STORED GENERATED INVISIBLE', 'VIRTUAL GENERATED INVISIBLE'] as $extra) {
    verify(MySQLGeneratedColumnInspection::isGeneratedExtra($extra), 'Native projection category recognized: ' . $extra);
}
foreach (['', 'auto_increment', 'DEFAULT_GENERATED', 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', 'INVISIBLE'] as $extra) {
    verify(!MySQLGeneratedColumnInspection::isGeneratedExtra($extra), 'A native default or ordinary attribute is not a projection: ' . $extra);
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
if ($DB->getProvider() === 'pgsql') {
    echo "pgsql: $assertions MySQL metadata-category controls passed; PostgreSQL migration queries retain their native is_generated policy.\n";
    exit(0);
}

$connection = $DB->getDoctrineConnection();
verify(!$connection->isTransactionActive(), 'Native metadata fixture requires its supplied idle writer');
$connection->assertManagedTransaction();
$schema = (string)$connection->fetchOne('SELECT DATABASE()');
$name = 'itsm_port_generated_' . bin2hex(random_bytes(5));
$quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
$table = $quote($schema) . '.' . $quote($name);
$catalog = static fn (): array => $connection->createSchemaManager()->listTableNames();
$ledger = static fn (): array => $connection->fetchAllAssociative('SELECT version, state FROM itsmng_migrations ORDER BY version');
$beforeCatalog = $catalog();
$beforeLedger = $ledger();
verify(!in_array($name, $beforeCatalog, true), 'New owned probe table must be absent');
$mode = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
$collation = (string)$connection->fetchOne('SELECT @@SESSION.collation_connection');
$created = false;
$frame = null;
$primary = null;
$cleanup = [];
try {
    $connection->executeStatement("CREATE TABLE $table (id INTEGER PRIMARY KEY, base_value INTEGER NOT NULL,"
        . ' stored_value INTEGER GENERATED ALWAYS AS (base_value + 1) STORED,'
        . ' virtual_value INTEGER GENERATED ALWAYS AS (base_value * 2) VIRTUAL,'
        . ' created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $created = true;
    $frame = OwnedMutationFrame::begin($connection);
    $connection->insert($table, ['id' => 1, 'base_value' => 11]);
    $before = $connection->fetchAllAssociative("SELECT * FROM $table ORDER BY id");
    $native = $connection->fetchAllAssociative(
        'SELECT COLUMN_NAME AS column_name, GENERATION_EXPRESSION AS generation_expression, EXTRA AS extra'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$schema, $name]
    );
    verify(array_column($native, 'column_name') === ['id', 'base_value', 'stored_value', 'virtual_value', 'created_at'], 'Actual native probe column order is complete');
    if (!$connection->getDatabasePlatform() instanceof MariaDBPlatform) {
        verify(str_contains($native[4]['extra'], 'DEFAULT_GENERATED'), 'Official MySQL exposes its actual native default-expression marker');
    }
    verify(!MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $name, 'id'), 'An ordinary assigned key is not generated');
    verify(!MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $name, 'base_value'), 'A real required scalar is not generated');
    verify(!MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $name, 'created_at'), 'An explicit native timestamp default is not a compatibility projection');
    verify(MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $name, 'stored_value'), 'Actual STORED native projection recognized');
    verify(MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $name, 'virtual_value'), 'Actual VIRTUAL native projection recognized');
    verify(!MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $name, 'missing_column'), 'A missing column retains the prior zero-count outcome');
    $absent = $name . '_absent';
    verify(!in_array($absent, $catalog(), true), 'Missing-table control is actually absent');
    verify(!MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $absent, 'stored_value'), 'A missing table cannot borrow another table projection');
    verify(MySQLGeneratedColumnInspection::listGeneratedColumns($connection, $schema, $absent) === [], 'Missing-table expression inventory stays empty');
    $expected = [];
    foreach ($native as $column) {
        if (in_array($column['column_name'], ['stored_value', 'virtual_value'], true)) {
            $expected[] = ['column_name' => $column['column_name'], 'generation_expression' => $column['generation_expression']];
        }
    }
    verify(MySQLGeneratedColumnInspection::listGeneratedColumns($connection, $schema, $name) === $expected, 'Generated expression inventory retains native ordinals and exact expressions, excluding the timestamp default');
    verify((int)$before[0]['stored_value'] === 12 && (int)$before[0]['virtual_value'] === 22 && $before[0]['created_at'] !== null, 'Actual projection and native default values are independently materialized');
    verify($connection->fetchAllAssociative("SELECT * FROM $table ORDER BY id") === $before, 'Repeated inspection does not modify any native row');
    verify($connection->fetchAllAssociative(
        'SELECT COLUMN_NAME AS column_name, GENERATION_EXPRESSION AS generation_expression, EXTRA AS extra'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$schema, $name]
    ) === $native, 'Repeated inspection does not rewrite native metadata');
    verify($ledger() === $beforeLedger, 'Native inspection leaves the complete migration ledger unchanged');
    $frame->rollBack();
    $frame = null;
    verify((int)$connection->fetchOne("SELECT COUNT(*) FROM $table") === 0, 'Owned DML rollback removes the probe row');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($frame !== null) {
        try {
            $frame->rollBack();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    $idle = false;
    try {
        $connection->assertManagedTransaction();
        verify(!$connection->isTransactionActive(), 'Cleanup retains the same idle managed writer');
        $idle = true;
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    if ($idle) {
        if ($created) {
            try {
                $connection->executeStatement("DROP TABLE $table");
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        foreach ([
            static function () use ($catalog, $beforeCatalog): void {
                verify($catalog() === $beforeCatalog, 'Only the newly owned native table is removed');
            },
            static function () use ($ledger, $beforeLedger): void {
                verify($ledger() === $beforeLedger, 'All native ledger rows are preserved');
            },
            static function () use ($connection, $mode, $collation): void {
                verify((string)$connection->fetchOne('SELECT @@SESSION.sql_mode') === $mode
                    && (string)$connection->fetchOne('SELECT @@SESSION.collation_connection') === $collation, 'Inspection preserves actual session modes and collation');
            },
        ] as $check) {
            try {
                $check();
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Secondary cleanup failure: ' . $error::class . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
if ($cleanup) {
    throw $cleanup[0];
}
echo $DB->getProvider() . ": $assertions native generated-column inspection assertions passed\n";
