<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\TicketAssets;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\TicketAssetRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/ticket-assets.php /path/to/test-config\n");
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
$migration = new TicketAssets();
$migration->apply($connection);
$DB->clearSchemaCache();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$originalConfiguration = $CFG_GLPI;
$originalTimezone = date_default_timezone_get();
$DB->setTimezone('Europe/Paris');
$CFG_GLPI['use_notifications'] = false;
$CFG_GLPI['keep_tickets_on_delete'] = true;
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$table = 'glpi_items_tickets';
$branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
$expectedKinds = $CFG_GLPI['ticket_types'];
$actualKinds = array_keys($branches);
sort($expectedKinds);
sort($actualKinds);
verify($actualKinds === $expectedKinds && count($branches) === 20, 'Every core ticket asset has an owning association');
verify(TicketAssetRepository::supports('Item_DeviceSimcard') && !TicketAssetRepository::supports('User'), 'Ticket asset support comes from local associations');
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
$DB->beginTransaction();
try {
    $sameId = 4294968301;
    $ticketId = $fixtures->create('glpi_tickets', ['id' => 4294968302, 'name' => "Asset O'Reilly", 'status' => $_SESSION['INCOMING'], 'type' => Ticket::INCIDENT_TYPE, 'priority' => 4]);
    $links = [];
    $ticket = new Ticket();
    foreach ($branches as $kind => $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId]);
        $model = new Item_Ticket();
        $links[$kind] = $model->add(['tickets_id' => $ticketId, 'itemtype' => $kind, 'items_id' => $sameId, '_do_notif' => false]);
        verify($links[$kind] > 0 && (int)$model->fields[$selection['column']] === $sameId && (int)$model->fields['items_id'] === $sameId, 'Public link stores its canonical wide asset: ' . $kind);
        verify($ticket->countActiveTicketsForItem($kind, $sameId) === 1, 'Public active count isolates overlapping kinds: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['tickets_id' => $ticketId, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Native asset orphan rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Native asset deletion restricted: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['tickets_id' => $ticketId, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate ticket/asset link rejected: ' . $kind);
    }
    foreach ([[], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'computers_id' => 0],
        ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert($table, $invalid + ['tickets_id' => $ticketId]), 'Native missing/unknown/zero/wrong/multiple selection rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, 'glpi_items_tickets_typed_item_kind');
    }
    $retargetTicket = $fixtures->create('glpi_tickets');
    $retarget = $fixtures->create($table, ['tickets_id' => $retargetTicket, 'itemtype' => 'Computer', 'items_id' => $sameId]);
    $changes = $storage->update($table, $retarget, ['itemtype' => 'Monitor', 'items_id' => $sameId]);
    verify($read($table, $retarget)['computers_id'] === null && $read($table, $retarget)['monitors_id'] === $sameId && in_array('items_id', $changes, true), 'Mapped retarget clears prior branch and reports logical identity');
    $storage->update($table, $retarget, ['tickets_id' => $retargetTicket]);
    verify($read($table, $retarget)['items_id'] === $sameId, 'Unrelated partial update preserves selected asset');
    foreach ([['items_id' => 0], ['items_id' => $sameId + 1, 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        try {
            $storage->update($table, $retarget, $invalid);
            throw new RuntimeException('Invalid mapped ticket asset accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $em = Orm::create($DB);
    $nativeAsset = new Record\Computer();
    $nativeAsset->entities = $em->getReference(Record\Entity::class, 0);
    $nativeTicket = new Record\Ticket();
    $nativeTicket->entities = $em->getReference(Record\Entity::class, 0);
    $nativeLink = new Record\ItemTicket();
    $nativeLink->itemtype = 'Computer';
    $nativeLink->computer = $nativeAsset;
    $nativeLink->tickets = $nativeTicket;
    foreach ([$nativeAsset, $nativeTicket, $nativeLink] as $record) {
        $em->persist($record);
    }
    $em->flush();
    $em->refresh($nativeLink);
    verify($nativeLink->items_id === $nativeAsset->id && $nativeLink->tickets->id === $nativeTicket->id, 'Native ticket, asset and link persist in one unit of work');

    $bind = static fn (int $id): int => $fixtures->create($table, ['tickets_id' => $id, 'itemtype' => 'Computer', 'items_id' => $sameId]);
    $new = static function (array $values = []) use ($fixtures, $bind): int {
        $id = $fixtures->create('glpi_tickets', $values + ['status' => $_SESSION['INCOMING'], 'type' => Ticket::INCIDENT_TYPE, 'name' => 'Boundary', 'priority' => 4]);
        $bind($id);
        return $id;
    };
    $solved = $new(['status' => $_SESSION['SOLVED'], 'solvedate' => '2030-03-31 00:30:01']);
    $closed = $new(['status' => $_SESSION['CLOSED'], 'solvedate' => '2030-03-31 00:30:01']);
    $exact = $new(['status' => $_SESSION['SOLVED'], 'solvedate' => '2030-03-31 00:30:00']);
    $null = $new(['status' => $_SESSION['SOLVED']]);
    $deleted = $new(['is_deleted' => true]);
    $request = $new(['type' => Ticket::DEMAND_TYPE]);
    $finished = [$_SESSION['SOLVED'], $_SESSION['CLOSED']];
    $now = new DateTimeImmutable('2030-04-01 00:30:00');
    $repo = new TicketAssetRepository(Orm::create($DB));
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $ids = array_keys($repo->activeOrRecent('Computer', $sameId, $finished, 1, $now));
    sort($ids, SORT_NUMERIC);
    $expected = [$ticketId, $solved, $closed, $deleted, $request];
    sort($expected, SORT_NUMERIC);
    verify($ids === $expected, 'Active-or-recent includes active/deleted rows and strictly recent finished rows across spring clock change');
    verify($repo->recentlyFinishedCount('Computer', $sameId, $finished, 1, $now) === 2, 'Recently finished excludes null and exact solvedate boundaries');
    verify($repo->recentlyFinishedCount('Computer', $sameId, [], 1, $now) === 0, 'No finished statuses produce no solved count');
    verify(count($repo->activeOrRecent('Computer', $sameId, [], 1, $now)) === 7, 'No finished statuses leave all rows active');
    verify($ticket->countActiveTicketsForItem('Computer', $sameId) === 3, 'Historical active count includes soft-deleted active tickets');
    $rows = $repo->active('Computer', $sameId, $finished, Ticket::INCIDENT_TYPE);
    verify(count($rows) === 1 && $rows[0]['id'] === $ticketId && $rows[0]['priority'] === 4 && !in_array($deleted, array_column($rows, 'id'), true), 'Active picker applies ticket type and deletion filters');
    $iterator = $ticket->getActiveTicketsForItem('Computer', $sameId, Ticket::INCIDENT_TYPE);
    verify($iterator instanceof \itsmng\Database\RowIterator && count($iterator) === 1 && $iterator->next() !== null && count(iterator_to_array($iterator)) === 1, 'Public active picker retains count, next and foreach iteration without a driver iterator');
    verify($ticket->countSolvedTicketsForItemLastDays('Computer', $sameId, 10000) === 3, 'Public recent-finished count uses database time');
    verify(count($ticket->getActiveOrSolvedLastDaysTicketsForItem('Computer', $sameId, 10000)) === 6, 'Public active-or-recent returns scalar identifier/name map');
    verify($repo->active('UnknownPlugin', $sameId, $finished, 1) === [] && $repo->activeCount('Computer', 0, $finished) === 0, 'Unsupported kinds and empty IDs cannot select another asset');
    $fixtures->create('glpi_ticketcosts', ['tickets_id' => $ticketId, 'actiontime' => 3600, 'cost_time' => '12.0000', 'cost_fixed' => '20.0000', 'cost_material' => '3.0000']);
    $fixtures->create('glpi_ticketcosts', ['tickets_id' => $ticketId, 'cost_fixed' => '5.0000', 'cost_material' => '-1.0000']);
    $fixtures->create('glpi_ticketcosts', ['tickets_id' => $ticketId, 'cost_fixed' => '-10.0000']);
    $fixtures->create('glpi_ticketcosts', ['tickets_id' => $request]);
    $asset = new Computer();
    verify($asset->getFromDB($sameId) && (float)Ticket::computeTco($asset) === 39.0, 'Public TCO preserves individual cost eligibility and currency calculation without duplicate link joins');
    $transfers = $repo->transferRows('Computer', $sameId);
    verify(count($transfers) === 7 && count(array_unique(array_column($transfers, '_relid'))) === 7, 'Transfer snapshots preserve full ticket rows and relation identifiers');
    verify($SQL_TOTAL_REQUEST === 0, 'Asset lookup, counters, picker, costs and transfer repository bypass adapter SQL');

    $copyAsset = $fixtures->create('glpi_computers');
    $transferTicket = $fixtures->create('glpi_tickets', ['status' => $_SESSION['INCOMING']]);
    $transferLink = $fixtures->create($table, ['tickets_id' => $transferTicket, 'itemtype' => 'Computer', 'items_id' => $copyAsset]);
    $destination = $fixtures->create('glpi_entities', ['name' => 'Ticket transfer']);
    $_SESSION['glpiactiveentities'][] = $destination;
    $targetAsset = $fixtures->create('glpi_computers', ['entities_id' => $destination]);
    $transfer = new class () extends Transfer {
        public function transferTaskCategory($itemtype, $ID, $newID)
        {
            // Other task-category transfer stages have their own contract.
        }
    };
    $transfer->to = $destination;
    $transfer->options = ['keep_ticket' => 2];
    $transfer->transferTickets('Computer', $copyAsset, $targetAsset);
    verify($read($table, $transferLink)['computers_id'] === $targetAsset && $read('glpi_tickets', $transferTicket)['entities_id'] === $destination, 'Public transfer retargets owning asset and updates ticket through model hooks');
    $transfer->options = ['keep_ticket' => 1];
    $transfer->transferTickets('Computer', $targetAsset, $targetAsset);
    verify($read($table, $transferLink) === null && $read('glpi_tickets', $transferTicket) !== null, 'Public keep-ticket transfer removes the selected relation by its actual alias');
    foreach ($branches as $kind => $selection) {
        verify((new $kind())->delete(['id' => $sameId], true), 'Public ticket asset purge: ' . $kind);
        verify($read($table, $links[$kind]) === null && $read('glpi_tickets', $ticketId) !== null, 'Asset purge clears its link and retains shared ticket: ' . $kind);
        foreach (array_slice(array_keys($branches), array_search($kind, array_keys($branches)) + 1) as $otherKind) {
            verify($read($table, $links[$otherKind]) !== null, 'Asset purge preserves overlapping IDs of another kind');
        }
    }
    verify((new Ticket())->delete(['id' => $nativeTicket->id], true) && $read($table, $nativeLink->id) === null && $read('glpi_computers', $nativeAsset->id) !== null, 'Ticket purge clears its link and retains the asset');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned asset references');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $originalConfiguration;
    $DB->setTimezone($originalTimezone);
}

// Reconstruct legacy asset IDs and indexes only in this owned disposable fixture.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$computer = $fixtures->create('glpi_computers', ['id' => 950000158]);
$legacyTicket = $fixtures->create('glpi_tickets');
$columns = array_column($branches, 'column');
$legacyId = null;
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
        if ($index->isUnique()) {
            $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
        } else {
            $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
        }
    }
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->insert($table, ['tickets_id' => $legacyTicket, 'itemtype' => 'Computer', 'items_id' => $computer]);
    $legacyId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
    foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0]] as $bad) {
        $connection->insert($table, $bad + ['tickets_id' => $legacyTicket]);
        $badId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Invalid legacy references refuse before DDL');
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
    verify($failed && !$manager->introspectTable($table)->hasColumn('monitors_id'), 'Conflicting canonical and legacy references refuse before DDL');
    $connection->update($table, ['computers_id' => $computer], ['id' => $legacyId]);
    $migration->apply($connection);
    $DB->clearSchemaCache();
    $row = $read($table, $legacyId);
    verify($row['computers_id'] === $computer && $row['items_id'] === $computer && $row['tickets_id'] === $legacyTicket, 'Upgrade retains ticket, asset and relation identities');
    foreach ($indexes as $index) {
        $actual = $manager->introspectTable($table)->getIndex($index->getName());
        verify($actual->isUnique() === $index->isUnique(), 'Upgrade preserves lookup-index uniqueness');
    }
    foreach ($migration->apply($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry is idempotent');
    }
    echo "PASS: twenty ticket asset FKs, native/public writes, ORM queries, cost/transfer/purge hooks and frozen upgrade\n";
} finally {
    if ($legacyId !== null) {
        $connection->delete($table, ['id' => $legacyId]);
    }
    $connection->delete('glpi_tickets', ['id' => $legacyTicket]);
    $connection->delete('glpi_computers', ['id' => $computer]);
}
