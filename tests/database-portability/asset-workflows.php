<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\NetworkConnectionRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/asset-workflows.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$originalConfig = $CFG_GLPI;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $childEntity = (new Entity())->add(['name' => 'Asset workflow child', 'entities_id' => 0]);
    verify((int)$childEntity > 0, 'Child entity created');
    foreach (['Printer', 'NetworkEquipment'] as $type) {
        $owner = new $type();
        $ownerId = $fixtures->create($owner::getTable(), ['entities_id' => 0, 'is_recursive' => true]);
        verify($owner->getFromDB($ownerId), 'Load recursive network owner');
        $allowed = $fixtures->create('glpi_networkequipments', ['entities_id' => 0]);
        $foreign = $fixtures->create('glpi_networkequipments', ['entities_id' => $childEntity, 'is_deleted' => true]);
        $port = static fn (string $itemtype, int $id): int => $fixtures->create('glpi_networkports', ['itemtype' => $itemtype, 'items_id' => $id]);
        $localPort = $port($type, $ownerId);
        $fixtures->create('glpi_networkports_networkports', ['networkports_id_1' => $localPort, 'networkports_id_2' => $port('NetworkEquipment', $allowed)]);
        verify(!NetworkPort::hasConnectionsOutsideEntities($type, $ownerId, [0]), 'Allowed peer permits non-recursive owner');
        verify($owner->canUnrecurs(), 'Public recursion check accepts allowed peer');
        $fixtures->create('glpi_networkports_networkports', ['networkports_id_1' => $port('NetworkEquipment', $foreign), 'networkports_id_2' => $localPort]);
        // Another port to the same asset must not multiply its identity.
        $fixtures->create('glpi_networkports_networkports', ['networkports_id_1' => $localPort, 'networkports_id_2' => $port('NetworkEquipment', $foreign)]);
        $em = Orm::create($DB);
        $peers = (new NetworkConnectionRepository($em))->peers($type, $ownerId);
        $em->clear();
        $ids = array_values($peers['NetworkEquipment']);
        sort($ids);
        $expected = [$allowed, $foreign];
        sort($expected);
        verify($ids === $expected, 'Both cable orientations yield distinct peer identities');
        verify(NetworkPort::hasConnectionsOutsideEntities($type, $ownerId, [0]), 'Foreign peer is detected among multiple IDs of one type');
        verify(!$owner->canUnrecurs(), 'Public recursion check rejects foreign peer, even when deleted');
        verify(!NetworkPort::hasConnectionsOutsideEntities($type, $ownerId, [0, $childEntity]), 'Expanded entity scope admits both peers');
        verify(!NetworkPort::hasConnectionsOutsideEntities($type, -1, [0]), 'Unconnected owner has no foreign peers');
    }

    $computerId = $fixtures->create('glpi_computers', ['name' => 'Propagation owner']);
    $computer = new Computer();
    verify($computer->getFromDB($computerId), 'Load propagation owner');
    $targets = [];
    foreach (['active', 'global', 'deleted'] as $kind) {
        $id = $fixtures->create('glpi_monitors', ['name' => $kind, 'contact' => 'Before', 'is_global' => $kind === 'global']);
        $fixtures->create('glpi_computers_items', ['computers_id' => $computerId, 'itemtype' => 'Monitor', 'items_id' => $id, 'is_deleted' => $kind === 'deleted']);
        $targets[$kind] = $id;
    }
    $fixtures->create('glpi_computers_items', ['computers_id' => $computerId, 'itemtype' => 'Monitor', 'items_id' => 2147483647]);
    $processor = $fixtures->create('glpi_items_deviceprocessors', ['itemtype' => 'Computer', 'items_id' => $computerId]);
    $deletedProcessor = $fixtures->create('glpi_items_deviceprocessors', ['itemtype' => 'Computer', 'items_id' => $computerId, 'is_deleted' => true]);
    $location = $fixtures->create('glpi_locations', ['name' => 'Propagated location']);
    $state = $fixtures->create('glpi_states', ['name' => 'Propagated state']);
    $CFG_GLPI['is_contact_autoupdate'] = 1;
    $CFG_GLPI['is_location_autoupdate'] = 1;
    $CFG_GLPI['state_autoupdate_mode'] = -1;
    $contact = "O'Reilly \\ workstation";
    verify($computer->update(Toolbox::addslashes_deep(['id' => $computerId, 'contact' => $contact, 'locations_id' => $location, 'states_id' => $state])), 'Propagate computer update');
    foreach ($targets as $kind => $id) {
        $monitor = new Monitor();
        verify($monitor->getFromDB($id), 'Reload propagation target');
        verify($monitor->fields['contact'] === ($kind === 'active' ? $contact : 'Before'), 'Propagation respects global and deleted-link exclusions');
        verify((int)$monitor->fields['locations_id'] === ($kind === 'active' ? $location : 0), 'Location follows the same exclusions');
    }
    $device = new Item_DeviceProcessor();
    verify($device->getFromDB($processor) && (int)$device->fields['locations_id'] === $location && (int)$device->fields['states_id'] === $state, 'Device association receives only state/location changes');
    verify($device->getFromDB($deletedProcessor) && (int)$device->fields['locations_id'] === 0, 'Deleted device association remains unchanged');
    verify($computer->update(['id' => $computerId, 'states_id' => 0, 'locations_id' => 0]), 'Clear computer state and location through legacy empty selections');
    $monitor = new Monitor();
    verify($monitor->getFromDB($targets['active']) && $monitor->fields['states_id'] === null, 'Clearing computer state propagates NULL to attached assets');
    verify($device->getFromDB($processor) && $device->fields['states_id'] === null, 'Clearing computer state propagates NULL to attached components');
    verify($computer->getFromDB($computerId) && $computer->fields['states_id'] === null, 'Computer state remains NULL');
    verify($monitor->fields['locations_id'] === null && $device->fields['locations_id'] === null && $computer->fields['locations_id'] === null, 'Clearing computer location propagates NULL to attached assets and components');
    $CFG_GLPI['is_contact_autoupdate'] = 0;
    verify($computer->update(['id' => $computerId, 'contact' => 'Disabled propagation']), 'Update with propagation disabled');
    $monitor = new Monitor();
    verify($monitor->getFromDB($targets['active']) && $monitor->fields['contact'] === $contact, 'Disabled propagation preserves the attached asset');

    verify(Computer_Item::canUnrecursSpecif($computer, [0]), 'Direct connections in the same entity permit recursion removal');
    $foreignMonitor = $fixtures->create('glpi_monitors', ['entities_id' => $childEntity]);
    $fixtures->create('glpi_computers_items', ['computers_id' => $computerId, 'itemtype' => 'Monitor', 'items_id' => $foreignMonitor]);
    verify(!Computer_Item::canUnrecursSpecif($computer, [0]), 'Computer recursion checks every attached ID of the same type');
    $foreignComputer = $fixtures->create('glpi_computers', ['entities_id' => $childEntity]);
    $fixtures->create('glpi_computers_items', ['computers_id' => $foreignComputer, 'itemtype' => 'Monitor', 'items_id' => $targets['global']]);
    $globalMonitor = new Monitor();
    verify($globalMonitor->getFromDB($targets['global']), 'Load shared monitor');
    verify(!Computer_Item::canUnrecursSpecif($globalMonitor, [0]), 'Reverse recursion checks every connected computer');
    verify(Computer_Item::canUnrecursSpecif($globalMonitor, [0, $childEntity]), 'Reverse recursion respects the complete allowed scope');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Computer_Item::canUnrecursSpecif($globalMonitor, [0]);
    NetworkPort::hasConnectionsOutsideEntities($type, $ownerId, [0]);
    verify($SQL_TOTAL_REQUEST === 0, 'Connection scope checks execute through ORM');

    $prefix = 'Imported printer ' . bin2hex(random_bytes(4));
    $name = $prefix . " O'Reilly \\ office";
    $printer = new Printer();
    $restorable = $fixtures->create('glpi_printers', ['name' => $name, 'entities_id' => $childEntity, 'is_deleted' => true]);
    verify((int)$printer->addOrRestoreFromTrash(addslashes($name), '', $childEntity) === $restorable, 'Restore exact entity printer with escaped import name');
    verify($printer->getFromDB($restorable) && !(bool)$printer->fields['is_deleted'], 'Printer is restored');
    $recursive = $fixtures->create('glpi_printers', ['name' => $prefix, 'entities_id' => 0, 'is_recursive' => true]);
    verify((int)$printer->addPrinter($prefix, '', $childEntity) === $recursive, 'Import reuses recursive ancestor');
    $privateName = $prefix . ' private';
    $private = $fixtures->create('glpi_printers', ['name' => $privateName, 'entities_id' => 0, 'is_recursive' => false]);
    $created = $printer->addPrinter($privateName, '', $childEntity);
    verify((int)$created > 0 && (int)$created !== $private && $printer->getFromDB($created) && (int)$printer->fields['entities_id'] === (int)$childEntity, 'Import does not reuse a hidden ancestor');
} finally {
    $CFG_GLPI = $originalConfig;
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped network peers, recursion scope, computer propagation and printer imports passed.\n";
