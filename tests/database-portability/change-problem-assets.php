<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ChangeProblemAssets;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILAssetRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/change-problem-assets.php /path/to/test-config\n");
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
$migration = new ChangeProblemAssets();
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
$cases = [
    ['Change', Change_Item::class, Record\Change::class, Record\ChangeItem::class, 'glpi_changes_items', 'changes_id', 'changes'],
    ['Problem', Item_Problem::class, Record\Problem::class, Record\ItemProblem::class, 'glpi_items_problems', 'problems_id', 'problems'],
];
$branches = EntityRegistry::discriminatedReferences('glpi_items_tickets')['items_id']['selections'];
$DB->beginTransaction();
try {
    $sameId = 4294968401;
    foreach ($branches as $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId]);
    }
    $links = $parents = [];
    foreach ($cases as [$type, $linkModel, $parentClass, $linkClass, $table, $parentColumn, $parentProperty]) {
        verify(EntityRegistry::discriminatedReferences($table)['items_id']['selections'] === $branches && count($branches) === 20, 'Same twenty core owning asset associations: ' . $type);
        $model = new $type();
        $parentTable = $model->getTable();
        $parent = $fixtures->create($parentTable, ['id' => 4294968402, 'name' => "Active O'Reilly", 'status' => $_SESSION['INCOMING'], 'priority' => 4]);
        $parents[$type] = $parent;
        foreach ($branches as $kind => $selection) {
            $link = new $linkModel();
            $id = $link->add([$parentColumn => $parent, 'itemtype' => $kind, 'items_id' => $sameId]);
            verify($id > 0 && (int)$link->fields[$selection['column']] === $sameId && (int)$link->fields['items_id'] === $sameId, 'Public owning asset link: ' . $type . '/' . $kind);
            $links[$type][$kind] = $id;
            $reject(static fn () => $connection->insert($table, [$parentColumn => $parent, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Native orphan rejected: ' . $type . '/' . $kind);
            $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Native asset purge restricted: ' . $type . '/' . $kind);
            $reject(static fn () => $connection->insert($table, [$parentColumn => $parent, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate parent/asset rejected: ' . $type . '/' . $kind);
        }
        foreach ([[], ['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
            $reject(static fn () => $connection->insert($table, $invalid + [$parentColumn => $parent]), 'Native missing/unknown/zero/wrong/multiple branch rejected: ' . $type, !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, $table . '_typed_item_kind');
        }
        $retargetParent = $fixtures->create($parentTable);
        $retarget = $fixtures->create($table, [$parentColumn => $retargetParent, 'itemtype' => 'Computer', 'items_id' => $sameId]);
        $changes = $storage->update($table, $retarget, ['itemtype' => 'Monitor', 'items_id' => $sameId]);
        verify($read($table, $retarget)['computers_id'] === null && $read($table, $retarget)['monitors_id'] === $sameId && in_array('items_id', $changes, true), 'Retarget clears previous branch and reports logical identity: ' . $type);
        $storage->update($table, $retarget, [$parentColumn => $retargetParent]);
        verify($read($table, $retarget)['items_id'] === $sameId, 'Unrelated partial update retains owning asset: ' . $type);
        foreach ([['items_id' => 0], ['itemtype' => 'UnknownPlugin'], ['items_id' => $sameId + 1, 'monitors_id' => $sameId]] as $invalid) {
            try {
                $storage->update($table, $retarget, $invalid);
                throw new RuntimeException('Invalid mapped asset accepted: ' . $type);
            } catch (InvalidArgumentException) {
            }
        }
        verify($storage->delete($table, $retarget), 'Retarget fixture cleanup retains the parent: ' . $type);
        $em = Orm::create($DB);
        $asset = new Record\Computer();
        $asset->entities = $em->getReference(Record\Entity::class, 0);
        $nativeParent = new $parentClass();
        $nativeParent->entities = $em->getReference(Record\Entity::class, 0);
        $nativeLink = new $linkClass();
        $nativeLink->$parentProperty = $nativeParent;
        $nativeLink->itemtype = 'Computer';
        $nativeLink->computer = $asset;
        foreach ([$asset, $nativeParent, $nativeLink] as $record) {
            $em->persist($record);
        }
        $em->flush();
        $em->refresh($nativeLink);
        verify($nativeLink->items_id === $asset->id && $nativeLink->$parentProperty->id === $nativeParent->id, 'Native ORM graph persists parents and asset together: ' . $type);
        verify((new $type())->delete(['id' => $nativeParent->id], true) && $read($table, $nativeLink->id) === null && $read('glpi_computers', $asset->id) !== null, 'Public parent purge removes link and retains asset: ' . $type);

        $finished = array_merge($model->getSolvedStatusArray(), $model->getClosedStatusArray());
        foreach ([['status' => $model->getSolvedStatusArray()[0]], ['status' => $model->getClosedStatusArray()[0]], ['status' => $_SESSION['INCOMING'], 'is_deleted' => true]] as $state) {
            $other = $fixtures->create($parentTable, $state);
            $fixtures->create($table, [$parentColumn => $other, 'itemtype' => 'Computer', 'items_id' => $sameId]);
        }
        $repo = new ITILAssetRepository(Orm::create($DB));
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $SQL_TOTAL_REQUEST = 0;
        foreach ($branches as $kind => $selection) {
            verify($repo->active($type, $kind, $sameId, $finished) === [['id' => $parent, 'name' => "Active O'Reilly", 'priority' => 4]], 'Active picker isolates owning kind and excludes finished/deleted objects: ' . $type . '/' . $kind);
        }
        $method = 'getActive' . $type . 'sForItem';
        $iterator = $model->$method('Computer', $sameId);
        verify($iterator instanceof \itsmng\Database\RowIterator && count($iterator) === 1 && $iterator->next()['id'] === $parent && count(iterator_to_array($iterator)) === 1, 'Public active picker retains count, next and foreach: ' . $type);
        verify(count($repo->active($type, 'Computer', $sameId, [])) === 3, 'No finished statuses retain nondeleted rows: ' . $type);
        verify($repo->active($type, 'UnknownPlugin', $sameId, $finished) === [] && $repo->active($type, 'Computer', 0, $finished) === [], 'Unsupported/empty asset does not select another kind: ' . $type);
        verify($SQL_TOTAL_REQUEST === 0, 'Active repository and public picker bypass adapter SQL: ' . $type);
    }
    foreach (['User', 'Group', 'Supplier'] as $actorType) {
        $actor = new $actorType();
        $actorId = $fixtures->create($actor->getTable());
        verify($actor->getFromDB($actorId), 'Actor fixture');
        foreach ($cases as [$type, $linkModel]) {
            $parentColumn = strtolower($type) . 's_id';
            $actorColumn = $actor->getForeignKeyField();
            $actorTable = $actorType === 'Group' && $type === 'Problem' ? 'glpi_groups_problems' : 'glpi_' . strtolower($type) . 's_' . strtolower($actorType) . 's';
            $fixtures->create($actorTable, [$parentColumn => $parents[$type], $actorColumn => $actorId, 'type' => CommonITILActor::REQUESTER]);
            $_SESSION['glpishow_count_on_tabs'] = true;
            $SQL_TOTAL_REQUEST = 0;
            $tab = (new $linkModel())->getTabNameForItem($actor);
            verify(str_contains($tab, '1') && $SQL_TOTAL_REQUEST === 0, 'Public actor tab count uses ORM: ' . $type . '/' . $actorType);
        }
    }
    foreach ($branches as $kind => $selection) {
        verify((new $kind())->delete(['id' => $sameId], true), 'Public asset purge: ' . $kind);
        foreach ($cases as [$type, , , , $table]) {
            verify($read($table, $links[$type][$kind]) === null && $read((new $type())->getTable(), $parents[$type]) !== null, 'Asset purge removes selected association and retains ITIL object: ' . $type . '/' . $kind);
        }
    }
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $configuration;
}

// Frozen upgrade checks reconstruct only these owned disposable fixture tables.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
foreach ($cases as [$type, , , , $table, $parentColumn]) {
    $computer = $fixtures->create('glpi_computers', ['id' => $type === 'Change' ? 950000168 : 950000169]);
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
echo "PASS: forty change/problem asset FKs, native/public writes, active pickers, actor tabs, purge and frozen upgrade\n";
