<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Installer;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\ApplianceAssets20261005;
use itsmng\Database\Migration\ApplianceRecipients20261005;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\Booleans20261002;
use itsmng\Database\Migration\BooleanDomains20261008;
use itsmng\Database\Migration\DomainDocuments20261006;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\ProjectAssets20261003;
use itsmng\Database\Migration\OperatingSystemSubjects20261006;
use itsmng\Database\Migration\Seeds20261001;
use itsmng\Database\Migration\SoftwareInstallationSubjects20261011;
use itsmng\Database\Migration\SoftwareLicenseSubjects20261011;
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
require __DIR__ . '/fixtures/LegacyReleaseFormat.php';
$started = $phaseStarted = microtime(true);
$checkpoint = static function (string $phase) use ($started, &$phaseStarted): void {
    $now = microtime(true);
    echo $phase . ': phase=' . number_format($now - $phaseStarted, 3, '.', '') . 's, elapsed=' . number_format($now - $started, 3, '.', '') . "s\n";
    $phaseStarted = $now;
};
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
verify((int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_rulerightparameters WHERE comment = ''") === 13
    && (int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_ssovariables WHERE comment = ''") === 6, 'Strict raw seed replay supplies the nineteen frozen required comment inputs explicitly');
$seedReceipt = Ledger::state($connection, Seeds20261001::VERSION);
$connection->update('glpi_rulerightparameters', ['comment' => 'A retained later seed edit'], ['id' => 1]);
(new Seeds20261001())->apply($connection, progress: static function (): void {
    throw new RuntimeException('Completed seeds unexpectedly replayed');
});
verify($connection->fetchOne('SELECT comment FROM glpi_rulerightparameters WHERE id = 1') === 'A retained later seed edit'
    && Ledger::state($connection, Seeds20261001::VERSION) === $seedReceipt, 'Completed seed receipt preserves later values without any replay');
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
$connection->insert('glpi_operatingsystems', ['id' => 801, 'name' => 'Historical inventory OS']);
$connection->insert('glpi_items_operatingsystems', ['id' => 802, 'itemtype' => 'Computer', 'items_id' => $legacyId, 'operatingsystems_id' => 801, 'licenseid' => 'Legacy product', 'license_number' => 'Legacy license', 'is_dynamic' => true]);
$connection->insert('glpi_items_operatingsystems', ['id' => 803, 'itemtype' => 'Computer', 'items_id' => $legacyId, 'is_deleted' => true]);
$connection->insert('glpi_softwares', ['id' => 901, 'name' => 'Historical assigned software']);
$connection->insert('glpi_softwareversions', ['id' => 902, 'softwares_id' => 901, 'name' => 'Historical assigned version']);
$connection->insert('glpi_softwarelicenses', ['id' => 903, 'softwares_id' => 901, 'number' => -1, 'softwareversions_id_use' => 902]);
$connection->insert('glpi_items_softwareversions', ['id' => 904, 'softwareversions_id' => 902, 'itemtype' => 'Computer', 'items_id' => $legacyId, 'date_install' => '2026-10-02', 'is_dynamic' => 1]);
foreach ([905, 906] as $id) {
    $connection->insert('glpi_items_softwarelicenses', ['id' => $id, 'softwarelicenses_id' => 903, 'itemtype' => 'Computer', 'items_id' => $legacyId]);
}
$connection->insert('glpi_deviceprocessors', ['id' => 1901, 'designation' => 'Historical processor definition']);
foreach ([1902, 1903] as $processorId) {
    $connection->insert('glpi_items_deviceprocessors', ['id' => $processorId, 'deviceprocessors_id' => 1901,
        'itemtype' => 'Computer', 'items_id' => $legacyId, 'serial' => "Historical CPU O'Reilly", 'nbthreads' => null,
        'frequency' => 3200, 'is_deleted' => $processorId === 1903 ? 1 : 0]);
}
$connection->insert('glpi_items_deviceprocessors', ['id' => 1904, 'deviceprocessors_id' => 1901, 'itemtype' => '', 'items_id' => 0]);
$connection->insert('glpi_items_deviceprocessors', ['id' => 1905, 'deviceprocessors_id' => 1901, 'itemtype' => null, 'items_id' => 0]);
// New family ownership is exercised in the same raw populated adoption, not
// marked complete after constructing today’s metadata schema.
foreach (['glpi_networkequipments', 'glpi_peripherals', 'glpi_printers', 'glpi_phones'] as $subjectTable) {
    $connection->insert($subjectTable, ['id' => 2100, 'name' => 'Historical component subject']);
}
$componentFamilies = [
    ['glpi_items_devicemotherboards', 'glpi_devicemotherboards', 'devicemotherboards_id', ['Computer'], []],
    ['glpi_items_devicememories', 'glpi_devicememories', 'devicememories_id', ['Computer', 'NetworkEquipment', 'Peripheral', 'Printer'], ['size' => 8192]],
    ['glpi_items_deviceharddrives', 'glpi_deviceharddrives', 'deviceharddrives_id', ['Computer', 'Peripheral', 'NetworkEquipment', 'Printer', 'Phone'], ['capacity' => 1048576]],
];
$componentHistorical = [];
foreach ($componentFamilies as $familyIndex => [$bindingTable, $definitionTable, $deviceColumn, $kinds, $payload]) {
    $definition = 2200 + $familyIndex;
    $connection->insert($definitionTable, ['id' => $definition, 'designation' => 'Historical component definition']);
    foreach ($kinds as $kindIndex => $kind) {
        $identity = $kind === 'Computer' ? $legacyId : 2100;
        foreach ([0, 1] as $deleted) {
            $id = 2300 + $familyIndex * 100 + $kindIndex * 2 + $deleted;
            $values = ['id' => $id, $deviceColumn => $definition, 'itemtype' => $kind, 'items_id' => $identity,
                'serial' => "Historical component O'Reilly", 'is_deleted' => $deleted, 'is_dynamic' => 1] + $payload;
            $connection->insert($bindingTable, $values);
            $componentHistorical[$bindingTable][$id] = $values;
        }
    }
    foreach (['', null] as $stockIndex => $kind) {
        $id = 2390 + $familyIndex * 100 + $stockIndex;
        $values = ['id' => $id, $deviceColumn => $definition, 'itemtype' => $kind, 'items_id' => 0, 'serial' => null] + $payload;
        $connection->insert($bindingTable, $values);
        $componentHistorical[$bindingTable][$id] = $values;
    }
}
$connection->insert('glpi_logs', ['id' => $auditId, 'itemtype' => 'Computer', 'items_id' => $legacyId, 'user_name' => 'Legacy administrator', 'old_value' => $audit]);
$password = 'customer-password-hash-must-survive';
$connection->update('glpi_users', ['password' => $password], ['id' => 2]);

// Raw populated adoption has no ledger at all, not twelve completed receipts.
// Reuse this frozen baseline instead of running another installation contract.
$rawReceipts = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
$rawLedger = $manager->introspectTable(LegacyToOrm::LEDGER);
$rawSuppliers = range(1701, 1707);
try {
    $manager->dropTable(LegacyToOrm::LEDGER);
    // A completed historical installer publishes all four aliases; raw seed
    // placeholders alone do not represent an eligible ledgerless installation.
    LegacyReleaseFormat::publish($connection);
    if ($postgres) {
        foreach (['glpi_computers' => 'is_deleted', 'glpi_suppliers' => 'is_recursive'] as $table => $column) {
            $connection->executeStatement('ALTER TABLE ' . $table . ' ALTER COLUMN ' . $column . ' DROP DEFAULT, ALTER COLUMN ' . $column
                . ' TYPE SMALLINT USING CASE WHEN ' . $column . ' THEN 1 ELSE 0 END, ALTER COLUMN ' . $column . ' SET DEFAULT 0');
        }
    }
    foreach ($rawSuppliers as $id) {
        $connection->insert('glpi_suppliers', ['id' => $id, 'name' => 'Raw ledgerless boolean ' . $id, 'is_recursive' => $id % 2 ? 2 : -1]);
    }
    $connection->update('glpi_computers', ['is_deleted' => 2], ['id' => $legacyId]);
    $beforeRawCatalog = BooleanDomainSchema::catalog($connection);
    $beforeRawTables = $manager->listTableNames();
    $beforeRawRows = $connection->fetchAllAssociative('SELECT * FROM glpi_suppliers ORDER BY id');
    $beforeRawComputer = $connection->fetchAssociative('SELECT * FROM glpi_computers WHERE id = ?', [$legacyId]);
    try {
        (new BooleanDomains20261008())->plan($connection, true);
        throw new LogicException('Raw multi-table invalid flags were accepted');
    } catch (RuntimeException $error) {
        $lines = explode("\n", $error->getMessage());
        foreach (['glpi_computers.is_deleted' => [$legacyId], 'glpi_suppliers.is_recursive' => $rawSuppliers] as $property => $ids) {
            $matching = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Invalid boolean data: ' . $property . ' ')));
            verify(count($matching) === 1 && str_contains($matching[0], '(' . count($ids) . ' rows)'), 'Raw preflight aggregates the exact invalid count for ' . $property);
            $samples = json_decode(explode('; samples: ', $matching[0], 2)[1], true, flags: JSON_THROW_ON_ERROR);
            verify(array_map(static fn (array $row): int => (int)$row['id'], $samples) === array_slice($ids, 0, 5), 'Raw diagnostics bound and order sample identities for ' . $property);
        }
    }
    try {
        $history->upgrade($connection);
        throw new LogicException('Ledgerless populated bad flags were adopted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'glpi_computers.is_deleted'), 'Actual canonical adoption diagnoses the raw invalid owning property');
    }
    verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && Ledger::states($connection) === [], 'Invalid raw adoption does not bootstrap even an empty ledger');
    verify(
        BooleanDomainSchema::catalog($connection) === $beforeRawCatalog && $manager->listTableNames() === $beforeRawTables
        && $connection->fetchAllAssociative('SELECT * FROM glpi_suppliers ORDER BY id') === $beforeRawRows
        && $connection->fetchAssociative('SELECT * FROM glpi_computers WHERE id = ?', [$legacyId]) === $beforeRawComputer,
        'Ledgerless refusal preserves all inspected raw schema and populated rows before DDL'
    );
    verify(Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer'
        && !$manager->introspectTable('glpi_items_projects')->hasColumn('computers_id'), 'Raw refusal precedes identifier widening and subject-column adoption');
} finally {
    $connection->update('glpi_computers', ['is_deleted' => 0], ['id' => $legacyId]);
    foreach ($rawSuppliers as $id) {
        $connection->delete('glpi_suppliers', ['id' => $id]);
    }
    if ($postgres) {
        foreach (['glpi_computers' => 'is_deleted', 'glpi_suppliers' => 'is_recursive'] as $table => $column) {
            $connection->executeStatement('ALTER TABLE ' . $table . ' ALTER COLUMN ' . $column . ' DROP DEFAULT, ALTER COLUMN ' . $column
                . ' TYPE BOOLEAN USING (' . $column . ' = 1), ALTER COLUMN ' . $column . ' SET DEFAULT FALSE');
        }
    }
    if (!$manager->tablesExist([LegacyToOrm::LEDGER])) {
        $manager->createTable($rawLedger);
        foreach ($rawReceipts as $row) {
            $connection->insert(LegacyToOrm::LEDGER, $row);
        }
    }
}
verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $rawReceipts, 'Raw refusal fixture restores the prior seed/baseline receipts exactly');

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
        $exactDiagnostic = "Exact subject preflight failed before DDL or receipt:\n"
            . 'Invalid exact subject data: glpi_items_projects (1 rows); samples: '
            . json_encode([['id' => 303] + $invalid], JSON_THROW_ON_ERROR)
            . '. Correct the source assignment explicitly; no spelling or identifier is rewritten.';
        verify(str_contains($error->getMessage(), 'project asset kinds') || str_contains($error->getMessage(), 'Invalid or unsupported legacy typed item references: glpi_items_projects')
            || ($error::class === RuntimeException::class && $error->getMessage() === $exactDiagnostic), 'Project diagnostic identifies the unsupported/invalid relationship before adoption');
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
            $canonicalDiagnostic = "Exact subject preflight failed before DDL or receipt:\nInvalid exact subject data: " . $table . ' (1 rows); samples: '
                . json_encode([['id' => 701] + $invalid], JSON_THROW_ON_ERROR)
                . '. Correct the source assignment explicitly; no spelling or identifier is rewritten.';
            verify((str_contains($error->getMessage(), $table) && (str_contains($error->getMessage(), 'Unsupported typed relationship kinds') || str_contains($error->getMessage(), 'Invalid or unsupported')))
                || ($error::class === RuntimeException::class && $error->getMessage() === $canonicalDiagnostic), 'Appliance diagnostic identifies invalid relationship before adoption');
        }
        verify(
            Ledger::state($connection, LegacyToOrm::VERSION) === null && Ledger::state($connection, ApplianceAssets20261005::VERSION) === null && Ledger::state($connection, ApplianceRecipients20261005::VERSION) === null
            && Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer' && !$manager->introspectTable($table)->hasColumn($column),
            'Appliance preflight refuses before any adoption DDL or stage journal'
        );
        $connection->delete($table, ['id' => 701]);
    }
}
foreach ([['itemtype' => 'PluginInventoryAsset', 'items_id' => $legacyId], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => 1999999999]] as $invalid) {
    $connection->insert('glpi_items_operatingsystems', ['id' => 804] + $invalid);
    try {
        $history->upgrade($connection);
        throw new LogicException('Invalid inventory subject accepted before adoption');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'glpi_items_operatingsystems') && (
            str_contains($error->getMessage(), 'Unsupported typed relationship kinds')
            || str_contains($error->getMessage(), 'Invalid or unsupported')
            || ($error::class === RuntimeException::class && $error->getMessage() ===
                "Exact subject preflight failed before DDL or receipt:\n"
                . 'Invalid exact subject data: glpi_items_operatingsystems (1 rows); samples: '
                . json_encode([['id' => 804] + $invalid], JSON_THROW_ON_ERROR)
                . '. Correct the source assignment explicitly; no spelling or identifier is rewritten.')
        ), 'OS source diagnostic identifies invalid owning subject before adoption');
    }
    verify(Ledger::state($connection, LegacyToOrm::VERSION) === null && Ledger::state($connection, OperatingSystemSubjects20261006::VERSION) === null
        && Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer'
        && !$manager->introspectTable('glpi_items_operatingsystems')->hasColumn('computers_id'), 'OS preflight occurs before any adoption DDL or stage journal');
    $connection->delete('glpi_items_operatingsystems', ['id' => 804]);
}
// A valid installation cannot start DDL while the companion licence graph is invalid.
$connection->insert('glpi_items_softwarelicenses', ['id' => 907, 'softwarelicenses_id' => 903, 'itemtype' => 'PluginInventoryAsset', 'items_id' => $legacyId]);
try {
    $history->upgrade($connection);
    throw new LogicException('Unsupported software extension accepted');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'glpi_items_softwarelicenses') && str_contains($error->getMessage(), 'Plugin::registerClass'), 'Plugin extension diagnostic explains missing canonical mapping');
}
verify(Ledger::state($connection, LegacyToOrm::VERSION) === null
    && Ledger::state($connection, SoftwareInstallationSubjects20261011::VERSION) === null
    && Ledger::state($connection, SoftwareLicenseSubjects20261011::VERSION) === null
    && !$manager->introspectTable('glpi_items_softwareversions')->hasColumn('computers_id'), 'Both software families are audited before any adoption or assignment DDL');
$connection->delete('glpi_items_softwarelicenses', ['id' => 907]);
$connection->insert('glpi_useremails', ['users_id' => 1999999999, 'email' => 'history-orphan@example.invalid']);
try {
    $history->upgrade($connection);
    throw new LogicException('Required orphan was accepted');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'Orphaned required reference: glpi_useremails.users_id'), 'Invalid reference reports its concrete owning field');
}
verify(Ledger::state($connection, LegacyToOrm::VERSION) === null, 'Invalid data creates no adoption journal');
$connection->delete('glpi_useremails', ['email' => 'history-orphan@example.invalid']);
$upgradeConfig = sys_get_temp_dir() . '/itsm-history-upgrade-' . bin2hex(random_bytes(6));
mkdir($upgradeConfig, 0700);
$class = $postgres ? 'DBpgsql' : 'DBmysql';
$properties = ['dbhost' => $DB->dbhost, 'dbuser' => $DB->dbuser, 'dbpassword' => $DB->dbpassword, 'dbdefault' => $name];
$source = '<?php class DB extends ' . $class . ' {';
foreach ($properties as $field => $value) {
    $source .= ' public $' . $field . ' = ' . var_export($value, true) . ';';
}
file_put_contents($upgradeConfig . '/config_db.php', $source . '}');
chmod($upgradeConfig . '/config_db.php', 0600);
$key = (new \itsmng\Database\Upgrade($DB))->expectedSecurityKeyPath();
verify($key !== null && is_file($key), 'The configured parent installation has its original encryption key');
copy($key, $upgradeConfig . '/glpicrypt.key');
chmod($upgradeConfig . '/glpicrypt.key', 0600);
$cli = static function (array $arguments) use ($upgradeConfig): array {
    $process = proc_open([PHP_BINARY, GLPI_ROOT . '/bin/console', '--config-dir=' . $upgradeConfig, '--no-interaction', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, GLPI_ROOT);
    verify(is_resource($process), 'Historical CLI updater starts');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
};
try {
    $manager->dropTable(LegacyToOrm::LEDGER);
    verify(Ledger::states($connection) === [] && Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer', 'Actual populated updater starts from raw tables with no ledger and legacy identifier widths');
    $rawRowbags = static function () use ($connection, $platform, $schema): array {
        $result = [];
        foreach ($schema->getTables() as $table) {
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $platform->quoteIdentifier($table->getName()));
            $bag = array_map(serialize(...), $rows);
            sort($bag, SORT_STRING);
            $result[$table->getName()] = $bag;
        }
        ksort($result);
        return $result;
    };
    // A malformed legacy source can lack the Config uniqueness index while
    // retaining every admitted table/column. Duplicate publication rows must
    // refuse before any same-name ordering or array map can choose a value.
    LegacyReleaseFormat::publish($connection);
    $publishedRelease = \itsmng\Database\LegacyAdoptionEligibility::release($connection);
    $configBefore = $manager->introspectTable('glpi_configs');
    $configRowsBefore = $connection->fetchAllAssociative('SELECT * FROM glpi_configs ORDER BY id');
    $configIndexes = array_values(array_filter($configBefore->getIndexes(),
        static fn ($index): bool => $index->isUnique() && $index->getColumns() === ['context', 'name']));
    verify(count($configIndexes) === 1, 'Actual historical Config has one context/name uniqueness index');
    $configIndex = $configIndexes[0];
    $manager->dropIndex($configIndex->getName(), 'glpi_configs');
    try {
        $ambiguousSchema = $manager->introspectSchema();
        $ambiguousCatalog = BooleanDomainSchema::catalog($connection);
        $ambiguousInputs = [
            ['context' => 'core', 'name' => 'itsmdbversion', 'source' => 'itsmdbversion', 'value' => '2.1.2', 'diagnostic' => 'Ambiguous historical publication: duplicate core.itsmdbversion alias'],
            ['context' => 'core', 'name' => 'version', 'source' => 'version', 'value' => '2.1.3', 'diagnostic' => 'Ambiguous historical publication: duplicate core.version alias'],
            ['context' => 'core', 'name' => 'Version', 'source' => 'version', 'value' => '2.1.2', 'diagnostic' => 'Noncanonical historical publication key: "core"."Version"'],
            ['context' => 'Core', 'name' => 'version', 'source' => 'version', 'value' => '2.1.2', 'diagnostic' => 'Noncanonical historical publication key: "Core"."version"'],
        ];
        foreach ($ambiguousInputs as $input) {
            $original = $connection->fetchAssociative('SELECT * FROM glpi_configs WHERE context = ? AND name = ?', ['core', $input['source']]);
            verify($original !== false, 'Actual historical alias exists before duplicate fixture');
            $duplicateId = 1 + (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_configs');
            $connection->insert('glpi_configs', array_replace($original, ['id' => $duplicateId, 'context' => $input['context'], 'name' => $input['name'], 'value' => $input['value']]));
            try {
                $ambiguousRows = $rawRowbags();
                $variant = $input['context'] !== 'core' || $input['name'] !== $input['source'];
                if ($variant) {
                    $nativeSelected = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_configs WHERE id = ? AND context = ? AND name = ?', [$duplicateId, 'core', 'version']) === 1;
                    verify($nativeSelected === !$postgres, 'Actual provider membership distinguishes case-sensitive keys from native-equivalent legacy spellings');
                    if (!$nativeSelected) {
                        verify(\itsmng\Database\LegacyAdoptionEligibility::release($connection) === $publishedRelease, 'Distinct PostgreSQL configuration key does not replace canonical publication');
                        $history->plan($connection);
                        verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && $rawRowbags() === $ambiguousRows
                            && $manager->createComparator()->compareSchemas($ambiguousSchema, $manager->introspectSchema())->isEmpty()
                            && BooleanDomainSchema::catalog($connection) === $ambiguousCatalog, 'Actual PostgreSQL preview preserves distinct configuration keys, all native rows/schema and absent ledger');
                        continue;
                    }
                }
                $duplicateDiagnostic = $input['diagnostic'];
                $refusals = [
                    'History preview' => static fn () => $history->plan($connection),
                    'History apply' => static fn () => $history->upgrade($connection),
                    'Upgrade preview' => static fn () => (new \itsmng\Database\Upgrade($database))->plan(),
                    'Upgrade apply' => static fn () => (new \itsmng\Database\Upgrade($database))->apply(),
                ];
                foreach ($refusals as $entrypoint => $operation) {
                    try {
                        $operation();
                        throw new LogicException('Duplicate historical publication was accepted by ' . $entrypoint);
                    } catch (RuntimeException $error) {
                        verify(str_contains($error->getMessage(), $duplicateDiagnostic), $entrypoint . ' refuses ambiguous source identity without choosing a value');
                        verify(!str_contains($error->getMessage(), '"' . $input['value'] . '"'), 'Ambiguity diagnostic does not print actual duplicate values');
                    }
                    verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && $rawRowbags() === $ambiguousRows, $entrypoint . ' preserves every native row and absent ledger on duplicate refusal');
                }
                foreach ([['db:update', '--dry-run'], ['db:migrate', '--apply']] as $arguments) {
                    [$status, $output] = $cli($arguments);
                    verify($status !== 0 && str_contains($output, $duplicateDiagnostic), 'Actual CLI refuses contradictory or same-value duplicate publication');
                    verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && $rawRowbags() === $ambiguousRows, 'Actual CLI duplicate refusal preserves every native row and absent ledger');
                }
                verify($manager->createComparator()->compareSchemas($ambiguousSchema, $manager->introspectSchema())->isEmpty()
                    && BooleanDomainSchema::catalog($connection) === $ambiguousCatalog, 'Every duplicate refusal preserves the complete native schema and CHECK definitions');
            } finally {
                $connection->delete('glpi_configs', ['id' => $duplicateId]);
            }
        }
    } finally {
        $manager->createIndex($configIndex, 'glpi_configs');
    }
    verify($connection->fetchAllAssociative('SELECT * FROM glpi_configs ORDER BY id') === $configRowsBefore
        && $manager->createComparator()->compareTables($configBefore, $manager->introspectTable('glpi_configs'))->isEmpty(),
        'Duplicate fixture restores every original single-valued publication row and native uniqueness definition');
    // The actual 2.1.2 and 2.1.3 dumps are byte-identical. Reconstruct its
    // missing terminal DML on existing default right rows, without fake DDL.
    LegacyReleaseFormat::publish($connection, '2.1.2', '2.1.2');
    $retainedCustomRights = [];
    foreach (['followup', 'task'] as $rightName) {
        $row = $connection->fetchAssociative('SELECT id, rights FROM glpi_profilerights WHERE profiles_id = ? AND name = ?', [1, $rightName]);
        verify($row !== false, 'Historical default profile has the original ' . $rightName . ' row');
        $retainedCustomRights[(int)$row['id']] = (int)$row['rights'] & ~16384;
        $connection->update('glpi_profilerights', ['rights' => $retainedCustomRights[(int)$row['id']]], ['id' => $row['id']]);
    }
    $unsupportedSchema = $manager->introspectSchema();
    $unsupportedCatalog = BooleanDomainSchema::catalog($connection);
    $oldRowbags = $rawRowbags;
    $unsupportedRows = $oldRowbags();
    $provenanceRefusals = [
        'History preview' => static fn () => $history->plan($connection),
        'History apply' => static fn () => $history->upgrade($connection),
        'CLI preview' => static function () use ($cli): void {
            [$status, $output] = $cli(['db:update', '--dry-run']);
            verify($status !== 0 && str_contains($output, 'Historical ITSM-NG adoption provenance'), 'Actual CLI preview refuses old historical data provenance');
        },
        'CLI apply' => static function () use ($cli): void {
            [$status, $output] = $cli(['db:migrate', '--apply']);
            verify($status !== 0 && str_contains($output, 'Historical ITSM-NG adoption provenance'), 'Actual CLI apply refuses old historical data provenance');
        },
    ];
    foreach ($provenanceRefusals as $entrypoint => $operation) {
        if (str_starts_with($entrypoint, 'History')) {
            try {
                $operation();
                throw new LogicException('Structurally matching pre-2.1.3 data was accepted by ' . $entrypoint);
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'Historical ITSM-NG adoption provenance')
                    && str_contains($error->getMessage(), 'itsmdbversion="2.1.2"'), $entrypoint . ' diagnoses the exact missing historical format');
            }
        } else {
            $operation();
        }
        verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && Ledger::states($connection) === [], $entrypoint . ' creates no ledger or progress receipt');
        verify($manager->createComparator()->compareSchemas($unsupportedSchema, $manager->introspectSchema())->isEmpty()
            && BooleanDomainSchema::catalog($connection) === $unsupportedCatalog, $entrypoint . ' preserves complete schema/native column and CHECK definitions');
        verify($oldRowbags() === $unsupportedRows, $entrypoint . ' preserves every native row bag, credentials, rights, sentinels and audit record');
    }
    // A genuine completed historical publication is the supported boundary.
    // These current revoked masks may be administrator edits after that upgrade;
    // they must survive the existing positive adoption and all retries below.
    LegacyReleaseFormat::publish($connection);
    $beforeCliSchema = $manager->introspectSchema();
    $beforeCliCatalog = BooleanDomainSchema::catalog($connection);
    $beforeCliRows = [];
    foreach (['glpi_computers', 'glpi_appliances_items', 'glpi_configs', 'glpi_users', 'glpi_profilerights', 'glpi_logs'] as $table) {
        $beforeCliRows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->getDatabasePlatform()->quoteIdentifier($table) . ' ORDER BY id');
    }
    $beforeCliComputer = $connection->fetchAssociative('SELECT * FROM glpi_computers WHERE id = ?', [$legacyId]);
    $beforeCliBinding = $connection->fetchAssociative('SELECT * FROM glpi_appliances_items WHERE id = 402');
    // Exercise apply's own locked preflight, without a discarded CLI preview.
    // Reuse the populated raw graph; no second installation or budget change.
    foreach (['invalid boolean', 'missing target', 'wrong kind'] as $refusal) {
        try {
            if ($refusal === 'invalid boolean') {
                $connection->update('glpi_computers', ['is_deleted' => 2], ['id' => $legacyId]);
                $arguments = ['db:update'];
                $diagnostic = 'glpi_computers.is_deleted';
            } else {
                $connection->update('glpi_appliances_items', $refusal === 'missing target'
                    ? ['items_id' => 1999999999] : ['itemtype' => 'PluginCLIAsset'], ['id' => 402]);
                $arguments = ['db:migrate', '--apply'];
                $diagnostic = 'glpi_appliances_items';
            }
            $invalid = $connection->fetchAssociative('SELECT * FROM ' . ($refusal === 'invalid boolean' ? 'glpi_computers WHERE id = ' . $legacyId : 'glpi_appliances_items WHERE id = 402'));
            [$status, $output] = $cli($arguments);
            verify($status !== 0 && str_contains($output, $diagnostic), 'Actual raw CLI apply refuses ' . $refusal . ' with its owning-field diagnostic: ' . $output);
            verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && Ledger::states($connection) === [], 'Refused raw CLI ' . $refusal . ' bootstraps no ledger');
            verify($manager->createComparator()->compareSchemas($beforeCliSchema, $manager->introspectSchema())->isEmpty()
                && BooleanDomainSchema::catalog($connection) === $beforeCliCatalog, 'Refused raw CLI ' . $refusal . ' commits no schema/check changes');
            verify($connection->fetchAssociative('SELECT * FROM ' . ($refusal === 'invalid boolean' ? 'glpi_computers WHERE id = ' . $legacyId : 'glpi_appliances_items WHERE id = 402')) === $invalid, 'Refused raw CLI retains the invalid source row for correction');
        } finally {
            $connection->update('glpi_computers', ['is_deleted' => $beforeCliComputer['is_deleted']], ['id' => $legacyId]);
            $connection->update('glpi_appliances_items', ['itemtype' => $beforeCliBinding['itemtype'], 'items_id' => $beforeCliBinding['items_id']], ['id' => 402]);
        }
        foreach ($beforeCliRows as $table => $rows) {
            verify($connection->fetchAllAssociative('SELECT * FROM ' . $connection->getDatabasePlatform()->quoteIdentifier($table) . ' ORDER BY id') === $rows, 'Rejected CLI apply preserves release, credentials, audit and populated owner/binding rows: ' . $table);
        }
    }
    // Legitimate pre-existing core Domain documents also need the frozen data
    // prerequisite, even when no Domains plugin tables or aliases exist at all.
    $connection->insert('glpi_domains', ['id' => 801, 'name' => 'Existing core Domain']);
    $connection->insert('glpi_documents', ['id' => 802, 'name' => 'Original core attachment']);
    $sourceTimezone = $postgres ? $connection->fetchOne('SHOW TIME ZONE') : $connection->fetchOne('SELECT @@SESSION.time_zone');
    $connection->executeStatement($postgres ? "SET TIME ZONE '+02:00'" : "SET time_zone = '+02:00'");
    try {
        $connection->insert('glpi_documents_items', ['id' => 803, 'documents_id' => 802, 'items_id' => 801, 'itemtype' => 'Domain', 'entities_id' => 0, 'users_id' => 0,
            'is_recursive' => $postgres ? true : 1, 'timeline_position' => 1, 'date_mod' => '2026-02-03 04:05:06', 'date_creation' => '2026-02-04 05:06:07', 'date' => '2026-02-05 06:07:08'], $postgres ? ['is_recursive' => \Doctrine\DBAL\Types\Types::BOOLEAN] : []);
        $documentInstantSql = $postgres ? 'SELECT EXTRACT(EPOCH FROM date_mod) FROM glpi_documents_items WHERE id=803' : 'SELECT UNIX_TIMESTAMP(date_mod) FROM glpi_documents_items WHERE id=803';
        $documentInstant = $connection->fetchOne($documentInstantSql);
        $documentPreview = $history->plan($connection);
        verify(($documentPreview['domain_prerequisite']['version'] ?? null) === DomainDocuments20261006::GENERAL_RECEIPT
            && str_contains($documentPreview['canonical_preflight'], 'Deferred') && Ledger::state($connection, DomainDocuments20261006::GENERAL_RECEIPT) === null
            && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_documents_items WHERE id=803') === 1, 'No-plugin Domain document preview is read-only and honestly defers canonical audits');
    } finally {
        $postgres ? $connection->fetchOne('SELECT set_config(?, ?, false)', ['TimeZone', $sourceTimezone]) : $connection->executeStatement('SET time_zone = ?', [$sourceTimezone]);
    }
    // Exercise the supported public updater against populated frozen tables, before
    // any current-only association columns exist. It must never replay legacy scripts.
    $checkpoint('Raw baseline/seeds and invalid-data audits');
    $beforePreviewRows = $connection->fetchAllAssociative('SELECT * FROM glpi_documents_items ORDER BY id');
    [$status, $output] = $cli(['db:update', '--dry-run']);
    verify($status === 0 && str_contains($output, 'Deferred canonical audits') && str_contains($output, 'No changes.'), 'Actual raw CLI preview retains its read-only deferred canonical plan output: ' . $output);
    verify(!$manager->tablesExist([LegacyToOrm::LEDGER]) && Ledger::states($connection) === []
        && $connection->fetchAllAssociative('SELECT * FROM glpi_documents_items ORDER BY id') === $beforePreviewRows
        && BooleanDomainSchema::catalog($connection) === $beforeCliCatalog, 'CLI preview preserves raw source rows, schema/check definitions and absent ledger');
    [$status, $output] = $cli(['db:update']);
    verify($status === 0 && str_contains($output, 'Canonical database history complete'), 'Actual db:update adopts populated frozen history without requiring later columns: ' . $output);
} finally {
    unlink($upgradeConfig . '/config_db.php');
    unlink($upgradeConfig . '/glpicrypt.key');
    rmdir($upgradeConfig);
}
$checkpoint('Actual populated db:update');
$database->clearSchemaCache();
foreach ([Baseline20261001::VERSION, Seeds20261001::VERSION] as $adoptedVersion) {
    verify(Ledger::state($connection, $adoptedVersion) === ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved'], 'Actual ledgerless updater records inherited history without replaying seeds: ' . $adoptedVersion);
}
verify((new SchemaCheck())->differences($connection) === [], 'Populated historical replay converges to the complete required schema');
foreach ($retainedCustomRights as $rightId => $mask) {
    verify((int)$connection->fetchOne('SELECT rights FROM glpi_profilerights WHERE id = ?', [$rightId]) === $mask, 'Canonical adoption preserves customized historical rights instead of replaying the old bit grant');
}
$document = $connection->fetchAssociative('SELECT id, documents_id, domains_id, items_id, users_id, is_recursive, timeline_position FROM glpi_documents_items WHERE id=803');
$documentReceipt = Ledger::state($connection, DomainDocuments20261006::GENERAL_RECEIPT);
verify((int)$document['id'] === 803 && (int)$document['documents_id'] === 802 && (int)$document['domains_id'] === 801 && (int)$document['items_id'] === 801
    && $document['users_id'] === null && (bool)$document['is_recursive'] && (int)$document['timeline_position'] === 1
    && (float)$connection->fetchOne($documentInstantSql) === (float)$documentInstant, 'Actual populated db:update preserves core-only Domain document ownership, full-row semantics and native timestamp instant across sessions');
verify($documentReceipt['complete'] && $documentReceipt['documents_restored'] && $documentReceipt['timestamp_timezone'] === '+00:00'
    && !$manager->tablesExist(['glpi_plugin_domains_domains']), 'General document prerequisite and restoration share the ledger without any plugin source');
verify($connection->fetchOne('SELECT old_value FROM glpi_logs WHERE id = ?', [$auditId]) === $audit, 'Audit data and its original ID survive');
verify($connection->fetchOne('SELECT comment FROM glpi_rulerightparameters WHERE id = 1') === 'A retained later seed edit', 'Populated adoption preserves later seed-row edits');
verify($connection->fetchOne('SELECT password FROM glpi_users WHERE id = 2') === $password, 'Customer account data survives adoption');
$installed = $connection->fetchAssociative('SELECT computers_id, items_id, date_install, is_dynamic FROM glpi_items_softwareversions WHERE id = 904');
verify((int)$installed['computers_id'] === $legacyId && (int)$installed['items_id'] === $legacyId
    && $installed['date_install'] === '2026-10-02' && (bool)$installed['is_dynamic'], 'Full history preserves owning installation subject, calendar date and dynamic flag');
$licensed = $connection->fetchAllAssociative('SELECT id, computers_id, items_id, softwarelicenses_id FROM glpi_items_softwarelicenses ORDER BY id');
verify(array_map(static fn ($row) => (int)$row['id'], $licensed) === [905, 906], 'Full history preserves independent duplicate licence assignments');
foreach ($licensed as $row) {
    verify((int)$row['computers_id'] === $legacyId && (int)$row['items_id'] === $legacyId && (int)$row['softwarelicenses_id'] === 903, 'Full history retains licence parent and selected subject');
}

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
verify(count(History::VERSIONS) === 20
    && array_slice(History::VERSIONS, 13, 4) === [\itsmng\Database\Migration\ExactDiscriminators20261010::VERSION,
        SoftwareInstallationSubjects20261011::VERSION, SoftwareLicenseSubjects20261011::VERSION,
        \itsmng\Database\Migration\ProcessorSubjects20261012::VERSION], 'Exact14, Software15/16 and Processor17 retain one ordered canonical ledger');
verify(array_slice(History::VERSIONS, -3) === [
    \itsmng\Database\Migration\MotherboardSubjects20261013::VERSION,
    \itsmng\Database\Migration\MemorySubjects20261013::VERSION,
    \itsmng\Database\Migration\HardDriveSubjects20261013::VERSION,
], 'Motherboard18, Memory19 and HardDrive20 extend the same ordered canonical ledger');
foreach ($componentHistorical as $bindingTable => $sourceRows) {
    $reference = \itsmng\Database\EntityRegistry::discriminatedReferences($bindingTable)['items_id'];
    foreach ($sourceRows as $id => $sourceRow) {
        $row = $connection->fetchAssociative('SELECT * FROM ' . $bindingTable . ' WHERE id = ?', [$id]);
        verify($row !== false && (int)$row['id'] === $id && $row['serial'] === $sourceRow['serial'], 'Canonical full replay preserves every historical component row and nullable literal payload');
        foreach ($sourceRow as $column => $value) {
            if (in_array($column, ['id', 'itemtype', 'items_id', 'serial'], true)) {
                continue;
            }
            verify((int)$row[$column] === (int)$value, 'Canonical full replay retains definition, capacity/size and deletion/dynamic flags');
        }
        $kind = $sourceRow['itemtype'] ?: null;
        verify($row['itemtype'] === $kind && (int)$row['items_id'] === (int)$sourceRow['items_id'], 'Canonical full replay retains every selected kind and normalizes only explicit stock');
        foreach ($reference['selections'] as $selection => $target) {
            verify($selection === $kind ? (int)$row[$target['column']] === (int)$sourceRow['items_id'] : $row[$target['column']] === null, 'Exactly the selected historical component acquires its real owning FK');
        }
    }
}
$processorLinks = $connection->fetchAllAssociative('SELECT * FROM glpi_items_deviceprocessors WHERE id IN (1902,1903,1904,1905) ORDER BY id');
verify(count($processorLinks) === 4, 'Full populated replay retains both duplicate processor assignments and both stock records');
foreach (array_slice($processorLinks, 0, 2) as $row) {
    verify(
        (int)$row['computers_id'] === $legacyId && (int)$row['items_id'] === $legacyId
        && (int)$row['deviceprocessors_id'] === 1901 && $row['serial'] === "Historical CPU O'Reilly"
        && $row['nbthreads'] === null && (int)$row['frequency'] === 3200,
        'Populated Processor17 retains real owner, definition, duplicate IDs and nullable inventory payload'
    );
}
verify(!Type::getType('boolean')->convertToPHPValue($processorLinks[0]['is_deleted'], $platform)
    && Type::getType('boolean')->convertToPHPValue($processorLinks[1]['is_deleted'], $platform), 'Processor duplicate deletion history retains real boolean conversion');
foreach (array_slice($processorLinks, 2) as $row) {
    verify($row['itemtype'] === null && $row['computers_id'] === null && (int)$row['items_id'] === 0
        && (int)$row['deviceprocessors_id'] === 1901, 'Full populated replay normalizes legacy blank/null processor stock without inventing an owner');
}
$writer = new RecordWriter(Orm::create($database));
$osLinks = $connection->fetchAllAssociative('SELECT id, computers_id, items_id, operatingsystems_id, operatingsystem_key, architecture_key, licenseid, license_number, is_dynamic, is_deleted FROM glpi_items_operatingsystems ORDER BY id');
verify(count($osLinks) === 2 && (int)$osLinks[0]['computers_id'] === $legacyId && (int)$osLinks[0]['items_id'] === $legacyId && (int)$osLinks[0]['operatingsystems_id'] === 801
    && $osLinks[0]['licenseid'] === 'Legacy product' && $osLinks[0]['license_number'] === 'Legacy license'
    && Type::getType('boolean')->convertToPHPValue($osLinks[0]['is_dynamic'], $platform), 'Populated full history retains dynamic OS assignment ownership, component and license payload');
verify($osLinks[1]['operatingsystems_id'] === null && (int)$osLinks[1]['operatingsystem_key'] === 0 && (int)$osLinks[1]['architecture_key'] === 0
    && Type::getType('boolean')->convertToPHPValue($osLinks[1]['is_deleted'], $platform), 'Populated full history retains nullable uniqueness keys and deleted inventory history');
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
$checkpoint('Completed adoption retries and native sequence behavior');
// Interrupt the actual fresh installation in the OS subject stage
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
        if ($step === 'OperatingSystemSubjects20261006: columns') {
            throw new RuntimeException('Injected fresh OS subject migration interruption');
        }
    });
    throw new LogicException('Fresh installation interruption did not execute');
} catch (RuntimeException $error) {
    verify($error->getMessage() === 'Injected fresh OS subject migration interruption', 'Actual installer surfaces the appended migration interruption');
}
if ($postgres) {
    verify($manager->listTableNames() === [] && !History::isInstalling($connection), 'PostgreSQL actual fresh installation rolls back all phases');
} else {
    verify(
        History::isInstalling($connection) && !Ledger::state($connection, Baseline20261001::VERSION)['installation_complete']
        && Ledger::state($connection, LegacyToOrm::VERSION)['complete'] && Ledger::state($connection, ApplianceAssets20261005::VERSION)['complete'] && Ledger::state($connection, ApplianceRecipients20261005::VERSION)['complete']
        && !Ledger::state($connection, OperatingSystemSubjects20261006::VERSION)['complete'],
        'MySQL actual fresh installation stays retryable after completed earlier appliance history and incomplete OS subject stage'
    );
}
$history->install($database, 'en_GB');
verify(!History::isInstalling($connection) && Ledger::state($connection, Baseline20261001::VERSION)['installation_complete'], 'Retried real installation closes its explicit installation marker');
verify((new SchemaCheck())->differences($connection) === [], 'Retried actual fresh installation converges on the same required schema');
foreach (History::VERSIONS as $version) {
    verify(Ledger::state($connection, $version)['complete'], 'Retried actual install completes every appended history version: ' . $version);
}
$checkpoint('Actual fresh-install interruption and retry');
echo $database->getProvider() . ": frozen baseline/seed replay, conflicting and interrupted DDL, seed rollback, populated adoption, invalid booleans/references/project/appliance/OS subjects before DDL, separate owners, nested duplicate preservation and project roles, dynamic licensed OS assignments, Domain documents, projections, preserved account/audit data, sequence synchronization, appended fresh-install retry and idempotency passed.\n";
