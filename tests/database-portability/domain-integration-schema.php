<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\DomainIntegration20261006;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-integration-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable core required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
verify((new SchemaCheck())->differences($connection) === [], 'Current core converges with property-declared Domain fields');
verify(Ledger::state($connection, DomainIntegration20261006::VERSION)['complete'], 'Canonical installation/adoption replays Domain stage');
verify(EntityRegistry::relations()['glpi_domains']['suppliers_id'] === 'glpi_suppliers'
    && EntityRegistry::isBoolean('glpi_domains', 'is_helpdesk_visible'), 'Supplier ownership and flag belong to Domain properties');
$reject = static function (callable $operation, string $message, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $rejected = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $rejected = in_array($error->getSQLState(), ['23502', '23503', '23514', '22003', '22023', '22P02', '42804', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($rejected, $message);
    } finally {
        $connection->rollBack();
    }
};
$reject(static fn () => $connection->insert('glpi_domains', ['name' => 'Invalid direct vendor', 'suppliers_id' => 9223372036854770000]), 'Native writes reject missing commercial vendor');
$reject(static fn () => $connection->executeStatement('INSERT INTO glpi_domains (name, is_helpdesk_visible) VALUES (?, 2)', ['Invalid visibility']), 'Native writes reject enum values for real flag', 'glpi_domains_is_helpdesk_visible_boolean');

// A small isolated historical fixture tests nontransactional DDL without
// rebuilding or altering the installed core used by the full suite.
$database = getenv('PORT_DOMAIN_SCHEMA_DB') ?: $DB->dbdefault . '_domain_schema';
verify(str_starts_with($database, 'itsm_port_') && $database !== $DB->dbdefault, 'Separate historical fixture required');
$quote = $platform->quoteIdentifier(...);
$existing = $connection->fetchOne($postgres ? 'SELECT 1 FROM pg_database WHERE datname = ?' : 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]);
verify($existing === false, 'Historical fixture name must be unused: ' . $database);
$connection->executeStatement('CREATE DATABASE ' . $quote($database));
$adapter = DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $database);
verify($adapter->connected, 'Historical fixture connection');
$fixture = $adapter->getDoctrineConnection();
try {
    $manager = $fixture->createSchemaManager();
    $historical = (new Baseline20261001())->build($platform);
    foreach (['glpi_domains', 'glpi_suppliers', 'glpi_profiles', 'glpi_profilerights'] as $name) {
        $table = clone $historical->getTable($name);
        $table->getColumn('id')->setType(Type::getType(Types::BIGINT));
        if ($name === 'glpi_domains') {
            $table->addColumn('suppliers_id', Types::INTEGER, ['default' => 0, 'comment' => 'Direct vendor role']);
            $table->addColumn('is_helpdesk_visible', Types::INTEGER, ['default' => 1, 'comment' => 'Legacy visibility flag']);
        }
        $manager->createTable($table);
    }
    $fixture->insert('glpi_suppliers', ['id' => 4294971001, 'name' => 'Commercial']);
    $fixture->insert('glpi_profiles', ['id' => 1, 'name' => 'Existing dropdown user']);
    $fixture->insert('glpi_profiles', ['id' => 2, 'name' => 'Existing dedicated policy']);
    $fixture->insert('glpi_profilerights', ['profiles_id' => 1, 'name' => 'dropdown', 'rights' => 31]);
    $fixture->insert('glpi_profilerights', ['profiles_id' => 2, 'name' => 'dropdown', 'rights' => 31]);
    $fixture->insert('glpi_profilerights', ['profiles_id' => 2, 'name' => 'domaintype', 'rights' => 1]);
    $fixture->insert('glpi_domains', ['id' => 91, 'name' => 'Keep name and history', 'suppliers_id' => 0, 'is_helpdesk_visible' => 2]);
    $migration = new DomainIntegration20261006();
    $rejectPlan = static function (string $message) use ($fixture, $migration): void {
        $failed = false;
        try {
            $migration->plan($fixture);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), $message);
        }
        verify($failed, 'Historical preflight identifies ' . $message);
        verify(!Ledger::assertTransactional($fixture), 'Invalid preflight creates no ledger');
    };
    $rejectPlan('Invalid Domain helpdesk flags');
    $fixture->update('glpi_domains', ['is_helpdesk_visible' => 0, 'suppliers_id' => -1], ['id' => 91]);
    $rejectPlan('Invalid direct Domain suppliers');
    $fixture->update('glpi_domains', ['suppliers_id' => 0], ['id' => 91]);
    if (!$postgres) {
        foreach (['glpi_domains', 'glpi_profilerights'] as $name) {
            $beforeRows = $fixture->fetchAllAssociative('SELECT * FROM ' . $quote($name) . ' ORDER BY id');
            $fixture->executeStatement('ALTER TABLE ' . $quote($name) . ' ENGINE=MyISAM');
            try {
                $rejectPlan('requires transactional InnoDB ' . $name);
                verify($fixture->fetchAllAssociative('SELECT * FROM ' . $quote($name) . ' ORDER BY id') === $beforeRows, 'Nontransactional storage refuses before normalization, grant copies or schema adoption');
            } finally {
                $fixture->executeStatement('ALTER TABLE ' . $quote($name) . ' ENGINE=InnoDB');
            }
        }
    }
    $before = $manager->introspectTable('glpi_domains');
    $migration->plan($fixture);
    verify($manager->createComparator()->compareTables($before, $manager->introspectTable('glpi_domains'))->isEmpty(), 'Valid preview remains read-only');
    if (!$postgres) {
        $fixture->executeStatement('ALTER TABLE glpi_domains ADD CONSTRAINT glpi_domains_is_helpdesk_visible_boolean CHECK (1 = 1)');
    }
    $interrupted = false;
    try {
        $migration->apply($fixture, static function (string $sql) use ($postgres): void {
            if ($postgres || str_contains($sql, 'DROP CONSTRAINT') || str_contains($sql, 'DROP CHECK')) {
                throw new RuntimeException('Intentional stage interruption');
            }
        });
    } catch (RuntimeException $error) {
        $interrupted = $error->getMessage() === 'Intentional stage interruption';
    }
    verify($interrupted, 'Interrupt the stage after real DDL');
    verify((Ledger::state($fixture, DomainIntegration20261006::VERSION)['complete'] ?? false) !== true, 'Interrupted stage is not complete');
    $migration->apply($fixture);
    $table = $manager->introspectTable('glpi_domains');
    verify(Type::lookupName($table->getColumn('suppliers_id')->getType()) === Types::BIGINT
        && !$table->getColumn('suppliers_id')->getNotnull()
        && $table->getColumn('suppliers_id')->getComment() === 'Direct vendor role', 'Retry widens supplier and preserves comments/nullability');
    verify($table->getColumn('is_helpdesk_visible')->getComment() === 'Legacy visibility flag', 'Integer flag conversion preserves comment');
    $row = $fixture->fetchAssociative('SELECT name, suppliers_id, is_helpdesk_visible FROM glpi_domains WHERE id = 91');
    verify($row['name'] === 'Keep name and history' && $row['suppliers_id'] === null && !(bool)$row['is_helpdesk_visible'], 'Legacy zero selection normalizes while false/content survive');
    verify((int)$fixture->fetchOne("SELECT rights FROM glpi_profilerights WHERE profiles_id = 1 AND name = 'domaintype'") === 31
        && (int)$fixture->fetchOne("SELECT rights FROM glpi_profilerights WHERE profiles_id = 2 AND name = 'domaintype'") === 1
        && (int)$fixture->fetchOne("SELECT rights FROM glpi_profilerights WHERE profiles_id = 2 AND name = 'dropdown'") === 31, 'New dedicated grant inherits old global grant without replacing distinct policy');
    $fixture->beginTransaction();
    try {
        $rejected = false;
        try {
            $fixture->executeStatement('UPDATE glpi_domains SET is_helpdesk_visible = 2 WHERE id = 91');
        } catch (DriverException $error) {
            $rejected = in_array($error->getSQLState(), ['23514', '22003', '22023', '22P02', '42804', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, 'glpi_domains_is_helpdesk_visible_boolean');
        }
        verify($rejected, 'Retry replaces permissive CHECK with actual native enforcement');
    } finally {
        $fixture->rollBack();
    }
    $fixture->update('glpi_domains', ['suppliers_id' => 4294971001], ['id' => 91]);
    verify((int)$fixture->fetchOne('SELECT suppliers_id FROM glpi_domains WHERE id = 91') === 4294971001, 'Supplier ownership retains wide identifiers');
    $migration->apply($fixture);
    verify((int)$fixture->fetchOne('SELECT suppliers_id FROM glpi_domains WHERE id = 91') === 4294971001 && $migration->plan($fixture) === [], 'Completed retry never resets user modifications');
} finally {
    $adapter->close();
    $connection->executeStatement('DROP DATABASE ' . $quote($database));
}
echo $DB->getProvider() . ": Domain property schema, frozen rights, populated partial upgrade, invalid-data preflight, real DDL interruption and native constraints passed.\n";
