<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\V220\Booleans;
use itsmng\Database\Migration\V220\HardDriveSubjects;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\MemorySubjects;
use itsmng\Database\Migration\V220\MotherboardSubjects;
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
$families = $componentSchemaFamilies ?? [
    [Item_DeviceMotherboard::class, MotherboardSubjects::class, []],
    [Item_DeviceMemory::class, MemorySubjects::class, ['size' => 8192]],
    [Item_DeviceHardDrive::class, HardDriveSubjects::class, ['capacity' => 1048576]],
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
    $version = $migrationClass::PHASE;
    $flagColumns = array_keys(EntityRegistry::booleanFields($table));
    verify(count($flagColumns) === 3, 'All three component flags come from the owning entity properties');
    $flagStorage = static function () use ($connection, $platform, $postgres, $table, $flagColumns, $expected): array {
        $selection = implode(', ', array_fill(0, count($flagColumns), '?'));
        $rows = $postgres
            ? $connection->fetchAllAssociative('SELECT column_name AS name, data_type AS storage, is_nullable AS nullable FROM information_schema.columns '
                . 'WHERE (table_schema, table_name)=(SELECT n.nspname, c.relname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=to_regclass(?)) '
                . 'AND column_name IN (' . $selection . ') ORDER BY column_name', [$platform->quoteIdentifier($table), ...$flagColumns])
            : $connection->fetchAllAssociative('SELECT COLUMN_NAME AS name, DATA_TYPE AS storage, IS_NULLABLE AS nullable FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (' . $selection . ') ORDER BY COLUMN_NAME', [$table, ...$flagColumns]);
        verify(count($rows) === count($flagColumns), 'Native inspection finds every property-owned component flag');
        foreach ($rows as $row) {
            verify($row['storage'] === Type::lookupName($expected->getTable($table)->getColumn($row['name'])->getType())
                && $row['nullable'] === 'NO', 'Native component flags retain canonical provider storage and required nullability');
        }
        return $rows;
    };
    $canonicalFlagStorage = $flagStorage();
    $reconstruction = null;
    $owners = [];
    $primary = null;
    $cleanup = [];
    $prefix = 'Component adoption ' . bin2hex(random_bytes(5));
    $comment = "Component identity O'Reilly 日本語";
    $device = 4294996100 + $familyIndex;
    $subject = 4294996001;
    $secondSubject = 4294996002;
    try {
        $reconstruction = new ComponentFamilyTable($connection, $expected, $table, $version, $columns);
        foreach ($reference['selections'] as $selection) {
            verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $selection['target'] . ' WHERE id = ?', [$subject]) === 0, 'Never adopt a preexisting source owner');
            $fixtures->create($selection['target'], ['id' => $subject, 'name' => $prefix]);
            $owners[] = [$selection['target'], $subject];
            verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $selection['target'] . ' WHERE id = ?', [$secondSubject]) === 0, 'Never adopt a preexisting canonical update target');
            $fixtures->create($selection['target'], ['id' => $secondSubject, 'name' => $prefix . ' update']);
            $owners[] = [$selection['target'], $secondSubject];
        }
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $deviceTable . ' WHERE id = ?', [$device]) === 0, 'Never adopt a preexisting source definition');
        $fixtures->create($deviceTable, ['id' => $device, 'designation' => $prefix]);
        $owners[] = [$deviceTable, $device];
        foreach (['glpi_locations', 'glpi_states'] as $emptyTarget) {
            verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $emptyTarget . ' WHERE id=0') === 0, 'Never adopt a preexisting zero-sentinel target fixture');
            // Reconstruct an exceptional legacy ID through DBAL after a normal
            // ORM insert. MySQL INSERT id=0 otherwise generates a positive ID.
            $generated = $fixtures->create($emptyTarget, ['name' => $prefix . ' sentinel']);
            $owners[] = [$emptyTarget, $generated];
            verify($generated > 0, 'Ordinary ORM target insertion returns its generated positive identity');
            $beforeZero = $connection->fetchAssociative('SELECT * FROM ' . $emptyTarget . ' WHERE id=?', [$generated]);
            verify($beforeZero !== false && $beforeZero['name'] === $prefix . ' sentinel', 'Own the exact generated fixture before reconstructing its legacy identity');
            $changed = $connection->update($emptyTarget, ['id' => 0], ['id' => $generated]);
            if ($changed === 1) {
                $owners[array_key_last($owners)] = [$emptyTarget, 0];
            }
            $zero = $connection->fetchAssociative('SELECT * FROM ' . $emptyTarget . ' WHERE id=0');
            verify($changed === 1 && $zero !== false && (int)$zero['id'] === 0
                && $connection->fetchOne('SELECT id FROM ' . $emptyTarget . ' WHERE id=?', [$generated]) === false, 'Create an explicit source target0 without confusing it with a generated identity');
            unset($beforeZero['id'], $zero['id']);
            verify($zero === $beforeZero, 'Legacy zero-target reconstruction retains every other native cell');
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
            $ledger = $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
            try {
                $canonical ? (new History())->upgrade($connection) : $migration->apply($connection);
                throw new LogicException('Invalid component source was accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), $table) && str_contains($error->getMessage(), (string)$id), 'Local source audit identifies its actual table and row before DDL');
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows
                && $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') === $ledger
                && Ledger::state($connection, $version) === null, 'Invalid source preserves rows and every raw receipt');
            foreach ($columns as $column) {
                verify(!$manager->introspectTable($table)->hasColumn($column), 'Invalid source creates no owning columns');
            }
            $connection->delete($table, ['id' => $id]);
        };
        $reconstruction->legacy($comment);
        if (isset($componentSchemaExtensionProbe)) {
            $componentSchemaExtensionProbe($connection, $table, $deviceColumn, $device, $subject, $migration, $linkClass);
        }
        foreach ([
            ['itemtype' => 'PluginAsset'], ['itemtype' => 'computer'], ['itemtype' => 'Computer '],
            ['itemtype' => ' ', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => 0],
            ['itemtype' => 'Computer', 'items_id' => null], ['items_id' => $subject + 99],
            ['itemtype' => '', 'items_id' => $subject], ['itemtype' => null, 'items_id' => $subject],
        ] as $invalid) {
            $rejectLegacy($invalid);
        }
        if ($familyIndex > 0 && $familyIndex === count($families) - 1) {
            // Earlier selected family receipts are deliberately pending too.
            // The invalid final family must refuse the canonical updater
            // before any earlier selected family changes its CHECK or receipt.
            $otherReceipts = [];
            $beforeJoint = $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
            $earlierFamilies = array_slice($families, 0, $familyIndex);
            $firstFamilyChecks = static function () use ($earlierFamilies, $postgres, $connection): array {
                $checks = [];
                foreach ($earlierFamilies as [$earlierLink]) {
                    $earlierTable = $earlierLink::getTable();
                    $checks[$earlierTable] = $postgres
                        ? $connection->fetchAllAssociative('SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND contype=\'c\' ORDER BY conname', [$connection->quoteIdentifier($earlierTable)])
                        : \itsmng\Database\BooleanDomainSchema::checks($connection, $earlierTable);
                }
                return $checks;
            };
            $beforeJointChecks = $firstFamilyChecks();
            try {
                foreach ($earlierFamilies as [, $earlierMigration]) {
                    $earlier = $earlierMigration::PHASE;
                    $receipt = $connection->fetchAssociative('SELECT * FROM ' . Ledger::TABLE . ' WHERE version=?', [$earlier]);
                    verify($receipt !== false && (Ledger::state($connection, $earlier)['complete'] ?? false), 'Capture each actual earlier completed family receipt before declaring it pending');
                    $otherReceipts[$earlier] = $receipt;
                    $connection->delete(Ledger::TABLE, ['version' => $earlier]);
                }
                $rejectLegacy(['items_id' => $subject + 99], canonical: true);
                verify($firstFamilyChecks() === $beforeJointChecks, 'Joint updater audits all selected pending families before earlier CHECK replacement');
                foreach (array_keys($otherReceipts) as $earlier) {
                    verify(Ledger::state($connection, $earlier) === null, 'Invalid later source creates no earlier family receipt');
                }
            } finally {
                foreach ($otherReceipts as $receipt) {
                    $connection->insert(Ledger::TABLE, $receipt);
                }
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') === $beforeJoint, 'Joint refusal fixture restores exact original earlier raw receipts');
        }
        // Deliberately reconstructed historical drift must not inherit a pass
        // from completed older boolean/reference receipts.
        $oldBoolean = Ledger::state($connection, Booleans::PHASE);
        $reconstruction->legacy($comment, integerFlag: 'is_dynamic', nullableOwner: $deviceColumn, relaxFlagCheck: true);
        foreach ([['is_dynamic' => 2], ['is_dynamic' => null], [$deviceColumn => null]] as $invalid) {
            $rejectLegacy($invalid);
            verify(Ledger::state($connection, Booleans::PHASE) === $oldBoolean, 'New local audit never rewrites the completed older boolean receipt');
        }
        $refuseShape = static function (string $diagnostic) use ($connection, $table, $migration, $manager, $platform): void {
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            $ledger = $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
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
            verify(
                $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows
                && $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') === $ledger
                && $platform->getCreateTableSQL($manager->introspectTable($table)) === $shape && $nativeForeign() === $foreignBefore,
                'Valid source rows cannot bypass damaged completed core shape; all rows, raw receipts and DBAL DDL remain exact'
            );
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
        verify(Type::lookupName($manager->introspectTable($table)->getColumn('is_dynamic')->getType())
            === Type::lookupName($expected->getTable($table)->getColumn('is_dynamic')->getType()), 'Constraint-free valid legacy flag storage converges to the intended provider mapping');
        verify($flagStorage() === $canonicalFlagStorage, 'Populated integer drift converges to canonical physical flag storage without changing the older receipt');
        verify(
            Type::getType('boolean')->convertToPHPValue($connection->fetchOne('SELECT is_dynamic FROM ' . $table . ' WHERE id=?', [4294996200]), $platform) === true
            && Type::getType('boolean')->convertToPHPValue($connection->fetchOne('SELECT is_dynamic FROM ' . $table . ' WHERE id=?', [4294996280]), $platform) === false,
            'Actual integer1/0 values become true/false without losing populated identities'
        );
        verify(Ledger::state($connection, Booleans::PHASE) === $oldBoolean, 'New family conversion retains exact old boolean receipt');
        foreach (['columns', 'stock_normalization', 'copy', 'projection', 'constraints', ...($postgres ? ['missing_projection'] : [])] as $interruption) {
            $reconstruction->legacy($comment);
            $seed();
            if ($interruption === 'columns') {
                ComponentIncomingProjection::verify($connection, $migration, $table, 4294996200, $subject);
            }
            $ledger = $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
            $preview = (new History())->plan($connection);
            verify(in_array($version, $preview['phases'], true) && $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') === $ledger, 'Canonical joint preview sees the family and remains read-only');
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
                    verify(is_int($value) ? (int)$row[$field] === $value : $row[$field] === $value, 'Retry preserves family-specific numeric/date/null payload');
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
            verify($flagStorage() === $canonicalFlagStorage, 'Every real interrupted phase retains provider-native physical flag storage');
            verify($migration->plan($connection) === [] && $migration->apply($connection) === [], 'Completed family replay is idempotent');
            verify($flagStorage() === $canonicalFlagStorage, 'Completed replay leaves native flag storage unchanged');
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
            // Each native update starts from an unused, canonical owned row.
            // The second real target makes the positive UPDATE change identity;
            // CHECK controls keep every FK valid, and FK controls keep the
            // discriminator/positive-identity CHECK valid.
            $updateId = 4294996400 + array_search($kind, array_keys($reference['selections']), true);
            verify(
                (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE id=?', [$updateId]) === 0,
                'Canonical native UPDATE fixture never adopts an existing binding'
            );
            $missingSubject = $secondSubject + 999;
            verify(
                (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $selection['target'] . ' WHERE id=?', [$missingSubject]) === 0,
                'Selected native UPDATE FK control proves its target absent'
            );
            $secondValue = $connection->fetchOne('SELECT id FROM ' . $selection['target'] . ' WHERE id=?', [$secondSubject]);
            verify((int)$secondValue === $secondSubject, 'Canonical positive UPDATE has a real second subject');
            $beforeUpdate = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            $beforeUpdateLedger = $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
            $updateFrame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            $updatePrimary = null;
            $updateCleanup = [];
            try {
                verify(
                    $connection->insert($table, ['id' => $updateId] + $valid + $payload) === 1,
                    'Every branch admits a canonical native INSERT before UPDATE refusal controls'
                );
                $inserted = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id=?', [$updateId]);
                verify(
                    $inserted !== false && $inserted['itemtype'] === $kind
                    && (int)$inserted[$selection['column']] === $subject && (int)$inserted['items_id'] === $subject,
                    'Canonical native INSERT has the selected owner and generated legacy projection'
                );
                foreach ($reference['selections'] as $otherKind => $other) {
                    verify(
                        $otherKind === $kind || $inserted[$other['column']] === null,
                        'Canonical native INSERT leaves each unselected subject empty'
                    );
                }
                verify(
                    $connection->update($table, [$selection['column'] => $secondSubject], ['id' => $updateId]) === 1,
                    'Actual positive native UPDATE changes the selected owning identity'
                );
                $expectedUpdate = array_replace($inserted, [$selection['column'] => $secondValue, 'items_id' => $secondValue]);
                verify(
                    $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id=?', [$updateId]) === $expectedUpdate,
                    'Positive native UPDATE regenerates items_id and retains every other native cell'
                );
                $validUpdateRows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                $check = $table . '_typed_item_kind';
                $invalidUpdates = [];
                foreach ([strtolower($kind), $kind . ' ', 'PluginAsset', null, ''] as $invalidKind) {
                    $invalidUpdates[] = [['itemtype' => $invalidKind], 'check', $check];
                }
                $invalidUpdates[] = [[$selection['column'] => null], 'check', $check];
                $invalidUpdates[] = [[$selection['column'] => $missingSubject], 'foreign', ForeignKeys::name($table, $selection['column'])];
                foreach ($reference['selections'] as $otherKind => $other) {
                    if ($otherKind !== $kind) {
                        $invalidUpdates[] = [['itemtype' => $otherKind], 'check', $check];
                        $invalidUpdates[] = [[$other['column'] => $subject], 'check', $check];
                    }
                }
                foreach ($invalidUpdates as [$invalid, $cause, $constraint]) {
                    ComponentNativeAdmission::reject(
                        $connection,
                        fn () => $connection->update($table, $invalid, ['id' => $updateId]),
                        $table,
                        $cause,
                        $constraint
                    );
                    verify(
                        $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $validUpdateRows
                        && $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') === $beforeUpdateLedger,
                        'Selected CHECK/FK UPDATE refusal restores the complete canonical row vector and receipts'
                    );
                }
            } catch (Throwable $error) {
                $updatePrimary = $error;
            } finally {
                try {
                    $updateFrame->rollBack();
                } catch (Throwable $error) {
                    $updateCleanup[] = $error;
                }
                try {
                    verify(
                        $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $beforeUpdate
                        && $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') === $beforeUpdateLedger,
                        'Owned canonical INSERT/UPDATE controls restore the complete original family graph and ledger'
                    );
                } catch (Throwable $error) {
                    $updateCleanup[] = $error;
                }
            }
            if ($updatePrimary !== null) {
                foreach ($updateCleanup as $error) {
                    try {
                        fwrite(STDERR, 'Additional owned native UPDATE cleanup failure: ' . $error::class . "\n");
                    } catch (Throwable) {
                        // Secondary reporting cannot mask the original selected cause.
                    }
                }
                throw $updatePrimary;
            }
            if ($updateCleanup) {
                throw $updateCleanup[0];
            }

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
