<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ApplianceAssets20261005;
use itsmng\Database\Migration\ApplianceRecipients20261005;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/appliance-assets-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$stages = [
    'glpi_appliances_items' => [Record\ApplianceItem::class, 'appliances', 'appliances_id', new ApplianceAssets20261005(), $CFG_GLPI['appliance_types'], true],
    'glpi_appliances_items_relations' => [Record\ApplianceItemRelation::class, 'appliances_items', 'appliances_items_id', new ApplianceRecipients20261005(), $CFG_GLPI['appliance_relation_types'], false],
];
verify((new SchemaCheck())->differences($connection) === [], 'Fresh appliance schema converges');
$fixtures = new FixtureRecords($DB);
$reject = static function (callable $operation, string $message, ?string $omittedRequiredColumn = null, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $rejected = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $rejected = NativeConstraintRefusal::matches($error, $omittedRequiredColumn)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($rejected, $message);
    } finally {
        $connection->rollBack();
    }
};
$DB->beginTransaction();
try {
    foreach ($stages as $table => [$entity, $ownerProperty, $ownerColumn, $migration, $expected, $unique]) {
        verify(Ledger::state($connection, $migration::VERSION)['complete'], 'Fresh installation replays appended appliance stage: ' . $table);
        $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
        $actual = array_keys($branches);
        sort($expected);
        sort($actual);
        verify($actual === $expected, 'Every configured kind owns one property: ' . $table);
        verify(count($manager->listTableForeignKeys($table)) === count($expected) + 1, 'All subjects and the separate owner have real FKs: ' . $table);
        $ownerTable = $unique ? 'glpi_appliances' : 'glpi_appliances_items';
        $owner = $fixtures->create($ownerTable);
        $sameId = 4294969007;
        foreach ($branches as $kind => $selection) {
            $fixtures->create($selection['target'], ['id' => $sameId]);
            $fixtures->create($selection['target'], ['id' => $sameId + 1]);
            $em = Orm::create($DB);
            $link = new $entity();
            $ownerClass = $em->getClassMetadata($entity)->getAssociationTargetClass($ownerProperty);
            $link->{$ownerProperty} = $em->getReference($ownerClass, $owner);
            $link->itemtype = $kind;
            $association = $entity::referenceAssociation($kind);
            $class = $em->getClassMetadata($entity)->getAssociationTargetClass($association);
            $link->{$association} = $em->getReference($class, $sameId);
            $em->persist($link);
            $em->flush();
            verify($link->items_id === $sameId, 'Native owning graph generates wide identity: ' . $kind);
            $row = $connection->fetchAssociative('SELECT ' . $ownerColumn . ', ' . $selection['column'] . ', items_id FROM ' . $table . ' WHERE id = ?', [$link->id]);
            verify((int)$row[$ownerColumn] === $owner && (int)$row[$selection['column']] === $sameId, 'Owner and subject remain independent: ' . $kind);
            $reject(static fn () => $connection->insert($table, [$ownerColumn => $owner, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Missing subject is rejected: ' . $kind);
            $reject(static fn () => $connection->insert($table, [$ownerColumn => 999999999, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Missing owner is rejected: ' . $kind);
            if ($unique) {
                $reject(static fn () => $connection->insert($table, [$ownerColumn => $owner, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate appliance asset is rejected: ' . $kind);
            } else {
                $connection->insert($table, [$ownerColumn => $owner, 'itemtype' => $kind, $selection['column'] => $sameId]);
                verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $ownerColumn . ' = ? AND itemtype = ? AND items_id = ?', [$owner, $kind, $sameId]) === 2, 'Nested duplicate bindings retain separate rows: ' . $kind);
                $connection->executeStatement('DELETE FROM ' . $table . ' WHERE id <> ? AND ' . $ownerColumn . ' = ? AND itemtype = ?', [$link->id, $owner, $kind]);
            }
            $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Referenced subject deletion is restrictive: ' . $kind);
            $reject(static fn () => $connection->delete($ownerTable, ['id' => $owner]), 'Referenced owner deletion is restrictive: ' . $kind);
            $link->{$association} = $em->getReference($class, $sameId + 1);
            $em->flush();
            verify($link->items_id === $sameId + 1 && $link->{$ownerProperty}->id === $owner, 'Native update retargets subject and retains owner: ' . $kind);
            $em->remove($link);
            $em->flush();
        }
        $columns = array_column($branches, 'column');
        $firstKind = array_key_first($branches);
        foreach ([[], ['itemtype' => null], ['itemtype' => 'PluginExampleAsset'], ['itemtype' => $firstKind], ['itemtype' => $firstKind, $columns[0] => 0], ['itemtype' => $firstKind, $columns[1] => $sameId], ['itemtype' => $firstKind, $columns[0] => $sameId, $columns[1] => $sameId]] as $invalid) {
            $reject(static fn () => $connection->insert($table, $invalid + [$ownerColumn => $owner]), 'Missing, zero, unknown, wrong and multiple selections are rejected: ' . $table, !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, $table . '_typed_item_kind');
        }
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Native appliance graphs leave no orphans');
} finally {
    $DB->rollBack();
}

// Rebuild only empty owned tables, dropping the nested table first to retain
// restrictive ownership. Test every committed phase with populated wide IDs.
foreach (array_keys($stages) as $table) {
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Never reconstruct nonempty shared fixture: ' . $table);
}
$required = (new \itsmng\Database\BaselineSchema())->build($platform);
$states = [];
foreach ($stages as $table => $stage) {
    $states[$table] = Ledger::state($connection, $stage[3]::VERSION);
}
$owner = $fixtures->create('glpi_appliances');
$computer = $fixtures->create('glpi_computers', ['id' => 4294969511]);
$location = $fixtures->create('glpi_locations', ['id' => 4294969512]);
try {
    foreach (['columns', 'copy', 'projection', 'constraints'] as $interruptPhase) {
        foreach (array_reverse(array_keys($stages)) as $table) {
            $manager->dropTable($table);
        }
        foreach ($stages as $table => [$entity, $ownerProperty, $ownerColumn, $migration]) {
            $legacy = (new Baseline20261001())->build($platform)->getTable($table);
            foreach (['id', $ownerColumn, 'items_id'] as $column) {
                $legacy->getColumn($column)->setType(Type::getType('bigint'));
            }
            $legacy->getColumn('itemtype')->setNotnull(false); // Explicit nullable legacy-drift fixture for diagnostic preflight.
            $legacy->getColumn('items_id')->setComment("Appliance identity O'Reilly 日本語");
            $legacy->addForeignKeyConstraint($table === 'glpi_appliances_items' ? 'glpi_appliances' : 'glpi_appliances_items', [$ownerColumn], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'fk_' . substr($table, 5) . '_' . $ownerColumn);
            $manager->createTable($legacy);
            $connection->delete(LegacyToOrm::LEDGER, ['version' => $migration::VERSION]);
        }
        $connection->insert('glpi_appliances_items', ['id' => 4294969601, 'appliances_id' => $owner, 'itemtype' => 'Computer', 'items_id' => $computer]);
        foreach ([4294969701, 4294969702] as $id) {
            $connection->insert('glpi_appliances_items_relations', ['id' => $id, 'appliances_items_id' => 4294969601, 'itemtype' => 'Location', 'items_id' => $location]);
        }
        foreach ($stages as $table => [$entity, $ownerProperty, $ownerColumn, $migration]) {
            $kind = $table === 'glpi_appliances_items' ? 'Computer' : 'Location';
            $parent = $table === 'glpi_appliances_items' ? $owner : 4294969601;
            $identity = $table === 'glpi_appliances_items' ? $computer : $location;
            if ($interruptPhase === 'columns') {
                foreach ([['itemtype' => 'PluginExampleAsset', 'items_id' => $identity], ['itemtype' => $kind, 'items_id' => 0], ['itemtype' => $kind, 'items_id' => 999999999], ['itemtype' => null, 'items_id' => 0]] as $invalid) {
                    $connection->insert($table, ['id' => 4294969801, $ownerColumn => $parent] + $invalid);
                    try {
                        $migration->apply($connection);
                        throw new LogicException('Invalid legacy appliance relationship accepted');
                    } catch (RuntimeException $error) {
                        verify(str_contains($error->getMessage(), 'Unsupported typed relationship kinds') || str_contains($error->getMessage(), 'Invalid or unsupported'), 'Migration reports invalid/unsupported relationship');
                    }
                    verify(Ledger::state($connection, $migration::VERSION) === null && !$manager->introspectTable($table)->hasColumn($kind === 'Computer' ? 'computers_id' : 'locations_id'), 'Invalid preflight writes neither schema nor stage journal');
                    $connection->delete($table, ['id' => 4294969801]);
                }
                $ledger = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
                $plan = (new History())->plan($connection);
                verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger, 'Canonical preview retains read-only ledger');
                $section = $table === 'glpi_appliances_items' ? 'appliance_assets' : 'appliance_recipients';
                verify(str_contains(implode('\n', $plan[$section][$table]['copy']), 'UPDATE ' . $table . ' SET'), 'Preview includes frozen data conversion');
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(GLPI_ROOT . '/bin/console') . ' db:migrate --no-interaction --config-dir=' . escapeshellarg(GLPI_CONFIG_DIR) . ' 2>&1', $output, $status);
                $preview = implode("\n", $output);
                verify($status === 0 && str_contains($preview, 'Migration plan: ' . $section) && str_contains($preview, 'UPDATE ' . $table . ' SET') && str_contains($preview, 'No changes.'), 'Actual CLI previews appended appliance DDL and copy without applying it');
                verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $ledger, 'Actual CLI preview leaves ledger untouched');
                $column = $kind === 'Computer' ? 'computers_id' : 'locations_id';
                verify(!$manager->introspectTable($table)->hasColumn($column), 'Actual CLI preview leaves schema untouched');
                $connection->executeStatement('ALTER TABLE ' . $table . ' ADD ' . $column . ' BIGINT NULL');
                $connection->executeStatement('UPDATE ' . $table . ' SET ' . $column . ' = ?', [$identity + 1]);
                try {
                    $migration->apply($connection);
                    throw new LogicException('Partial canonical/legacy disagreement was accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Canonical and legacy typed item references disagree: ' . $table . '.' . $column), 'Partial adoption disagreement identifies owning column');
                }
                verify(Ledger::state($connection, $migration::VERSION) === null && !$manager->introspectTable($table)->hasColumn($kind === 'Computer' ? 'monitors_id' : 'networks_id'), 'Partial disagreement changes neither further columns nor stage journal');
                $connection->executeStatement('UPDATE ' . $table . ' SET ' . $column . ' = ?', [$identity]);

            }
            try {
                $migration->apply($connection, static function (string $phase) use ($interruptPhase): void {
                    if ($phase === $interruptPhase) {
                        throw new RuntimeException('Injected appliance phase interruption');
                    }
                });
                throw new LogicException('Phase interruption did not execute');
            } catch (RuntimeException $error) {
                verify($error->getMessage() === 'Injected appliance phase interruption', 'Real stage surfaces interruption: ' . $table . '.' . $interruptPhase);
            }
            verify($postgres ? Ledger::state($connection, $migration::VERSION) === null : !Ledger::state($connection, $migration::VERSION)['complete'], 'PG rollback or MySQL incomplete journal survives real failure');
            $migration->apply($connection);
            $column = $kind === 'Computer' ? 'computers_id' : 'locations_id';
            $rows = $connection->fetchAllAssociative('SELECT ' . $ownerColumn . ', items_id, ' . $column . ' FROM ' . $table . ' ORDER BY id');
            verify(count($rows) === ($table === 'glpi_appliances_items' ? 1 : 2), 'Retry preserves every duplicate nested row');
            foreach ($rows as $row) {
                verify((int)$row[$ownerColumn] === $parent && (int)$row['items_id'] === $identity && (int)$row[$column] === $identity, 'Retry preserves wide owner and subject identities');
            }
            $projection = $manager->introspectTable($table)->getColumn('items_id');
            verify($projection->getComment() === "Appliance identity O'Reilly 日本語" && !$projection->getNotnull() && $projection->getDefault() === null, 'Retry preserves comments, generated nullability and default');
            verify(count($manager->listTableForeignKeys($table)) === ($table === 'glpi_appliances_items' ? 9 : 4), 'Retry installs all restrictive ownership FKs');
            verify($migration->apply($connection) === [] && $migration->plan($connection) === [], 'Completed appliance stage is idempotent');
        }
    }
} finally {
    foreach (array_reverse(array_keys($stages)) as $table) {
        $manager->dropTable($table);
    }
    foreach ($stages as $table => $stage) {
        $manager->createTable($required->getTable($table));
        $connection->executeStatement($stage[3]::checkSql($table));
        Ledger::save($connection, $stage[3]::VERSION, $states[$table]);
    }
    $connection->delete('glpi_appliances', ['id' => $owner]);
    $connection->delete('glpi_computers', ['id' => $computer]);
    $connection->delete('glpi_locations', ['id' => $location]);
    $DB->clearSchemaCache();
}
verify((new SchemaCheck())->differences($connection) === [], 'Cleanup restores entire required schema');
echo $DB->getProvider() . ": eight owning appliance subjects, three nested recipients, separate owners, wide native graphs, restrictive FKs, asset uniqueness, nested duplicate preservation, invalid-data preflight and four-phase populated retry passed.\n";
