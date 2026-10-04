<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\ProcessorSubjects20261012;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/processor-subjects-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
require __DIR__ . '/fixtures/ProcessorNativeAdmission.php';
require __DIR__ . '/fixtures/ProcessorTableChecks.php';
require __DIR__ . '/fixtures/ProcessorIncomingReferences.php';
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
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$tableName = 'glpi_items_deviceprocessors';
$version = ProcessorSubjects20261012::VERSION;
$migration = new ProcessorSubjects20261012();
$reference = EntityRegistry::discriminatedReferences($tableName)['items_id'];
verify($reference['empty_value'] === 0 && array_keys($reference['selections']) === ['Computer'], 'Current metadata declares Computer ownership and zero stock projection');
verify((new SchemaCheck())->differences($connection) === [], 'Complete current schema before reconstruction');
verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $tableName) === 0, 'Only reconstruct an empty processor assignment table');
$current = (new BaselineSchema())->build($platform)->getTable($tableName);
$expected = (new BaselineSchema())->build($platform);
$comment = "Processor identity O'Reilly 日本語";
$expected->getTable($tableName)->getColumn('items_id')->setComment($comment);
$originalState = Ledger::state($connection, $version);
verify(($originalState['complete'] ?? false) === true, 'Processor append completed before contract');
$originalLedger = $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
$originalReceipt = $connection->fetchAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [$version]);
verify($originalReceipt !== false && json_decode($originalReceipt['state'], true, flags: JSON_THROW_ON_ERROR) === $originalState, 'Capture the exact completed processor receipt before fixture mutation');
$nativeChecks = new ProcessorTableChecks($connection, $tableName);
$coreIncoming = new ProcessorIncomingReferences($connection, $expected, $tableName);
$fixtures = new FixtureRecords($DB);
$computer = $device = null;
$tableTouched = false;
$dropAttempted = false;
$consumerOwned = $uniqueOwned = false;
$primary = null;
$cleanupErrors = [];
verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_computers WHERE id = 4294990001') === 0
    && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_deviceprocessors WHERE id = 4294990002') === 0, 'Never adopt preexisting fixed-ID processor fixture owners');
$rebuild = static function () use ($current, $manager, $connection, $tableName, $version, $comment, $nativeChecks, $coreIncoming, &$tableTouched, &$dropAttempted): void {
    $coreIncoming->detach();
    $dropAttempted = true;
    $manager->dropTable($tableName);
    $tableTouched = true; // Only a completed DROP authorizes table reconstruction in cleanup.
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    $legacy = clone $current;
    foreach ($legacy->getForeignKeys() as $foreign) {
        if (array_map(static fn ($column) => trim($column, '`'), $foreign->getLocalColumns()) === ['computers_id']) {
            $legacy->removeForeignKey($foreign->getName());
        }
    }
    foreach ($legacy->getIndexes() as $index) {
        if (in_array('computers_id', array_map(static fn ($column) => trim($column, '`'), $index->getColumns()), true)) {
            $legacy->dropIndex($index->getName());
        }
    }
    $legacy->dropColumn('computers_id');
    // Nullable source drift is legitimate only for stock; selected NULL identities remain invalid.
    $legacy->getColumn('items_id')->setColumnDefinition(null)->setNotnull(false)->setDefault(0)->setComment($comment);
    $manager->createTable($legacy);
    $nativeChecks->install(false);
    $coreIncoming->restore();
    verify($coreIncoming->restored(), 'Each legacy reconstruction retains exact core incoming ownership and all consumer rows');
};
$rejectSql = static function (array $values, bool $foreign = false) use ($connection, $tableName, &$device): void {
    $connection->beginTransaction();
    try {
        $connection->insert($tableName, $values + ['deviceprocessors_id' => $device]);
        throw new LogicException('Invalid canonical processor row was accepted');
    } catch (DriverException $error) {
        verify($foreign ? $error instanceof ForeignKeyConstraintViolationException : ProcessorNativeAdmission::selectedCheck($error, $tableName, (string)$connection->getDatabase()), 'Actual selected processor CHECK/FK native cause rejects ownership; unrelated integrity or HY000 failure is not enforcement evidence');
    } finally {
        $connection->rollBack();
    }
};
try {
    $computer = $fixtures->create('glpi_computers', ['id' => 4294990001]);
    $device = $fixtures->create('glpi_deviceprocessors', ['id' => 4294990002, 'designation' => 'Historical processor']);
    foreach (['columns', 'stock_normalization', 'copy', 'projection', 'missing_projection', 'constraints'] as $interruption) {
        if (!$postgres && $interruption === 'missing_projection') {
            continue; // MySQL replaces the compatibility column in one ALTER.
        }
        $rebuild();
        foreach ([
            ['id' => 4294990101, 'itemtype' => 'Computer', 'items_id' => $computer, 'is_dynamic' => true, 'frequency' => 3200, 'nbcores' => 8, 'serial' => "CPU O'Reilly"],
            ['id' => 4294990102, 'itemtype' => 'Computer', 'items_id' => $computer, 'is_deleted' => true, 'nbthreads' => 16],
            ['id' => 4294990103, 'itemtype' => '', 'items_id' => 0, 'is_dynamic' => true],
            ['id' => 4294990104, 'itemtype' => null, 'items_id' => null],
        ] as $values) {
            $connection->insert($tableName, $values + ['deviceprocessors_id' => $device]);
        }
        if ($interruption === 'columns') {
            $consumer = 'port_processor_projection_consumer';
            $unique = 'port_processor_projection_pair';
            verify(!$manager->tablesExist([$consumer]), 'Never adopt a preexisting incoming-reference fixture');
            $incomingPrimary = null;
            $incomingCleanup = [];
            try {
                $connection->executeStatement('ALTER TABLE ' . $tableName . ' ADD CONSTRAINT ' . $unique . ' UNIQUE (id, items_id)');
                $uniqueOwned = true;
                $connection->executeStatement('CREATE TABLE ' . $consumer . ' (id BIGINT NOT NULL PRIMARY KEY, binding_id BIGINT NOT NULL, subject_id BIGINT NOT NULL, CONSTRAINT port_processor_projection_fk FOREIGN KEY (binding_id, subject_id) REFERENCES ' . $tableName . ' (id, items_id))');
                $consumerOwned = true;
                $connection->insert($consumer, ['id' => 1, 'binding_id' => 4294990101, 'subject_id' => $computer]);
                $incoming = new \itsmng\Database\Migration\IncomingProjectionReferences($connection);
                $schemaName = (string)$connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
                verify($incoming->has($schemaName, $tableName), 'Native incoming projection inventory finds items_id at second composite ordinal');
                $beforeIncoming = $connection->fetchAllAssociative('SELECT * FROM ' . $tableName . ' ORDER BY id');
                $beforeIncomingLedger = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
                $foreignVector = static function () use ($manager, $tableName, $consumer): array {
                    $vector = [];
                    foreach ([$tableName, $consumer] as $table) {
                        foreach ($manager->listTableForeignKeys($table) as $foreign) {
                            $vector[$table][$foreign->getName()] = [$foreign->getLocalColumns(), $foreign->getForeignTableName(), $foreign->getForeignColumns(), $foreign->getOptions()];
                        }
                        ksort($vector[$table]);
                    }
                    return $vector;
                };
                $beforeForeign = $foreignVector();
                $graphRejected = false;
                try {
                    $coreIncoming->detach();
                } catch (LogicException $error) {
                    verify(in_array($error->getMessage(), [
                        'Validated nondeferrable RESTRICT incoming processor ownership required.',
                        'Single-column RESTRICT incoming processor ownership required.'
                    ], true), 'The actual unowned composite projection reference causes fixture graph refusal');
                    $graphRejected = true;
                }
                verify($graphRejected && $foreignVector() === $beforeForeign, 'Fixture reconstruction refuses the custom projection consumer without detaching any constraints');
                verify(
                    $connection->fetchAllAssociative('SELECT * FROM ' . $tableName . ' ORDER BY id') === $beforeIncoming
                    && $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $beforeIncomingLedger,
                    'Fixture graph refusal preserves every processor row and raw receipt'
                );
                try {
                    $migration->plan($connection, $incoming);
                    throw new LogicException('Incoming legacy projection FK accepted destructive migration');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Incoming typed legacy item foreign key'), 'Frozen processor plan retains supplied incoming-reference context and refuses destructive replacement');
                }
                verify($connection->fetchAllAssociative('SELECT * FROM ' . $tableName . ' ORDER BY id') === $beforeIncoming
                    && $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $beforeIncomingLedger
                    && !$manager->introspectTable($tableName)->hasColumn('computers_id'), 'Incoming-FK refusal preserves every fixture row/receipt and performs no DDL');
            } catch (Throwable $error) {
                $incomingPrimary = $error;
            } finally {
                if ($consumerOwned) {
                    try {
                        $manager->dropTable($consumer);
                        $consumerOwned = false;
                    } catch (Throwable $error) {
                        $incomingCleanup[] = $error;
                    }
                }
                if ($uniqueOwned && !$consumerOwned) {
                    try {
                        $connection->executeStatement('ALTER TABLE ' . $tableName . ' DROP ' . ($postgres ? 'CONSTRAINT ' : 'INDEX ') . $unique);
                        $uniqueOwned = false;
                    } catch (Throwable $error) {
                        $incomingCleanup[] = $error;
                    }
                }
            }
            array_push($cleanupErrors, ...$incomingCleanup);
            if ($incomingPrimary !== null) {
                throw $incomingPrimary;
            }
            if ($incomingCleanup) {
                throw new RuntimeException('Owned incoming-reference fixture cleanup failed.', previous: $incomingCleanup[0]);
            }
            foreach ([
                ['itemtype' => 'Phone', 'items_id' => $computer],
                ['itemtype' => 'PluginAsset', 'items_id' => $computer],
                ['itemtype' => 'computer', 'items_id' => $computer],
                ['itemtype' => 'Computer ', 'items_id' => $computer],
                ['itemtype' => ' ', 'items_id' => 0],
                ['itemtype' => 'Computer', 'items_id' => 0],
                ['itemtype' => 'Computer', 'items_id' => null],
                ['itemtype' => 'Computer', 'items_id' => $computer + 99],
                ['itemtype' => '', 'items_id' => $computer],
                ['itemtype' => null, 'items_id' => $computer],
            ] as $invalid) {
                $connection->insert($tableName, ['id' => 4294990201, 'deviceprocessors_id' => $device] + $invalid);
                $ledger = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
                try {
                    $migration->apply($connection);
                    throw new LogicException('Invalid historical processor subject accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), $tableName) && str_contains($error->getMessage(), '4294990201'), 'Preflight identifies invalid table and source row');
                }
                verify(!$manager->introspectTable($tableName)->hasColumn('computers_id') && Ledger::state($connection, $version) === null, 'Invalid source changes neither columns nor append receipt');
                verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger, 'Invalid source preserves unrelated history receipts');
                $connection->delete($tableName, ['id' => 4294990201]);
            }
            $ledger = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            $plan = (new History())->plan($connection);
            verify(isset($plan['processor_subjects'][$tableName]) && count($plan['processor_subjects'][$tableName]['copy']) === 2, 'History preview includes stock normalization and owner backfill');
            verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger, 'History preview is read-only');
            $connection->executeStatement('ALTER TABLE ' . $tableName . ' ADD computers_id BIGINT NULL');
            $connection->executeStatement('UPDATE ' . $tableName . ' SET computers_id = ? WHERE id = ?', [$computer + 1, 4294990101]);
            try {
                $migration->apply($connection);
                throw new LogicException('Conflicting partial canonical ownership accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'Canonical and legacy typed item references disagree'), 'Partial ownership disagreement is diagnosed before copy');
            }
            verify(Ledger::state($connection, $version) === null, 'Conflicting canonical ownership writes no receipt');
            $connection->executeStatement('UPDATE ' . $tableName . ' SET computers_id = NULL');
            // The disagreement control added this owned partial column. Restore
            // actual historical absence before interrupting its required ADD.
            $legacyRows = array_map(static fn (array $row): array => array_diff_key($row, ['computers_id' => true]),
                $connection->fetchAllAssociative('SELECT * FROM ' . $tableName . ' ORDER BY id'));
            $partial = $manager->introspectTable($tableName);
            $legacyAgain = clone $partial;
            $legacyAgain->dropColumn('computers_id');
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($partial, $legacyAgain)) as $statement) {
                $connection->executeStatement($statement);
            }
            verify(!$manager->introspectTable($tableName)->hasColumn('computers_id')
                && $connection->fetchAllAssociative('SELECT * FROM ' . $tableName . ' ORDER BY id') === $legacyRows,
                'Owned partial-column control returns to genuine historical absence without changing any legacy row');
            verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger
                && Ledger::state($connection, $version) === null, 'Removing the owned partial column preserves every adoption receipt');
            verify($migration->plan($connection)[$tableName]['columns'] !== [], 'Actual missing canonical column requires real columns-phase DDL before interruption');

        }
        try {
            $migration->apply($connection, static function (string $phase, string $statement) use ($interruption, $postgres, $migration, $connection, $tableName): void {
                $stock = $interruption === 'stock_normalization' && $phase === 'copy' && str_contains($statement, 'SET itemtype = NULL');
                $copy = $interruption === 'copy' && $phase === 'copy' && str_contains($statement, 'SET computers_id = CASE');
                $missing = $interruption === 'missing_projection' && $phase === 'projection' && str_contains($statement, 'DROP items_id');
                if ($stock || $copy || $missing || ($phase === $interruption && $phase !== 'copy')) {
                    if ($missing) {
                        verify($postgres && !$connection->createSchemaManager()->introspectTable($tableName)->hasColumn('items_id'), 'Actual projection DROP exposes canonical-only recovery');
                        verify($migration->plan($connection)[$tableName]['projection'] !== [], 'Frozen optional identity plans column-absent recovery');
                    }
                    throw new RuntimeException('Injected processor phase interruption');
                }
            });
            throw new LogicException('Expected real processor phase interruption');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Injected processor phase interruption', 'Real migration surfaces injected phase failure');
        }
        verify($postgres ? Ledger::state($connection, $version) === null : !Ledger::state($connection, $version)['complete'], 'Rollback or incomplete MySQL DDL journal remains recoverable');
        $migration->apply($connection);
        $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $tableName . ' ORDER BY id');
        verify(count($rows) === 4 && (int)$rows[0]['items_id'] === $computer && (int)$rows[0]['computers_id'] === $computer && (int)$rows[0]['deviceprocessors_id'] === $device, 'Retry preserves wide component and Computer identities');
        verify((int)$rows[0]['frequency'] === 3200 && (int)$rows[0]['nbcores'] === 8 && $rows[0]['serial'] === "CPU O'Reilly", 'Retry preserves processor payload');
        $boolean = Doctrine\DBAL\Types\Type::getType('boolean');
        verify($boolean->convertToPHPValue($rows[0]['is_dynamic'], $platform) && $boolean->convertToPHPValue($rows[1]['is_deleted'], $platform) && (int)$rows[1]['nbthreads'] === 16, 'Multiple assigned rows retain dynamic and deleted inventory history');
        foreach (array_slice($rows, 2) as $stock) {
            verify($stock['itemtype'] === null && $stock['computers_id'] === null && (int)$stock['items_id'] === 0 && (int)$stock['deviceprocessors_id'] === $device, 'Both legacy stock spellings become canonical stock without losing their device');
        }
        $column = $manager->introspectTable($tableName)->getColumn('items_id');
        verify(!$column->getNotnull() && $column->getDefault() === null && $column->getComment() === $comment, 'Generated identity retains historical comment and canonical column policy');
        verify(count($manager->listTableForeignKeys($tableName)) === 5, 'Computer, device, entity, location and state retain real FKs');
        $rejectSql(['itemtype' => 'Computer', 'computers_id' => null]);
        $rejectSql(['itemtype' => null, 'computers_id' => $computer]);
        $rejectSql(['itemtype' => '', 'computers_id' => null]);
        $rejectSql(['itemtype' => 'Phone', 'computers_id' => $computer]);
        $rejectSql(['itemtype' => 'computer', 'computers_id' => $computer]);
        $rejectSql(['itemtype' => 'Computer ', 'computers_id' => $computer]);
        $rejectSql(['itemtype' => ' ', 'computers_id' => null]);
        $rejectSql(['itemtype' => 'Computer', 'computers_id' => 0]);
        $rejectSql(['itemtype' => 'Computer', 'computers_id' => -1]);
        $rejectSql(['itemtype' => 'Computer', 'computers_id' => $computer + 99], true);
        ProcessorNativeAdmission::rejectGenerated($connection, static fn () => $connection->insert($tableName, [
            'deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'computers_id' => $computer, 'items_id' => $computer
        ]), $tableName, 'INSERT');
        ProcessorNativeAdmission::rejectGenerated($connection, static fn () => $connection->executeStatement(
            'UPDATE ' . $tableName . ' SET items_id=? WHERE id=?',
            [$computer, 4294990101]
        ), $tableName, 'UPDATE');
        verify($migration->plan($connection) === [] && $migration->apply($connection) === [], 'Completed append is a no-op');
        verify((new SchemaCheck())->differences($connection, $expected) === [], 'Populated optional adoption converges to current metadata');
    }
    // A generated projection is not proof of a completed canonical adoption.
    // Invalid partial states fail before stock normalization or any new receipt.
    $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($tableName) . ' DROP '
        . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $platform->quoteIdentifier($tableName . '_typed_item_kind'));
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    foreach ([
        ['itemtype' => '', 'computers_id' => null],
        ['itemtype' => 'Computer', 'computers_id' => null],
        ['itemtype' => null, 'computers_id' => $computer],
    ] as $invalid) {
        $connection->update($tableName, $invalid, ['id' => 4294990104]);
        $before = $connection->fetchAssociative('SELECT * FROM ' . $tableName . ' WHERE id = 4294990104');
        try {
            $migration->apply($connection);
            throw new LogicException('Invalid partial generated stock ownership was accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), $tableName), 'Partial generated ownership is rejected by the frozen source audit');
        }
        verify($connection->fetchAssociative('SELECT * FROM ' . $tableName . ' WHERE id = 4294990104') === $before
            && Ledger::state($connection, $version) === null, 'Invalid generated partial adoption changes neither row nor receipt');
    }
    $connection->update($tableName, ['itemtype' => null, 'computers_id' => null], ['id' => 4294990104]);
    verify(count($migration->plan($connection)[$tableName]['copy']) === 1, 'Canonical-only retry retains stock normalization without recopying the generated identity');
    $migration->apply($connection);
    verify((new SchemaCheck())->differences($connection, $expected) === [], 'Valid canonical-only retry reinstalls the owned CHECK');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($consumerOwned) {
        try {
            $manager->dropTable('port_processor_projection_consumer');
            $consumerOwned = false;
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    // Native definitions must be restored before its completed receipt.
    $dropStateKnown = true;
    if ($dropAttempted && !$tableTouched) {
        try {
            // A transport error may follow actual DDL: inspect before claiming no DROP occurred.
            $tableTouched = !$manager->tablesExist([$tableName]);
        } catch (Throwable $error) {
            $dropStateKnown = false;
            $cleanupErrors[] = $error;
        }
    }
    if ($dropStateKnown && $tableTouched && !$consumerOwned) {
        try {
            $coreIncoming->detach();
            if ($manager->tablesExist([$tableName])) {
                $manager->dropTable($tableName);
            }
            $manager->createTable($current);
            $nativeChecks->install(true);
            $coreIncoming->restore();
            verify($nativeChecks->restored(), 'All original native processor CHECKs, including BooleanDomains, restore exactly');
            verify($coreIncoming->restored(), 'All original incoming processor FKs and consumer rows restore exactly');
            verify((new SchemaCheck())->differences($connection) === [], 'Structural/native schema restored before completed processor receipt');
            $unrelated = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['version'] !== $version));
            verify($unrelated($connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version')) === $unrelated($originalLedger), 'Refuse unrelated ledger changes before restoring the owned raw processor receipt');
            if ($connection->fetchOne('SELECT 1 FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [$version]) === false) {
                $connection->insert(LegacyToOrm::LEDGER, $originalReceipt);
            } else {
                $connection->update(LegacyToOrm::LEDGER, ['state' => $originalReceipt['state']], ['version' => $version]);
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $originalLedger, 'Every original raw receipt restores exactly after full native proof');
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    } elseif ($dropStateKnown && !$consumerOwned) {
        try {
            // A failed detach/DROP must preserve the existing table and completed receipt.
            $coreIncoming->restore();
            verify($coreIncoming->restored() && $nativeChecks->restored(), 'Failed setup restores only its owned incoming constraint changes');
            verify((new SchemaCheck())->differences($connection) === [], 'Failed setup leaves the original processor schema intact');
            verify($connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $originalLedger, 'Failed setup leaves every raw receipt intact');
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    foreach (['glpi_deviceprocessors' => $device, 'glpi_computers' => $computer] as $ownerTable => $ownerId) {
        if ($ownerId !== null) {
            try {
                $connection->delete($ownerTable, ['id' => $ownerId]);
            } catch (Throwable $error) {
                $cleanupErrors[] = $error;
            }
        }
    }
    try {
        $DB->clearSchemaCache();
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
}
if ($primary !== null) {
    fwrite(STDERR, (string)$primary . "\n"); // Original failure always precedes restoration diagnostics.
    foreach ($cleanupErrors as $error) {
        fwrite(STDERR, "Additional owned-fixture cleanup failure: " . (string)$error . "\n");
    }
    exit(1);
}
if ($cleanupErrors) {
    throw new RuntimeException('Processor fixture restoration failed.', previous: $cleanupErrors[0]);
}
verify((new SchemaCheck())->differences($connection) === [], 'Fixture cleanup restores complete schema');
// The current metadata builder must enforce the same stock rule as historical adoption.
$connection->beginTransaction();
try {
    $freshDevice = $fixtures->create('glpi_deviceprocessors');
    $freshComputer = $fixtures->create('glpi_computers');
    $connection->insert($tableName, ['id' => 4294990301, 'deviceprocessors_id' => $freshDevice, 'itemtype' => null, 'computers_id' => null]);
    $connection->insert($tableName, ['id' => 4294990302, 'deviceprocessors_id' => $freshDevice, 'itemtype' => 'Computer', 'computers_id' => $freshComputer]);
    verify((int)$connection->fetchOne('SELECT items_id FROM ' . $tableName . ' WHERE id = 4294990301') === 0 && (int)$connection->fetchOne('SELECT items_id FROM ' . $tableName . ' WHERE id = 4294990302') === $freshComputer, 'Current generated schema accepts stock and assigned subjects');
    $rejectSql(['deviceprocessors_id' => $freshDevice, 'itemtype' => null, 'computers_id' => $freshComputer]);
    $rejectSql(['deviceprocessors_id' => $freshDevice, 'itemtype' => 'Computer', 'computers_id' => null]);
} finally {
    $connection->rollBack();
}
echo $DB->getProvider() . ": frozen processor Computer-or-stock adoption, preflight, wide payload, real CHECK/FK enforcement and interrupted retry passed.\n";
