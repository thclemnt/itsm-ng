<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;
use itsmng\Domain\VlanMembershipService;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/vlan-membership-callers.php /path/to/test-config\n");
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
/** Only expose the real inherited endpoint and its ordinary response boundary. */
final class VlanMembershipApi extends Glpi\Api\APIRest
{
    public function initialize(string $token): void
    {
        $this->parameters = ['app_token' => $token, 'session_token' => session_id()];
        $this->session_write = true;
        $this->initApi();
    }

    public function createMembership(array $input): array
    {
        return parent::createItems(NetworkPort_Vlan::class, ['input' => (object)$input]);
    }

    public function returnResponse($response, $httpcode = 200, $additionalheaders = []): never
    {
        throw new VlanMembershipApiResponse($response, (int)$httpcode);
    }
}
final class VlanMembershipApiResponse extends RuntimeException
{
    public function __construct(public readonly mixed $response, int $status)
    {
        parent::__construct('Actual API response', $status);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
Session::start();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true) && session_id() !== '', 'Actual administrator and PHP session required');
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$savedServer = $_SERVER;
$savedPost = $_POST;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$connection = $DB->getDoctrineConnection();
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before caller tests');
$frame = OwnedMutationFrame::begin($connection);
$primary = null;
$cleanup = [];
$bulk = null;
$events = [];
$blockedRight = null;
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (int $id): ?array => $records()->find(NetworkPort_Vlan::getTable(), 'id', $id);
try {
    $CFG_GLPI['use_notifications'] = false;
    $CFG_GLPI['enable_api'] = true;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['PHP_SELF'] = '/front/massiveaction.php';
    $fixtures = new FixtureRecords($DB);
    $prefix = 'VLAN callers ' . bin2hex(random_bytes(5));
    $computer = $fixtures->create('glpi_computers', ['name' => $prefix . ' Computer']);
    $port = $fixtures->create('glpi_networkports', ['name' => $prefix . ' port', 'itemtype' => 'Computer', 'items_id' => $computer]);
    $vlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' VLAN']);
    $apiVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' API VLAN']);
    $apiDefaultVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' API omitted tagged VLAN']);
    $bulkVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' massive VLAN']);
    $apiToken = bin2hex(random_bytes(32));
    $client = $fixtures->create('glpi_apiclients', ['name' => $prefix . ' active API client', 'is_active' => 1, 'app_token' => $apiToken, 'dolog_method' => 0]);
    verify($client > 0, 'Actual active API client is owned by this caller frame');
    $api = new VlanMembershipApi();
    $api->initialize($apiToken);
    $plugins->setValue(null, [...$savedPlugins, 'vlan_membership_callers']);
    $PLUGIN_HOOKS['item_can']['vlan_membership_callers'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$blockedRight): void {
        if ($model->right === $blockedRight) {
            $model->right = false;
        }
    };
    $PLUGIN_HOOKS['item_add']['vlan_membership_callers'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use (&$events): void {
        $events[] = [$model->getID(), $model->fields['networkports_id'], $model->fields['vlans_id'], $model->fields['tagged']];
    };
    $service = new VlanMembershipService($DB);
    // Characterize the existing SAME/VIEW/dynamic-parent admission, rather than inventing a port UPDATE requirement.
    $_SESSION['glpiactiveprofile']['computer'] = READ;
    $_SESSION['glpiactiveprofile']['networking'] = READ;
    $_SESSION['glpiactiveprofile']['dropdown'] = READ;
    $input = ['networkports_id' => $port, 'vlans_id' => $vlan, 'tagged' => 0];
    $relation = new NetworkPort_Vlan();
    verify(NetworkPort_Vlan::$checkItem_2_Rights === CommonDBConnexity::HAVE_VIEW_RIGHT_ON_ITEM && NetworkPort::canUpdate(), 'Actual declared VLAN view role and dynamic port static policy remain unchanged');
    verify($relation->can(-1, CREATE, $input) && $relation->canCreateItem(), 'Existing visible read-only parent combination retains actual relation admission');
    verify(!$relation->canRelationItem('canUpdateItem', 'canUpdate', true, true), 'Force-both still asks the dynamic port parent for its actual update policy');
    $formInput = $input;
    verify((new NetworkPort_Vlan())->can(-1, UPDATE, $formInput), 'The actual front form UPDATE guard retains its framework role policy');
    // The PHP form exits through Html::back; this invokes its actual guard/helper pipeline without claiming HTTP verification.
    $id = (new NetworkPort_Vlan())->assignVlan($formInput['networkports_id'], $formInput['vlans_id'], $formInput['tagged']);
    verify($id > 0 && $read($id)['tagged'] === 0, 'Admitted form helper persists its requested untagged pair');
    $apiResult = $api->createMembership(['networkports_id' => $port, 'vlans_id' => $apiVlan, 'tagged' => true]);
    verify(is_int($apiResult['id']) && $apiResult['id'] > 0 && $read($apiResult['id'])['tagged'] === 1, 'Actual inherited API create uses the same relation roles and normal public lifecycle');
    verify(in_array([$apiResult['id'], $port, $apiVlan, 1], $events, true), 'Actual API producer reaches the normal membership callback');
    $apiDefault = $api->createMembership(['networkports_id' => $port, 'vlans_id' => $apiDefaultVlan]);
    verify(is_int($apiDefault['id']) && $apiDefault['id'] > 0 && $read($apiDefault['id'])['tagged'] === 0,
        'Actual inherited API creation preserves omitted tagged as the metadata-owned false default');
    verify(in_array([$apiDefault['id'], $port, $apiDefaultVlan, 0], $events, true), 'Omitted API flag still reaches the actual persisted lifecycle callback');
    $_SESSION['glpiactiveprofile']['dropdown'] = 0;
    $invisible = ['networkports_id' => $port, 'vlans_id' => $bulkVlan, 'tagged' => 1];
    verify(!(new NetworkPort_Vlan())->can(-1, CREATE, $invisible), 'A VLAN VIEW role still requires actual VLAN visibility');
    $before = $service->membershipsForPort($port);
    $response = null;
    try {
        $api->createMembership($invisible);
    } catch (VlanMembershipApiResponse $error) {
        $response = $error;
    }
    verify($response !== null && $response->getCode() === 400 && $service->membershipsForPort($port) === $before, 'Actual API permission failure creates no relation');
    $_SESSION['glpiactiveprofile']['dropdown'] = READ;
    $_SESSION['glpiactiveprofile']['computer'] = 0;
    $hiddenPort = ['networkports_id' => $port, 'vlans_id' => $bulkVlan, 'tagged' => 0];
    verify(!(new NetworkPort_Vlan())->can(-1, CREATE, $hiddenPort), 'A readable VLAN does not replace dynamic port-parent visibility');
    $_SESSION = $savedSession;
    $blockedRight = CREATE;
    $restricted = ['networkports_id' => $port, 'vlans_id' => $bulkVlan, 'tagged' => 1];
    verify(!(new NetworkPort_Vlan())->can(-1, CREATE, $restricted), 'Actual restrictive item_can hook remains authoritative');
    $blockedRight = null;

    // Run the genuine constructor stages and processor; no supplied specific_actions or overridden getters.
    $initial = new MassiveAction(['item' => [NetworkPort::class => [$port => 1]], 'check_itemtype' => Computer::class, 'check_items_id' => $computer], [], 'initial');
    $next = $initial->getInput();
    $action = NetworkPort_Vlan::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'add';
    verify(isset($next['actions'][$action]), 'Normal massive-action discovery offers the admitted VLAN association');
    $next['action'] = $action;
    $specialize = new MassiveAction($next, [], 'specialize');
    $next = $specialize->getInput();
    $next['peer_vlans_id'] = $bulkVlan;
    $next['tagged'] = 1;
    $bulk = new MassiveAction($next, [], 'process');
    $buffer = ob_get_level();
    ob_start();
    try {
        $result = $bulk->process();
    } finally {
        while (ob_get_level() > $buffer) {
            ob_end_clean();
        }
    }
    verify($result['ok'] === 1 && $result['ko'] === 0 && $result['noright'] === 0, 'Actual massive-action processor uses the original relation CREATE policy');
    $bulkRows = $records()->matching(NetworkPort_Vlan::getTable(), ['networkports_id' => $port, 'vlans_id' => $bulkVlan]);
    verify(count($bulkRows) === 1 && $bulkRows[0]['tagged'] === 1 && in_array([$bulkRows[0]['id'], $port, $bulkVlan, 1], $events, true), 'Actual massive action retains tagged intent and ordinary callbacks');
    unset($initial, $specialize, $bulk);
    $bulk = null;

    $hiddenEntity = new Entity();
    $entityInput = ['name' => $prefix . ' hidden Entity', 'entities_id' => 0];
    verify($hiddenEntity->can(-1, CREATE, $entityInput), 'Caller can create the real hidden-scope fixture');
    $hiddenEntityId = $hiddenEntity->add($entityInput);
    $foreignComputer = $fixtures->create('glpi_computers', ['name' => $prefix . ' foreign Computer', 'entities_id' => $hiddenEntityId]);
    $foreignPort = $fixtures->create('glpi_networkports', ['name' => $prefix . ' foreign port', 'itemtype' => 'Computer', 'items_id' => $foreignComputer, 'entities_id' => $hiddenEntityId]);
    $foreignVlan = $fixtures->create('glpi_vlans', ['name' => $prefix . ' foreign VLAN', 'entities_id' => $hiddenEntityId]);
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $hidden = ['networkports_id' => $foreignPort, 'vlans_id' => $foreignVlan, 'tagged' => 0];
    verify(!Session::haveAccessToEntity($hiddenEntityId) && !(new NetworkPort_Vlan())->can(-1, CREATE, $hidden), 'Actual parent entity scoping remains separate from coherent endpoint ownership');
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    verify(!(new NetworkPort_Vlan())->can(-1, CREATE, $hidden), 'Empty caller scope does not admit the foreign membership');
    $_SESSION = $savedSession;
    $frame->assertActive();
    verify((new SchemaCheck())->differences($connection) === [], 'Caller workflows leave schema unchanged');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    unset($bulk);
    try {
        $frame->rollBack();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $plugins->setValue(null, $savedPlugins);
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI = $savedConfiguration;
    $_SESSION = $savedSession;
    $_SERVER = $savedServer;
    $_POST = $savedPost;
}
foreach ($cleanup as $error) {
    $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error, true);
}
if ($primary !== null) {
    throw $primary;
}
echo 'VLAN membership callers: ' . $assertions . " assertions passed\n";
