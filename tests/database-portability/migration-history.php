<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Installer;
use itsmng\Database\Migration\ApplianceAssets20261005;
use itsmng\Database\Migration\ApplianceRecipients20261005;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\Booleans20261002;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\ProjectAssets20261003;
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
$connection->insert('glpi_projects', ['id' => 201, 'name' => 'Historical project owner']);
$connection->insert('glpi_projects', ['id' => 202, 'name' => 'Historical project subject']);
$connection->insert('glpi_items_projects', ['id' => 301, 'projects_id' => 201, 'itemtype' => 'Computer', 'items_id' => $legacyId]);
$connection->insert('glpi_items_projects', ['id' => 302, 'projects_id' => 201, 'itemtype' => 'Project', 'items_id' => 202]);
$connection->insert('glpi_appliances', ['id' => 401, 'name' => 'Historical appliance']);
$connection->insert('glpi_appliances_items', ['id' => 402, 'appliances_id' => 401, 'itemtype' => 'Computer', 'items_id' => $legacyId]);
$connection->insert('glpi_locations', ['id' => 601, 'name' => 'Historical appliance recipient']);
foreach ([501, 502] as $id) {
    $connection->insert('glpi_appliances_items_relations', ['id' => $id, 'appliances_items_id' => 402, 'itemtype' => 'Location', 'items_id' => 601]);
}
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
foreach ([['itemtype' => 'PluginExampleAsset', 'items_id' => $legacyId], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => 1999999999]] as $invalid) {
    $connection->insert('glpi_items_projects', ['id' => 303, 'projects_id' => 201] + $invalid);
    try {
        $history->upgrade($connection);
        throw new LogicException('Invalid project subject accepted before adoption');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'project asset kinds') || str_contains($error->getMessage(), 'Invalid or unsupported legacy typed item references: glpi_items_projects'), 'Project diagnostic identifies the unsupported/invalid relationship before adoption');
    }
    verify(Ledger::state($connection, LegacyToOrm::VERSION) === null && Ledger::state($connection, ProjectAssets20261003::VERSION) === null
        && Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer'
        && !$manager->introspectTable('glpi_items_projects')->hasColumn('computers_id'), 'Project preflight occurs before all nontransactional adoption DDL and journal writes');
    $connection->delete('glpi_items_projects', ['id' => 303]);
}
// Both new relationship scopes are validated before the old adoption stage
// can commit identifier widening or any other MySQL DDL.
foreach ([['glpi_appliances_items', 'appliances_id', 401, 'Computer', $legacyId, ApplianceAssets20261005::VERSION, 'computers_id'],
    ['glpi_appliances_items_relations', 'appliances_items_id', 402, 'Location', 601, ApplianceRecipients20261005::VERSION, 'locations_id']] as [$table, $ownerColumn, $ownerId, $kind, $targetId, $version, $column]) {
    foreach ([['itemtype' => 'PluginExampleAsset', 'items_id' => $targetId], ['itemtype' => $kind, 'items_id' => 0], ['itemtype' => $kind, 'items_id' => 1999999999]] as $invalid) {
        $connection->insert($table, ['id' => 701, $ownerColumn => $ownerId] + $invalid);
        try {
            $history->upgrade($connection);
            throw new LogicException('Invalid appliance relationship accepted before adoption');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), $table) && (str_contains($error->getMessage(), 'Unsupported typed relationship kinds') || str_contains($error->getMessage(), 'Invalid or unsupported')), 'Appliance diagnostic identifies invalid relationship before adoption');
        }
        verify(
            Ledger::state($connection, LegacyToOrm::VERSION) === null && Ledger::state($connection, ApplianceAssets20261005::VERSION) === null && Ledger::state($connection, ApplianceRecipients20261005::VERSION) === null
            && Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer' && !$manager->introspectTable($table)->hasColumn($column),
            'Appliance preflight refuses before any adoption DDL or stage journal'
        );
        $connection->delete($table, ['id' => 701]);
    }
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
$projectLinks = $connection->fetchAllAssociative('SELECT projects_id, itemtype, computers_id, subject_projects_id, items_id FROM glpi_items_projects ORDER BY id');
verify(
    count($projectLinks) === 2 && (int)$projectLinks[0]['computers_id'] === $legacyId && (int)$projectLinks[0]['items_id'] === $legacyId
    && (int)$projectLinks[1]['subject_projects_id'] === 202 && (int)$projectLinks[1]['items_id'] === 202 && (int)$projectLinks[1]['projects_id'] === 201,
    'Full populated history appends project subjects without mixing the container and Project target'
);
$applianceLink = $connection->fetchAssociative('SELECT appliances_id, computers_id, items_id FROM glpi_appliances_items WHERE id = 402');
verify((int)$applianceLink['appliances_id'] === 401 && (int)$applianceLink['computers_id'] === $legacyId && (int)$applianceLink['items_id'] === $legacyId, 'Populated history preserves separate appliance owner and subject');
$nestedLinks = $connection->fetchAllAssociative('SELECT id, appliances_items_id, locations_id, items_id FROM glpi_appliances_items_relations ORDER BY id');
verify(count($nestedLinks) === 2 && array_map(static fn ($row) => (int)$row['id'], $nestedLinks) === [501, 502], 'Populated history preserves duplicate nested relation row IDs');
foreach ($nestedLinks as $row) {
    verify((int)$row['appliances_items_id'] === 402 && (int)$row['locations_id'] === 601 && (int)$row['items_id'] === 601, 'Populated history preserves nested owner and recipient');
}
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
// Interrupt the actual fresh installation in the final nested appliance stage
// after earlier history completion, then prove installation remains retryable.
if ($postgres) {
    foreach ($manager->listTableNames() as $name) {
        $connection->executeStatement('DROP TABLE ' . $platform->quoteIdentifier($name) . ' CASCADE');
    }
} else {
    Installer::resetMysqlCore($connection);
}
try {
    $history->install($database, 'en_GB', static function (string $step): void {
        if ($step === 'ApplianceRecipients20261005: columns') {
            throw new RuntimeException('Injected fresh appliance recipient migration interruption');
        }
    });
    throw new LogicException('Fresh installation interruption did not execute');
} catch (RuntimeException $error) {
    verify($error->getMessage() === 'Injected fresh appliance recipient migration interruption', 'Actual installer surfaces the appended migration interruption');
}
if ($postgres) {
    verify($manager->listTableNames() === [] && !History::isInstalling($connection), 'PostgreSQL actual fresh installation rolls back all phases');
} else {
    verify(
        History::isInstalling($connection) && !Ledger::state($connection, Baseline20261001::VERSION)['installation_complete']
        && Ledger::state($connection, LegacyToOrm::VERSION)['complete'] && Ledger::state($connection, ApplianceAssets20261005::VERSION)['complete'],
        'MySQL actual fresh installation stays retryable after completed earlier appliance history'
    );
}
$history->install($database, 'en_GB');
verify(!History::isInstalling($connection) && Ledger::state($connection, Baseline20261001::VERSION)['installation_complete'], 'Retried real installation closes its explicit installation marker');
verify((new SchemaCheck())->differences($connection) === [], 'Retried actual fresh installation converges on the same required schema');
foreach (History::VERSIONS as $version) {
    verify(Ledger::state($connection, $version)['complete'], 'Retried actual install completes every appended history version: ' . $version);
}
echo $database->getProvider() . ": frozen baseline/seed replay, conflicting and interrupted DDL, seed rollback, populated adoption, invalid booleans/references/project/appliance subjects before DDL, separate owners, nested duplicate preservation and project roles, projections, preserved account/audit data, sequence synchronization, appended fresh-install retry and idempotency passed.\n";
