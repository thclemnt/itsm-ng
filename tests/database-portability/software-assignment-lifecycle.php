<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-assignment-lifecycle.php /path/to/test-config\n");
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
final class SoftwareAssignmentApiResponse extends RuntimeException
{
    public function __construct(public array $response, int $status)
    {
        parent::__construct(json_encode($response, JSON_THROW_ON_ERROR), $status);
    }
}

final class SoftwareAssignmentApiProbe extends \Glpi\Api\APIRest
{
    public function configure(int $client): void
    {
        $this->session_write = true;
        $this->app_tokens = [$client => 'software-assignment-fixture'];
        $this->parameters = ['app_token' => 'software-assignment-fixture', 'session_token' => session_id()];
    }
    public function owner(string $kind, int $id): array
    {
        return $this->getItem($kind, $id, ['get_hateoas' => false, 'with_softwares' => true]);
    }
    public function retarget(string $kind, array $input): array
    {
        try {
            return $this->updateItems($kind, ['input' => [(object)$input]]);
        } catch (SoftwareAssignmentApiResponse $response) {
            if ($response->getCode() === 400 && ($response->response[0] ?? null) === 'ERROR_GLPI_UPDATE'
                && is_array($response->response[1] ?? null)
                && count($response->response[1]) === 1
                && ($response->response[1][0][$input['id']] ?? null) === false
                && is_string($response->response[1][0]['message'] ?? null)) {
                return $response->response[1];
            }
            throw $response;
        }
    }
    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new SoftwareAssignmentApiResponse($response, $httpcode);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'software_assignment_fixture']);
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): array => $records()->find($table, 'id', $id);
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$DB->beginTransaction();
try {
    $entity = $fixtures->create('glpi_entities', ['name' => 'Software assignment owner']);
    $sibling = $fixtures->create('glpi_entities', ['name' => 'Hidden software assignment owner']);
    $software = $fixtures->create('glpi_softwares', ['entities_id' => $entity, 'name' => 'Assignment API software']);
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software, 'entities_id' => $entity]);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'entities_id' => $entity, 'number' => 0]);
    $otherLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'entities_id' => $entity, 'number' => 0]);
    $hiddenSoftware = $fixtures->create('glpi_softwares', ['entities_id' => $sibling]);
    $hiddenLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $hiddenSoftware, 'entities_id' => $sibling]);
    $owner = $fixtures->create('glpi_monitors', ['entities_id' => $entity]);
    $otherOwner = $fixtures->create('glpi_monitors', ['entities_id' => $entity, 'is_deleted' => true]);
    $hiddenOwner = $fixtures->create('glpi_monitors', ['entities_id' => $sibling]);
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactiveentities_string'] = (string)$entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $client = $fixtures->create('glpi_apiclients', ['name' => 'Software assignment fixture', 'dolog_method' => 0]);
    $api = new SoftwareAssignmentApiProbe();
    $api->configure($client);
    $install = (new Item_SoftwareVersion())->add(['itemtype' => 'Monitor', 'items_id' => $owner, 'softwareversions_id' => $version, 'is_dynamic' => true]);
    $link = (new Item_SoftwareLicense())->add(['itemtype' => 'Monitor', 'items_id' => $owner, 'softwarelicenses_id' => $license]);
    verify($install > 0 && $link > 0, 'Actual assignment creation');
    $expanded = $api->owner('Monitor', $owner)['_softwares'];
    verify(count($expanded) === 1 && $expanded[0]['is_dynamic'] === 1 && is_int($expanded[0]['is_valid']), 'Actual API preserves numeric boolean flags');
    verify($expanded[0]['states_id'] === null && $expanded[0]['softwarecategories_id'] === null, 'Actual API preserves nullable optional associations');
    $beforeValidity = $read('glpi_softwarelicenses', $license);
    verify(!$beforeValidity['is_valid'] && $read('glpi_softwarelicenses', $otherLicense)['is_valid'], 'Source validity reflects finite over-allocation');
    foreach ([Item_SoftwareVersion::class => [$install, 'glpi_items_softwareversions'], Item_SoftwareLicense::class => [$link, 'glpi_items_softwarelicenses']] as $kind => [$id, $table]) {
        verify((new $kind())->can($id, UPDATE), 'Fixture actor can update the stored assignment ' . $kind);
        verify((new $kind())->can($id, DELETE) && (new $kind())->can($id, PURGE), 'Fixture actor may remove the original attachment ' . $kind);
        $proposed = ['itemtype' => 'Monitor', 'monitors_id' => $otherOwner]
            + ($kind === Item_SoftwareVersion::class ? ['softwareversions_id' => $version] : ['softwarelicenses_id' => $license]);
        verify((new $kind())->can(-1, CREATE, $proposed), 'Fixture actor can create the proposed visible relationship ' . $kind);
        $snapshot = $read($table, $id);
        $history = $records()->countMatching('glpi_logs', []);
        $response = $api->retarget($kind, ['id' => $id, 'monitors_id' => $hiddenOwner]);
        verify($response[0][$id] === false, 'Actual API refuses canonical-only hidden endpoint retarget ' . $kind);
        verify($read($table, $id) === $snapshot && $records()->countMatching('glpi_logs', []) === $history, 'Refused canonical retarget changes neither row nor history ' . $kind);
        verify($read('glpi_softwarelicenses', $license) === $beforeValidity, 'Refused retarget preserves licence validity');
        verify($api->retarget($kind, ['id' => $id, 'monitors_id' => $otherOwner])[0][$id] === true, 'Actual API accepts canonical-only authorized endpoint retarget ' . $kind);
        $changed = $read($table, $id);
        verify($changed['monitors_id'] === $otherOwner && $changed['items_id'] === $otherOwner, 'Canonical retarget refreshes compatibility projection ' . $kind);
        if ($kind === Item_SoftwareVersion::class) {
            verify($changed['is_deleted_item'] && (int)$changed['entities_id'] === $entity, 'Actual installation retarget forwards subject deletion/entity cache');
        }
        verify($api->retarget($kind, ['id' => $id, 'itemtype' => 'Monitor', 'items_id' => $owner])[0][$id] === true, 'Legacy endpoint retarget preserves existing authorization ' . $kind);
    }
    $snapshot = $read('glpi_items_softwarelicenses', $link);
    $history = $records()->countMatching('glpi_logs', []);
    verify($api->retarget(Item_SoftwareLicense::class, ['id' => $link, 'softwarelicenses_id' => $hiddenLicense])[0][$link] === false, 'Existing proposed-parent guard refuses hidden licence');
    verify($read('glpi_items_softwarelicenses', $link) === $snapshot && $records()->countMatching('glpi_logs', []) === $history, 'Refused parent update has no row/history side effects');
    verify($api->retarget(Item_SoftwareLicense::class, ['id' => $link, 'softwarelicenses_id' => $otherLicense])[0][$link] === true, 'Actual API accepts visible licence reassignment');
    verify($read('glpi_softwarelicenses', $license)['is_valid'] && !$read('glpi_softwarelicenses', $otherLicense)['is_valid'], 'Successful licence reassignment refreshes both old and new validity');

    $activeScope = $_SESSION;
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    $_SESSION['glpishowallentities'] = true;
    foreach ([Item_SoftwareVersion::class => [$install, 'glpi_items_softwareversions', 'softwareversions_id', $version], Item_SoftwareLicense::class => [$link, 'glpi_items_softwarelicenses', 'softwarelicenses_id', $otherLicense]] as $kind => [$id, $table, $parentField, $parent]) {
        $before = $read($table, $id);
        $history = $records()->countMatching('glpi_logs', []);
        $proposed = ['itemtype' => 'Monitor', 'monitors_id' => $owner, $parentField => $parent];
        verify(!(new $kind())->can(-1, CREATE, $proposed), 'Actual CREATE refuses empty scope despite stale show-all flag ' . $kind);
        verify($api->retarget($kind, ['id' => $id, 'monitors_id' => $otherOwner])[0][$id] === false, 'Actual API refuses update from empty scope ' . $kind);
        verify($read($table, $id) === $before && $records()->countMatching('glpi_logs', []) === $history, 'Empty-scope refusal preserves row and audit ' . $kind);
    }
    $_SESSION = $activeScope;

    $_SESSION = $savedSession;
    $_SESSION['glpiactiveentities'] = [0, $entity];
    $_SESSION['glpiactiveentities_string'] = '0,' . $entity;
    $_SESSION['glpishowallentities'] = false;
    foreach (['finite' => 2, 'unlimited' => -1, 'new' => 0, 'last' => 2, 'zero-source' => 2, 'unlimited-source' => 2, 'unlimited-source-new' => 0, 'same' => 2, 'duplicates' => 2, 'literal' => 2, 'cached-zero' => 2, 'cached-negative' => 2, 'veto' => 2, 'discard' => 2] as $scenario => $destinationNumber) {
        $sourceSoftware = $fixtures->create('glpi_softwares', ['name' => 'Transfer software ' . $scenario]);
        $destinationSoftware = $fixtures->create('glpi_softwares', ['name' => 'Transfer software ' . $scenario, 'entities_id' => $entity]);
        $sourceVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $sourceSoftware, 'name' => 'Use role']);
        $sourceBuyVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $sourceSoftware, 'name' => 'Buy role']);
        $destinationVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $destinationSoftware, 'entities_id' => $entity, 'name' => 'Use role']);
        $destinationBuyVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $destinationSoftware, 'entities_id' => $entity, 'name' => 'Buy role']);
        $literal = $scenario === 'literal' ? "Licence %_\\' literal" : $scenario;
        $sourceNumber = match ($scenario) {
            'last' => 1, 'zero-source' => 0, 'unlimited-source', 'unlimited-source-new' => -1, default => 2,
        };
        $sourceLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $sourceSoftware, 'name' => $literal, 'serial' => $literal, 'number' => $sourceNumber, 'softwareversions_id_buy' => $sourceBuyVersion, 'softwareversions_id_use' => $sourceVersion]);
        $destinationLicense = in_array($scenario, ['new', 'unlimited-source-new'], true) ? null : $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $destinationSoftware, 'entities_id' => $entity, 'name' => $literal, 'serial' => $literal, 'number' => $destinationNumber]);
        if ($scenario === 'same') {
            $destinationSoftware = $sourceSoftware;
            $destinationVersion = $sourceVersion;
            $destinationBuyVersion = $sourceBuyVersion;
            $destinationLicense = $sourceLicense;
        }
        $asset = $fixtures->create('glpi_monitors', ['is_recursive' => true]);
        $installation = (new Item_SoftwareVersion())->add(['itemtype' => 'Monitor', 'items_id' => $asset, 'softwareversions_id' => $sourceVersion]);
        $assignment = (new Item_SoftwareLicense())->add(['itemtype' => 'Monitor', 'items_id' => $asset, 'softwarelicenses_id' => $sourceLicense]);
        verify($installation > 0 && $assignment > 0, 'Transfer source creation ' . $scenario);
        $duplicate = null;
        if ($scenario === 'duplicates') {
            $duplicate = (new Item_SoftwareLicense())->add(['itemtype' => 'Monitor', 'items_id' => $asset, 'softwarelicenses_id' => $sourceLicense]);
            verify($duplicate > 0 && $duplicate !== $assignment, 'Independent duplicate licence assignments remain supported');
        }
        $transfer = new Transfer();
        $transfer->to = $entity;
        $transfer->options['keep_software'] = $scenario !== 'discard';
        $transfer->already_transfer = ['Software' => [$sourceSoftware => $destinationSoftware], 'SoftwareVersion' => [$sourceVersion => $destinationVersion, $sourceBuyVersion => $destinationBuyVersion]];
        if (in_array($scenario, ['cached-zero', 'cached-negative'], true)) {
            $transfer->already_transfer['SoftwareVersion'][$sourceVersion] = $scenario === 'cached-zero' ? 0 : -1;
        }
        $beforeCache = $transfer->already_transfer;
        $before = [$read('glpi_items_softwareversions', $installation), $read('glpi_items_softwarelicenses', $assignment), $read('glpi_softwarelicenses', $sourceLicense), $destinationLicense === null ? null : $read('glpi_softwarelicenses', $destinationLicense)];
        $history = $records()->countMatching('glpi_logs', []);
        if (in_array($scenario, ['veto', 'cached-zero', 'cached-negative'], true)) {
            if ($scenario === 'veto') {
                $PLUGIN_HOOKS['pre_item_update']['software_assignment_fixture'][Item_SoftwareLicense::class] = static function (Item_SoftwareLicense $item): void {
                    $item->input = false;
                };
            }
            $cancelled = $transfer->transferItemSoftwares('Monitor', $asset) === false;
            unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_fixture']);
            verify($cancelled, 'Actual required assignment/copy outcome refuses direct transfer ' . $scenario);
            verify([$read('glpi_items_softwareversions', $installation), $read('glpi_items_softwarelicenses', $assignment), $read('glpi_softwarelicenses', $sourceLicense), $read('glpi_softwarelicenses', $destinationLicense)] === $before, 'Veto rolls back installation, licence parent, quantities and validity');
            verify($records()->countMatching('glpi_logs', []) === $history && $transfer->already_transfer === $beforeCache, 'Veto rolls back audit history and transfer cache');
        } else {
            verify($transfer->transferItemSoftwares('Monitor', $asset) !== false, 'Actual direct transfer accepts the public lifecycle ' . $scenario);
            if ($scenario === 'discard') {
                verify($records()->find('glpi_items_softwareversions', 'id', $installation) === null && $records()->find('glpi_items_softwarelicenses', 'id', $assignment) === null, 'Discard uses actual installation/licence purge lifecycle');
                verify($read('glpi_softwarelicenses', $sourceLicense)['number'] === 2 && $read('glpi_softwarelicenses', $destinationLicense)['number'] === $destinationNumber, 'Discard does not transfer licence quantities');
            } else {
                if (in_array($scenario, ['new', 'unlimited-source-new'], true)) {
                    $destinationLicense = $read('glpi_items_softwarelicenses', $assignment)['softwarelicenses_id'];
                    $created = $read('glpi_softwarelicenses', $destinationLicense);
                    verify($destinationLicense !== $sourceLicense && $created['softwares_id'] === $destinationSoftware
                        && $created['softwareversions_id_buy'] === $destinationBuyVersion && $created['softwareversions_id_use'] === $destinationVersion
                        && $created['number'] === 1, 'New destination licence uses actual public creation and mapped buy/use versions');
                }
                verify($read('glpi_items_softwareversions', $installation)['softwareversions_id'] === $destinationVersion && $read('glpi_items_softwarelicenses', $assignment)['softwarelicenses_id'] === $destinationLicense, 'Keep transfers actual installation/licence parents ' . $scenario);
                if ($scenario === 'same') {
                    verify($read('glpi_softwarelicenses', $sourceLicense)['number'] === 2 && $records()->countMatching('glpi_logs', []) === $history, 'Already applicable licence transfer preserves quantities and audit history');
                } elseif ($scenario === 'duplicates') {
                    verify(
                        $read('glpi_items_softwarelicenses', $duplicate)['softwarelicenses_id'] === $destinationLicense
                        && $read('glpi_softwarelicenses', $sourceLicense)['is_deleted']
                        && $read('glpi_softwarelicenses', $destinationLicense)['number'] === 4,
                        'Each duplicate transfers one independent allocation and quantity through public lifecycle'
                    );
                } elseif ($scenario === 'last') {
                    verify($read('glpi_softwarelicenses', $sourceLicense)['is_deleted'] && $read('glpi_softwarelicenses', $destinationLicense)['number'] === 3, 'Last finite quantity is deleted only after successful reassignment');
                } else {
                    verify($read('glpi_softwarelicenses', $sourceLicense)['number'] === ($sourceNumber > 1 ? $sourceNumber - 1 : $sourceNumber) && $read('glpi_softwarelicenses', $destinationLicense)['number'] === ($scenario === 'unlimited' ? -1 : (in_array($scenario, ['new', 'unlimited-source-new'], true) ? 1 : 3)), 'Finite, zero and unlimited source/destination quantity semantics ' . $scenario);
                }
            }
        }
    }
    $missing = new Transfer();
    $missing->to = $entity;
    verify($missing->copySingleVersion(PHP_INT_MAX) === -1, 'Direct missing-source version helper retains its established -1 contract');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after fixtures');
echo $DB->getProvider() . ": software assignment API, proposed-end authorization, validity and atomic public transfers passed.\n";
