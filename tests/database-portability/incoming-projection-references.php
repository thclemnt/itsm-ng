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
$tables = [$prefix . '_a', $prefix . '_b', $prefix . '_generated'];
$created = [];
$externalCreated = false;
$qualified = static fn (string $namespace, string $table): string => $platform->quoteIdentifier($namespace) . '.' . $platform->quoteIdentifier($table);
$foreignName = $prefix . '_incoming';
$createChild = static function (string $namespace, string $name, string $parent, string $column) use ($connection, $qualified, $platform, $schema, $foreignName, &$created): string {
    $child = $qualified($namespace, $name);
    $connection->executeStatement('CREATE TABLE ' . $child . ' (id BIGINT NOT NULL PRIMARY KEY, parent_id BIGINT NULL, CONSTRAINT '
        . $platform->quoteIdentifier($foreignName) . ' FOREIGN KEY (parent_id) REFERENCES ' . $qualified($schema, $parent)
        . ' (' . $platform->quoteIdentifier($column) . '))');
    $created[] = $child;
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
        $options = ['notnull' => false];
        if ($index === 2) {
            $options['columnDefinition'] = "BIGINT GENERATED ALWAYS AS (CASE itemtype WHEN 'Computer' THEN computers_id ELSE NULL END) STORED";
        }
        $table->addColumn('items_id', 'bigint', $options);
        $table->addUniqueIndex(['items_id'], $name . '_identity');
        foreach ($platform->getCreateTableSQL($table) as $position => $sql) {
            $connection->executeStatement($sql);
            if ($position === 0) {
                $created[] = $qualified($schema, $name);
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

    $generatedMigration = new ProjectionPlanningFixture([$tables[2]]);
    $generatedBefore = $generatedMigration->plan($connection);
    verify($generatedBefore[$tables[2]]['key_sql'] === [], 'An installed generated projection does not require replacement');
    $outside = $createChild($external, $prefix . '_generated_child', $tables[2], 'items_id');
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
} finally {
    foreach (array_reverse($created) as $table) {
        $connection->executeStatement('DROP TABLE ' . $table);
    }
    if ($externalCreated) {
        $connection->executeStatement('DROP SCHEMA ' . $platform->quoteIdentifier($external));
    }
}
verify(!$connection->createSchemaManager()->tablesExist($tables), 'All owned fixture tables are removed');
echo $DB->getProvider() . ": $assertions incoming projection planning assertions passed.\n";
