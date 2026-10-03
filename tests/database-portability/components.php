<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/components.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $assetId = $fixtures->create('glpi_computers', ['name' => 'Component asset']);
    $otherEntity = (new Entity())->add(['name' => 'Component foreign entity', 'entities_id' => 0]);
    verify((bool)$otherEntity, 'Create foreign entity');
    $otherId = $fixtures->create('glpi_computers', ['name' => 'Other asset', 'entities_id' => $otherEntity]);
    $asset = new Computer();
    verify($asset->getFromDB($assetId), 'Load asset');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [0];
    $tested = 0;
    foreach (\itsmng\Database\ForeignKeys::relations() as $table => $relations) {
        if (!str_starts_with($table, 'glpi_items_device')) {
            continue;
        }
        $linkType = getItemTypeForTable($table);
        $column = $linkType::getDeviceForeignKey();
        $parentTable = $relations[$column];
        $deviceId = $fixtures->create($parentTable);
        $replacement = $fixtures->create($parentTable);
        $deviceType = getItemTypeForTable($parentTable);
        $device = new $deviceType();
        verify($device->getFromDB($deviceId), 'Load device');
        $link = new $linkType();
        $assigned = $fixtures->create($table, [$column => $deviceId, 'itemtype' => 'Computer', 'items_id' => $assetId]);
        $foreign = $fixtures->create($table, [$column => $deviceId, 'itemtype' => 'Computer', 'items_id' => $otherId]);
        $stock = $fixtures->create($table, [$column => $deviceId, 'itemtype' => '', 'items_id' => 0]);
        $deleted = $fixtures->create($table, [$column => $deviceId, 'itemtype' => 'Computer', 'items_id' => $assetId, 'is_deleted' => true]);
        $unrelated = $fixtures->create($table, [$column => $replacement, 'itemtype' => '', 'items_id' => 0]);
        verify(array_column($link->getTableGroupRows($device, 'Computer'), 'id') === [$assigned], 'Device view scopes assets and excludes deleted links: ' . $table);
        verify(array_column($link->getTableGroupRows($asset), 'id') === [$assigned], 'Asset view selects components: ' . $table);
        verify(array_column($link->getTableGroupRows($device, ''), 'id') === [$stock], 'Stock has no asset entity restriction');
        $_SESSION['glpiactiveentities'] = [];
        verify($link->getTableGroupRows($device, 'Computer') === [], 'Empty entity scope cannot expose assignments');
        verify(array_column($link->getTableGroupRows($device, ''), 'id') === [$stock], 'Empty asset scope still allows the stock view');
        $_SESSION['glpishowallentities'] = true;
        verify($link->getTableGroupRows($device, 'Computer') === [], 'Cached all-entities optimization cannot override explicitly empty component grants');
        verify(array_column($link->getTableGroupRows($device, ''), 'id') === [$stock], 'Global stock remains available after authoritative empty grant selection');
        $_SESSION['glpishowallentities'] = false;
        unset($_SESSION['glpiactiveentities']);
        verify(array_column($link->getTableGroupRows($device, 'Computer'), 'id') === [$assigned], 'CLI with no entity selection defaults to root');
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpishowallentities'] = true;
        verify(count($link->getTableGroupRows($device, 'Computer')) === 2, 'All-entity scope');
        $_SESSION['glpishowallentities'] = false;
        $em = \itsmng\Database\Orm::create($DB);
        verify((new \itsmng\Database\Repository\ComponentRepository($em))->detach($table, 'Monitor', $assetId) === 0, 'Stock detach respects polymorphic type');
        verify((new \itsmng\Database\Repository\ComponentRepository($em))->detach($table, 'Computer', $assetId) === 2, 'Stock detach includes deleted components');
        $typedStock = isset(\itsmng\Database\EntityRegistry::discriminatedReferences($table)['items_id']['empty_value']);
        verify($link->getFromDB($assigned) && $link->fields['itemtype'] === ($typedStock ? null : '') && (int)$link->fields['items_id'] === 0, 'Detached stock preserves required device and its entity-declared canonical stock kind');
        verify($device->delete(['id' => $deviceId, '_replace_by' => $replacement], true), 'Replace device under FK enforcement: ' . $table);
        verify($link->getFromDB($assigned) && (int)$link->fields[$column] === $replacement, 'Replacement preserves link');
        verify($link->getFromDB($foreign), 'Replacement preserves foreign asset link');
        verify($device->delete(['id' => $replacement], true), 'Purge replacement device under FK enforcement');
        verify($link->find([$column => $replacement]) === [], 'Purge removes assigned, deleted and stock links');
        ++$tested;
    }
    verify($tested === 17, 'All component device associations exercised');

    $memory = $fixtures->create('glpi_devicememories', ['designation' => 'Clone memory']);
    $link = new Item_DeviceMemory();
    $id = $link->add(['devicememories_id' => $memory, 'itemtype' => 'Computer', 'items_id' => $assetId, 'size' => 8192]);
    verify((bool)$id, 'Application component add');
    $associated = Item_Devices::getItemsAssociatedTo('Computer', $assetId);
    verify(count($associated) === 1 && (int)$associated[0]->getID() === (int)$id, 'Associated model reads');
    Item_Devices::cloneItem('Computer', $assetId, $otherId);
    $cloned = $link->find(['itemtype' => 'Computer', 'items_id' => $otherId]);
    verify(count($cloned) === 1 && (int)reset($cloned)['size'] === 8192, 'Cloning preserves component specificities');
    Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', $assetId, true);
    verify($link->getFromDB($id) && $link->fields['itemtype'] === '', 'Public stock detach path');
    Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', $otherId, false);
    verify($link->find(['itemtype' => 'Computer', 'items_id' => $otherId]) === [], 'Public delete path');
    verify($link->getFromDB($id), 'Other stock preserved');
    $device = new DeviceMemory();
    verify($device->getFromDB($memory), 'Load stock device');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    verify(count($link->getTableGroupRows($device, '')) === 1, 'Mapped stock listing');
    $link->getTableGroupRows($asset);
    $link->getTableGroupRows($device, 'Computer');
    Item_Devices::getItemsAssociatedTo('Computer', $assetId);
    Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', $assetId, true);
    verify($SQL_TOTAL_REQUEST === 0, 'Core component reads and stock detach bypass legacy query execution');
    verify((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphaned relationships');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": 17 component associations, entity scope, replacement, purge, cloning and stock handling passed.\n";
