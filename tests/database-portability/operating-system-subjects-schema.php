<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\InventoryUniqueness;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\OperatingSystemSubjects20261006;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/operating-system-subjects-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeBooleanFixture.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$connection = $DB->getDoctrineConnection();
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_items_operatingsystems'], captureTableDeclarations: true, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $platform = $connection->getDatabasePlatform();
    $postgres = $platform instanceof PostgreSQLPlatform;
    $manager = $connection->createSchemaManager();
    $tableName = 'glpi_items_operatingsystems';
    $version = OperatingSystemSubjects20261006::VERSION;
    $migration = new OperatingSystemSubjects20261006();
    $subjects = EntityRegistry::discriminatedReferences($tableName)['items_id']['selections'];
    verify((new SchemaCheck())->differences($connection) === [], 'Current schema converges before reconstruction');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $tableName) === 0, 'Only reconstruct an empty disposable assignment table');
    $required = (new \itsmng\Database\BaselineSchema())->build($platform)->getTable($tableName);
    $expectedWithHistoricalComment = (new \itsmng\Database\BaselineSchema())->build($platform);
    $expectedWithHistoricalComment->getTable($tableName)->getColumn('items_id')->setComment("OS identity O'Reilly 日本語");
    $originalState = Ledger::state($connection, $version);
    $nativeBooleans = new NativeBooleanFixture($connection, $tableName);
    verify($originalState['complete'], 'Appended OS ownership stage completed');
    $fixtures = new FixtureRecords($DB);
    $computer = $fixtures->create('glpi_computers', ['id' => 4294972001]);
    $os = $fixtures->create('glpi_operatingsystems', ['name' => 'Preserved historical OS']);
    $rebuild = static function () use ($required, $manager, $tableName, $subjects, $connection, $version, $nativeBooleans): void {
        $manager->dropTable($tableName);
        $legacy = clone $required;
        foreach ($subjects as $selection) {
            foreach ($legacy->getForeignKeys() as $foreign) {
                if (array_map(static fn ($column) => trim($column, '`'), $foreign->getLocalColumns()) === [$selection['column']]) {
                    $legacy->removeForeignKey($foreign->getName());
                }
            }
            foreach ($legacy->getIndexes() as $index) {
                if (in_array($selection['column'], array_map(static fn ($column) => trim($column, '`'), $index->getColumns()), true)) {
                    $legacy->dropIndex($index->getName());
                }
            }
            $legacy->dropColumn($selection['column']);
        }
        $legacy->getColumn('items_id')->setColumnDefinition(null)->setNotnull(true)->setDefault(0)->setComment("OS identity O'Reilly 日本語");
        $legacy->getColumn('itemtype')->setNotnull(false); // Actual nullable legacy drift must be diagnosed before DDL.
        $manager->createTable($legacy);
        $nativeBooleans->restore();
        $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    };
    try {
        foreach (['columns', 'copy', 'projection', 'missing_projection', 'constraints'] as $interruption) {
            if (!$postgres && $interruption === 'missing_projection') {
                continue; // MySQL replaces the column in one ALTER; there is no missing-column statement boundary.
            }
            $rebuild();
            $connection->insert($tableName, ['id' => 4294972101, 'itemtype' => 'Computer', 'items_id' => $computer, 'operatingsystems_id' => $os, 'licenseid' => 'Historical product', 'license_number' => 'Historical license', 'is_dynamic' => true]);
            $connection->insert($tableName, ['id' => 4294972102, 'itemtype' => 'Computer', 'items_id' => $computer, 'is_deleted' => true]);
            if ($interruption === 'columns') {
                foreach ([['itemtype' => 'PluginAsset', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => $computer + 99], ['itemtype' => null, 'items_id' => $computer]] as $invalid) {
                    $connection->insert($tableName, ['id' => 4294972201] + $invalid);
                    try {
                        $migration->apply($connection);
                        throw new LogicException('Invalid historical subject was accepted');
                    } catch (RuntimeException $error) {
                        verify(str_contains($error->getMessage(), 'Unsupported typed relationship kinds') || str_contains($error->getMessage(), 'Invalid or unsupported'), 'Invalid subject diagnostic identifies the failure');
                        verify(str_contains($error->getMessage(), $tableName) && str_contains($error->getMessage(), '4294972201'), 'Diagnostic identifies the affected OS assignment table and row');
                        if ($invalid['itemtype'] === 'PluginAsset') {
                            verify(str_contains($error->getMessage(), '4294972201') && str_contains($error->getMessage(), 'persisted Computer') && !str_contains($error->getMessage(), 'appliance'), 'Unsupported OS kind provides its row sample and source-specific recovery');
                        }
                    }
                    verify(Ledger::state($connection, $version) === null && !$manager->introspectTable($tableName)->hasColumn('computers_id'), 'Invalid source changes neither schema nor journal');
                    $connection->delete($tableName, ['id' => 4294972201]);
                }
                $unique = InventoryUniqueness::indexName($platform);
                $connection->executeStatement($platform->getDropIndexSQL($unique, $tableName));
                $connection->insert($tableName, ['id' => 4294972201, 'itemtype' => 'Computer', 'items_id' => $computer, 'is_dynamic' => true]);
                try {
                    $migration->apply($connection);
                    throw new LogicException('Duplicate empty OS/architecture was accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Duplicate OS/architecture assignments'), 'Duplicate populated upgrade is diagnosed before subject DDL');
                }
                verify(Ledger::state($connection, $version) === null && !$manager->introspectTable($tableName)->hasColumn('computers_id'), 'Duplicate source changes neither columns nor journal');
                $connection->delete($tableName, ['id' => 4294972201]);
                $connection->executeStatement($platform->getCreateIndexSQL($required->getIndex($unique), $tableName));
                $ledger = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
                $plan = (new History())->plan($connection);
                verify(isset($plan['operating_system_subjects'][$tableName]) && str_contains(implode("\n", $plan['operating_system_subjects'][$tableName]['copy']), 'UPDATE ' . $tableName), 'Canonical preview includes frozen OS data conversion');
                verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger, 'Preview leaves ledger untouched');
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(GLPI_ROOT . '/bin/console') . ' db:migrate --no-interaction --config-dir=' . escapeshellarg(GLPI_CONFIG_DIR) . ' 2>&1', $output, $status);
                verify($status === 0 && str_contains(implode("\n", $output), 'Migration plan: operating_system_subjects') && str_contains(implode("\n", $output), 'No changes.'), 'Real pending-history CLI safely previews OS migration');
                verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger && !$manager->introspectTable($tableName)->hasColumn('computers_id'), 'Real preview changes neither schema nor ledger');
                $connection->executeStatement('ALTER TABLE ' . $tableName . ' ADD computers_id BIGINT NULL');
                $connection->executeStatement('UPDATE ' . $tableName . ' SET computers_id = ?', [$computer + 1]);
                try {
                    $migration->apply($connection);
                    throw new LogicException('Canonical and legacy disagreement was accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Canonical and legacy typed item references disagree: ' . $tableName . '.computers_id'), 'Partial migration disagreement identifies selected column');
                }
                verify(Ledger::state($connection, $version) === null && !$manager->introspectTable($tableName)->hasColumn('monitors_id'), 'Disagreement changes no additional columns or journal');
                $connection->executeStatement('UPDATE ' . $tableName . ' SET computers_id = ?', [$computer]);
            }
            try {
                $migration->apply($connection, static function (string $phase, string $statement) use ($interruption, $postgres, $migration, $connection, $tableName): void {
                    if ($phase === $interruption || ($interruption === 'missing_projection' && $phase === 'projection' && ($postgres ? str_contains($statement, 'DROP items_id') : str_contains($statement, 'CHANGE items_id')))) {
                        if ($interruption === 'missing_projection') {
                            verify(!$connection->createSchemaManager()->introspectTable($tableName)->hasColumn('items_id'), 'Real projection DROP exposes a recoverable column-absent state');
                            verify($migration->plan($connection)[$tableName]['projection'] !== [], 'Frozen canonical identity audits and plans recovery while the compatibility column is absent');
                        }
                        throw new RuntimeException('Injected OS phase interruption');
                    }
                });
                throw new LogicException('Expected real phase interruption');
            } catch (RuntimeException $error) {
                verify($error->getMessage() === 'Injected OS phase interruption', 'Real migration surfaces phase failure: ' . $interruption);
            }
            verify($postgres ? Ledger::state($connection, $version) === null : !Ledger::state($connection, $version)['complete'], 'Transactional rollback or incomplete nontransactional DDL journal');
            $migration->apply($connection);
            $rows = $connection->fetchAllAssociative('SELECT id, items_id, computers_id, operatingsystems_id, operatingsystem_key, architecture_key, licenseid, license_number, is_deleted, is_dynamic FROM ' . $tableName . ' ORDER BY id');
            verify(count($rows) === 2 && (int)$rows[0]['items_id'] === $computer && (int)$rows[0]['computers_id'] === $computer && (int)$rows[0]['operatingsystems_id'] === $os, 'Retry retains wide owner and component identities');
            verify($rows[0]['licenseid'] === 'Historical product' && $rows[0]['license_number'] === 'Historical license' && \Doctrine\DBAL\Types\Type::getType('boolean')->convertToPHPValue($rows[0]['is_dynamic'], $platform), 'Retry retains dynamic inventory license payload');
            verify($rows[1]['operatingsystems_id'] === null && (int)$rows[1]['operatingsystem_key'] === 0 && (int)$rows[1]['architecture_key'] === 0 && \Doctrine\DBAL\Types\Type::getType('boolean')->convertToPHPValue($rows[1]['is_deleted'], $platform), 'Retry preserves nullable component uniqueness and deleted history');
            $column = $manager->introspectTable($tableName)->getColumn('items_id');
            verify(!$column->getNotnull() && $column->getDefault() === null && $column->getComment() === "OS identity O'Reilly 日本語", 'Generated projection preserves original comment and canonical nullability/default');
            verify(count($manager->listTableForeignKeys($tableName)) === 13, 'Six subjects, six independent component roles and entity scope retain real FKs');
            verify($migration->plan($connection) === [] && $migration->apply($connection) === [], 'Completed migration replay is idempotent');
            $differences = (new SchemaCheck())->differences($connection, $expectedWithHistoricalComment);
            verify($differences === [], 'Populated retry converges to complete current schema: ' . json_encode($differences));
        }
    } finally {
        $manager->dropTable($tableName);
        $manager->createTable($nativeExact->restorationTable($tableName));
        $nativeBooleans->restore();
        $connection->executeStatement(OperatingSystemSubjects20261006::checkSql($tableName));
        Ledger::save($connection, $version, $originalState);
        $connection->delete('glpi_computers', ['id' => $computer]);
        $connection->delete('glpi_operatingsystems', ['id' => $os]);
        $DB->clearSchemaCache();
    }
    verify((new SchemaCheck())->differences($connection) === [], 'Fixture cleanup restores complete schema');

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
echo $DB->getProvider() . ": frozen OS ownership migration, invalid/zero/orphan/duplicate diagnostics, read-only CLI preview, wide populated payload, four real interrupted phases and idempotent retry passed.\n";
