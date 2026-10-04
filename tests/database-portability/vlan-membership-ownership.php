<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;
use itsmng\Domain\VlanMembershipService;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/vlan-membership-ownership.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
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
Session::start();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$connection = $DB->getDoctrineConnection();
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before command tests');
$frame = OwnedMutationFrame::begin($connection);
$primary = null;
$cleanup = [];
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$snapshot = static function () use ($connection): array {
    $result = [];
    foreach ([NetworkPort_Vlan::getTable(), NetworkPort::getTable(), Vlan::getTable(), Entity::getTable(), Log::getTable(), QueuedNotification::getTable()] as $table) {
        $result[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY ' . $connection->quoteIdentifier('id'));
    }
    return $result;
};
$mode = '';
$added = [];
$updated = [];
$primaryHookFailure = new RuntimeException('Actual VLAN membership item_update failure');
try {
    $CFG_GLPI['use_notifications'] = false;
    $sessionInstant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$_SESSION['glpi_currenttime'], new DateTimeZone('UTC'));
    verify($sessionInstant !== false && $sessionInstant->format('Y-m-d H:i:s') === $_SESSION['glpi_currenttime'], 'Actual session instant is a valid queue timestamp');
    $futureInstant = $sessionInstant->modify('+1 day');
    verify($futureInstant > $sessionInstant && $futureInstant >= new DateTimeImmutable('1970-01-01 00:00:01', new DateTimeZone('UTC'))
        && $futureInstant <= new DateTimeImmutable('2038-01-19 03:14:07', new DateTimeZone('UTC')), 'Future queue fixture fits the preserved native MySQL/MariaDB TIMESTAMP domain');
    $futureQueueTime = $futureInstant->format('Y-m-d H:i:s');
    $plugins->setValue(null, [...$savedPlugins, 'vlan_membership_fixture']);
    $PLUGIN_HOOKS['pre_item_add']['vlan_membership_fixture'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$mode): void {
        if ($mode === 'cancel-add') {
            $model->input = false;
        }
    };
    $PLUGIN_HOOKS['post_prepareadd']['vlan_membership_fixture'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$mode): void {
        if ($mode === 'missing-after-preparation') {
            $model->input['vlans_id'] = PHP_INT_MAX;
        }
    };
    $PLUGIN_HOOKS['item_add']['vlan_membership_fixture'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$mode, &$added): void {
        $added[] = [$model->getID(), $model->fields['networkports_id'], $model->fields['vlans_id'], $model->fields['tagged']];
        if ($mode === 'late-input') {
            $model->input['vlans_id'] = PHP_INT_MAX;
        }
    };
    $PLUGIN_HOOKS['item_update']['vlan_membership_fixture'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$mode, &$updated, $primaryHookFailure, $futureQueueTime): void {
        $updated[] = [$model->getID(), $model->fields['tagged']];
        if ($mode === 'throw-after-history') {
            $_SESSION['vlan_membership_attempt'] = 'Actual public callback state';
            $queue = (new QueuedNotification())->add(['itemtype' => NetworkPort_Vlan::class, 'items_id' => $model->getID(), 'mode' => 'mail', 'send_time' => $futureQueueTime, 'name' => 'VLAN callback queued work']);
            verify($queue > 0, 'Actual public callback queues future work before its primary failure');
            throw $primaryHookFailure;
        }
        if ($mode === 'reload-current') {
            verify($model->getFromDB($model->getID()), 'A real post-update callback may reload its own persisted membership');
        }
        if ($mode === 'late-identity') {
            $model->fields['networkports_id'] = PHP_INT_MAX;
        }
    };
    $fixtures = new FixtureRecords($DB);
    $prefix = 'VLAN membership ' . bin2hex(random_bytes(5));
    $computer = $fixtures->create('glpi_computers', ['name' => $prefix . ' Computer']);
    $port = $fixtures->create('glpi_networkports', ['name' => $prefix . ' port', 'itemtype' => 'Computer', 'items_id' => $computer]);
    $port2 = $fixtures->create('glpi_networkports', ['name' => $prefix . ' second', 'itemtype' => 'Computer', 'items_id' => $computer]);
    $vlan = $fixtures->create('glpi_vlans', ['name' => $prefix . " VLAN O'Reilly \\ 日本語", 'comment' => null, 'tag' => 51]);
    $vlan2 = $fixtures->create('glpi_vlans', ['name' => $prefix . ' second VLAN', 'tag' => 52]);
    $service = new VlanMembershipService($DB);
    $before = $snapshot();
    $model = new NetworkPort_Vlan();
    $id = $model->assignVlan($port, $vlan, 0);
    verify(is_int($id) && $id > 0, 'Public natural-key assignment returns its real new identity');
    $native = $read(NetworkPort_Vlan::getTable(), $id);
    verify($native['networkports_id'] === $port && $native['vlans_id'] === $vlan && $native['tagged'] === 0, 'Both owning associations and false semantics persist');
    verify($added === [[$id, $port, $vlan, 0]], 'Ordinary public item_add receives its actual persisted scalar context');
    $newLogs = array_slice($snapshot()[Log::getTable()], count($before[Log::getTable()]));
    verify(count(array_filter($newLogs, static fn (array $row): bool => $row['itemtype'] === NetworkPort::class && (int)$row['items_id'] === $port)) > 0
        && count(array_filter($newLogs, static fn (array $row): bool => $row['itemtype'] === Vlan::class && (int)$row['items_id'] === $vlan)) > 0,
        'The unchanged relation lifecycle audits both actual owning parents');
    verify($service->membershipsForPort($port) === [['id' => $id, 'networkports_id' => $port, 'vlans_id' => $vlan, 'tagged' => false]], 'Supplied writer ORM reads include its uncommitted assignment with native boolean semantics');
    $portRows = $service->forPort($port);
    $vlanRows = $service->forVlan($vlan);
    verify($portRows[0]['assocID'] === $id && $portRows[0]['id'] === $vlan && $portRows[0]['name'] === $prefix . " VLAN O'Reilly \\ 日本語" && $portRows[0]['comment'] === null
        && $vlanRows[0]['assocID'] === $id && $vlanRows[0]['id'] === $port && $vlanRows[0]['items_id'] === $computer,
        'Screens preserve relation identity separately from the displayed endpoint identity');
    verify(NetworkPort_Vlan::getVlansForNetworkPort($port) === [$vlan => $vlan], 'The public VLAN-ID compatibility map preserves its keys');
    verify($service->countForPort($port) === 1 && $service->countForVlan($vlan) === 1, 'Association counts retain both roles');
    $defaultVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' omitted tagged VLAN']);
    $defaultModel = new NetworkPort_Vlan();
    $defaultId = $defaultModel->add(['networkports_id' => $port2, 'vlans_id' => $defaultVlan]);
    verify(is_int($defaultId) && $defaultId > 0 && $read(NetworkPort_Vlan::getTable(), $defaultId)['tagged'] === 0,
        'Actual public add preserves omitted tagged and the existing property-owned false default');
    verify(!array_key_exists('tagged', $defaultModel->input) && in_array([$defaultId, $port2, $defaultVlan, 0], $added, true),
        'Omitted tagged remains absent in prepared input while the real persisted callback sees false');
    verify($defaultModel->unassignVlan($port2, $defaultVlan), 'Remove only the additional actual omitted-default control');
    $duplicateBefore = $snapshot();
    $duplicate = null;
    try {
        (new NetworkPort_Vlan())->assignVlan($port, $vlan, 1);
    } catch (UniqueConstraintViolationException $error) {
        $duplicate = $error;
    }
    verify($duplicate !== null && $snapshot() === $duplicateBefore, 'A duplicate remains an actual unique refusal and never becomes a tagged upsert');
    $frame->assertActive();
    verify($model->update(['id' => $id, 'tagged' => '1']), 'Public tagged update uses ordinary preparation and lifecycle');
    verify($read(NetworkPort_Vlan::getTable(), $id)['tagged'] === 1 && $updated === [[$id, 1]], 'Tagged update preserves exact boolean and callback context');
    verify($service->membershipsForPort($port)[0]['tagged'] === true, 'A reused read service refreshes after an actual public writer');
    // This is a real supplied-connection write inside the caller frame, not a fabricated result.
    $connection->executeStatement('UPDATE ' . $connection->quoteIdentifier(NetworkPort_Vlan::getTable()) . ' SET tagged = ? WHERE id = ?', [false, $id], [Doctrine\DBAL\Types\Types::BOOLEAN, Doctrine\DBAL\Types\Types::BIGINT]);
    verify($service->forPort($port)[0]['tagged'] === 0, 'Operation-local ORM endpoint projection sees a later uncommitted legacy-compatible write');
    verify($model->getFromDB($id) && $model->update(['id' => $id, 'tagged' => 0]), 'An accepted unchanged public update preserves its strict natural key');
    $mode = 'reload-current';
    verify($model->update(['id' => $id, 'tagged' => 1]) && $model->fields['tagged'] === 1, 'A genuine late same-model read retains the prepared current membership');
    $mode = '';
    verify($model->update(['id' => $id, 'tagged' => 0]), 'Restore false before refusal controls');
    verify($model->update(['id' => $id, 'networkports_id' => $port2]) && $read(NetworkPort_Vlan::getTable(), $id)['networkports_id'] === $port2,
        'A genuine endpoint reassignment retains ordinary proposed CREATE and stored DELETE/PURGE checks');
    verify($model->update(['id' => $id, 'networkports_id' => $port]) && $read(NetworkPort_Vlan::getTable(), $id)['networkports_id'] === $port,
        'The same relation identity can return to its authorized natural pair');


    foreach (['cancel-add', 'missing-after-preparation'] as $mode) {
        $before = $snapshot();
        verify((new NetworkPort_Vlan())->assignVlan($port2, $vlan2, 1) === false, 'Actual preparation refusal ' . $mode);
        verify($snapshot() === $before, 'Preparation refusal changes no membership, endpoint, audit or queue row ' . $mode);
        $frame->assertActive();
    }
    $mode = 'late-input';
    $before = $snapshot();
    $lateInput = null;
    try {
        (new NetworkPort_Vlan())->assignVlan($port2, $vlan2, 1);
    } catch (RuntimeException $error) {
        $lateInput = $error;
    }
    verify($lateInput !== null && $snapshot() === $before, 'A genuine item_add amendment cannot change the selected tuple after persistence; history rolls back');
    foreach (['throw-after-history', 'late-identity'] as $mode) {
        verify($model->getFromDB($id), 'Reload before actual update hook ' . $mode);
        $before = $snapshot();
        $beforeModel = $model->fields;
        $beforeSession = $_SESSION;
        $failure = null;
        try {
            $model->update(['id' => $id, 'tagged' => 1]);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        verify($failure !== null && ($mode !== 'throw-after-history' || $failure === $primaryHookFailure), 'The actual update hook refusal retains its primary cause ' . $mode);
        verify($snapshot() === $before && $model->fields === $beforeModel, 'Proven owned rollback restores native data and the actual loaded model ' . $mode);
        verify(!array_key_exists('vlan_membership_attempt', $_SESSION) && ($_SESSION['vlan_membership_attempt'] ?? null) === ($beforeSession['vlan_membership_attempt'] ?? null), 'Proven rollback removes callback-owned Session state');
        $frame->assertActive();
    }
    $mode = '';
    $before = $snapshot();
    verify((new NetworkPort_Vlan())->assignVlan(PHP_INT_MAX, $vlan, 0) === false
        && (new NetworkPort_Vlan())->assignVlan($port, PHP_INT_MAX, 0) === false, 'Missing typed targets refuse before membership insertion');
    verify($snapshot() === $before, 'Missing targets leave parent histories and queue untouched');
    foreach ([null, 2, 'false'] as $invalidTagged) {
        $priorFeedback = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] = ['Earlier VLAN boolean feedback'];
        $result = (new NetworkPort_Vlan())->assignVlan($port2, $vlan2, $invalidTagged);
        $diagnostic = 'Invalid boolean value: ' . NetworkPort_Vlan::getTable() . '.tagged. Expected zero or one; received ' . get_debug_type($invalidTagged) . '.';
        verify($result === false && $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] === ['Earlier VLAN boolean feedback', $diagnostic],
            'Actual public lifecycle rejects invalid nonnullable tagged input and retains its field-owned Session error');
        verify($snapshot() === $before, 'Every explicit NULL/arbitrary truthy boolean refusal leaves the native graph unchanged');
        $frame->assertActive();
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $priorFeedback;
    }

    $model->getFromDB($id);
    $loadedBeforeMissing = $read(NetworkPort_Vlan::getTable(), $id);
    verify($model->unassignVlan($port2, $vlan2) === false && $read(NetworkPort_Vlan::getTable(), $id) === $loadedBeforeMissing, 'A missing natural key does not delete a previously loaded different membership');
    verify($model->unassignVlan($port, $vlan) && $read(NetworkPort_Vlan::getTable(), $id) === null, 'Natural-key removal deletes only its selected stored relation');
    verify($model->unassignVlan($port, $vlan) === false && $service->countForPort($port) === 0, 'Repeated missing removal is a false no-op');

    $entity = new Entity();
    $childInput = ['name' => $prefix . ' child', 'entities_id' => 0];
    verify($entity->can(-1, CREATE, $childInput), 'Actor may create a real child Entity');
    $child = $entity->add($childInput);
    verify($child > 0 && Session::haveAccessToEntity($child), 'Actual Entity lifecycle grants creator scope');
    $childComputer = $fixtures->create('glpi_computers', ['name' => $prefix . ' child Computer', 'entities_id' => $child]);
    $childPort = $fixtures->create('glpi_networkports', ['name' => $prefix . ' child port', 'itemtype' => 'Computer', 'items_id' => $childComputer, 'entities_id' => $child]);
    $recursive = $fixtures->create('glpi_vlans', ['name' => $prefix . ' recursive VLAN', 'entities_id' => 0, 'is_recursive' => true]);
    $nonrecursive = $fixtures->create('glpi_vlans', ['name' => $prefix . ' local root VLAN', 'entities_id' => 0, 'is_recursive' => false]);
    verify((new NetworkPort_Vlan())->assignVlan($childPort, $recursive, 1) > 0, 'Actual recursive ancestor VLAN can serve a descendant port');
    $before = $snapshot();
    verify((new NetworkPort_Vlan())->assignVlan($childPort, $nonrecursive, 1) === false && $snapshot() === $before, 'The command retains entity coherency for a nonrecursive cross-entity proposal');
    $grandchildInput = ['name' => $prefix . ' cycle grandchild', 'entities_id' => $child];
    verify((new Entity())->can(-1, CREATE, $grandchildInput), 'Create the actual second Entity for the cycle control');
    $grandchild = (new Entity())->add($grandchildInput);
    verify($grandchild > 0, 'The cycle control starts from two distinct valid persisted Entities');
    $cycleVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' cycle ancestor VLAN', 'entities_id' => $grandchild, 'is_recursive' => true]);
    $connection->executeStatement('UPDATE ' . $connection->quoteIdentifier(Entity::getTable()) . ' SET entities_id = ? WHERE id = ?', [$grandchild, $child]);
    $cycleBefore = $snapshot();
    verify((int)$read(Entity::getTable(), $child)['entities_id'] === $grandchild && (int)$read(Entity::getTable(), $grandchild)['entities_id'] === $child,
        'Both actual native owning edges form a genuine two-node cycle');
    $cycleFailure = null;
    try {
        (new NetworkPort_Vlan())->assignVlan($childPort, $cycleVlan, 0);
    } catch (UnexpectedValueException $error) {
        $cycleFailure = $error;
    }
    verify($cycleFailure !== null && $snapshot() === $cycleBefore, 'An encountered selected ancestor inside a cycle cannot authorize a membership or change history');
    $connection->executeStatement('UPDATE ' . $connection->quoteIdentifier(Entity::getTable()) . ' SET entities_id = ? WHERE id = ?', [0, $child]);


    $cloneSource = $fixtures->create('glpi_computers', ['name' => $prefix . ' legacy clone source']);
    $cloneDestination = $fixtures->create('glpi_computers', ['name' => $prefix . ' legacy clone destination']);
    $clonePort = $fixtures->create('glpi_networkports', ['name' => $prefix . ' clone port', 'itemtype' => 'Computer', 'items_id' => $cloneSource, 'instantiation_type' => '']);
    verify((new NetworkPort_Vlan())->assignVlan($clonePort, $vlan2, 1) > 0, 'Prepare real tagged legacy clone membership');
    NetworkPort::cloneItem(Computer::class, $cloneSource, $cloneDestination);
    $copied = $records()->matching(NetworkPort::getTable(), ['itemtype' => Computer::class, 'items_id' => $cloneDestination], ['id']);
    verify(count($copied) === 1 && $service->membershipsForPort($copied[0]['id'])[0]['vlans_id'] === $vlan2
        && $service->membershipsForPort($copied[0]['id'])[0]['tagged'] === true, 'The deprecated public clone retains its existing tagged membership copy through actual public add');
    $sourcePort = new NetworkPort();
    verify($sourcePort->getFromDB($clonePort), 'Load source for modern public clone characterization');
    $modernPort = $sourcePort->clone(['items_id' => $cloneDestination, 'name' => $prefix . ' modern port']);
    verify($modernPort > 0 && $service->membershipsForPort($modernPort) === [], 'Current NetworkPort post_clone still does not invent a VLAN copy');
    $relationSource = new NetworkPort_Vlan();
    $relationSourceId = $service->membershipsForPort($clonePort)[0]['id'];
    verify($relationSource->getFromDB($relationSourceId), 'Load actual relation clone source');
    $relationCopy = $relationSource->clone(['networkports_id' => $modernPort]);
    verify($relationCopy > 0 && $read(NetworkPort_Vlan::getTable(), $relationCopy)['tagged'] === 1, 'Ordinary public relation clone retains its new natural key and true flag');

    $purgePort = $fixtures->create('glpi_networkports', ['name' => $prefix . ' purge port', 'itemtype' => 'Computer', 'items_id' => $computer]);
    $purgeVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' purge VLAN']);
    $purgeLink = (new NetworkPort_Vlan())->assignVlan($purgePort, $purgeVlan, 0);
    $veto = true;
    $PLUGIN_HOOKS['pre_item_purge']['vlan_membership_fixture'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$veto): void {
        if ($veto) {
            $model->input = false;
        }
    };
    $before = $snapshot();
    verify(!(new Vlan())->delete(['id' => $purgeVlan], 1) && $snapshot() === $before, 'A genuine required-child veto rolls back the parent purge');
    $veto = false;
    verify((new Vlan())->delete(['id' => $purgeVlan], 1) && $read(NetworkPort_Vlan::getTable(), $purgeLink) === null, 'VLAN parent purge retains child lifecycle before its owning FK');
    $purgeLink = (new NetworkPort_Vlan())->assignVlan($purgePort, $vlan2, 1);
    verify((new NetworkPort())->delete(['id' => $purgePort], 1) && $read(NetworkPort_Vlan::getTable(), $purgeLink) === null, 'Port parent purge retains child lifecycle before its owning FK');
    $frame->assertActive();
    verify((new SchemaCheck())->differences($connection) === [], 'Application workflow leaves canonical schema unchanged');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        $frame->rollBack();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $plugins->setValue(null, $savedPlugins);
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI = $savedConfiguration;
    $_SESSION = $savedSession;
}
foreach ($cleanup as $error) {
    $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error, true);
}
if ($primary !== null) {
    throw $primary;
}
echo 'VLAN membership ownership: ' . $assertions . " assertions passed\n";
