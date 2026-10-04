<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\InventoryLockRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/inventory-locks.php /path/to/test-config\n");
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

/** Collect bulk-action outcomes while exercising the real selection and model restore. */
final class InventoryUnlockAction extends MassiveAction
{
    public array $results = [];

    public function __construct(private array $kinds)
    {
    }

    public function getAction()
    {
        return 'unlock';
    }

    public function getInput()
    {
        return ['attached_item' => $this->kinds];
    }

    public function itemDone($itemtype, $id, $result)
    {
        $this->results[$itemtype][$id] = $result;
    }

    public function addMessage($message)
    {
        throw new RuntimeException((string)$message);
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$DB->beginTransaction();
try {
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $assetId = $fixtures->create('glpi_computers', ['name' => 'Locked inventory asset', 'is_dynamic' => true]);
    $otherId = $fixtures->create('glpi_computers', ['name' => 'Unrelated inventory asset']);
    $asset = new Computer();
    verify($asset->getFromDB($assetId), 'Load dynamic source');
    $locks = new InventoryLockRepository(Orm::create($DB));
    $ids = static fn (string $kind, string $type = 'Computer', ?int $id = null): array => array_map('intval', array_column($locks->forItem($kind, $type, $id ?? $assetId), 'id'));
    $locked = ['is_dynamic' => true, 'is_deleted' => true];
    $expected = [];
    $excluded = [];
    foreach (['Monitor', 'Peripheral', 'Printer', 'Phone'] as $kind) {
        $target = $fixtures->create($kind::getTable(), ['name' => 'Locked ' . $kind]);
        $values = ['computers_id' => $assetId, 'itemtype' => $kind, 'items_id' => $target];
        $expected[$kind] = $fixtures->create('glpi_computers_items', $values + $locked);
        $excluded[] = ['glpi_computers_items', $fixtures->create('glpi_computers_items', ['computers_id' => $otherId] + $values + $locked)];
        $excluded[] = ['glpi_computers_items', $fixtures->create('glpi_computers_items', ['is_dynamic' => false] + $values + $locked)];
        verify($ids($kind) === [$expected[$kind]], 'Computer attachment scope: ' . $kind);
        verify($ids($kind, 'Monitor') === [], 'Computer-only attachments reject a different source kind');
    }
    foreach (['Item_Disk', 'ComputerVirtualMachine', 'SoftwareVersion', 'SoftwareLicense', 'NetworkPort'] as $kind) {
        $model = InventoryLockRepository::modelType($kind);
        $table = $model::getTable();
        $parent = $kind === 'ComputerVirtualMachine' ? 'computers_id' : 'items_id';
        $values = [$parent => $assetId];
        $software = null;
        if ($kind !== 'ComputerVirtualMachine') {
            $values['itemtype'] = 'Computer';
        }
        if ($kind === 'SoftwareVersion' || $kind === 'SoftwareLicense') {
            $software = $fixtures->create('glpi_softwares', ['name' => 'Locked software']);
        }
        $create = static function (array $assignment) use ($fixtures, $table, $kind, $software): int {
            if ($software !== null) {
                $column = $kind === 'SoftwareVersion' ? 'softwareversions_id' : 'softwarelicenses_id';
                $assignment[$column] = $fixtures->create($kind::getTable(), ['name' => 'Locked selection', 'softwares_id' => $software]);
            }
            return $fixtures->create($table, $assignment);
        };
        $expected[$kind] = $create($values + $locked);
        $excluded[] = [$table, $create([$parent => $otherId] + $values + $locked)];
        $excluded[] = [$table, $create(['is_dynamic' => false] + $values + $locked)];
        $create(['is_deleted' => false] + $values + $locked);
        if ($kind !== 'ComputerVirtualMachine') {
            if (in_array($kind, ['SoftwareVersion', 'SoftwareLicense'], true)
                && (new RecordRepository(Orm::create($DB)))->find('glpi_monitors', 'id', $assetId) === null) {
                $fixtures->create('glpi_monitors', ['id' => $assetId, 'name' => 'Distinct kind with the same source ID']);
            }
            $excluded[] = [$table, $create(['itemtype' => 'Monitor'] + $values + $locked)];
        }
        verify($ids($kind) === [$expected[$kind]], 'Locked flags and typed source: ' . $kind);
        if ($kind === 'SoftwareVersion' || $kind === 'SoftwareLicense') {
            $row = $locks->forItem($kind, 'Computer', $assetId)[0];
            verify($row['software'] === 'Locked software' && $row['version'] === 'Locked selection', 'Software labels follow mapped associations');
        }
    }
    $differentKindControls = 0;
    foreach (Item_Devices::getDeviceTypes() as $kind) {
        $table = $kind::getTable();
        $deviceType = $kind::getDeviceType();
        $deviceId = $fixtures->create($deviceType::getTable(), ['designation' => 'Locked ' . $deviceType]);
        $values = [$kind::getDeviceForeignKey() => $deviceId, 'itemtype' => 'Computer', 'items_id' => $assetId];
        $expected[$kind] = $fixtures->create($table, $values + $locked);
        $excluded[] = [$table, $fixtures->create($table, ['items_id' => $otherId] + $values + $locked)];
        // Use the concrete model's supported affinity, derived from owning
        // metadata where available. A same numeric ID in another real subject
        // remains excluded; Computer-only families use legitimate stock instead.
        $affinity = $kind::itemAffinity();
        $alternatives = array_values(array_diff(in_array('*', $affinity, true) ? $CFG_GLPI['itemdevices_types'] : $affinity, ['Computer']));
        if ($alternatives !== []) {
            $alternateType = $alternatives[0];
            $alternate = getItemForItemtype($alternateType);
            verify($alternate instanceof CommonDBTM && isset(\itsmng\Database\EntityRegistry::tables()[$alternate->getTable()]),
                'Alternate component subject is a real mapped model: ' . $kind);
            if ((new RecordRepository(Orm::create($DB)))->find($alternate->getTable(), 'id', $assetId) === null) {
                $fixtures->create($alternate->getTable(), ['id' => $assetId, 'name' => 'Supported component subject with the same source ID']);
            }
            $excluded[] = [$table, $fixtures->create($table, ['itemtype' => $alternateType] + $values + $locked)];
            ++$differentKindControls;
        } else {
            $excluded[] = [$table, $fixtures->create($table, ['itemtype' => null, 'items_id' => 0] + $values + $locked)];
        }
        $excluded[] = [$table, $fixtures->create($table, ['is_dynamic' => false] + $values + $locked)];
        $fixtures->create($table, ['is_deleted' => false] + $values + $locked);
        $rows = $locks->forItem($kind, 'Computer', $assetId);
        verify(array_map('intval', array_column($rows, 'id')) === [$expected[$kind]], 'Component assignment scope: ' . $kind);
        verify($rows[0]['name'] === 'Locked ' . $deviceType, 'Component designation follows its mapped association');
    }
    verify(count(Item_Devices::getDeviceTypes()) === 17, 'All 17 core component associations exercised');
    verify($differentKindControls > 0, 'Supported component families retain genuine same-ID wrong-discriminator controls');
    $processorTable = Item_DeviceProcessor::getTable();
    $processorReference = \itsmng\Database\EntityRegistry::discriminatedReferences($processorTable)['items_id'];
    verify(array_keys($processorReference['selections']) === ['Computer'], 'Processor subject affinity remains its sole entity-owned Computer association');
    $processorClass = \itsmng\Database\EntityRegistry::tables()[$processorTable];
    $processorProposal = (new RecordRepository(Orm::create($DB)))->find($processorTable, 'id', $expected[Item_DeviceProcessor::class]);
    unset($processorProposal['id']);
    $processorProposal['itemtype'] = 'Monitor';
    $connection = $DB->getDoctrineConnection();
    $processorRowsBefore = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($processorTable) . ' ORDER BY id');
    $processorRefused = false;
    try {
        (new $processorClass())->normalizeInput($processorProposal);
    } catch (InvalidArgumentException $error) {
        $processorRefused = $error->getMessage() === 'Unsupported Typed item reference: Monitor';
    }
    verify($processorRefused
        && $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($processorTable) . ' ORDER BY id') === $processorRowsBefore,
        'Actual entity input refuses unsupported Processor Monitor without inserting or changing any row');
    verify($ids(Item_DeviceProcessor::class, 'Monitor') === [], 'Unsupported Processor source kind cannot select a valid Computer or stock lock');
    // Ancestors need not be locked themselves. Every polymorphic hop must match its kind.
    $port = $fixtures->create('glpi_networkports', ['items_id' => $assetId, 'itemtype' => 'Computer']);
    $foreignPort = $fixtures->create('glpi_networkports', ['items_id' => $otherId, 'itemtype' => 'Computer']);
    $wrongPort = $fixtures->create('glpi_networkports', ['items_id' => $assetId, 'itemtype' => 'Monitor']);
    $name = $fixtures->create('glpi_networknames', ['items_id' => $port, 'itemtype' => 'NetworkPort', 'name' => 'live-parent']);
    $expected['NetworkName'] = $fixtures->create('glpi_networknames', ['items_id' => $port, 'itemtype' => 'NetworkPort', 'name' => 'locked-name'] + $locked);
    $expected['IPAddress'] = $fixtures->create('glpi_ipaddresses', ['items_id' => $name, 'itemtype' => 'NetworkName', 'name' => '192.0.2.10'] + $locked);
    foreach ([[$foreignPort, 'NetworkPort'], [$wrongPort, 'NetworkPort'], [$port, 'Computer']] as [$parent, $type]) {
        $badName = $fixtures->create('glpi_networknames', ['items_id' => $parent, 'itemtype' => $type, 'name' => 'excluded-name'] + $locked);
        $excluded[] = ['glpi_networknames', $badName];
        $excluded[] = ['glpi_ipaddresses', $fixtures->create('glpi_ipaddresses', ['items_id' => $badName, 'itemtype' => 'NetworkName'] + $locked)];
    }
    $excluded[] = ['glpi_ipaddresses', $fixtures->create('glpi_ipaddresses', ['items_id' => $name, 'itemtype' => 'Computer'] + $locked)];
    verify($ids('NetworkName') === [$expected['NetworkName']], 'Names follow a typed port ancestry with a live port');
    verify($ids('IPAddress') === [$expected['IPAddress']], 'Addresses follow typed name and port ancestry with live ancestors');
    verify($ids('Unsupported') === [] && $ids('NetworkPort', 'Computer', 0) === [], 'Unknown kind and missing source do not select assignments');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    foreach (array_keys($expected) as $kind) {
        verify($ids($kind) === [$expected[$kind]], 'Mapped lock selection: ' . $kind);
    }
    verify($SQL_TOTAL_REQUEST === 0, 'All inventory-lock selections bypass adapter SQL');
    ob_start();
    Lock::showForItem($asset);
    $html = ob_get_clean();
    foreach ($expected as $kind => $id) {
        if ($kind !== 'Phone') { // Phone is available in bulk unlock, as before.
            verify(str_contains($html, "name='" . InventoryLockRepository::modelType($kind) . '[' . $id . "]'"), 'Lock form lists selected assignment: ' . $kind);
        }
    }
    $asset->fields['is_dynamic'] = 0;
    ob_start();
    verify(Lock::showForItem($asset) === false, 'Non-dynamic source cannot display lock form');
    verify(ob_get_clean() === '', 'Rejected source renders no selection');
    $asset->fields['is_dynamic'] = 1;
    $rights = $_SESSION['glpiactiveprofile']['computer'];
    $_SESSION['glpiactiveprofile']['computer'] = READ;
    ob_start();
    verify(Lock::showForItem($asset) === false, 'Read-only source cannot display lock form');
    verify(ob_get_clean() === '', 'Read-only source renders no selection');
    $_SESSION['glpiactiveprofile']['computer'] = $rights;

    $kinds = array_values(array_diff(array_keys($expected), Item_Devices::getDeviceTypes()));
    $action = new InventoryUnlockAction([...$kinds, 'Device']);
    Lock::processMassiveActionsForOneItemtype($action, $asset, [$assetId]);
    verify($action->results === ['Computer' => [$assetId => MassiveAction::ACTION_OK]], 'Bulk unlock reports success');
    $records = new RecordRepository(Orm::create($DB));
    foreach ($expected as $kind => $id) {
        $model = InventoryLockRepository::modelType($kind);
        verify(!$records->find($model::getTable(), 'id', $id)['is_deleted'], 'Bulk unlock restores the actual owning model: ' . $kind);
        verify($ids($kind) === [], 'Restored assignment leaves the lock view');
    }
    foreach ($excluded as [$table, $id]) {
        verify((bool)$records->find($table, 'id', $id)['is_deleted'], 'Unrelated, manually deleted and differently typed assignments stay deleted');
    }
    $action = new InventoryUnlockAction(['NetworkName', 'IPAddress', 'ComputerVirtualMachine']);
    Lock::processMassiveActionsForOneItemtype($action, $asset, [$assetId]);
    verify($action->results['Computer'][$assetId] === MassiveAction::ACTION_OK, 'Unlock of an empty selection succeeds');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
}
echo $DB->getProvider() . ": inventory lock forms, all component associations, typed ancestry and bulk restore passed.\n";
