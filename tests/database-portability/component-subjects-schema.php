<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\Booleans20261002;
use itsmng\Database\Migration\HardDriveSubjects20261013;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\MemorySubjects20261013;
use itsmng\Database\Migration\MotherboardSubjects20261013;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php component-subjects-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/ComponentFamilyTable.php';
require __DIR__ . '/fixtures/ComponentNativeAdmission.php';
require __DIR__ . '/fixtures/ComponentIncomingProjection.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$expected = (new BaselineSchema())->build($platform);
verify((new SchemaCheck())->differences($connection, $expected) === [], 'Canonical complete schema before component reconstruction');
$families = [
    [Item_DeviceMotherboard::class, MotherboardSubjects20261013::class, []],
    [Item_DeviceMemory::class, MemorySubjects20261013::class, ['size' => 8192]],
    [Item_DeviceHardDrive::class, HardDriveSubjects20261013::class, ['capacity' => 1048576]],
];
$fixtures = new FixtureRecords($DB);
foreach ($families as $familyIndex => [$linkClass, $migrationClass, $payload]) {
    $table = $linkClass::getTable();
    $deviceClass = $linkClass::getDeviceType();
    $deviceTable = $deviceClass::getTable();
    $deviceColumn = $linkClass::getDeviceForeignKey();
    $reference = EntityRegistry::discriminatedReferences($table)['items_id'];
    $columns = array_column($reference['selections'], 'column');
    $migration = new $migrationClass();
    $version = $migrationClass::VERSION;
    $reconstruction = null;
    $owners = [];
    $primary = null;
    $cleanup = [];
    $prefix = 'Component adoption ' . bin2hex(random_bytes(5));
    $comment = "Component identity O'Reilly 日本語";
    $device = 4294996100 + $familyIndex;
    $subject = 4294996001;
    try {
        $reconstruction = new ComponentFamilyTable($connection, $expected, $table, $version, $columns);
        foreach ($reference['selections'] as $selection) {
            verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $selection['target'] . ' WHERE id = ?', [$subject]) === 0, 'Never adopt a preexisting source owner');
            $fixtures->create($selection['target'], ['id' => $subject, 'name' => $prefix]);
            $owners[] = [$selection['target'], $subject];
        }
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $deviceTable . ' WHERE id = ?', [$device]) === 0, 'Never adopt a preexisting source definition');
        $fixtures->create($deviceTable, ['id' => $device, 'designation' => $prefix]);
        $owners[] = [$deviceTable, $device];
        foreach (['glpi_locations', 'glpi_states'] as $emptyTarget) {
            verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $emptyTarget . ' WHERE id=0') === 0, 'Never adopt a preexisting zero-sentinel target fixture');
            verify($fixtures->create($emptyTarget, ['id' => 0, 'name' => $prefix . ' sentinel']) === 0, 'Create an explicit source target0 without confusing it with a generated identity');
            $owners[] = [$emptyTarget, 0];
        }

        $source = [];
        $seed = static function () use ($connection, $table, $deviceColumn, $device, $reference, $subject, $payload, &$source): void {
            $source = [];
            foreach (array_keys($reference['selections']) as $index => $kind) {
                foreach ([0, 1] as $deleted) {
                    $id = 4294996200 + $index * 2 + $deleted;
                    $values = ['id' => $id, $deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $subject,
                        'serial' => "Component O'Reilly \\ 日本語", 'otherserial' => null, 'is_deleted' => $deleted, 'is_dynamic' => 1, 'locations_id' => 0, 'states_id' => 0] + $payload;
                    $connection->insert($table, $values);
                    $source[$id] = $values;
                }
            }
            foreach (['', null] as $index => $kind) {
                $id = 4294996280 + $index;
                $values = ['id' => $id, $deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $index === 0 ? 0 : null, 'serial' => null, 'locations_id' => 0, 'states_id' => 0] + $payload;
                $connection->insert($table, $values);
                $source[$id] = $values;
            }
        };
        $rejectLegacy = static function (array $invalid, bool $canonical = false) use ($connection, $table, $deviceColumn, $device, $migration, $version, $columns, $manager): void {
            $id = 4294996290;
            $connection->insert($table, array_replace(['id' => $id, $deviceColumn => $device, 'itemtype' => 'Computer', 'items_id' => 4294996001], $invalid));
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            $ledger = $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            try {
                $canonical ? (new History())->upgrade($connection) : $migration->apply($connection);
                throw new LogicException('Invalid component source was accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), $table) && str_contains($error->getMessage(), (string)$id), 'Local source audit identifies its actual table and row before DDL');
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows
                && $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger
                && Ledger::state($connection, $version) === null, 'Invalid source preserves rows and every raw receipt');
            foreach ($columns as $column) {
                verify(!$manager->introspectTable($table)->hasColumn($column), 'Invalid source creates no owning columns');
            }
            $connection->delete($table, ['id' => $id]);
        };
        $reconstruction->legacy($comment);
        foreach ([
            ['itemtype' => 'PluginAsset'], ['itemtype' => 'computer'], ['itemtype' => 'Computer '],
            ['itemtype' => ' ', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => 0],
            ['itemtype' => 'Computer', 'items_id' => null], ['items_id' => $subject + 99],
            ['itemtype' => '', 'items_id' => $subject], ['itemtype' => null, 'items_id' => $subject],
        ] as $invalid) {
            $rejectLegacy($invalid);
        }
        if ($familyIndex === 2) {
            // Both earlier family receipts are deliberately pending too. The
            // invalid final family must refuse the canonical updater before
            // either earlier family can replace its CHECK or earn a receipt.
            $otherReceipts = [];
            $beforeJoint = $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            $firstFamilyChecks = static fn (): array => [
                'motherboard' => $postgres
                    ? $connection->fetchAllAssociative("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass('glpi_items_devicemotherboards') AND contype='c' ORDER BY conname")
                    : \itsmng\Database\BooleanDomainSchema::checks($connection, 'glpi_items_devicemotherboards'),
                'memory' => $postgres
                    ? $connection->fetchAllAssociative("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass('glpi_items_devicememories') AND contype='c' ORDER BY conname")
                    : \itsmng\Database\BooleanDomainSchema::checks($connection, 'glpi_items_devicememories'),
            ];
            $beforeJointChecks = $firstFamilyChecks();
            try {
                foreach ([MotherboardSubjects20261013::VERSION, MemorySubjects20261013::VERSION] as $earlier) {
                    $receipt = $connection->fetchAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' WHERE version=?', [$earlier]);
                    verify($receipt !== false && (Ledger::state($connection, $earlier)['complete'] ?? false), 'Capture each actual earlier completed family receipt before declaring it pending');
                    $otherReceipts[$earlier] = $receipt;
                    $connection->delete(LegacyToOrm::LEDGER, ['version' => $earlier]);
                }
                $rejectLegacy(['items_id' => $subject + 99], canonical: true);
                verify($firstFamilyChecks() === $beforeJointChecks, 'Joint updater audits all three pending families before earlier CHECK replacement');
                foreach (array_keys($otherReceipts) as $earlier) {
                    verify(Ledger::state($connection, $earlier) === null, 'Invalid later source creates no earlier family receipt');
                }
            } finally {
                foreach ($otherReceipts as $receipt) {
                    $connection->insert(LegacyToOrm::LEDGER, $receipt);
                }
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $beforeJoint, 'Joint refusal fixture restores exact original earlier raw receipts');
        }
        // Deliberately reconstructed historical drift must not inherit a pass
        // from completed older boolean/reference receipts.
        $oldBoolean = Ledger::state($connection, Booleans20261002::VERSION);
        $reconstruction->legacy($comment, integerFlag: 'is_dynamic', nullableOwner: $deviceColumn, relaxFlagCheck: true);
        foreach ([['is_dynamic' => 2], ['is_dynamic' => null], [$deviceColumn => null]] as $invalid) {
            $rejectLegacy($invalid);
            verify(Ledger::state($connection, Booleans20261002::VERSION) === $oldBoolean, 'New local audit never rewrites the completed older boolean receipt');
        }
        $refuseShape = static function (string $diagnostic) use ($connection, $table, $migration, $manager, $platform): void {
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            $ledger = $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            $shape = $platform->getCreateTableSQL($manager->introspectTable($table));
            $nativeForeign = static fn (): array => $platform instanceof PostgreSQLPlatform
                ? $connection->fetchAllAssociative("SELECT conname, convalidated, condeferrable, condeferred, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND contype='f' ORDER BY conname", [$platform->quoteIdentifier($table)])
                : $connection->fetchAllAssociative('SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_SCHEMA, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME', [$table]);
            $foreignBefore = $nativeForeign();

            $checks = $platform instanceof PostgreSQLPlatform
                ? $connection->fetchAllAssociative("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND contype='c' ORDER BY conname", [$platform->quoteIdentifier($table)])
                : \itsmng\Database\BooleanDomainSchema::checks($connection, $table);
            try {
                $migration->apply($connection);
                throw new LogicException('Damaged completed core component declaration accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), $diagnostic) && str_contains($error->getMessage(), $table), 'The actual frozen core/CHECK ownership diagnostic refuses before DDL');
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows
                && $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger
                && $platform->getCreateTableSQL($manager->introspectTable($table)) === $shape && $nativeForeign() === $foreignBefore,
                'Valid source rows cannot bypass damaged completed core shape; all rows, raw receipts and DBAL DDL remain exact');
            $afterChecks = $platform instanceof PostgreSQLPlatform
                ? $connection->fetchAllAssociative("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND contype='c' ORDER BY conname", [$platform->quoteIdentifier($table)])
                : \itsmng\Database\BooleanDomainSchema::checks($connection, $table);
            verify($checks === $afterChecks, 'Refused adoption retains every actual native CHECK');
        };
        // Completed older adoption cannot repair an absent FK or mandatory
        // owner declaration. Valid rows alone must not earn a new receipt.
        $reconstruction->legacy($comment, nullableOwner: $deviceColumn);
        $seed();
        $refuseShape('Completed historical component owner shape is missing or changed');
        $reconstruction->legacy($comment);
        $seed();
        $deviceForeign = ForeignKeys::name($table, $deviceColumn);
        $manager->dropForeignKey($deviceForeign, $table);
        $refuseShape('Completed historical component owner shape is missing or changed');
        $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' ADD CONSTRAINT '
            . $platform->quoteIdentifier($deviceForeign) . ' FOREIGN KEY (' . $platform->quoteIdentifier($deviceColumn)
            . ') REFERENCES ' . $platform->quoteIdentifier($deviceTable) . ' (id) ON DELETE CASCADE');
        $refuseShape('Completed historical component FK is missing, changed or unvalidated');
        if ($postgres) {
            foreach (['DEFERRABLE INITIALLY IMMEDIATE', 'NOT VALID'] as $nativeDrift) {
                $manager->dropForeignKey($deviceForeign, $table);
                $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' ADD CONSTRAINT '
                    . $platform->quoteIdentifier($deviceForeign) . ' FOREIGN KEY (' . $platform->quoteIdentifier($deviceColumn)
                    . ') REFERENCES ' . $platform->quoteIdentifier($deviceTable) . ' (id) ON DELETE RESTRICT ON UPDATE RESTRICT ' . $nativeDrift);
                $refuseShape('Completed historical component FK is missing, changed or unvalidated');
            }
        }

        if (!$postgres) {
            $reconstruction->legacy($comment, integerFlag: 'is_dynamic', relaxFlagCheck: true);
            $seed();
            $refuseShape('Completed historical component boolean CHECK is missing, changed or unenforced');
        }

        $reconstruction->legacy($comment);
        $seed();
        $newSubjectColumn = $columns[0];
        $newSubjectForeign = ForeignKeys::name($table, $newSubjectColumn);
        $newSubjectTarget = $reference['selections'][array_key_first($reference['selections'])]['target'];
        $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' ADD ' . $platform->quoteIdentifier($newSubjectColumn) . ' BIGINT NULL');
        $connection->executeStatement('UPDATE ' . $platform->quoteIdentifier($table) . ' SET ' . $platform->quoteIdentifier($newSubjectColumn) . '=items_id WHERE itemtype=?', [array_key_first($reference['selections'])]);
        foreach (['ON DELETE CASCADE', ...($postgres ? ['DEFERRABLE INITIALLY IMMEDIATE', 'NOT VALID'] : [])] as $nativeDrift) {
            $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' ADD CONSTRAINT '
                . $platform->quoteIdentifier($newSubjectForeign) . ' FOREIGN KEY (' . $platform->quoteIdentifier($newSubjectColumn)
                . ') REFERENCES ' . $platform->quoteIdentifier($newSubjectTarget) . ' (id) ' . $nativeDrift);
            $primaryForeign = null;
            try {
                $refuseShape('Existing historical component subject FK is missing, changed or unvalidated');
            } catch (Throwable $error) {
                $primaryForeign = $error;
            }
            try {
                $manager->dropForeignKey($newSubjectForeign, $table);
            } catch (Throwable $cleanupForeign) {
                if ($primaryForeign !== null) {
                    throw new \itsmng\Database\MutationCleanupFailure($primaryForeign, $cleanupForeign, false);
                }
                throw $cleanupForeign;
            }
            if ($primaryForeign !== null) {
                throw $primaryForeign;
            }
        }
        // A genuinely missing new subject FK is installed normally. It must
        // not be mistaken for the damaged already-completed core constraints.
        $migration->apply($connection);
        verify($manager->introspectTable($table)->hasForeignKey($newSubjectForeign), 'Normal pending stage enforces an absent new subject FK');
        // Actual historical integer storage must convert valid0/1 rows. A
        // PostgreSQL integer CHECK is not owned by these frozen definitions:
        // familiar names and cross-field custom constraints both refuse intact.
        $reconstruction->legacy($comment, integerFlag: 'is_dynamic');
        $seed();
        if ($postgres) {
            foreach ([
                [\itsmng\Database\BooleanDomainSchema::name($table, 'is_dynamic'), 'is_dynamic IS NOT NULL AND is_dynamic IN (0, 1)'],
                ['port_component_custom_integer_check', 'is_dynamic IN (0, 1) AND id > 0'],
            ] as [$checkName, $expression]) {
                $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' ADD CONSTRAINT '
                    . $platform->quoteIdentifier($checkName) . ' CHECK (' . $expression . ')');
                try {
                    $refuseShape('Historical component boolean conversion requires explicit CHECK adoption');
                } finally {
                    $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' DROP CONSTRAINT ' . $platform->quoteIdentifier($checkName));
                }
            }
        }
        $migration->apply($connection);
        verify(Type::lookupName($manager->introspectTable($table)->getColumn('is_dynamic')->getType()) === 'boolean', 'Constraint-free valid legacy flag storage converges to the intended boolean mapping');
        verify(Type::getType('boolean')->convertToPHPValue($connection->fetchOne('SELECT is_dynamic FROM ' . $table . ' WHERE id=?', [4294996200]), $platform) === true
            && Type::getType('boolean')->convertToPHPValue($connection->fetchOne('SELECT is_dynamic FROM ' . $table . ' WHERE id=?', [4294996280]), $platform) === false,
            'Actual integer1/0 values become true/false without losing populated identities');
        verify(Ledger::state($connection, Booleans20261002::VERSION) === $oldBoolean, 'New family conversion retains exact old boolean receipt');
        foreach (['columns', 'stock_normalization', 'copy', 'projection', 'constraints', ...($postgres ? ['missing_projection'] : [])] as $interruption) {
            $reconstruction->legacy($comment);
            $seed();
            if ($interruption === 'columns') {
                ComponentIncomingProjection::verify($connection, $migration, $table, 4294996200, $subject);
            }
            $ledger = $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            $preview = (new History())->plan($connection);
            verify(in_array($version, $preview['pending'], true) && $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger, 'Canonical joint preview sees the family and remains read-only');
            try {
                $migration->apply($connection, static function (string $phase, string $sql) use ($interruption, $postgres, $manager, $table, $migration, $connection, $columns): void {
                    $drop = $interruption === 'missing_projection' && $phase === 'projection' && str_contains($sql, 'DROP items_id');
                    if ($drop) {
                        verify($postgres && !$manager->introspectTable($table)->hasColumn('items_id')
                            && $migration->plan($connection)[$table]['projection'] !== [], 'Actual transactional DROP exposes a recoverable canonical-only projection plan');
                    }
                    $stock = $interruption === 'stock_normalization' && $phase === 'copy' && str_contains($sql, 'SET itemtype = NULL');
                    if ($drop || $stock || ($phase === $interruption && ($phase !== 'copy' || str_contains($sql, 'SET ' . $columns[0] . ' = CASE')))) {
                        throw new RuntimeException('Injected component phase interruption');
                    }
                });
                throw new LogicException('Expected actual component phase interruption');
            } catch (RuntimeException $error) {
                verify($error->getMessage() === 'Injected component phase interruption', 'Actual DDL progress interruption remains primary');
            }
            verify($postgres ? Ledger::state($connection, $version) === null : (Ledger::state($connection, $version)['complete'] ?? false) !== true, 'PostgreSQL rolls back; MySQL retains only an incomplete owned journal');
            $migration->apply($connection);
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            verify(count($rows) === count($source), 'Retry preserves all duplicates, kinds and stock rows');
            foreach ($rows as $row) {
                $original = $source[(int)$row['id']];
                $kind = $original['itemtype'] ?: null;
                verify($row['itemtype'] === $kind && (int)$row['items_id'] === (int)$original['items_id']
                    && (int)$row[$deviceColumn] === $device && $row['serial'] === $original['serial'], 'Retry retains wide owner/definition identities and literal/null inventory');
                foreach ($reference['selections'] as $selection => $target) {
                    verify($selection === $kind ? (int)$row[$target['column']] === $subject : $row[$target['column']] === null, 'Retry selects exactly the actual kind with no cross-kind identity leakage');
                }
                verify($row['locations_id'] === null && $row['states_id'] === null, 'Historical optional zero selections normalize to NULL without selecting persisted target0');
                foreach ($payload as $field => $value) {
                    verify((int)$row[$field] === $value, 'Retry preserves family-specific size/capacity');
                }
                if ($kind !== null) {
                    verify(Type::getType('boolean')->convertToPHPValue($row['is_deleted'], $platform) === (bool)$original['is_deleted']
                        && Type::getType('boolean')->convertToPHPValue($row['is_dynamic'], $platform), 'Retry retains actual boolean semantics for duplicate/deleted inventory');
                }
            }
            $column = $manager->introspectTable($table)->getColumn('items_id');
            verify(!$column->getNotnull() && $column->getDefault() === null && $column->getComment() === $comment, 'Generated projection preserves native nullability/default/comment');
            $withComment = clone $expected;
            $withComment->getTable($table)->getColumn('items_id')->setComment($comment);
            verify((new SchemaCheck())->differences($connection, $withComment) === [], 'Completed populated family matches intended complete schema');
            verify($migration->plan($connection) === [] && $migration->apply($connection) === [], 'Completed family replay is idempotent');
            verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_locations WHERE id=0') === 1
                && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_states WHERE id=0') === 1, 'Canonical empty-selection conversion preserves the independent source target rows');
        }
        foreach ($reference['selections'] as $kind => $selection) {
            $valid = [$deviceColumn => $device, 'itemtype' => $kind, $selection['column'] => $subject];
            ComponentNativeAdmission::reject($connection, fn () => $connection->insert($table, array_replace($valid, [$selection['column'] => $subject + 99])), $table, 'foreign', ForeignKeys::name($table, $selection['column']));
            ComponentNativeAdmission::reject($connection, fn () => $connection->delete($selection['target'], ['id' => $subject]), $table, 'parent-foreign', ForeignKeys::name($table, $selection['column']), $selection['target']);
            foreach ([strtolower($kind), $kind . ' ', 'PluginAsset', null, ''] as $invalid) {
                ComponentNativeAdmission::reject($connection, fn () => $connection->insert($table, array_replace($valid, ['itemtype' => $invalid])), $table, 'check', $table . '_typed_item_kind');
            }
            ComponentNativeAdmission::reject($connection, fn () => $connection->insert($table, array_replace($valid, [$selection['column'] => 0])), $table, 'check', $table . '_typed_item_kind');
            foreach ($reference['selections'] as $otherKind => $other) {
                if ($otherKind !== $kind) {
                    ComponentNativeAdmission::reject($connection, fn () => $connection->insert($table, $valid + [$other['column'] => $subject]), $table, 'check', $table . '_typed_item_kind');
                }
            }
            ComponentNativeAdmission::reject($connection, fn () => $connection->insert($table, $valid + ['items_id' => $subject]), $table, 'generated-insert');
        }
        ComponentNativeAdmission::reject($connection, fn () => $connection->executeStatement('UPDATE ' . $table . ' SET items_id=? WHERE id=?', [$subject, 4294996200]), $table, 'generated-update');
    } catch (Throwable $error) {
        $primary = $error;
    } finally {
        try {
            $reconstruction?->restore();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        foreach (array_reverse($owners) as [$ownerTable, $id]) {
            try {
                $connection->delete($ownerTable, ['id' => $id]);
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
    }
    if ($primary !== null) {
        foreach ($cleanup as $error) {
            try {
                fwrite(STDERR, 'Additional owned component cleanup failure: ' . (string)$error . "\n");
            } catch (Throwable) {
                // Retain the actual primary even if secondary reporting fails.
            }
        }
        throw $primary;
    }
    if ($cleanup) {
        throw new RuntimeException('Component reconstruction cleanup failed.', previous: $cleanup[0]);
    }
}
verify((new SchemaCheck())->differences($connection) === [] && History::pendingVersions($connection) === [], 'Final complete schema and original family receipts restored');
echo $DB->getProvider() . ": component populated adoption, invalid diagnostics, real interruption/retry and native ownership ($assertions assertions) passed.\n";
