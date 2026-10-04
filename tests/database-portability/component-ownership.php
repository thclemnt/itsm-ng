<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\EntityRegistry;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\ComponentRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php component-ownership.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
/** Expose the real read endpoint without overriding token, scope or model authorization. */
final class ComponentOwnershipApi extends Glpi\Api\APIRest
{
    public function initialize(string $token): void
    {
        $this->parameters = ['app_token' => $token, 'session_token' => session_id()];
        $this->session_write = true;
        $this->initApi();
    }

    public function readItem(string $kind, int $id, array $parameters): array
    {
        return parent::getItem($kind, $id, $parameters);
    }

    public function returnResponse($response, $httpcode = 200, $additionalheaders = []): never
    {
        // Successful getItem returns its genuine fields; errors retain the
        // actual endpoint HTTP/status response instead of exiting this test.
        throw new ComponentOwnershipApiFailure($response, (int)$httpcode);
    }
}
final class ComponentOwnershipApiFailure extends RuntimeException
{
    public function __construct(public readonly mixed $response, int $status)
    {
        parent::__construct('Actual component API endpoint refused: ' . $status, $status);
    }
}
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable component application database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$savedPost = $_POST;
$savedApiUrl = Glpi\Api\API::$api_url;
$savedDisplayErrors = ini_get('display_errors');
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'component_ownership_fixture']);
$connection = $DB->getDoctrineConnection();
$level = $connection->getTransactionNestingLevel();
$frame = OwnedMutationFrame::begin($connection);
$primary = null;
$cleanup = [];
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$rows = static fn (string $table, array $criteria): array => (new RecordRepository(Orm::create($DB)))->matching($table, $criteria, ['id']);
$createEntity = static function (string $name): int {
    $entity = new Entity();
    $input = ['name' => $name, 'entities_id' => 0];
    verify($entity->can(-1, CREATE, $input), 'Actual caller may create the child Entity');
    $id = (int)$entity->add($input);
    verify($id > 0 && Session::haveAccessToEntity($id), 'Actual Entity lifecycle grants its creator scope');
    return $id;
};
try {
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Component ownership ' . bin2hex(random_bytes(5));
    $CFG_GLPI['enable_api'] = true;
    $apiToken = bin2hex(random_bytes(32));
    $apiClient = $fixtures->create('glpi_apiclients', ['name' => $prefix . ' API', 'is_active' => 1, 'app_token' => $apiToken, 'dolog_method' => 0]);
    verify($apiClient > 0 && session_id() !== '', 'Real active API client and authenticated PHP session required');
    $api = new ComponentOwnershipApi();
    $api->initialize($apiToken);

    $foreignEntity = $createEntity($prefix . ' definition');
    $transferEntity = $createEntity($prefix . ' transfer');
    $admittedSession = $_SESSION;
    $repository = new ComponentRepository(Orm::create($DB));
    $families = [
        [Item_DeviceMotherboard::class, [], [0 => 'Computer']],
        [Item_DeviceMemory::class, ['size' => 8192], [0 => 'Computer', 1 => 'NetworkEquipment', 2 => 'Peripheral', 4 => 'Printer']],
        [Item_DeviceHardDrive::class, ['capacity' => 1048576], ['Computer', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer']],
    ];
    foreach ($families as $familyIndex => [$linkClass, $payload, $concernedItems]) {
        $table = $linkClass::getTable();
        $deviceClass = $linkClass::getDeviceType();
        $deviceTable = $deviceClass::getTable();
        $deviceColumn = $linkClass::getDeviceForeignKey();
        $definition = EntityRegistry::discriminatedReferences($table)['items_id'];
        $entityClass = EntityRegistry::tables()[$table];
        $kinds = array_keys($definition['selections']);
        verify($linkClass::itemAffinity() === $kinds, 'Actual component affinity uses the owning declaration order');
        verify($linkClass::getConcernedItems() === $concernedItems, 'Public UI list retains its original global ordering and sparse keys while filtering metadata-owned affinity');
        foreach (array_diff(['Computer', 'NetworkEquipment', 'Peripheral', 'Printer', 'Phone'], $kinds) as $unsupported) {
            verify(!in_array($linkClass, Glpi\Api\API::getHatoasClasses($unsupported), true), 'Actual API discovery excludes unsupported family subjects');
        }
        $sameId = 4294997000 + $familyIndex;
        $destinationId = $sameId + 100;
        foreach ($definition['selections'] as $kind => $selection) {
            verify($read($selection['target'], $sameId) === null && $read($selection['target'], $destinationId) === null, 'Never adopt a preexisting kind-collision owner');
            $fixtures->create($selection['target'], ['id' => $sameId, 'name' => $prefix . ' ' . $kind, 'is_recursive' => true]);
            $fixtures->create($selection['target'], ['id' => $destinationId, 'name' => $prefix . ' destination ' . $kind, 'is_recursive' => true]);
            verify(in_array($linkClass, Glpi\Api\API::getHatoasClasses($kind), true), 'Actual API discovery includes this concrete supported component family');
        }
        if ($linkClass === Item_DeviceHardDrive::class) {
            verify(!array_key_exists('itemdeviceharddrive_types', $CFG_GLPI), 'No restored manual affinity config masks the actual with_disks caller');
            $filesystem = $fixtures->create('glpi_filesystems', ['name' => $prefix . ' filesystem']);
            foreach ($definition['selections'] as $kind => $selection) {
                $disk = $fixtures->create('glpi_items_disks', ['itemtype' => $kind, 'items_id' => $sameId, 'filesystems_id' => $filesystem,
                    'name' => $prefix . ' volume ' . $kind, 'totalsize' => 200, 'freesize' => 100]);
                $deleted = $fixtures->create('glpi_items_disks', ['itemtype' => $kind, 'items_id' => $sameId, 'filesystems_id' => $filesystem,
                    'name' => $prefix . ' deleted volume ' . $kind, 'is_deleted' => true]);
                $actual = $api->readItem($kind, $sameId, ['with_disks' => true, 'get_hateoas' => false]);
                verify(
                    count($actual['_disks']) === 1 && (int)$actual['_disks'][0]['name']['id'] === $disk
                    && $actual['_disks'][0]['name']['itemtype'] === $kind
                    && $actual['_disks'][0]['name']['fsname'] === $prefix . ' filesystem'
                    && (int)$actual['_disks'][0]['name']['totalsize'] === 200
                    && !array_key_exists('items_id', $actual['_disks'][0]['name']) && !array_key_exists('is_deleted', $actual['_disks'][0]['name']),
                    'Actual with_disks endpoint preserves separate filesystem rows/output and excludes deleted/colliding-kind records'
                );
                verify($read('glpi_items_disks', $deleted) !== null, 'API read never deletes historical filesystem rows');
                $hiddenAsset = $fixtures->create($selection['target'], ['name' => $prefix . ' denied API ' . $kind, 'entities_id' => $foreignEntity]);
                $fixtures->create('glpi_items_disks', ['itemtype' => $kind, 'items_id' => $hiddenAsset, 'name' => $prefix . ' denied volume']);
                $_SESSION['glpiactiveentities'] = [0];
                $_SESSION['glpiactiveentities_string'] = '0';
                $_SESSION['glpishowallentities'] = false;
                try {
                    $api->readItem($kind, $hiddenAsset, ['with_disks' => true, 'get_hateoas' => false]);
                    throw new LogicException('Filesystem API leaked a subject outside actual caller scope');
                } catch (ComponentOwnershipApiFailure $error) {
                    verify($error->getCode() === 403 && ($error->response[0] ?? null) === 'ERROR_RIGHT_MISSING', 'Actual asset READ/scoping refuses filesystem API output before retrieval');
                } finally {
                    $_SESSION = $admittedSession;
                }
            }
        }
        $default = $payload === [] ? [] : [array_key_first($payload) . '_default' => reset($payload)];
        $device = $fixtures->create($deviceTable, ['designation' => $prefix . ' ' . $deviceClass, 'entities_id' => $foreignEntity] + $default);
        foreach (array_diff(['Computer', 'NetworkEquipment', 'Peripheral', 'Printer', 'Phone'], $kinds) as $unsupported) {
            verify((new $linkClass())->add([$deviceColumn => $device, 'itemtype' => $unsupported, 'items_id' => 4294997999]) === false, 'Public creation refuses unsupported subject kinds with an otherwise real definition');
        }
        $model = new $linkClass();
        $updates = [];
        $PLUGIN_HOOKS['item_update']['component_ownership_fixture'][$linkClass] = static function (Item_Devices $item) use (&$updates): void {
            $updates[] = [$item->getID(), $item->fields['itemtype'], $item->fields['items_id']];
        };
        $bindings = [];
        foreach ($definition['selections'] as $kind => $selection) {
            $input = [$deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $sameId, 'serial' => "Component O'Reilly \\ 日本語", 'otherserial' => null] + $payload;
            verify($model->can(-1, CREATE, $input), 'Actual actor may create this supported subject binding');
            $id = (int)$model->add(Toolbox::addslashes_deep($input));
            verify(
                $id > 0 && $model->getFromDB($id) && (int)$model->fields[$selection['column']] === $sameId
                && (int)$model->fields['items_id'] === $sameId && (int)$model->fields['entities_id'] === $foreignEntity
                && $model->fields['serial'] === $input['serial'] && $model->fields['otherserial'] === null,
                'Public add publishes actual owning subject, generated wide projection and independent Device entity/literal payload'
            );
            $duplicate = (int)(new $linkClass())->add([$deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $sameId] + $payload);
            verify($duplicate > 0 && $duplicate !== $id, 'Individual duplicate component links remain legitimate');
            $bindings[$kind] = [$id, $duplicate];
            foreach ($definition['selections'] as $otherKind => $other) {
                if ($otherKind !== $kind) {
                    verify($model->fields[$other['column']] === null, 'Same numerical subject IDs in other tables do not leak into owning associations');
                }
            }
        }
        $before = $rows($table, [$deviceColumn => $device]);
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = '0';
        $_SESSION['glpishowallentities'] = false;
        verify(!Session::haveAccessToEntity($foreignEntity), 'Actual narrow scope excludes Device owner');
        $model->addDevices(1, '', 0, $device);
        verify($rows($table, [$deviceColumn => $device]) === $before, 'Real stock command refuses an invisible Device without writes');
        $_SESSION = $admittedSession;
        $model->addDevices(2, '', 0, $device);
        $stock = $repository->stock($table, $deviceColumn, $device);
        verify(count($stock) === 2 && $stock[0]['itemtype'] === null && (int)$stock[0]['items_id'] === 0, 'Actual stock command and ORM stock reader agree on canonical empty identity');
        foreach ($payload as $field => $value) {
            verify((int)$stock[0][$field] === $value, 'Device screen retains actual size/capacity defaults');
        }
        $deletedStock = $fixtures->create($table, [$deviceColumn => $device, 'itemtype' => null, 'items_id' => 0, 'is_deleted' => true] + $payload);
        $stockCandidates = $repository->stock($table, $deviceColumn, $device);
        verify(
            count($stockCandidates) === 3 && (int)$stockCandidates[2]['id'] === $deletedStock && $stockCandidates[2]['is_deleted'],
            'Stock selection retains legitimate historical deleted stock candidates separately from active screen rows'
        );
        $_POST = ['itemtype' => $deviceClass, 'items_id' => $device];
        ob_start();
        try {
            include GLPI_ROOT . '/ajax/selectUnaffectedOrNewItem_Device.php';
            $selection = json_decode(ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        verify($selection['name'] === $deviceColumn && array_keys($selection['options']) === array_column($stockCandidates, 'id'), 'Actual stock AJAX exposes binding IDs with the concrete definition field');
        $deviceModel = new $deviceClass();
        verify($deviceModel->getFromDB($device), 'Actual Device model loaded for scoped rows');
        foreach ($definition['selections'] as $kind => $selection) {
            $foreign = $fixtures->create($selection['target'], ['name' => $prefix . ' hidden ' . $kind, 'entities_id' => $foreignEntity]);
            $hiddenBinding = $fixtures->create($table, [$deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $foreign]);
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = '0';
            $_SESSION['glpishowallentities'] = false;
            verify(array_column($model->getTableGroupRows($deviceModel, $kind), 'id') === $bindings[$kind], 'Actual attached screen scopes the selected subject independently of Device owner');
            $_SESSION['glpiactiveentities'] = [];
            $_SESSION['glpiactiveentities_string'] = '';
            $_SESSION['glpishowallentities'] = true;
            verify($model->getTableGroupRows($deviceModel, $kind) === [], 'Explicit empty entity scope admits no attached asset');
            verify(array_column($model->getTableGroupRows($deviceModel, ''), 'id') === array_column($stock, 'id'), 'Empty attached scope still permits stock without inventing an asset owner');
            $_SESSION = $admittedSession;
            verify($read($table, $hiddenBinding) !== null, 'Scope filtering never deletes the hidden binding');
            verify($repository->assigned($table, $deviceColumn, $kind, $sameId, [$device]) === [], 'Transfer exclusion compares actual Device IDs, not binding identities');
            verify(array_column($repository->assigned($table, $deviceColumn, $kind, $sameId, [$bindings[$kind][0]]), 'id') === $bindings[$kind], 'Excluding a binding number cannot exclude its differently identified Device');
        }
        $firstKind = $kinds[0];
        $id = $bindings[$firstKind][0];
        verify($model->getFromDB($id), 'Reload actual binding for public updates');
        $snapshot = $read($table, $id);
        $updates = [];
        foreach ([['itemtype' => strtolower($firstKind)], ['items_id' => 0], ['items_id' => $sameId + 999],
            ['itemtype' => null, 'items_id' => $sameId], ['itemtype' => $firstKind, 'items_id' => $sameId, $definition['selections'][$firstKind]['column'] => $destinationId]] as $invalid) {
            verify($model->update(['id' => $id] + $invalid) === false && $read($table, $id) === $snapshot, 'Actual invalid discriminator/identity/canonical conflict refuses without persistence');
        }
        verify($updates === [], 'Refused public inputs emit no completion hooks');
        foreach ($definition['selections'] as $kind => $selection) {
            verify($model->update(['id' => $id, 'itemtype' => $kind, 'items_id' => $sameId]) && $model->getFromDB($id), 'Actual update retargets each supported kind with the same numerical identity');
            foreach ($definition['selections'] as $otherKind => $other) {
                verify($kind === $otherKind ? (int)$model->fields[$other['column']] === $sameId : $model->fields[$other['column']] === null, 'Retarget update selects one real owner and clears all old associations');
            }
        }
        verify(
            $model->update(['id' => $id, 'itemtype' => null, 'serial' => null]) && $model->getFromDB($id)
            && $model->fields['itemtype'] === null && (int)$model->fields['items_id'] === 0 && $model->fields['serial'] === null,
            'Supplied null kind/payload returns the component to stock with a cleared projection'
        );
        verify($linkClass::affectItem_Device($id, $sameId, $firstKind), 'Actual public attachment can reassign stock');
        verify(
            $model->update(['id' => $id, 'otherserial' => null]) && $model->getFromDB($id) && $model->fields['itemtype'] === $firstKind,
            'Absent subject keys retain owner while an explicit nullable specificity is accepted'
        );
        $rights = $_SESSION['glpiactiveprofile'];
        $_SESSION['glpiactiveprofile'][$firstKind::$rightname] = 0;
        $_SESSION['glpiactiveprofile'][$deviceClass::$rightname] = 0;
        verify($model->can($id, UPDATE) === false, 'Actual public binding authorization refuses absent subject/Device rights');
        $_SESSION['glpiactiveprofile'] = $rights;

        // Concrete clone callers own the selected override. Whole asset cloning
        // still has the separately tracked historical SQL gap.
        foreach ($definition['selections'] as $kind => $selection) {
            $original = new $linkClass();
            verify($original->getFromDB($bindings[$kind][1]), 'Load each real concrete binding for cloning');
            $copy = (int)$original->clone($entityClass::withReference(['serial' => null], $kind, $destinationId));
            $cloned = $read($table, $copy);
            verify(
                $copy > 0 && $copy !== $bindings[$kind][1] && $cloned['itemtype'] === $kind && (int)$cloned[$selection['column']] === $destinationId
                && (int)$cloned['items_id'] === $destinationId && $cloned['serial'] === null && (int)$cloned[$deviceColumn] === $device,
                'Real concrete cloning retargets ownership, preserves Device and distinguishes supplied null payload'
            );
            $beforeCopies = count($rows($table, ['itemtype' => $kind, $selection['column'] => $destinationId]));
            Item_Devices::cloneItem($kind, $sameId, $destinationId);
            verify(
                count($rows($table, ['itemtype' => $kind, $selection['column'] => $destinationId])) === $beforeCopies + 2,
                'Deprecated actual clone caller retains each distinct selected-family source link'
            );
        }

        // Each supported real asset purge covers keep_devices versus deletion.
        // Financial/contract/project ownership belongs to the binding itself.
        foreach ($definition['selections'] as $kind => $selection) {
            $purgeSource = $fixtures->create($selection['target'], ['name' => $prefix . ' keep ' . $kind]);
            $purgeDestination = $fixtures->create($selection['target'], ['name' => $prefix . ' delete ' . $kind]);
            $binding = $fixtures->create($table, [$deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $purgeSource] + $payload);
            $infocom = $fixtures->create('glpi_infocoms', ['itemtype' => $linkClass, 'items_id' => $binding, 'entities_id' => $foreignEntity]);
            $contract = $fixtures->create('glpi_contracts', ['is_recursive' => true]);
            $contractLink = $fixtures->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => $linkClass, 'items_id' => $binding]);
            $project = $fixtures->create('glpi_projects', ['is_recursive' => true]);
            $projectLink = $fixtures->create('glpi_items_projects', ['projects_id' => $project, 'itemtype' => $linkClass, 'items_id' => $binding]);
            $asset = new $kind();
            verify($asset->getFromDB($purgeSource) && $asset->delete(['id' => $purgeSource, 'keep_devices' => 1], true), 'Actual asset purge returns its components to stock');
            verify(
                $read($table, $binding)[$selection['column']] === null && (int)$read($table, $binding)['items_id'] === 0
                && $read('glpi_infocoms', $infocom) !== null && $read('glpi_contracts_items', $contractLink) !== null && $read('glpi_items_projects', $projectLink) !== null,
                'Stock return retains actual binding-owned financial/contract/project relations'
            );
            verify($linkClass::affectItem_Device($binding, $purgeDestination, $kind), 'Financially linked stock can be reassigned');
            verify($asset->getFromDB($purgeDestination) && $asset->delete(['id' => $purgeDestination, 'keep_devices' => 0], true), 'Actual asset deletion invokes component purge');
            verify(
                $read($table, $binding) === null && $read('glpi_infocoms', $infocom) === null
                && $read('glpi_contracts_items', $contractLink) === null && $read('glpi_items_projects', $projectLink) === null
                && $read('glpi_contracts', $contract) !== null && $read('glpi_projects', $project) !== null,
                'Actual binding purge deletes its links while retaining surviving Contract/Project owners'
            );
        }

        // Same ID in several kinds is a graph key, never a global asset identity.
        $moveDevice = $fixtures->create($deviceTable, ['designation' => $prefix . ' graph ' . $familyIndex]);
        $moving = [];
        $moveBindings = [];
        foreach ($definition['selections'] as $kind => $selection) {
            $moveBindings[$kind] = $fixtures->create($table, [$deviceColumn => $moveDevice, 'itemtype' => $kind, 'items_id' => $destinationId]);
            $moving[$kind] = [$destinationId => true];
        }
        verify($repository->canMoveDevice($table, $deviceColumn, $moveDevice, $moving), 'ORM graph admits the entire kind-qualified mixed subject set');
        if (count($kinds) > 1) {
            verify(!$repository->canMoveDevice($table, $deviceColumn, $moveDevice, [$firstKind => [$destinationId => true]]), 'Same numerical ID of a different unselected kind still requires a Device copy');
        }
        $stockBinding = $fixtures->create($table, [$deviceColumn => $moveDevice, 'itemtype' => '', 'items_id' => 0]);
        verify(!$repository->canMoveDevice($table, $deviceColumn, $moveDevice, $moving), 'Real canonical stock outside the moving graph requires copying');
        verify((new Transfer())->moveItems([$firstKind => [$destinationId]], $transferEntity, ['keep_device' => 1, 'keep_history' => 1]), 'Actual Transfer copies the selected family while stock/mixed-kind users remain outside');
        $moved = $read($table, $moveBindings[$firstKind]);
        verify(
            (int)$moved[$deviceColumn] !== $moveDevice && (int)$moved[$definition['selections'][$firstKind]['column']] === $destinationId
            && (int)$read($deviceTable, $moved[$deviceColumn])['entities_id'] === $transferEntity
            && (int)$read($deviceTable, $moveDevice)['entities_id'] === 0 && (int)$read($table, $stockBinding)[$deviceColumn] === $moveDevice,
            'Actual Transfer preserves original stock/Device ownership and selected generated projection when copying'
        );
        foreach (array_slice($kinds, 1) as $kind) {
            verify((int)$read($table, $moveBindings[$kind])[$deviceColumn] === $moveDevice, 'Unselected colliding-kind binding remains on its own original Device');
        }
        $wholeDevice = $fixtures->create($deviceTable, ['designation' => $prefix . ' entire graph ' . $familyIndex]);
        $wholeGraph = $wholeBindings = [];
        foreach ($definition['selections'] as $kind => $selection) {
            $assetId = $fixtures->create($selection['target'], ['name' => $prefix . ' complete graph ' . $kind]);
            $wholeGraph[$kind] = [$assetId];
            $wholeBindings[] = $fixtures->create($table, [$deviceColumn => $wholeDevice, 'itemtype' => $kind, 'items_id' => $assetId]);
            $wholeBindings[] = $fixtures->create($table, [$deviceColumn => $wholeDevice, 'itemtype' => $kind, 'items_id' => $assetId]);
        }
        verify((new Transfer())->moveItems($wholeGraph, $transferEntity, ['keep_device' => 1]), 'Actual full mixed-kind Transfer admits all duplicate links');
        verify((int)$read($deviceTable, $wholeDevice)['entities_id'] === $transferEntity, 'Whole selected graph moves the original Device');
        foreach ($wholeBindings as $binding) {
            verify((int)$read($table, $binding)[$deviceColumn] === $wholeDevice, 'Whole graph keeps each distinct allocation and original Device identity');
        }
        $beforeBinding = $read($table, $moveBindings[$firstKind]);
        $beforeAsset = $read($definition['selections'][$firstKind]['target'], $destinationId);
        $PLUGIN_HOOKS['pre_item_purge']['component_ownership_fixture'][$linkClass] = static function (Item_Devices $item) use ($moveBindings, $firstKind): void {
            if ((int)$item->getID() === $moveBindings[$firstKind]) {
                $item->input = [];
            }
        };
        verify((new Transfer())->moveItems([$firstKind => [$destinationId]], 0, ['keep_device' => 0]) === false, 'Actual binding purge hook can veto Transfer');
        verify(
            $read($table, $moveBindings[$firstKind]) === $beforeBinding
            && $read($definition['selections'][$firstKind]['target'], $destinationId) === $beforeAsset,
            'Late actual purge veto restores selected parent and binding writes'
        );
        unset($PLUGIN_HOOKS['pre_item_purge']['component_ownership_fixture'][$linkClass]);
        verify($connection->getTransactionNestingLevel() === $level + 1, 'Application commands preserve original caller transaction');
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        $frame->rollBack();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $_POST = $savedPost;
    $plugins->setValue(null, $savedPlugins);
    Glpi\Api\API::$api_url = $savedApiUrl;
    ini_set('display_errors', $savedDisplayErrors);
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Additional owned component rollback failure: ' . (string)$error . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw new RuntimeException('Component caller scope rollback failed.', previous: $cleanup[0]);
}
verify($connection->getTransactionNestingLevel() === $level && (new SchemaCheck())->differences($connection) === [], 'Owned application fixture restores caller depth and complete schema');
echo $DB->getProvider() . ": component property ownership, public lifecycle/scoping/clone/purge/AJAX and mixed-kind transfer ($assertions assertions) passed.\n";
