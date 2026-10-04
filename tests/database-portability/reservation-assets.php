<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ReservationAssets;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\ReservationItemRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/reservation-assets.php /path/to/test-config\n");
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
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_reservationitems'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $migration = new ReservationAssets();
    $migration->apply($connection);
    $DB->clearSchemaCache();
    foreach ($migration->plan($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Idempotent reservable asset migration');
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
    $table = 'glpi_reservationitems';
    $branches = \itsmng\Database\EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
    verify(array_keys($branches) === $CFG_GLPI['reservation_types'] && count($branches) === 7, 'Every core reservable type has an owning association');
    verify(!ReservationItemRepository::supports('Rack') && ReservationItemRepository::supports('Computer'), 'Mapped table alone does not make an asset reservable');
    $DB->beginTransaction();
    try {
        $sameId = 900000081;
        $links = $bookings = [];
        $repository = new ReservationItemRepository(Orm::create($DB));
        foreach ($branches as $kind => $selection) {
            $fixtures->create($selection['target'], ['id' => $sameId, 'name' => 'Reservable ' . $kind]);
            $item = new ReservationItem();
            $id = $item->add(['itemtype' => $kind, 'items_id' => $sameId, 'comment' => 'Typed ' . $kind]);
            verify($id > 0 && (int)$item->fields[$selection['column']] === $sameId && (int)$item->fields['items_id'] === $sameId, 'Public legacy write selects the owning asset: ' . $kind);
            $links[$kind] = $id;
            $records = new RecordRepository(Orm::create($DB));
            verify($records->countMatching($table, ['itemtype' => $kind, 'items_id' => $sameId]) === 1, 'Legacy criteria isolate overlapping asset IDs');
            verify((new ReservationItem())->getFromDBbyItem($kind, $sameId), 'Public typed asset lookup');
            $available = $repository->available($kind, 'name', ['entities_id' => 0], null, null);
            verify(array_map('intval', array_column($available, 'id')) === [$id] && $available[0]['name'] === 'Reservable ' . $kind, 'Availability follows the selected owning asset');
            $bookings[$kind] = $fixtures->create('glpi_reservations', ['reservationitems_id' => $id, 'begin' => '2026-10-01 10:00:00', 'end' => '2026-10-01 11:00:00']);
            verify($repository->available($kind, 'name', ['entities_id' => 0], '2026-10-01 10:30:00', '2026-10-01 10:45:00') === [], 'Occupied asset excluded');
            verify(array_map('intval', array_column($repository->available($kind, 'name', ['entities_id' => 0], '2026-10-01 11:00:00', '2026-10-01 12:00:00'), 'id')) === [$id], 'Adjacent booking remains available');
            $reject(fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => 999999999]), 'FK rejects unknown asset');
            $reject(fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'FK rejects asset deletion outside lifecycle');
        }
        foreach ([['itemtype' => null, 'computers_id' => $sameId], ['itemtype' => 'UnknownPlugin', 'computers_id' => $sameId],
            ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => 0],
            ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
            $reject(fn () => $connection->insert($table, $invalid), 'Required asset kind and exactly-one branch', 'glpi_reservationitems_typed_item_kind');
        }
        $id = $links['Computer'];
        $changes = $storage->update($table, $id, ['itemtype' => 'Monitor', 'items_id' => $sameId]);
        $row = $read($table, $id);
        verify($row['computers_id'] === null && (int)$row['monitors_id'] === $sameId && in_array('items_id', $changes, true), 'Retarget clears old branch and reports logical identity');
        $storage->update($table, $id, ['itemtype' => 'Computer', 'computers_id' => $sameId]);
        $storage->update($table, $id, ['comment' => 'Updated description']);
        verify((int)$read($table, $id)['items_id'] === $sameId && $read($table, $id)['monitors_id'] === null, 'Partial updates retain the selected asset');
        foreach ([['itemtype' => 'UnknownPlugin'], ['items_id' => 0], ['computers_id' => $sameId, 'items_id' => $sameId + 1],
            ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
            try {
                $storage->update($table, $id, $invalid);
                throw new RuntimeException('Invalid mapped asset selection accepted');
            } catch (InvalidArgumentException) {
            }
        }
        $em = Orm::create($DB);
        $nativeAsset = new Record\Computer();
        $nativeAsset->name = 'Native reservable computer';
        $nativeAsset->entities = $em->getReference(Record\Entity::class, 0);
        $nativeItem = new Record\ReservationItem();
        $nativeItem->itemtype = 'Computer';
        $nativeItem->computer = $nativeAsset;
        $nativeItem->entities = $em->getReference(Record\Entity::class, 0);
        $em->persist($nativeAsset);
        $em->persist($nativeItem);
        $em->flush();
        $em->refresh($nativeItem);
        verify($nativeItem->items_id === $nativeAsset->id, 'Native asset and association persist in one unit of work');
        $invalidEm = Orm::create($DB);
        $invalid = new Record\ReservationItem();
        $invalid->itemtype = 'Monitor';
        $invalid->computer = $invalidEm->getReference(Record\Computer::class, $sameId);
        $invalid->entities = $invalidEm->getReference(Record\Entity::class, 0);
        try {
            $invalidEm->persist($invalid);
            $invalidEm->flush();
            throw new RuntimeException('Native mismatched asset selection accepted');
        } catch (InvalidArgumentException) {
        }
        $copyAsset = $fixtures->create('glpi_computers', ['name' => 'Copied reservable computer']);
        $storage->update($table, $links['Computer'], ['is_active' => false]);
        $transfer = new Transfer();
        $transfer->options = ['keep_reservation' => 1];
        $transfer->transferReservations('Computer', $sameId, $copyAsset);
        $copied = new ReservationItem();
        verify($copied->getFromDBbyItem('Computer', $copyAsset), 'Public transfer makes the copied asset reservable');
        verify((int)$copied->fields['computers_id'] === $copyAsset && !$copied->fields['is_active'], 'Transfer selects the copied association and preserves availability state');
        verify($read('glpi_reservations', $bookings['Computer']) !== null, 'Transfer copy preserves source bookings');
        $transfer->options = ['keep_reservation' => 0];
        $transfer->transferReservations('Computer', $copyAsset, $copyAsset);
        verify($read($table, (int)$copied->getID()) === null && $read('glpi_computers', $copyAsset) !== null, 'Transfer discard removes the reservable entry and preserves its asset');
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $CFG_GLPI['debug_sql'] = true;
        $DEBUG_SQL = [];
        $SQL_TOTAL_REQUEST = 0;
        $repository->types([0]);
        $repository->peripheralTypes([0]);
        foreach (array_keys($branches) as $kind) {
            $repository->available($kind, 'name', ['entities_id' => 0], null, null);
        }
        verify($SQL_TOTAL_REQUEST === 0, 'Reservable asset queries bypass adapter SQL');
        foreach ($branches as $kind => $selection) {
            verify((new $kind())->delete(['id' => $sameId], true), 'Public reservable asset purge: ' . $kind);
            verify($read($table, $links[$kind]) === null && $read('glpi_reservations', $bookings[$kind]) === null, 'Asset purge removes its reservable entry and bookings');
            foreach (array_slice(array_keys($branches), array_search($kind, array_keys($branches)) + 1) as $otherKind) {
                verify($read($table, $links[$otherKind]) !== null, 'Purge preserves overlapping IDs of other asset kinds');
            }
        }
        verify((new ReservationItem())->delete(['id' => $nativeItem->id], true), 'Public reservable entry purge');
        verify($read('glpi_computers', $nativeAsset->id) !== null, 'Purging a reservable entry preserves its asset');
        verify((new ForeignKeys())->audit($connection) === [], 'No orphaned associations');
    } finally {
        $DB->rollBack();
    }

    // Reconstruct the old schema only in this disposable database, with its original indexes.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    $computer = $fixtures->create('glpi_computers', ['id' => 950000155, 'name' => 'Upgrade reservable computer']);
    $columns = array_column($branches, 'column');
    $legacyId = null;
    $legacyBooking = null;
    try {
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
        $before = $manager->introspectTable($table);
        $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
        foreach ($indexes as $index) {
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
        foreach ($indexes as $index) {
            $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert($table, ['itemtype' => 'Computer', 'items_id' => $computer, 'comment' => 'Preserved upgrade description', 'is_active' => false], ['is_active' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
        $legacyId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        $legacyBooking = $fixtures->create('glpi_reservations', ['reservationitems_id' => $legacyId, 'begin' => '2026-10-01 10:00:00', 'end' => '2026-10-01 11:00:00']);
        foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0]] as $bad) {
            $connection->insert($table, $bad);
            $badId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Invalid data refuses before schema changes');
            $connection->delete($table, ['id' => $badId]);
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD computers_id BIGINT NULL');
        $connection->update($table, ['computers_id' => $computer + 1], ['id' => $legacyId]);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'disagree');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('monitors_id'), 'Conflicting canonical data refuses before schema changes');
        $connection->update($table, ['computers_id' => $computer], ['id' => $legacyId]);
        $connection->executeStatement('CREATE UNIQUE INDEX port_reservable_key ON ' . $table . ' (items_id)');
        $connection->executeStatement('CREATE TABLE port_reservable_dependency (asset_id INTEGER NOT NULL, CONSTRAINT port_reservable_fk FOREIGN KEY (asset_id) REFERENCES ' . $table . ' (items_id))');
        try {
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Incoming typed');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('monitors_id'), 'Custom dependencies on the old identity refuse before DDL');
        } finally {
            $connection->executeStatement('DROP TABLE port_reservable_dependency');
            $connection->executeStatement($platform->getDropIndexSQL('port_reservable_key', $table));
        }
        $migration->apply($connection);
        $DB->clearSchemaCache();
        $row = $read($table, $legacyId);
        verify((int)$row['computers_id'] === $computer && (int)$row['items_id'] === $computer && $row['comment'] === 'Preserved upgrade description' && !$row['is_active'], 'Upgrade preserves asset identity and item state');
        verify((int)$read('glpi_reservations', $legacyBooking)['reservationitems_id'] === $legacyId, 'Upgrade preserves existing booking identity and its reservable item');
        foreach ($indexes as $index) {
            verify($manager->introspectTable($table)->hasIndex($index->getName()), 'Upgrade preserves legacy selection indexes');
        }
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry is idempotent');
        }
        echo $DB->getProvider() . ": seven reservable asset FKs, exact selection, native/public writes, availability, purge and frozen upgrade passed.\n";
    } finally {
        if ($legacyBooking !== null) {
            $connection->delete('glpi_reservations', ['id' => $legacyBooking]);
        }
        if ($legacyId !== null) {
            $connection->delete($table, ['id' => $legacyId]);
        }
        $connection->delete('glpi_computers', ['id' => $computer]);
    }

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
