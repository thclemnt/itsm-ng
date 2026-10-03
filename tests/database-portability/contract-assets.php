<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ContractAssets;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ContractAssetRepository;
use itsmng\Database\Repository\TransferBindingRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/contract-assets.php /path/to/test-config\n");
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
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
$migration = new ContractAssets();
$migration->apply($connection);
$DB->clearSchemaCache();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$configuration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$reject = static function (callable $operation, string $message, ?string $omittedRequiredColumn = null, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $failed = NativeConstraintRefusal::matches($error, $omittedRequiredColumn)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$table = 'glpi_contracts_items';
$branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
$expected = $CFG_GLPI['contract_types'];
$actual = array_keys($branches);
sort($expected);
sort($actual);
verify($actual === $expected && count($branches) === 35, 'Every configured asset and installed-component kind has an owning association');
$DB->beginTransaction();
try {
    $sameId = 4294968501;
    $contract = $fixtures->create('glpi_contracts', ['name' => "Contract O'Reilly"]);
    $other = $fixtures->create('glpi_contracts');
    $links = [];
    foreach ($branches as $kind => $selection) {
        $assetValues = ['id' => $sameId];
        if (str_starts_with($kind, 'Item_Device')) {
            $definitionColumn = $kind::$items_id_2;
            $definitionTable = ForeignKeys::relations()[$selection['target']][$definitionColumn];
            $assetValues[$definitionColumn] = $fixtures->create($definitionTable, ['designation' => "Device ' " . $kind]);
        }
        $fixtures->create($selection['target'], $assetValues);
        $model = new Contract_Item();
        $id = $model->add(['contracts_id' => $contract, 'itemtype' => $kind, 'items_id' => $sameId]);
        verify($id > 0 && $model->fields[$selection['column']] === $sameId && $model->fields['items_id'] === $sameId, 'Public owning contract link: ' . $kind);
        $links[$kind] = $id;
        $reject(static fn () => $connection->insert($table, ['contracts_id' => $contract, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Orphan rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Asset purge restricted: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['contracts_id' => $contract, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate rejected: ' . $kind);
        $manager = Orm::create($DB);
        $native = new Record\ContractItem();
        $native->contracts = $manager->getReference(Record\Contract::class, $other);
        $native->itemtype = $kind;
        $association = Record\ContractItem::referenceAssociation($kind);
        $target = $manager->getClassMetadata(Record\ContractItem::class)->getAssociationTargetClass($association);
        $native->{$association} = $manager->getReference($target, $sameId);
        $manager->persist($native);
        $manager->flush();
        verify($native->items_id === $sameId, 'Native graph generates legacy identity: ' . $kind);
        $manager->remove($native);
        $manager->flush();
        $manager->clear();
    }
    foreach ([[], ['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert($table, $invalid + ['contracts_id' => $other]), 'Missing/unknown/zero/wrong/multiple branch rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, 'glpi_contracts_items_typed_item_kind');
    }
    $transfer = TransferBindingRepository::contracts(Orm::create($DB));
    verify(count($transfer->links('Computer', $sameId)) === 1 && $transfer->links('Unsupported', $sameId) === [], 'Owning link lookup isolates overlapping IDs');
    $copy = $transfer->copy($other, 'Monitor', $sameId);
    $newAsset = $fixtures->create('glpi_monitors');
    $transfer->move($copy, null, $newAsset);
    verify($read($table, $copy)['monitors_id'] === $newAsset && $read($table, $copy)['items_id'] === $newAsset, 'Transfer retargets the owning association');
    $transfer->unlink('Monitor', $newAsset);
    verify($read($table, $copy) === null && $read($table, $links['Computer']) !== null, 'Unlink selects only the requested owning kind');
    $target = $fixtures->create('glpi_computers', ['name' => "Quoted ' asset"]);
    $SQL_TOTAL_REQUEST = 0;
    Contract_Item::cloneItem('Computer', $sameId, $target);
    verify($SQL_TOTAL_REQUEST === 0 && count($transfer->links('Computer', $target)) === 1, 'Public clone uses owning ORM lookup');
    $repository = new ContractAssetRepository(Orm::create($DB));
    $SQL_TOTAL_REQUEST = 0;
    $listing = $repository->assets($contract, 'Computer', ['entities_id' => 0], 'name', 100);
    verify($listing['count'] === 2 && count($listing['rows']) === 2 && $SQL_TOTAL_REQUEST === 0, 'Scoped contract asset list uses ORM');
    verify($repository->assets($contract, 'Computer', ['entities_id' => 0], 'name', 1) === ['count' => 2, 'rows' => []], 'Long list counts without loading rows');
    $hiddenEntity = $fixtures->create('glpi_entities');
    verify($repository->assets($contract, 'Computer', ['entities_id' => $hiddenEntity], 'name', 100)['count'] === 0, 'Foreign entities are excluded');
    $storage->update('glpi_computers', $target, ['is_template' => true]);
    verify($repository->assets($contract, 'Computer', ['entities_id' => 0, 'is_template' => false], 'name', 100)['count'] === 1, 'Templates are excluded when requested');
    foreach ($branches as $kind => $selection) {
        if (!str_starts_with($kind, 'Item_Device')) {
            continue;
        }
        $item = new $kind();
        $rows = $repository->assets($contract, $kind, ['entities_id' => 0], $item->getNameField(), 100, $kind::$items_id_2);
        verify($rows['count'] === 1 && $rows['rows'][0]['name_device'] === "Device ' " . $kind && (int)$rows['rows'][0]['linkid'] === $links[$kind], 'Installed-component list uses its definition association: ' . $kind);
    }
    // Association lifecycle retains the parent and removes its owning contract link.
    verify((new Monitor())->delete(['id' => $sameId], true), 'Public asset purge');
    verify($read($table, $links['Monitor']) === null && $read('glpi_contracts', $contract) !== null, 'Asset purge removes only its own contract links');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $configuration;
}

// Frozen upgrade checks reconstruct only these owned disposable fixture tables.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
foreach ([['Contract', 'glpi_contracts_items', 'contracts_id']] as [$type, $table, $parentColumn]) {
    $computer = $fixtures->create('glpi_computers', ['id' => 950000171]);
    $parentTable = (new $type())->getTable();
    $parent = $fixtures->create($parentTable);
    $id = null;
    try {
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
        $before = $manager->introspectTable($table);
        $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
        // MariaDB may use the composite unique index to support the parent FK.
        // Keep that FK enforceable while reconstructing the old scalar identity.
        $support = new \Doctrine\DBAL\Schema\Index($table . '_fixture_parent', [$parentColumn]);
        $connection->executeStatement($platform->getCreateIndexSQL($support, $table));
        foreach ($indexes as $index) {
            $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
        $before = $manager->introspectTable($table);
        $legacy = clone $before;
        $columns = array_column($branches, 'column');
        foreach ($legacy->getForeignKeys() as $foreign) {
            if (array_intersect($foreign->getLocalColumns(), $columns)) {
                $legacy->removeForeignKey($foreign->getName());
            }
        }
        foreach ($legacy->getIndexes() as $index) {
            if (array_intersect($index->getColumns(), $columns)) {
                $legacy->dropIndex($index->getName());
            }
        }
        foreach ($columns as $column) {
            $legacy->dropColumn($column);
        }
        $legacy->addColumn('items_id', 'integer', ['default' => 0]);
        foreach ($indexes as $index) {
            if ($index->isUnique()) {
                $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
            } else {
                $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
            }
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->executeStatement($platform->getDropIndexSQL($support->getName(), $table));
        $connection->insert($table, [$parentColumn => $parent, 'itemtype' => 'Computer', 'items_id' => $computer]);
        $id = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0]] as $bad) {
            $connection->insert($table, $bad + [$parentColumn => $parent]);
            $badId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Invalid legacy asset refuses before DDL: ' . $type);
            $connection->delete($table, ['id' => $badId]);
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD computers_id BIGINT NULL');
        $connection->update($table, ['computers_id' => $computer + 1], ['id' => $id]);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'disagree');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('monitors_id'), 'Conflicting canonical/legacy asset refuses before DDL: ' . $type);
        $connection->update($table, ['computers_id' => $computer], ['id' => $id]);
        $migration->apply($connection);
        $DB->clearSchemaCache();
        $row = $read($table, $id);
        verify($row['computers_id'] === $computer && $row['items_id'] === $computer && $row[$parentColumn] === $parent, 'Upgrade preserves parent/asset/relation identifiers: ' . $type);
        foreach ($indexes as $index) {
            verify($manager->introspectTable($table)->getIndex($index->getName())->isUnique() === $index->isUnique(), 'Upgrade preserves index uniqueness: ' . $type);
        }
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry is idempotent: ' . $type);
        }
    } finally {
        if ($id !== null) {
            $connection->delete($table, ['id' => $id]);
        }
        $connection->delete($parentTable, ['id' => $parent]);
        $connection->delete('glpi_computers', ['id' => $computer]);
    }
}
echo "PASS: thirty-five owning contract assets, native/public writes, bounded lists, components, transfer, purge and frozen upgrade\n";
