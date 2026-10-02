<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Installer;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\Booleans20261002;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\Seeds20261001;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/migration-history.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated parent test database required');
$name = getenv('PORT_HISTORY_DB') ?: 'itsm_port_history';
verify(str_starts_with($name, 'itsm_port_') && str_ends_with($name, '_history') && $name !== $DB->dbdefault, 'History fixture must be a separate disposable itsm_port_*_history database');
$database = DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $name);
verify($database->connected, 'Provision the dedicated history database and grant the test role access');
$connection = $database->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$history = new History();
$baseline = new Baseline20261001();
$schema = $baseline->build($platform);
$frozen = $baseline->toSql($platform);
$metadata = Orm::create($database)->getClassMetadata(itsmng\Database\Entity\Computer::class);
$metadata->fieldMappings['is_deleted']->type = 'integer';
verify($baseline->toSql($platform) === $frozen, 'Current entity metadata cannot rewrite historical DDL');
verify(Orm::create($database)->getClassMetadata(itsmng\Database\Entity\Computer::class)->fieldMappings['is_deleted']->type === 'boolean', 'Historical inspection does not contaminate later entity managers');
$owned = [...array_map(static fn ($table) => $table->getName(), $schema->getTables()), LegacyToOrm::LEDGER,
    itsmng\Database\Migration\NetworkPortAggregateOrigins::TABLE, itsmng\Database\Migration\PlanningEventGuests::TABLE];
verify(array_diff($manager->listTableNames(), $owned) === [], 'Refuse to reset a history fixture containing unrelated tables');
if ($postgres) {
    foreach ($manager->listTableNames() as $table) {
        $connection->executeStatement('DROP TABLE ' . $platform->quoteIdentifier($table) . ' CASCADE');
    }
} else {
    Installer::resetMysqlCore($connection);
}

// An unjournaled existing core table is never adopted by the empty-db installer.
$manager->createTable($schema->getTable('glpi_apiclients'));
try {
    $history->baseline($connection);
    throw new LogicException('Unjournaled core table was accepted');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'empty database'), 'Existing core table refuses before writes');
}
verify(!History::isInstalling($connection), 'Refusal creates no installation journal');
$manager->dropTable('glpi_apiclients');

// Simulate process death after CREATE commits but before its MySQL checkpoint.
$interrupted = null;
try {
    $history->baseline($connection, static function (string $step) use (&$interrupted): void {
        $interrupted = substr($step, strlen('Created table: '));
        throw new RuntimeException('Injected baseline interruption');
    });
    throw new LogicException('Interruption did not execute');
} catch (RuntimeException $error) {
    verify($error->getMessage() === 'Injected baseline interruption', 'Baseline interruption is surfaced');
}
if ($postgres) {
    verify($manager->listTableNames() === [] && !History::isInstalling($connection), 'PostgreSQL failed baseline rolls back schema and ledger');
} else {
    verify($manager->tablesExist([$interrupted]) && Ledger::state($connection, Baseline20261001::VERSION)['next'] === 0 && History::isInstalling($connection), 'MySQL committed CREATE retains a pending retry journal');
    $relation = $platform->quoteIdentifier($interrupted);
    $connection->executeStatement('ALTER TABLE ' . $relation . ' ADD conflicting_fixture INTEGER');
    try {
        $history->baseline($connection);
        throw new LogicException('Conflicting pending table was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'differs from history'), 'Retry refuses a conflicting table instead of hiding drift');
    }
    $connection->executeStatement('ALTER TABLE ' . $relation . ' DROP COLUMN conflicting_fixture');
}
$history->baseline($connection);
verify(count($manager->listTableNames()) === count($schema->getTables()) + 1, 'Retry creates every historical table and one shared ledger');

// Seeds remain raw at this point and their whole transaction can be retried.
$rows = 0;
try {
    (new Seeds20261001())->apply($connection, progress: static function () use (&$rows): void {
        if (++$rows === 20) {
            throw new RuntimeException('Injected seed interruption');
        }
    });
    throw new LogicException('Seed interruption did not execute');
} catch (RuntimeException $error) {
    verify($error->getMessage() === 'Injected seed interruption', 'Seed failure is surfaced');
}
verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_apiclients') === 0 && !Ledger::state($connection, Seeds20261001::VERSION)['complete'], 'Failed seed DML rolls back with an incomplete completion record');
(new Seeds20261001())->apply($connection);
verify((int)$connection->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 0') === -1, 'Pre-adoption root sentinel is preserved in frozen seed history');
$legacyId = 2147483640;
$auditId = 2147483646;
$audit = "Historical O'Reilly \\path 日本語";
$connection->insert('glpi_computers', ['id' => $legacyId, 'name' => 'Populated legacy computer', 'entities_id' => 0, 'computermodels_id' => 0]);
$connection->insert('glpi_certificates', ['id' => 100, 'name' => 'Populated legacy certificate']);
$connection->insert('glpi_certificates_items', ['id' => 101, 'certificates_id' => 100, 'itemtype' => 'Computer', 'items_id' => $legacyId]);
$connection->insert('glpi_logs', ['id' => $auditId, 'itemtype' => 'Computer', 'items_id' => $legacyId, 'user_name' => 'Legacy administrator', 'old_value' => $audit]);
$password = 'customer-password-hash-must-survive';
$connection->update('glpi_users', ['password' => $password], ['id' => 2]);

// Early PostgreSQL installations used smallint flags. Invalid values refuse first.
if ($postgres) {
    $connection->executeStatement('ALTER TABLE glpi_computers ALTER COLUMN is_deleted DROP DEFAULT, ALTER COLUMN is_deleted TYPE SMALLINT USING (CASE WHEN is_deleted THEN 1 ELSE 0 END), ALTER COLUMN is_deleted SET DEFAULT 0');
    $connection->update('glpi_computers', ['is_deleted' => 2], ['id' => $legacyId]);
    try {
        $history->upgrade($connection);
        throw new LogicException('Invalid boolean was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Invalid legacy boolean: glpi_computers.is_deleted') && str_contains($error->getMessage(), (string)$legacyId), 'Boolean diagnostic identifies the field and offending row');
    }
    verify(Ledger::state($connection, LegacyToOrm::VERSION) === null && Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer', 'Boolean refusal occurs before adoption DDL');
    $connection->update('glpi_computers', ['is_deleted' => 1], ['id' => $legacyId]);
    $connection->executeStatement('ALTER TABLE glpi_users ALTER COLUMN is_ids_visible DROP DEFAULT, ALTER COLUMN is_ids_visible TYPE SMALLINT USING (CASE WHEN is_ids_visible IS NULL THEN NULL WHEN is_ids_visible THEN 1 ELSE 0 END)');
    $connection->update('glpi_users', ['is_ids_visible' => null], ['id' => 2]);
}
$connection->insert('glpi_useremails', ['users_id' => 1999999999, 'email' => 'history-orphan@example.invalid']);
try {
    $history->upgrade($connection);
    throw new LogicException('Required orphan was accepted');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'Orphaned required reference: glpi_useremails.users_id'), 'Invalid reference reports its concrete owning field');
}
verify(Ledger::state($connection, LegacyToOrm::VERSION) === null, 'Invalid data creates no adoption journal');
$connection->delete('glpi_useremails', ['email' => 'history-orphan@example.invalid']);
$history->upgrade($connection);
$database->clearSchemaCache();
verify((new SchemaCheck())->differences($connection) === [], 'Populated historical replay converges to the complete required schema');
verify($connection->fetchOne('SELECT old_value FROM glpi_logs WHERE id = ?', [$auditId]) === $audit, 'Audit data and its original ID survive');
verify($connection->fetchOne('SELECT password FROM glpi_users WHERE id = 2') === $password, 'Customer account data survives adoption');
verify($connection->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 0') === null && $connection->fetchOne('SELECT computermodels_id FROM glpi_computers WHERE id = ?', [$legacyId]) === null, 'Root and optional zero sentinels become real nullable relationships');
$link = $connection->fetchAssociative('SELECT computers_id, items_id FROM glpi_certificates_items WHERE id = 101');
verify((int)$link['computers_id'] === $legacyId && (int)$link['items_id'] === $legacyId, 'Typed subject and read-only compatibility identity preserve the legacy link');
verify($manager->listTableColumns('glpi_certificates_items')['items_id']->getComment() === $schema->getTable('glpi_certificates_items')->getColumn('items_id')->getComment(), 'Historical projection comment survives complete replay');
if ($postgres) {
    verify(Type::lookupName($manager->listTableColumns('glpi_computers')['is_deleted']->getType()) === 'boolean' && $connection->fetchOne('SELECT is_deleted FROM glpi_computers WHERE id = ?', [$legacyId]) === true, 'Existing integer boolean becomes native boolean without losing its value');
    $nullable = $manager->listTableColumns('glpi_users')['is_ids_visible'];
    verify(Type::lookupName($nullable->getType()) === 'boolean' && !$nullable->getNotnull() && $nullable->getDefault() === null && $connection->fetchOne('SELECT is_ids_visible FROM glpi_users WHERE id = 2') === null, 'Nullable integer flags preserve NULL data, default and nullability');
}

// A normal legacy installation has no baseline/seed records. Validate and adopt,
// recording that its seed phase was inherited without inserting default rows.
foreach ([Baseline20261001::VERSION, Seeds20261001::VERSION] as $version) {
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
}
$beforePreview = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
$preview = $history->plan($connection);
verify(!$preview['complete'] && $preview['pending'] === [Baseline20261001::VERSION, Seeds20261001::VERSION] && !$preview['booleans'], 'Preview identifies inherited history records without inventing seed DDL');
verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $beforePreview, 'Canonical preview leaves the ledger untouched');
$history->upgrade($connection);
foreach ([Baseline20261001::VERSION, Seeds20261001::VERSION] as $version) {
    verify(Ledger::state($connection, $version) === ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved'], 'Adoption records preserved existing data: ' . $version);
}
$before = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
$history->upgrade($connection);
verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $before, 'Completed history retry leaves the ledger unchanged');
verify($connection->fetchOne('SELECT password FROM glpi_users WHERE id = 2') === $password, 'Completed retry does not reapply seed account values');
foreach (History::VERSIONS as $version) {
    verify(Ledger::state($connection, $version)['complete'], 'Every canonical migration is complete: ' . $version);
}
$writer = new RecordWriter(Orm::create($database));
$created = $writer->insert('glpi_computers', ['name' => 'Sequence after populated adoption']);
verify($created > $legacyId, 'Generated sequence/auto-increment continues above imported IDs');
if ($postgres) {
    $sequence = $connection->fetchOne("SELECT pg_get_serial_sequence('glpi_computers', 'id')");
    $advanced = $legacyId + 100;
    $connection->fetchOne('SELECT setval(?::regclass, ?, false)', [$sequence, $advanced]);
    $history->upgrade($connection);
    $allocated = $writer->insert('glpi_computers', ['name' => 'Advanced sequence after completed upgrade']);
    verify($allocated === $advanced, 'Completed adoption preserves an already advanced unused sequence');
    $history->upgrade($connection);
    verify($writer->insert('glpi_computers', ['name' => 'Called sequence after completed upgrade']) === $advanced + 1, 'Repeated adoption never rewinds a called sequence');

    $quote = $platform->quoteSingleIdentifier(...);
    $fixtureSchema = 'port.SequenceSchema';
    $table = $quote($fixtureSchema) . '.' . $quote('Assigned.Table');
    $searchPath = $connection->fetchOne('SHOW search_path');
    verify(!$connection->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$fixtureSchema]), 'Custom sequence fixture schema must not already exist');
    $connection->executeStatement('CREATE SCHEMA ' . $quote($fixtureSchema));
    try {
        $connection->executeStatement('CREATE TABLE ' . $table . ' ("legacy.id" BIGSERIAL PRIMARY KEY, "reserved.id" BIGINT GENERATED BY DEFAULT AS IDENTITY)');
        $serial = $connection->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$table, 'legacy.id']);
        $names = $connection->fetchAssociative('SELECT n.nspname, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = ?::regclass', [$serial]);
        $connection->executeStatement('ALTER SEQUENCE ' . $quote($names['nspname']) . '.' . $quote($names['relname']) . ' RENAME TO ' . $quote('Reserved"Sequence'));
        $connection->fetchOne('SELECT set_config(?, ?, false)', ['search_path', $quote($fixtureSchema) . ', public']);
        $connection->executeStatement('INSERT INTO ' . $table . ' ("legacy.id", "reserved.id") VALUES (17, 29)');
        SequenceSynchronizer::synchronize($connection);
        $allocated = $connection->fetchAssociative('INSERT INTO ' . $table . ' DEFAULT VALUES RETURNING "legacy.id", "reserved.id"');
        verify((int)$allocated['legacy.id'] === 18 && (int)$allocated['reserved.id'] === 30, 'Serial and identity imports synchronize with quoted schema, table, column and sequence names');
        foreach (['legacy.id' => [100, false], 'reserved.id' => [200, true]] as $column => [$value, $called]) {
            $sequence = $connection->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$table, $column]);
            $connection->fetchOne('SELECT setval(?::regclass, ?, ?)', [$sequence, $value, $called], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        SequenceSynchronizer::synchronize($connection);
        $allocated = $connection->fetchAssociative('INSERT INTO ' . $table . ' DEFAULT VALUES RETURNING "legacy.id", "reserved.id"');
        verify((int)$allocated['legacy.id'] === 100 && (int)$allocated['reserved.id'] === 201, 'Synchronization preserves advanced unused serial and called identity sequences');
    } finally {
        $connection->fetchOne('SELECT set_config(?, ?, false)', ['search_path', $searchPath]);
        $connection->executeStatement('DROP SCHEMA ' . $quote($fixtureSchema) . ' CASCADE');
    }
}
$large = 4294967301;
$writer->insert('glpi_logs', ['id' => $large, 'items_id' => $large, 'itemtype' => 'Computer', 'old_value' => 'Post-adoption wide audit']);
verify((new RecordRepository(Orm::create($database)))->find('glpi_logs', 'id', $large)['items_id'] === $large, 'Post-adoption ORM preserves identifiers above unsigned 32-bit range');
echo $database->getProvider() . ": frozen baseline/seed replay, conflicting and interrupted DDL, seed rollback, populated adoption, invalid booleans/references, projections, preserved account/audit data, sequence synchronization and idempotency passed.\n";
