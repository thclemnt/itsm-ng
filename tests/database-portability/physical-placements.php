<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\PhysicalPlacements;
use itsmng\Database\Orm;
use itsmng\Database\Repository\PlacementRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/physical-placements.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
require __DIR__ . '/FixtureRecords.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
$migration = new PhysicalPlacements();
$migration->apply($connection);
$DB->clearSchemaCache();
foreach ($migration->plan($connection) as $entry) {
    verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Idempotent placement migration');
}
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$reject = static function (callable $operation, string $message, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $failed = in_array($error->getSQLState(), ['23502', '23503', '23514', '23505', '23001', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$tables = ['glpi_items_racks', 'glpi_items_enclosures'];
$branches = EntityRegistry::discriminatedReferences($tables[0])['items_id']['selections'];
foreach ($tables as $table) {
    verify(EntityRegistry::discriminatedReferences($table)['items_id']['selections'] === $branches
        && array_keys($branches) === $CFG_GLPI['rackable_types'], 'All core rackable kinds have owning associations in both placement tables');
}
$DB->beginTransaction();
try {
    $sameId = 900000281;
    foreach ($branches as $kind => $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId, 'name' => 'Placed ' . $kind]);
    }
    $rack = $fixtures->create('glpi_racks', ['name' => 'Typed placement rack', 'number_units' => 42]);
    $enclosure = $fixtures->create('glpi_enclosures', ['name' => 'Typed placement container']);
    $links = [];
    foreach ($tables as $table) {
        $rackTable = $table === 'glpi_items_racks';
        $parentColumn = $rackTable ? 'racks_id' : 'enclosures_id';
        $parent = $rackTable ? $rack : $enclosure;
        $model = $rackTable ? new Item_Rack() : new Item_Enclosure();
        foreach ($branches as $kind => $selection) {
            $id = $model->add([$parentColumn => $parent, 'itemtype' => $kind, 'items_id' => $sameId, 'position' => 1 + count($links[$table] ?? []) * 3]);
            verify($id > 0, 'Public placement write: ' . $table . ' ' . $kind);
            $links[$table][$kind] = $id;
            $row = $read($table, $id);
            verify((int)$row[$selection['column']] === $sameId && (int)$row['items_id'] === $sameId
                && (int)$row[$parentColumn] === $parent, 'Selected asset is separate from its container');
            foreach ($branches as $other => $branch) {
                if ($other !== $kind) {
                    verify($row[$branch['column']] === null, 'Unselected asset branches are NULL');
                }
            }
            $reject(fn () => $connection->insert($table, [$parentColumn => $parent, 'position' => 40, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Placement FK accepted an orphan');
            $reject(fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Placement FK allowed its asset to disappear');
            $reject(fn () => $connection->insert($table, [$parentColumn => $parent, 'position' => 40, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Placement uniqueness accepted a duplicate');
        }
        foreach ([['itemtype' => null, 'asset_computers_id' => $sameId], ['itemtype' => 'UnknownPlugin', 'asset_computers_id' => $sameId],
            ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'asset_monitors_id' => $sameId],
            ['itemtype' => 'Computer', 'asset_computers_id' => 0],
            ['itemtype' => 'Computer', 'asset_computers_id' => $sameId, 'asset_monitors_id' => $sameId]] as $invalid) {
            $reject(fn () => $connection->insert($table, [$parentColumn => $parent, 'position' => 40] + $invalid), 'Required placement kind and exactly-one branch', $table . '_typed_item_kind');
        }
        $extraMonitor = $fixtures->create('glpi_monitors', ['name' => 'Retarget placement monitor']);
        $changes = $storage->update($table, $links[$table]['Computer'], ['itemtype' => 'Monitor', 'items_id' => $extraMonitor]);
        verify($read($table, $links[$table]['Computer'])['asset_computers_id'] === null
            && (int)$read($table, $links[$table]['Computer'])['asset_monitors_id'] === $extraMonitor
            && in_array('items_id', $changes, true), 'Retarget clears the old branch and reports logical identity');
        $storage->update($table, $links[$table]['Computer'], ['itemtype' => 'Computer', 'asset_computers_id' => $sameId]);
        $storage->update($table, $links[$table]['Computer'], ['position' => 2]);
        verify((int)$read($table, $links[$table]['Computer'])['items_id'] === $sameId, 'Partial geometry update preserves its asset');

        $em = Orm::create($DB);
        $asset = new Record\Computer();
        $asset->name = 'Native ' . $table;
        $asset->entities = $em->getReference(Record\Entity::class, 0);
        $native = $rackTable ? new Record\ItemRack() : new Record\ItemEnclosure();
        if ($rackTable) {
            $native->racks = $em->getReference(Record\Rack::class, $rack);
        } else {
            $native->enclosures = $em->getReference(Record\Enclosure::class, $enclosure);
        }
        $native->position = 30;
        $native->itemtype = 'Computer';
        $native->assetComputer = $asset;
        $em->persist($asset);
        $em->persist($native);
        $em->flush();
        $em->refresh($native);
        verify($native->items_id === $asset->id, 'Native asset and placement persist in one unit of work');
        $native->assetMonitor = $em->getReference(Record\Monitor::class, $sameId);
        try {
            $em->flush();
            throw new RuntimeException('Native invalid placement update accepted');
        } catch (InvalidArgumentException) {
        }
        $em->clear();
    }
    $reservation = $fixtures->create($tables[0], ['racks_id' => $rack, 'itemtype' => 'Computer', 'items_id' => $sameId, 'position' => 40, 'is_reserved' => true]);
    $repository = new PlacementRepository(Orm::create($DB));
    verify($repository->rackSelection()['reserved']['Computer'] === [$sameId], 'Rack reservation remains separate from installed placement');
    verify(in_array($sameId, $repository->pduSelection(), true), 'PDU selection follows its owning association');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->rackSelection();
    $repository->enclosureSelection();
    $repository->pduSelection();
    verify($SQL_TOTAL_REQUEST === 0, 'Placement selectors bypass adapter execution');
    foreach ($branches as $kind => $selection) {
        verify((new $kind())->delete(['id' => $sameId], true), 'Public placed asset purge: ' . $kind);
        foreach ($tables as $table) {
            verify($read($table, $links[$table][$kind]) === null, 'Asset purge removes its placement');
            foreach (array_slice(array_keys($branches), array_search($kind, array_keys($branches)) + 1) as $otherKind) {
                verify($read($table, $links[$table][$otherKind]) !== null, 'Purge preserves other kinds with overlapping IDs');
            }
        }
    }
    verify($read($tables[0], $reservation) === null, 'Asset purge also removes its reserved placement');
    verify((new Rack())->delete(['id' => $rack], true) && (new Enclosure())->delete(['id' => $enclosure], true), 'Public container purge removes remaining native placements');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references after public placement lifecycle');
} finally {
    $DB->rollBack();
}

// Rebuild only these disposable tables' old identities, including unique indexes.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
// Earlier wide-ID contracts can advance MariaDB's sequence beyond this old INT key.
$computer = $fixtures->create('glpi_computers', ['id' => 950000091, 'name' => 'Upgrade placed computer']);
$rack = $fixtures->create('glpi_racks', ['name' => 'Upgrade placement rack', 'number_units' => 42]);
$enclosure = $fixtures->create('glpi_enclosures', ['name' => 'Upgrade placement enclosure']);
$legacyIds = $indexes = [];
$columns = array_column($branches, 'column');
try {
    foreach ($tables as $table) {
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
        $before = $manager->introspectTable($table);
        $indexes[$table] = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
        $parentColumn = $table === $tables[0] ? 'racks_id' : 'enclosures_id';
        // MariaDB needs another supporting index while the composite identity
        // index is temporarily removed. Restore the original topology afterward.
        $connection->executeStatement('CREATE INDEX port_placement_container ON ' . $table . ' (' . $parentColumn . ')');
        foreach ($indexes[$table] as $index) {
            $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
        $before = $manager->introspectTable($table);
        $legacy = clone $before;
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
        foreach ($indexes[$table] as $index) {
            if ($index->isUnique()) {
                $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
            } else {
                $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
            }
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->executeStatement($platform->getDropIndexSQL('port_placement_container', $table));
        $values = $table === $tables[0] ? ['racks_id' => $rack, 'is_reserved' => true, 'hpos' => Rack::POS_LEFT, 'orientation' => Rack::REAR, 'bgcolor' => '#aabbcc'] : ['enclosures_id' => $enclosure];
        $connection->insert($table, $values + ['itemtype' => 'Computer', 'items_id' => $computer, 'position' => 12], ['is_reserved' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
        $legacyIds[$table] = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
    }
    foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0]] as $invalid) {
        $table = $tables[1];
        $connection->insert($table, ['enclosures_id' => $enclosure, 'position' => 20] + $invalid);
        $badId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        try {
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($tables[0])->hasColumn('asset_computers_id')
                && !$manager->introspectTable($tables[1])->hasColumn('asset_computers_id'), 'Both tables are audited before any placement DDL');
        } finally {
            $connection->delete($table, ['id' => $badId]);
        }
    }
    $table = $tables[1];
    $connection->executeStatement('ALTER TABLE ' . $table . ' ADD asset_computers_id BIGINT NULL');
    $connection->update($table, ['asset_computers_id' => $computer + 1], ['id' => $legacyIds[$table]]);
    try {
        $migration->apply($connection);
        throw new RuntimeException('Conflicting placement association was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'disagree') && !$manager->introspectTable($tables[0])->hasColumn('asset_computers_id'), 'Canonical conflict refuses before either table changes');
    }
    $connection->update($table, ['asset_computers_id' => $computer], ['id' => $legacyIds[$table]]);
    $connection->executeStatement('CREATE UNIQUE INDEX port_placement_key ON ' . $table . ' (items_id)');
    $connection->executeStatement('CREATE TABLE port_placement_dependency (asset_id INTEGER NOT NULL, CONSTRAINT port_placement_fk FOREIGN KEY (asset_id) REFERENCES ' . $table . ' (items_id))');
    try {
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Incoming typed');
        }
        verify($failed && !$manager->introspectTable($tables[0])->hasColumn('asset_computers_id'), 'Incoming legacy dependencies refuse before either table changes');
    } finally {
        $connection->executeStatement('DROP TABLE port_placement_dependency');
        $connection->executeStatement($platform->getDropIndexSQL('port_placement_key', $table));
    }
    $migration->apply($connection);
    $DB->clearSchemaCache();
    foreach ($tables as $table) {
        $row = $read($table, $legacyIds[$table]);
        verify((int)$row['asset_computers_id'] === $computer && (int)$row['items_id'] === $computer && (int)$row['position'] === 12, 'Upgrade preserves subject identity and geometry');
        if ($table === $tables[0]) {
            verify((int)$row['racks_id'] === $rack && $row['is_reserved'] && (int)$row['hpos'] === Rack::POS_LEFT
                && (int)$row['orientation'] === Rack::REAR && $row['bgcolor'] === '#aabbcc', 'Upgrade preserves rack reservation and rendering state');
        } else {
            verify((int)$row['enclosures_id'] === $enclosure, 'Upgrade preserves the enclosure container');
        }
        foreach ($indexes[$table] as $index) {
            $after = $manager->introspectTable($table)->getIndex($index->getName());
            verify($after->isUnique() === $index->isUnique() && $after->getColumns() === $index->getColumns(), 'Upgrade preserves selection indexes and their uniqueness');
        }
    }
    foreach ($migration->apply($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Placement upgrade retry is idempotent');
    }
    echo $DB->getProvider() . ": fourteen placement asset FKs, exact selection, native/public lifecycle, overlapping IDs, reservations and audited upgrade passed.\n";
} finally {
    foreach ($legacyIds as $table => $id) {
        $connection->delete($table, ['id' => $id]);
    }
    // A rejected upgrade must not leave disposable legacy tables for later contracts.
    $migration->apply($connection);
    $DB->clearSchemaCache();
    $connection->delete('glpi_computers', ['id' => $computer]);
    $connection->delete('glpi_racks', ['id' => $rack]);
    $connection->delete('glpi_enclosures', ['id' => $enclosure]);
}
