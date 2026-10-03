<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\IncomingProjectionReferences;
use itsmng\Database\Migration\TypedItemMigration;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/incoming-projection-references.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}

final class ProjectionPlanningFixture extends TypedItemMigration
{
    public function __construct(private readonly array $scope)
    {
    }

    protected function tables(): array
    {
        return $this->scope;
    }

    protected static function targets(): array
    {
        return ['Computer' => 'computers'];
    }

    public static function checkSql(string $table): string
    {
        // Native fixture creation installs this declaration before planning.
        // the dynamic fixture always presents an existing owned CHECK.
        return 'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . static::constraintName($table)
            . " CHECK (itemtype = 'Computer' AND computers_id IS NOT NULL AND computers_id >= 1)";
    }

}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$connection = $DB->getDoctrineConnection();
verify(!$connection->isTransactionActive(), 'Native DDL fixture owns an idle connection');
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$schema = (string)$connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
$prefix = 'itsm_port_projection_' . bin2hex(random_bytes(5));
$external = $postgres ? $prefix . '_external' : (getenv('PORT_PROJECTION_REFERENCE_DB') ?: 'itsm_port_projection_references');
verify(str_starts_with($external, 'itsm_port_') && $external !== $schema, 'External reference namespace is a separate disposable fixture');
$tables = [$prefix . '_a', $prefix . '_b', $prefix . '_legacy', $prefix . '_generated'];
$created = [];
$owned = [];
$computers = [];
$primaryError = null;
$cleanupError = null;
$externalCreated = false;
$qualified = static fn (string $namespace, string $table): string => $platform->quoteIdentifier($namespace) . '.' . $platform->quoteIdentifier($table);
$foreignName = $prefix . '_incoming';
$projectionComment = "Frozen O'Reilly compatibility identity";
$createChild = static function (string $namespace, string $name, string $parent, string $column) use ($connection, $qualified, $platform, $schema, $foreignName, &$created, &$owned): string {
    $child = $qualified($namespace, $name);
    $connection->executeStatement('CREATE TABLE ' . $child . ' (id BIGINT NOT NULL PRIMARY KEY, parent_id BIGINT NULL, CONSTRAINT '
        . $platform->quoteIdentifier($foreignName) . ' FOREIGN KEY (parent_id) REFERENCES ' . $qualified($schema, $parent)
        . ' (' . $platform->quoteIdentifier($column) . '))');
    $created[] = $child;
    $owned[$child] = [$namespace, $name];
    return $child;
};
$refused = static function (ProjectionPlanningFixture $migration, ?IncomingProjectionReferences $snapshot = null) use ($connection): bool {
    try {
        $migration->plan($connection, $snapshot);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Incoming typed legacy item foreign key requires an explicit migration') {
            return true;
        }
        throw $error;
    }
    return false;
};

try {
    foreach ($tables as $index => $name) {
        verify(!$connection->createSchemaManager()->tablesExist([$name]), 'Fixture must not replace an existing table');
        $table = new Table($name);
        $table->addColumn('id', 'bigint');
        $table->setPrimaryKey(['id']);
        $table->addColumn('itemtype', 'string', ['length' => 100]);
        $table->addColumn('computers_id', 'bigint', ['notnull' => false]);
        $options = ['notnull' => false, 'comment' => $projectionComment];
        if ($index === 2) {
            $options['notnull'] = true;
            $options['default'] = 0;
        } elseif ($index === 3) {
            $options['columnDefinition'] = "BIGINT GENERATED ALWAYS AS (CASE itemtype WHEN 'Computer' THEN computers_id ELSE NULL END) STORED";
        }
        $table->addColumn('items_id', 'bigint', $options);
        $table->addUniqueIndex(['items_id'], $name . '_identity');
        foreach ($platform->getCreateTableSQL($table) as $position => $sql) {
            $connection->executeStatement($sql);
            if ($position === 0) {
                $created[] = $qualified($schema, $name);
                $owned[$qualified($schema, $name)] = [$schema, $name];
            }
        }
        $connection->executeStatement(ProjectionPlanningFixture::checkSql($name));
    }
    $migration = new ProjectionPlanningFixture([$tables[0], $tables[1]]);
    $baseline = $migration->plan($connection);
    verify(array_keys($baseline) === array_slice($tables, 0, 2), 'Both frozen table scopes retain their order');
    verify($migration->plan($connection, new IncomingProjectionReferences($connection)) === $baseline, 'Shared native capture preserves every ordered SQL operation');
    verify($baseline[$tables[0]]['key_sql'] !== [] && $baseline[$tables[1]]['key_sql'] !== [], 'Legacy compatibility projections genuinely require replacement');

    if ($postgres) {
        $connection->executeStatement('CREATE SCHEMA ' . $platform->quoteIdentifier($external));
        $externalCreated = true;
    } else {
        verify((bool)$connection->fetchOne('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [$external]), 'Provision the external disposable database before running the contract');
    }
    $outside = $createChild($external, $prefix . '_outside', $tables[0], 'items_id');
    $snapshot = new IncomingProjectionReferences($connection);
    verify($snapshot->has($schema, $tables[0]) && !$snapshot->has($external, $tables[0]), 'Native relation identity retains target schema and external referencing schema');
    verify($refused($migration, $snapshot), 'Cross-schema incoming projection FK remains a hard migration refusal');
    verify($refused($migration), 'The same long-lived migration sees an externally added FK without a retained cache');
    $connection->executeStatement('DROP TABLE ' . $outside);
    array_pop($created);
    verify($migration->plan($connection) === $baseline, 'The same long-lived migration sees FK removal and retains exact SQL');

    $normal = $createChild($schema, $prefix . '_normal', $tables[0], 'id');
    verify($migration->plan($connection) === $baseline, 'Incoming reference to an unrelated column does not forbid projection replacement');
    if ($postgres) {
        $connection->executeStatement('ALTER TABLE ' . $qualified($schema, $tables[0]) . ' ADD CONSTRAINT '
            . $platform->quoteIdentifier($foreignName) . ' CHECK (items_id IS NULL OR items_id >= 0)');
        verify($migration->plan($connection) === $baseline, 'A CHECK and FK sharing a PostgreSQL constraint name cannot invent an incoming projection reference');
    }
    $outside = $createChild($external, $prefix . '_outside', $tables[1], 'items_id');
    $snapshot = new IncomingProjectionReferences($connection);
    verify(!$snapshot->has($schema, $tables[0]) && $snapshot->has($schema, $tables[1]), 'One capture distinguishes multiple targets and column roles');
    verify($refused($migration, $snapshot), 'Incoming projection FK on the second table is not missed');
    $connection->executeStatement('DROP TABLE ' . $outside);
    array_pop($created);
    verify($migration->plan($connection) === $baseline, 'Removing a second-table FK refreshes standalone planning');

    $generatedMigration = new ProjectionPlanningFixture([$tables[3]]);
    $generatedBefore = $generatedMigration->plan($connection);
    verify($generatedBefore[$tables[3]]['key_sql'] === [], 'An installed generated projection does not require replacement');
    $outside = $createChild($external, $prefix . '_generated_child', $tables[3], 'items_id');
    verify($generatedMigration->plan($connection) === $generatedBefore, 'An incoming FK does not forbid a projection that will not be rebuilt');

    // A wrong existing canonical FK must still fail before any DDL; no adoption
    // SQL is executed by this contract.
    $wrong = 'fk_' . substr($tables[0], 5) . '_computers_id';
    $connection->executeStatement('ALTER TABLE ' . $qualified($schema, $tables[0]) . ' ADD CONSTRAINT ' . $platform->quoteIdentifier($wrong)
        . ' FOREIGN KEY (computers_id) REFERENCES ' . $qualified($schema, 'glpi_monitors') . ' (id)');
    $wrongRefused = false;
    try {
        $migration->plan($connection);
    } catch (RuntimeException $error) {
        $wrongRefused = $error->getMessage() === 'Existing typed item reference FK has a different definition: ' . $wrong;
    }
    verify($wrongRefused, 'Existing wrong canonical targets retain their explicit diagnostic');

    $fixtures = new FixtureRecords($DB);
    foreach (['first', 'second'] as $label) {
        $computers[] = $fixtures->create('glpi_computers', ['name' => $prefix . ' ' . $label, 'entities_id' => 0]);
    }
    $generatedColumn = static fn (string $name): bool => (bool)$connection->fetchOne($postgres
        ? "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = 'items_id' AND is_generated = 'ALWAYS'"
        : "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = 'items_id' AND extra LIKE '%GENERATED%'", [$schema, $name]);
    $indexDefinitions = static function (string $name) use ($connection): array {
        $definitions = [];
        foreach ($connection->createSchemaManager()->listTableIndexes($name) as $index) {
            $definitions[$index->getName()] = ['columns' => $index->getColumns(), 'unique' => $index->isUnique(), 'primary' => $index->isPrimary(), 'flags' => $index->getFlags(), 'options' => $index->getOptions()];
        }
        ksort($definitions);
        return $definitions;
    };
    foreach ([$tables[1], $tables[2]] as $name) {
        $column = $connection->createSchemaManager()->listTableColumns($name)['items_id'];
        $indexesBefore = $indexDefinitions($name);
        verify($column->getComment() === $projectionComment, 'The ordinary compatibility column has an actual escaped native comment');
        verify($connection->fetchOne('SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?', [$schema, $name, 'items_id']) === 'bigint'
            && !$generatedColumn($name), 'The challenged native column is an ordinary BIGINT: ' . $name);
        verify(
            $name === $tables[1] ? !$column->getNotnull() && $column->getDefault() === null : $column->getNotnull() && (string)$column->getDefault() === '0',
            'Both same-shaped nullable and frozen NOT NULL DEFAULT 0 legacy states are exercised'
        );
        $connection->insert($name, ['id' => 1, 'itemtype' => 'Computer', 'computers_id' => $computers[0], 'items_id' => $computers[0]]);
        $subjectMigration = new ProjectionPlanningFixture([$name]);
        $entry = $subjectMigration->plan($connection)[$name];
        verify($entry['copy_legacy'] && $entry['key_sql'] !== [], 'Every ordinary BIGINT receives an actual generated projection installation');
        foreach ($entry['key_sql'] as $statement) {
            $connection->executeStatement($statement);
        }
        verify($generatedColumn($name), 'Native metadata confirms the planned projection is installed');
        verify($connection->createSchemaManager()->listTableColumns($name)['items_id']->getComment() === $projectionComment, 'Projection installation preserves the exact native column comment');
        verify($indexDefinitions($name) === $indexesBefore, 'Projection installation preserves every native index definition');
        $row = $connection->fetchAssociative('SELECT itemtype, computers_id, items_id FROM ' . $name . ' WHERE id = 1');
        verify($row['itemtype'] === 'Computer' && (int)$row['computers_id'] === $computers[0] && (int)$row['items_id'] === $computers[0], 'Projection installation preserves the populated subject link');
        $replanned = $subjectMigration->plan($connection)[$name];
        verify(!$replanned['copy_legacy'] && $replanned['key_sql'] === [], 'A native installed projection is not copied or rebuilt on retry');
        verify((new ProjectionPlanningFixture([$name]))->plan($connection) === $subjectMigration->plan($connection), 'A fresh planner and the same long-lived planner agree after native DDL');
        $connection->update($name, ['computers_id' => $computers[1]], ['id' => 1]);
        verify((int)$connection->fetchOne('SELECT items_id FROM ' . $name . ' WHERE id = 1') === $computers[1], 'Changing the canonical owner actually computes the compatibility projection');
        $writeRefused = false;
        try {
            $connection->update($name, ['items_id' => $computers[0]], ['id' => 1]);
        } catch (\Doctrine\DBAL\Exception) {
            $writeRefused = true;
        }
        verify($writeRefused && (int)$connection->fetchOne('SELECT items_id FROM ' . $name . ' WHERE id = 1') === $computers[1], 'The installed projection rejects direct writes without changing its computed value');
        $duplicateRefused = false;
        try {
            $connection->insert($name, ['id' => 2, 'itemtype' => 'Computer', 'computers_id' => $computers[1]]);
        } catch (\Doctrine\DBAL\Exception) {
            $duplicateRefused = true;
        }
        verify($duplicateRefused && (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $name) === 1, 'The original unique projection index remains effective');
    }
} catch (Throwable $error) {
    $primaryError = $error;
} finally {
    foreach (array_reverse($created) as $table) {
        try {
            $connection->executeStatement('DROP TABLE ' . $table);
        } catch (Throwable $error) {
            $cleanupError ??= $error;
            fwrite(STDERR, 'Fixture cleanup failed for ' . $table . ': ' . (string)$error . "\n");
        }
    }
    foreach (array_reverse($computers) as $id) {
        try {
            $connection->delete('glpi_computers', ['id' => $id]);
        } catch (Throwable $error) {
            $cleanupError ??= $error;
            fwrite(STDERR, 'Owned Computer fixture cleanup failed: ' . (string)$error . "\n");
        }
    }
    if ($externalCreated) {
        try {
            $connection->executeStatement('DROP SCHEMA ' . $platform->quoteIdentifier($external));
        } catch (Throwable $error) {
            $cleanupError ??= $error;
            fwrite(STDERR, 'Fixture schema cleanup failed: ' . (string)$error . "\n");
        }
    }
}
if ($primaryError !== null) {
    throw $primaryError;
}
if ($cleanupError !== null) {
    throw $cleanupError;
}
foreach ($owned as [$namespace, $name]) {
    verify(
        !(bool)$connection->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$namespace, $name]),
        'Every individually owned fixture table is removed: ' . $namespace . '.' . $name
    );
}
foreach ($computers as $id) {
    verify(!(bool)$connection->fetchOne('SELECT COUNT(*) FROM glpi_computers WHERE id = ?', [$id]), 'Every owned Computer fixture is removed');
}
echo $DB->getProvider() . ": $assertions incoming projection planning assertions passed.\n";
