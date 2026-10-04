<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\ComponentRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/processor-ownership.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$savedPost = $_POST;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'processor_ownership_fixture']);
$connection = $DB->getDoctrineConnection();
$nesting = $connection->getTransactionNestingLevel();
$connection->beginTransaction();
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$rows = static fn (string $table, array $criteria): array => (new RecordRepository(Orm::create($DB)))->matching($table, $criteria, ['id']);
$events = [];
$updated = [];
$createEntity = static function (string $name): int {
    $entity = new Entity();
    $input = ['name' => $name, 'entities_id' => 0];
    verify($entity->can(-1, CREATE, $input), 'Fixture actor may create this actual child Entity');
    $id = (int)$entity->add($input);
    verify($id > 0 && Session::haveAccessToEntity($id), 'Public Entity creation retains tree and creator scope lifecycle');
    return $id;
};
try {
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Processor ownership ' . bin2hex(random_bytes(5));
    $source = $fixtures->create('glpi_computers', ['id' => 4294991001, 'name' => $prefix . ' source', 'is_recursive' => true]);
    $destination = $fixtures->create('glpi_computers', ['id' => 4294991002, 'name' => $prefix . ' destination', 'is_recursive' => true]);
    $phone = $fixtures->create('glpi_phones', ['id' => $source, 'name' => $prefix . ' collision']);
    $foreignEntity = $createEntity($prefix . ' foreign');
    $admittedSession = $_SESSION;
    $foreign = $fixtures->create('glpi_computers', ['name' => $prefix . ' foreign Computer', 'entities_id' => $foreignEntity]);
    $device = $fixtures->create('glpi_deviceprocessors', ['designation' => $prefix . ' processor', 'entities_id' => $foreignEntity, 'frequency_default' => 3200]);
    $model = new Item_DeviceProcessor();
    $PLUGIN_HOOKS['item_update']['processor_ownership_fixture'][Item_DeviceProcessor::class] = static function (Item_DeviceProcessor $item) use (&$updated): void {
        $updated[] = [$item->getID(), $item->fields['itemtype'], $item->fields['items_id'], $item->fields['computers_id']];
    };
    $literal = "CPU O'Reilly \\ 日本語";
    $id = $model->add(Toolbox::addslashes_deep(['deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'items_id' => $source, 'serial' => $literal, 'nbcores' => 8, 'nbthreads' => null]));
    verify($id > 0 && $model->getFromDB($id), 'Public assignment creates and loads a real processor binding');
    verify((int)$model->fields['computers_id'] === $source && (int)$model->fields['items_id'] === $source && $model->fields['serial'] === $literal
        && $model->fields['nbthreads'] === null && (int)$model->fields['entities_id'] === $foreignEntity, 'Owning Computer and compatibility ID agree while device entity and nullable/literal payload remain independent');
    $duplicate = (new Item_DeviceProcessor())->add(['deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'items_id' => $source, 'nbcores' => 4]);
    verify($duplicate > 0 && $duplicate !== $id, 'Multiple processors with the same device and Computer remain valid');
    $stockSession = $_SESSION;
    $bindingsBeforeDenial = $rows('glpi_items_deviceprocessors', ['deviceprocessors_id' => $device]);
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpishowallentities'] = false;
    try {
        verify(!Session::haveAccessToEntity($foreignEntity), 'Narrow device-screen scope excludes the foreign Device entity');
        (new Item_DeviceProcessor())->addDevices(1, '', 0, $device);
        verify(
            $rows('glpi_items_deviceprocessors', ['deviceprocessors_id' => $device]) === $bindingsBeforeDenial,
            'Real stock command refuses invisible Device without changing any binding'
        );
    } finally {
        $_SESSION = $stockSession;
    }
    $stockDevice = new DeviceProcessor();
    verify($stockDevice->can($device, READ) && $stockDevice->can($device, UPDATE), 'Actual admitted actor may read and update the foreign-owned Device');
    verify(
        (int)$read('glpi_deviceprocessors', $device)['entities_id'] === $foreignEntity
        && (int)$read('glpi_computers', $source)['entities_id'] === 0 && $read('glpi_computers', $source)['is_recursive'],
        'Legitimate recursive Computer graph retains independent foreign Device ownership'
    );
    $model->addDevices(2, '', 0, $device);
    $stock = $rows('glpi_items_deviceprocessors', ['deviceprocessors_id' => $device, 'itemtype' => null]);
    verify(count($stock) === 2 && (int)$stock[0]['frequency'] === 3200 && (int)$stock[0]['items_id'] === 0 && $stock[0]['computers_id'] === null, 'Real device-screen stock creation retains defaults and zero compatibility identity');
    $repository = new ComponentRepository(Orm::create($DB));
    verify(array_column($repository->stock('glpi_items_deviceprocessors', 'deviceprocessors_id', $device), 'id') === array_column($stock, 'id'), 'Stock selection uses canonical unassigned owning references');
    $_POST = ['itemtype' => 'DeviceProcessor', 'items_id' => $device];
    $ajaxServer = $_SERVER;
    $_SERVER['REQUEST_URI'] = $CFG_GLPI['root_doc'] . '/ajax/selectUnaffectedOrNewItem_Device.php';
    $_SERVER['PHP_SELF'] = $_SERVER['REQUEST_URI'];
    $_SERVER['HTTP_REFERER'] = $CFG_GLPI['url_base'] . '/front/deviceprocessor.form.php?id=' . $device;
    ob_start();
    try {
        include GLPI_ROOT . '/ajax/selectUnaffectedOrNewItem_Device.php';
        $selection = json_decode(ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        ob_end_clean();
        $_SERVER = $ajaxServer;
    }
    verify($selection['name'] === 'deviceprocessors_id' && array_keys($selection['options']) === array_column($stock, 'id'), 'Actual stock AJAX route retains field name, binding identities and complete available stock');
    $snapshot = $read('glpi_items_deviceprocessors', $id);
    $historyBefore = $rows('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $source]);
    foreach ([
        ['itemtype' => 'Phone', 'items_id' => $phone], ['itemtype' => 'Computer', 'items_id' => 0],
        ['itemtype' => null, 'items_id' => $source], ['itemtype' => 'Computer', 'items_id' => $source, 'computers_id' => $destination],
    ] as $invalid) {
        verify($model->update(['id' => $id] + $invalid) === false && $read('glpi_items_deviceprocessors', $id) === $snapshot, 'Public invalid discriminator/stock/canonical conflict refuses without changing the persisted binding');
    }
    verify($updated === [] && $rows('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $source]) === $historyBefore, 'Refused public inputs emit no completion hook or false Computer audit history');
    $missingBefore = [
        $rows('glpi_items_deviceprocessors', []), $rows('glpi_logs', []), $rows('glpi_queuednotifications', []),
    ];
    verify((new Item_DeviceProcessor())->add(['deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'items_id' => 4294991999]) === false, 'Public invalid target fails before a binding is created');
    $preparedMissing = 0;
    $completedMissing = 0;
    $PLUGIN_HOOKS['post_prepareadd']['processor_ownership_fixture'][Item_DeviceProcessor::class] = static function (Item_DeviceProcessor $item) use (&$preparedMissing): void {
        ++$preparedMissing;
        unset($item->input['items_id']);
        $item->input['computers_id'] = 4294991999;
    };
    $PLUGIN_HOOKS['item_add']['processor_ownership_fixture'][Item_DeviceProcessor::class] = static function () use (&$completedMissing): void {
        ++$completedMissing;
    };
    try {
        verify(
            (new Item_DeviceProcessor())->add(['deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'items_id' => $source]) === false
            && $preparedMissing === 1 && $completedMissing === 0,
            'Actual preparation hook cannot replace a valid selected Computer with a missing target and complete insertion'
        );
    } finally {
        unset($PLUGIN_HOOKS['post_prepareadd']['processor_ownership_fixture'][Item_DeviceProcessor::class]);
        unset($PLUGIN_HOOKS['item_add']['processor_ownership_fixture'][Item_DeviceProcessor::class]);
    }
    verify(
        [$rows('glpi_items_deviceprocessors', []), $rows('glpi_logs', []), $rows('glpi_queuednotifications', [])] === $missingBefore,
        'Missing selected subject refusals preserve all bindings, audit rows and notification rows'
    );
    verify(Item_DeviceProcessor::affectItem_Device($stock[0]['id'], $destination, 'Computer'), 'Public attachment moves actual stock to an owning Computer');
    verify($model->update(['id' => $id, 'itemtype' => null, 'serial' => null]), 'Supplied null kind returns an assigned processor to stock without retaining its prior positive identity');
    verify($model->getFromDB($id) && $model->fields['itemtype'] === null && (int)$model->fields['items_id'] === 0 && $model->fields['computers_id'] === null && $model->fields['serial'] === null, 'Null stock update publishes the actual cleared owner and supplied null payload');
    verify(end($updated) === [$id, null, 0, null], 'Accepted completion hook observes the canonical stock association and zero compatibility identity');
    verify(Item_DeviceProcessor::affectItem_Device($id, $source, 'Computer'), 'Stock can be assigned again');
    verify($model->update(['id' => $id, 'nbthreads' => 16]) && $model->getFromDB($id) && (int)$model->fields['computers_id'] === $source, 'Absent subject input preserves the existing owner');
    $assignedForeign = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'items_id' => $foreign]);
    $deviceModel = new DeviceProcessor();
    verify($deviceModel->getFromDB($device), 'Load device-owned processor scope');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpishowallentities'] = false;
    verify(array_column($model->getTableGroupRows($deviceModel, 'Computer'), 'id') === [$id, $duplicate, $stock[0]['id']], 'Attached device view restricts Computer scope independently of device ownership');
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    $_SESSION['glpishowallentities'] = true;
    verify($model->getTableGroupRows($deviceModel, 'Computer') === [] && array_column($model->getTableGroupRows($deviceModel, ''), 'id') === [$stock[1]['id']], 'Explicit empty scope never leaks assigned Computers while remaining stock stays available');
    $_SESSION = $admittedSession;
    $rights = $_SESSION['glpiactiveprofile'];
    $_SESSION['glpiactiveprofile'][Computer::$rightname] = 0;
    $_SESSION['glpiactiveprofile'][DeviceProcessor::$rightname] = 0;
    verify($model->can($id, UPDATE) === false, 'Public binding authorization still refuses an actor without Computer and device rights');
    $_SESSION['glpiactiveprofile'] = $rights;
    verify(Item_DeviceProcessor::itemAffinity() === ['Computer'] && Item_DeviceProcessor::getConcernedItems() === ['Computer'], 'UI subject affinity derives from the owning association');
    verify(in_array('Item_DeviceProcessor', Glpi\Api\API::getHatoasClasses('Computer'), true)
        && !in_array('Item_DeviceProcessor', Glpi\Api\API::getHatoasClasses('Phone'), true), 'API discovery shares the actual component affinity and cannot advertise Phone processors');

    $processorOptions = static fn (array $options): array => array_values(array_filter(
        $options,
        static fn (array $option): bool => in_array($option['table'] ?? '', ['glpi_deviceprocessors', 'glpi_items_deviceprocessors'], true)
    ));
    $computerProcessorOptions = $processorOptions((new Computer())->rawSearchOptions());
    verify(
        array_column($computerProcessorOptions, 'field') === ['designation', 'nbcores', 'nbthreads', 'frequency'],
        'Actual Computer search options retain all four processor fields from metadata-owned affinity'
    );
    verify(
        $computerProcessorOptions[0]['joinparams']['beforejoin']['joinparams']['specific_itemtype'] === 'Computer'
        && $computerProcessorOptions[1]['joinparams']['specific_itemtype'] === 'Computer',
        'Actual processor search joins retain their Computer-specific compatibility projection'
    );
    verify(
        $processorOptions(Item_Devices::rawSearchOptionsToAdd('Phone')) === [],
        'Actual device search-option caller excludes Processor fields for an unsupported Phone subject'
    );
    verify(
        in_array('glpi_devicepcis', array_column(Item_Devices::rawSearchOptionsToAdd('Phone'), 'table'), true),
        'Unmapped core wildcard affinity remains available in actual device search options'
    );

    // The abstract Item_Devices clone family must rebind each concrete child.
    $computer = new Computer();
    verify($computer->getFromDB($source), 'Load Computer for actual modern cloning');
    $clone = $computer->clone(['name' => $prefix . ' clone']);
    verify($clone > 0 && count($rows('glpi_items_deviceprocessors', ['itemtype' => 'Computer', 'computers_id' => $clone])) === 2, 'Actual Computer cloning rebinds both duplicate Processor children through their concrete owning metadata');
    Item_Devices::cloneItem('Computer', $source, $destination);
    verify(count($rows('glpi_items_deviceprocessors', ['itemtype' => 'Computer', 'computers_id' => $destination])) === 3, 'Deprecated component cloning preserves distinct bindings and clears source canonical ownership');
    $template = $fixtures->create('glpi_computers', ['name' => $prefix . ' template', 'is_template' => true, 'is_recursive' => true]);
    $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $device, 'itemtype' => 'Computer', 'items_id' => $template, 'nbcores' => 12]);
    verify($computer->getFromDB($template), 'Load real Computer template');
    $templateCopy = $computer->clone(['name' => $prefix . ' template copy', 'is_template' => 0]);
    $templateLinks = $rows('glpi_items_deviceprocessors', ['itemtype' => 'Computer', 'computers_id' => $templateCopy]);
    verify($templateCopy > 0 && count($templateLinks) === 1 && (int)$templateLinks[0]['nbcores'] === 12, 'Template cloning preserves actual Processor payload and retargets its owner');

    // Computer purge chooses stock versus actual component deletion; financial links belong to the binding.
    $infocom = $fixtures->create('glpi_infocoms', ['itemtype' => 'Item_DeviceProcessor', 'items_id' => $id, 'entities_id' => $foreignEntity]);
    $contract = $fixtures->create('glpi_contracts', ['is_recursive' => true]);
    $contractLink = $fixtures->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => 'Item_DeviceProcessor', 'items_id' => $id]);
    $project = $fixtures->create('glpi_projects', ['is_recursive' => true]);
    $projectLink = $fixtures->create('glpi_items_projects', ['projects_id' => $project, 'itemtype' => 'Item_DeviceProcessor', 'items_id' => $id]);
    verify($computer->getFromDB($source) && $computer->delete(['id' => $source, 'keep_devices' => 1], true), 'Actual Computer purge with keep_devices returns Processor children to stock');
    verify($read('glpi_items_deviceprocessors', $id)['computers_id'] === null && $read('glpi_infocoms', $infocom) !== null
        && $read('glpi_contracts_items', $contractLink) !== null && $read('glpi_items_projects', $projectLink) !== null, 'Stock return preserves component-owned financial/project/contract bindings');
    verify(Item_DeviceProcessor::affectItem_Device($id, $destination, 'Computer'), 'Financially linked stock can be reassigned');
    verify($computer->getFromDB($destination) && $computer->delete(['id' => $destination, 'keep_devices' => 0], true), 'Actual Computer purge without keep_devices uses component public cleanup');
    verify($read('glpi_items_deviceprocessors', $id) === null && $read('glpi_infocoms', $infocom) === null
        && $read('glpi_contracts_items', $contractLink) === null && $read('glpi_items_projects', $projectLink) === null, 'Deleting the component purges its owned relationships without deleting surviving Contract/Project owners');
    verify($read('glpi_contracts', $contract) !== null && $read('glpi_projects', $project) !== null && $read('glpi_items_deviceprocessors', $assignedForeign) !== null, 'Unrelated owners and foreign Computer components survive');

    // Shared stock prevents moving the device definition; the transfer must copy or reuse it.
    $transferEntity = $createEntity($prefix . ' transfer');
    $transferAsset = $fixtures->create('glpi_computers', ['id' => 4294991003, 'name' => $prefix . ' transfer Computer']);
    $transferDevice = $fixtures->create('glpi_deviceprocessors', ['designation' => $prefix . ' shared transfer device']);
    $transferBinding = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $transferDevice, 'itemtype' => 'Computer', 'items_id' => $transferAsset]);
    $transferStock = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $transferDevice, 'itemtype' => '', 'items_id' => 0]);
    $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' caller marker']);
    verify((new Transfer())->moveItems(['Computer' => [$transferAsset]], $transferEntity, ['keep_device' => 1, 'keep_history' => 1]) === true, 'Actual transfer accepts assigned Processor plus shared stock within a caller transaction');
    $moved = $read('glpi_items_deviceprocessors', $transferBinding);
    verify((int)$moved['computers_id'] === $transferAsset && (int)$moved['deviceprocessors_id'] !== $transferDevice
        && (int)$read('glpi_deviceprocessors', $moved['deviceprocessors_id'])['entities_id'] === $transferEntity
        && (int)$read('glpi_deviceprocessors', $transferDevice)['entities_id'] === 0
        && (int)$read('glpi_items_deviceprocessors', $transferStock)['deviceprocessors_id'] === $transferDevice, 'Transfer copies the shared device while preserving stock and the actual wide owning binding');
    verify($connection->getTransactionNestingLevel() === $nesting + 1 && $read('glpi_suppliers', $marker) !== null, 'Transfer retains the caller transaction and prior marker');
    $before = $read('glpi_items_deviceprocessors', $transferBinding);
    $beforeAsset = $read('glpi_computers', $transferAsset);
    $PLUGIN_HOOKS['pre_item_purge']['processor_ownership_fixture'][Item_DeviceProcessor::class] = static function (Item_DeviceProcessor $item) use ($transferBinding, &$events): void {
        if ((int)$item->getID() === $transferBinding) {
            $events[] = 'refused component purge';
            $item->input = [];
        }
    };
    verify((new Transfer())->moveItems(['Computer' => [$transferAsset]], 0, ['keep_device' => 0, 'keep_history' => 0]) === false, 'A real component purge veto refuses Transfer instead of bypassing hooks with a raw delete');
    verify($events === ['refused component purge'] && $read('glpi_items_deviceprocessors', $transferBinding) === $before
        && $read('glpi_computers', $transferAsset) === $beforeAsset && $read('glpi_suppliers', $marker) !== null
        && $connection->getTransactionNestingLevel() === $nesting + 1, 'Late purge veto restores parent/component writes and preserves caller transaction');
    unset($PLUGIN_HOOKS['pre_item_purge']['processor_ownership_fixture']);

    $soleAsset = $fixtures->create('glpi_computers', ['name' => $prefix . ' sole transfer Computer']);
    $soleDevice = $fixtures->create('glpi_deviceprocessors', ['designation' => $prefix . ' sole device']);
    $soleBinding = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $soleDevice, 'itemtype' => 'Computer', 'items_id' => $soleAsset]);
    $soleDuplicate = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $soleDevice, 'itemtype' => 'Computer', 'items_id' => $soleAsset]);
    verify((new Transfer())->moveItems(['Computer' => [$soleAsset]], $transferEntity, ['keep_device' => 1]) === true, 'Actual transfer moves a device whose complete duplicate assignment set belongs to the selected Computer');
    verify((int)$read('glpi_deviceprocessors', $soleDevice)['entities_id'] === $transferEntity
        && (int)$read('glpi_items_deviceprocessors', $soleBinding)['deviceprocessors_id'] === $soleDevice
        && (int)$read('glpi_items_deviceprocessors', $soleDuplicate)['deviceprocessors_id'] === $soleDevice, 'Whole-device transfer retains both distinct binding IDs and the original device ID');

    $reuseAsset = $fixtures->create('glpi_computers', ['name' => $prefix . ' reuse Computer']);
    $reuseDevice = $fixtures->create('glpi_deviceprocessors', ['designation' => $prefix . ' reusable device']);
    $existingDevice = $fixtures->create('glpi_deviceprocessors', ['designation' => $prefix . ' reusable device', 'entities_id' => $transferEntity]);
    $reuseBinding = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $reuseDevice, 'itemtype' => 'Computer', 'items_id' => $reuseAsset]);
    $reuseStock = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $reuseDevice, 'itemtype' => '', 'items_id' => 0]);
    $deviceIds = array_column($rows('glpi_deviceprocessors', []), 'id');
    verify((new Transfer())->moveItems(['Computer' => [$reuseAsset]], $transferEntity, ['keep_device' => 1]) === true, 'Shared device transfer reuses an existing equivalent destination device');
    verify((int)$read('glpi_items_deviceprocessors', $reuseBinding)['deviceprocessors_id'] === $existingDevice
        && (int)$read('glpi_items_deviceprocessors', $reuseStock)['deviceprocessors_id'] === $reuseDevice
        && array_column($rows('glpi_deviceprocessors', []), 'id') === $deviceIds, 'Destination reuse creates no extra device and preserves the source stock binding');
} finally {
    while ($connection->getTransactionNestingLevel() > $nesting) {
        $connection->rollBack();
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $_POST = $savedPost;
    $plugins->setValue(null, $savedPlugins);
    restore_error_handler();
}
echo $DB->getProvider() . ": Processor ownership, stock AJAX, scope, public cloning/purge and atomic transfer: $assertions assertions passed.\n";
