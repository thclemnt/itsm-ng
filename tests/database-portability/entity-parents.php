<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\MappedReads;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\V220\EntityParents;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/entity-parents.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
$migration = new EntityParents();
$migration->apply($connection);
verify($migration->plan($connection) === ['sql' => [], 'constraint_sql' => [], 'root_rows' => 0], 'Upgrade is idempotent');
$connection->beginTransaction();
try {
    $em = Orm::create($DB);
    $root = $em->find(EntityRecord::class, 0);
    verify($root->parent === null, 'Root has a NULL association');
    $model = new Entity();
    verify($model->getFromDB(0) && $model->fields['entities_id'] === -1, 'Public model retains the root sentinel');
    verify(array_column(MappedReads::matching($DB, 'glpi_entities', ['entities_id' => -1]), 'id') === [0], 'Legacy root criteria select NULL through the mapped parent');
    $rows = (new RecordRepository($em))->matching('glpi_entities', ['entities_id' => null], legacyValues: false);
    verify(array_column($rows, 'id') === [0], 'Canonical root criteria use NULL');
    $create = (new FixtureRecords($DB))->create(...);
    $parent = $create('glpi_entities', ['name' => 'Mapped parent', 'entities_id' => 0]);
    $child = $create('glpi_entities', ['name' => 'Mapped child', 'entities_id' => $parent]);
    verify(Orm::create($DB)->find(EntityRecord::class, $parent)->parent->id === 0, 'Child parent zero is the real root');
    verify((new Entity())->update(['id' => 0, 'entities_id' => -1]), 'Public root update remains valid');
    verify($connection->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 0') === null, 'Root update stores NULL');
    foreach ([[$parent, null], [$parent, -1], [$parent, $parent], [0, $parent], [$child, 2147483647]] as [$id, $value]) {
        try {
            $connection->transactional(fn () => $connection->update('glpi_entities', ['entities_id' => $value], ['id' => $id]));
            throw new LogicException('Invalid parent unexpectedly accepted');
        } catch (DriverException $error) {
            verify(str_contains($error->getMessage(), 'glpi_entities_parent_root') || str_contains($error->getMessage(), 'fk_entities_entities_id'), 'Invalid parent rejected by the declared constraint');
        }
    }
    (new MappedStorage($DB))->update('glpi_entities', $child, ['entities_id' => 0]);
    verify(Orm::create($DB)->find(EntityRecord::class, $child)->parent->id === 0, 'Reparenting preserves zero as a target');
} finally {
    $connection->rollBack();
}

// Reconstruct old schemas separately: failure cannot damage the shared fixture.
$postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
$name = 'itsm_port_entity_parents_' . getmypid() . '_' . bin2hex(random_bytes(4));
$quote = $connection->quoteIdentifier($name);
$fixture = null;
$connection->executeStatement(($postgres ? 'CREATE SCHEMA ' : 'CREATE DATABASE ') . $quote);
try {
    $params = $connection->getParams();
    $params['driver'] = $postgres ? 'pdo_pgsql' : 'pdo_mysql';
    if ($postgres) {
        $host = $DB->dbhost;
        $port = $DB->dbport;
        if (preg_match('/^(.+):(\d+)$/', $host, $endpoint)) {
            $host = trim($endpoint[1], '[]');
            $port = (int)$endpoint[2];
        }
        $params += ['host' => $host, 'port' => $port, 'user' => $DB->dbuser, 'password' => rawurldecode($DB->dbpassword)];
    }
    if (!$postgres) {
        $params['dbname'] = $name;
    }
    $fixture = $postgres ? DriverManager::getConnection($params)
        : \itsmng\Database\MySQLConnection::create($params, $connection->getConfiguration());
    if (!$postgres) {
        verify($fixture !== $connection && $fixture instanceof \itsmng\Database\MySQLManagedConnection
            && $fixture->getNativeConnection() !== $connection->getNativeConnection()
            && $fixture->fetchOne('SELECT DATABASE()') === $name, 'Separate historical fixture retains its actual canonical MySQL writer and exclusively owned database');
    }
    if ($postgres) {
        $fixture->executeStatement('SET search_path TO ' . $quote);
    }
    $fixture->executeStatement('CREATE TABLE glpi_entities (id INTEGER PRIMARY KEY, entities_id INTEGER NOT NULL DEFAULT 0)');
    $fixture->executeStatement('INSERT INTO glpi_entities (id, entities_id) VALUES (0, -1), (1, 0), (2, 1)');
    foreach ([[0, 2], [1, -7], [1, 99], [1, 1], [1, 2]] as [$id, $value]) {
        $original = $fixture->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = ?', [$id]);
        $fixture->update('glpi_entities', ['entities_id' => $value], ['id' => $id]);
        try {
            $migration->apply($fixture);
            throw new LogicException('Invalid legacy hierarchy unexpectedly migrated');
        } catch (RuntimeException $error) {
            verify(!$error instanceof LogicException, 'Preflight rejects invalid hierarchy');
            verify($fixture->createSchemaManager()->introspectTable('glpi_entities')->getColumn('entities_id')->getNotnull(), 'Preflight leaves the schema unchanged');
        } finally {
            $fixture->update('glpi_entities', ['entities_id' => $original], ['id' => $id]);
        }
    }
    $plan = $migration->plan($fixture);
    verify($plan['root_rows'] === 1 && count($plan['constraint_sql']) === 2 && (int)$fixture->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 0') === -1, 'Dry run plans normalization without changing values');
    if (!$postgres) {
        $fixture->beginTransaction();
        try {
            $migration->apply($fixture);
            throw new LogicException('MySQL DDL ran in an application transaction');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'outside an application transaction'), 'MySQL DDL transaction guard');
        } finally {
            $fixture->rollBack();
        }
    }
    foreach ($plan['sql'] as $sql) {
        $fixture->executeStatement($sql);
    }
    $migration->apply($fixture);
    verify($fixture->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 0') === null && (int)$fixture->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 1') === 0, 'Retry normalizes only the root and preserves child zero');
    verify($migration->plan($fixture) === ['sql' => [], 'constraint_sql' => [], 'root_rows' => 0], 'Repeated migration has no work');
} finally {
    $fixture?->close();
    $connection->executeStatement(($postgres ? 'DROP SCHEMA ' : 'DROP DATABASE ') . $quote . ($postgres ? ' CASCADE' : ''));
}
echo "Entity parent mapping, root compatibility, FK/CHECK constraints and upgrade preflight/retry passed\n";
