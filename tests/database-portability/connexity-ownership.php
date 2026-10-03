<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\EntityRegistry;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/connexity-ownership.php /path/to/test-config\n");
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
final class ConnexityApiResponse extends RuntimeException
{
    public function __construct(public array $response, int $status)
    {
        parent::__construct(json_encode($response, JSON_THROW_ON_ERROR), $status);
    }
}

final class ConnexityApiProbe extends \Glpi\Api\APIRest
{
    public function configure(int $client): void
    {
        $this->session_write = true;
        $this->app_tokens = [$client => 'connexity-ownership-fixture'];
        $this->parameters = ['app_token' => 'connexity-ownership-fixture', 'session_token' => session_id()];
    }

    public function retarget(string $kind, array $input): array
    {
        try {
            return $this->updateItems($kind, ['input' => [(object)$input]]);
        } catch (ConnexityApiResponse $response) {
            if ($response->getCode() === 400 && $response->response[0] === 'ERROR_GLPI_UPDATE') {
                return $response->response[1];
            }
            throw $response;
        }
    }

    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new ConnexityApiResponse($response, $httpcode);
    }
}

/** Deliberately omit parent preparation: the lifecycle entry still owns the guard. */
class ConnexityRootEntityCallbackFixture extends Entity
{
    public static function getTable($classname = null)
    {
        return Entity::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields['id'] = null;
    }
}

class ConnexityPreparationFixture extends Domain_Item
{
    public static int $target;

    public static function getTable($classname = null)
    {
        return Domain_Item::getTable();
    }

    public function prepareInputForUpdate($input)
    {
        $input['computers_id'] = self::$target;
        return $input;
    }
}

class ConnexityFinalCallbackFixture extends Domain_Item
{
    public static int $target;

    public static function getTable($classname = null)
    {
        return Domain_Item::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields['computers_id'] = self::$target;
        $this->updates[] = 'computers_id';
    }
}

trait ConnexityCallbackMutation
{
    public static string $mode;
    public static int $target;

    public function pre_updateInDB()
    {
        if (self::$mode === 'cancel') {
            $this->updates = array_values(array_diff($this->updates, \itsmng\Database\ConnexityInput::endpointFields($this)));
        } elseif (self::$mode === 'cancel-content') {
            $this->updates = array_values(array_diff($this->updates, ['content']));
        } elseif (self::$mode === 'input') {
            $this->input['items_id'] = self::$target;
            $this->input['problems_id'] = self::$target;
        } elseif (self::$mode === 'identity') {
            $this->fields['id'] = self::$target;
        } else {
            $this->fields['problems_id'] = self::$target;
            $this->updates[] = 'problems_id';
        }
    }
}

class ConnexityFollowupCallbackFixture extends ITILFollowup
{
    use ConnexityCallbackMutation;

    public static function getTable($classname = null)
    {
        return ITILFollowup::getTable();
    }
}

class ConnexitySolutionCallbackFixture extends ITILSolution
{
    use ConnexityCallbackMutation;

    public static function getTable($classname = null)
    {
        return ITILSolution::getTable();
    }
}

class ConnexityRackCallbackFixture extends Item_Rack
{
    public static int $target;
    public static string $column;
    public static int $calls = 0;

    public static function getTable($classname = null)
    {
        return Item_Rack::getTable();
    }

    public function pre_updateInDB()
    {
        self::$calls++;
        $this->fields[self::$column] = self::$target;
        $this->updates[] = self::$column;
    }
}

class ConnexityOsCallbackFixture extends Item_OperatingSystem
{
    public static int $target;
    public static string $column;

    public static function getTable($classname = null)
    {
        return Item_OperatingSystem::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields[self::$column] = self::$target;
        $this->updates[] = self::$column;
    }
}

class ConnexitySatisfactionCallbackFixture extends TicketSatisfaction
{
    public static string $column;
    public static int $target;

    public static function getTable($classname = null)
    {
        return TicketSatisfaction::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields[self::$column] = self::$target;
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): array => $records()->find($table, 'id', $id);
$history = static fn (): int => $records()->countMatching('glpi_logs', []);
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before public/API contracts');
$DB->beginTransaction();
try {
    $hiddenEntity = $fixtures->create('glpi_entities', ['name' => 'Connexity hidden sibling']);
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $source = $fixtures->create('glpi_computers', ['name' => 'Connexity source']);
    $visible = $fixtures->create('glpi_computers', ['name' => 'Connexity visible']);
    $hidden = $fixtures->create('glpi_computers', ['name' => 'Connexity hidden', 'entities_id' => $hiddenEntity]);
    $phone = $fixtures->create('glpi_phones', ['name' => 'Connexity changed kind']);
    $api = new ConnexityApiProbe();
    $api->configure($fixtures->create('glpi_apiclients', ['name' => 'Connexity fixture', 'dolog_method' => 0]));
    $cases = [
        [Domain_Item::class, 'glpi_domains', 'domains_id', []],
        [Certificate_Item::class, 'glpi_certificates', 'certificates_id', []],
        [Contract_Item::class, 'glpi_contracts', 'contracts_id', []],
        [Document_Item::class, 'glpi_documents', 'documents_id', []],
        [Item_Cluster::class, 'glpi_clusters', 'clusters_id', []],
        [Appliance_Item::class, 'glpi_appliances', 'appliances_id', []],
        [Item_Project::class, 'glpi_projects', 'projects_id', []],
        [Item_OperatingSystem::class, null, null, []],
        [Item_Problem::class, 'glpi_problems', 'problems_id', []],
        [Change_Item::class, 'glpi_changes', 'changes_id', []],
        [ReservationItem::class, null, null, []],
        [Item_Rack::class, 'glpi_racks', 'racks_id', ['position' => 1]],
        [Item_Enclosure::class, 'glpi_enclosures', 'enclosures_id', ['position' => 1]],
    ];
    $links = [];
    foreach ($cases as [$model, $parentTable, $parentColumn, $extra]) {
        $table = $model::getTable();
        $definition = EntityRegistry::discriminatedReferences($table)['items_id'];
        $column = $definition['selections']['Computer']['column'];
        $parent = $parentTable === null ? [] : [$parentColumn => $fixtures->create($parentTable, $parentTable === 'glpi_racks' ? ['number_units' => 10] : [])];
        $legacy = ['itemtype' => 'Computer', 'items_id' => $source] + $parent + $extra;
        $canonical = ['itemtype' => 'Computer', $column => $source] + $parent + $extra;
        verify((new $model())->can(-1, CREATE, $canonical), 'Actual CREATE accepts owning columns before model authorization: ' . $model);
        verify($canonical['items_id'] === $source, 'Authorization exposes derived identity to actual endpoint hooks: ' . $model);
        $deniedCreate = ['itemtype' => 'Computer', $column => $hidden] + $parent + $extra;
        verify(!(new $model())->can(-1, CREATE, $deniedCreate), 'Actual CREATE refuses hidden owning subject: ' . $model);
        $id = $fixtures->create($table, $legacy);
        $links[$model] = [$table, $id, $column, $parent];
        verify((new $model())->can($id, UPDATE), 'Stored relation is actually writable: ' . $model);
        if ($model === Domain_Item::class) {
            $savedRight = $_SESSION['glpiactiveprofile']['domain'];
            $_SESSION['glpiactiveprofile']['domain'] = $savedRight & ~(DELETE | PURGE);
            $before = $read($table, $id);
            $beforeHistory = $history();
            verify($api->retarget($model, ['id' => $id, $column => $visible])[0][$id] === false, 'A visible proposed endpoint does not bypass original relationship removal rights');
            verify($read($table, $id) === $before && $history() === $beforeHistory, 'Missing removal rights preserves the original row/history');
            $_SESSION['glpiactiveprofile']['domain'] = $savedRight;
        }
        if ($model::$rightname) {
            // The test actor explicitly owns old-link removal rights; production
            // permissions are unchanged and restored with the session fixture.
            $_SESSION['glpiactiveprofile'][$model::$rightname] |= DELETE | PURGE;
        }
        verify((new $model())->can($id, DELETE) && (new $model())->can($id, PURGE), 'Fixture actor may remove the stored relationship: ' . $model);
        foreach ([[$column => $hidden], ['itemtype' => 'Computer', 'items_id' => $hidden], [$column => null], ['itemtype' => ['Computer']]] as $change) {
            $before = $read($table, $id);
            $beforeHistory = $history();
            $response = $api->retarget($model, ['id' => $id] + $change);
            verify($response[0][$id] === false, 'Actual REST update refuses invalid proposed endpoint: ' . $model);
            verify($read($table, $id) === $before && $history() === $beforeHistory, 'Refused update has no row/history effects: ' . $model);
        }
        $proposed = ['itemtype' => 'Computer', $column => $visible] + $parent + $extra;
        verify((new $model())->can(-1, CREATE, $proposed), 'Fixture actor may create the proposed visible relationship: ' . $model);
        $response = $api->retarget($model, ['id' => $id, $column => $visible]);
        verify($response[0][$id] === true, 'Actual REST accepts visible owning retarget: ' . $model . ' ' . json_encode($response));
        $row = $read($table, $id);
        verify($row[$column] === $visible && $row['items_id'] === $visible, 'Owning and generated identities agree: ' . $model);
        if (isset($definition['selections']['Phone'])) {
            $changedColumn = $definition['selections']['Phone']['column'];
            verify($api->retarget($model, ['id' => $id, 'itemtype' => 'Phone', $changedColumn => $phone])[0][$id] === true, 'Actual REST authorizes changed discriminator: ' . $model);
            $row = $read($table, $id);
            verify($row['itemtype'] === 'Phone' && $row[$changedColumn] === $phone && $row[$column] === null, 'Changing kind clears obsolete owning branch: ' . $model);
        }
    }

    [$documentTable, $documentLink] = $links[Document_Item::class];
    $document = new Document_Item();
    verify($document->update(['id' => $documentLink, 'itemtype' => 'Entity', 'subject_entities_id' => 0]), 'Public Document owner may select the root Entity zero');
    $row = $read($documentTable, $documentLink);
    verify($row['items_id'] === 0 && $row['subject_entities_id'] === 0
        && in_array('subject_entities_id', $document->updates, true) && array_key_exists('subject_entities_id', $document->oldvalues)
        && $document->oldvalues['subject_entities_id'] === null, 'Root zero is distinct from an absent nullable physical branch');
    verify($document->update(['id' => $documentLink, 'itemtype' => 'Computer', 'computers_id' => $source]), 'Public Document retarget clears the root Entity branch');
    verify($read($documentTable, $documentLink)['subject_entities_id'] === null
        && in_array('subject_entities_id', $document->updates, true) && $document->oldvalues['subject_entities_id'] === 0, 'Clearing root zero retains a real nullable branch change');

    [$table, $link, $column, $parent] = $links[Domain_Item::class];
    verify((new Domain_Item())->update(['id' => $link, 'itemtype' => 'Computer', 'computers_id' => $source]), 'Restore native probe owner');
    $relation = $fixtures->create('glpi_domainrelations');
    verify((new Domain_Item())->update(['id' => $link, 'domainrelations_id' => $relation]), 'Unrelated association update retains its own policy');
    verify($read($table, $link)['domainrelations_id'] === $relation && $read($table, $link)['items_id'] === $source, 'An absent endpoint remains unchanged');
    verify((new Domain_Item())->update(['id' => $link, 'domainrelations_id' => null]), 'An explicit unrelated nullable association remains null');
    verify($read($table, $link)['domainrelations_id'] === null && $read($table, $link)['items_id'] === $source, 'Explicit null is distinct from an absent key');
    $native = new \itsmng\Database\Entity\DomainItem();
    $native->itemtype = 'Computer';
    $em = Orm::create($DB);
    $native->computer = $em->getReference(\itsmng\Database\Entity\Computer::class, $hidden);
    $native->domains = $em->getReference(\itsmng\Database\Entity\Domain::class, $parent['domains_id']);
    $em->persist($native);
    $em->flush();
    verify($native->items_id === $hidden, 'Native ORM proves target/discriminator are structurally valid; scope belongs to public policy');
    $em->remove($native);
    $em->flush();
    foreach ([ConnexityPreparationFixture::class, ConnexityFinalCallbackFixture::class] as $model) {
        $model::$target = $hidden;
        $before = $read($table, $link);
        $beforeHistory = $history();
        verify(!(new $model())->update(['id' => $link, 'domainrelations_id' => $fixtures->create('glpi_domainrelations')]), 'A custom callback cannot skip proposed-end authorization: ' . $model);
        verify($read($table, $link) === $before && $history() === $beforeHistory, 'Callback refusal preserves actual persisted row/history: ' . $model);
    }

    $problem = $fixtures->create('glpi_problems', ['name' => 'Connexity ITIL source']);
    $otherProblem = $fixtures->create('glpi_problems', ['name' => 'Connexity ITIL destination']);
    $hiddenProblem = $fixtures->create('glpi_problems', ['entities_id' => $hiddenEntity]);
    $hiddenSolution = $fixtures->create('glpi_itilsolutions', ['itemtype' => 'Problem', 'items_id' => $hiddenProblem, 'content' => 'Hidden original solution']);
    $before = $read('glpi_itilsolutions', $hiddenSolution);
    $beforeHistory = $history();
    verify($api->retarget(ITILSolution::class, ['id' => $hiddenSolution, 'content' => 'Hidden edited solution'])[0][$hiddenSolution] === false, 'Actual solution API refuses an existing hidden parent even when solve-role rights exist');
    verify($read('glpi_itilsolutions', $hiddenSolution) === $before && $history() === $beforeHistory, 'Hidden solution refusal preserves stored content/history');
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    $_SESSION['glpishowallentities'] = true;
    $emptyScope = ['itemtype' => 'Problem', 'problems_id' => $problem];
    verify(!(new ITILSolution())->can(-1, CREATE, $emptyScope), 'An explicit empty scope overrides a stale show-all flag for solution creation');
    $visibleSolution = $fixtures->create('glpi_itilsolutions', ['itemtype' => 'Problem', 'items_id' => $problem]);
    verify($api->retarget(ITILSolution::class, ['id' => $visibleSolution, 'content' => 'Empty scope edited solution'])[0][$visibleSolution] === false, 'Actual existing solution update refuses an explicitly empty scope');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpishowallentities'] = false;
    $assignedProblem = $fixtures->create('glpi_problems');
    $fixtures->create('glpi_problems_users', ['problems_id' => $assignedProblem, 'users_id' => Session::getLoginUserID(), 'type' => CommonITILActor::ASSIGN]);
    $assignedSolution = $fixtures->create('glpi_itilsolutions', ['itemtype' => 'Problem', 'items_id' => $assignedProblem]);
    $savedProblemRight = $_SESSION['glpiactiveprofile']['problem'];
    $_SESSION['glpiactiveprofile']['problem'] = Problem::READMY;
    $actor = new Problem();
    verify(!Session::haveRight('problem', UPDATE) && $actor->can($assignedProblem, READ) && $actor->canSolve(), 'Fixture assigned actor retains specialized solve permission without global UPDATE');
    $assigned = ['itemtype' => 'Problem', 'problems_id' => $assignedProblem];
    verify((new ITILSolution())->can(-1, CREATE, $assigned), 'Solution CREATE retains readable assigned-actor solve semantics');
    verify($api->retarget(ITILSolution::class, ['id' => $assignedSolution, 'content' => 'Assigned actor edited solution'])[0][$assignedSolution] === true, 'Solution API retains readable assigned-actor maySolve permission');
    $_SESSION['glpiactiveprofile']['problem'] = $savedProblemRight;
    $_SESSION['glpiactiveprofile']['followup'] |= DELETE | PURGE;
    foreach ([ITILFollowup::class => 'glpi_itilfollowups', ITILSolution::class => 'glpi_itilsolutions'] as $model => $table) {
        $id = $fixtures->create($table, ['itemtype' => 'Problem', 'items_id' => $problem,
            'users_id' => Session::getLoginUserID(), 'content' => 'Retargeted ITIL content']);
        verify((new $model())->can($id, DELETE) && (new $model())->can($id, PURGE), 'Fixture actor may remove the original ITIL child: ' . $model);
        $proposed = ['itemtype' => 'Problem', 'problems_id' => $otherProblem, 'users_id' => Session::getLoginUserID()];
        verify((new $model())->can(-1, CREATE, $proposed), 'Fixture actor may create the proposed ITIL child: ' . $model);
        $before = $read($table, $id);
        $beforeHistory = $history();
        verify($api->retarget($model, ['id' => $id, 'problems_id' => $hiddenProblem])[0][$id] === false, 'Actual ITIL child guard refuses hidden new job: ' . $model);
        verify($read($table, $id) === $before && $history() === $beforeHistory, 'Refused ITIL job retarget has no row/history effects: ' . $model);
        $child = new $model();
        verify($child->update(['id' => $id, 'problems_id' => $otherProblem], 0), 'Public ITIL child accepts writable new job without history: ' . $model);
        verify($child->input['_job']->getID() === $otherProblem && $read($table, $id)['items_id'] === $otherProblem, 'Preparation binds the actual proposed job: ' . $model);
        if ($model === ITILSolution::class) {
            $cache = new ReflectionProperty(ITILSolution::class, 'item');
            verify($cache->getValue($child)->getID() === $otherProblem, 'Successful solution update refreshes its authorization owner cache');
        }
        $callback = $model === ITILFollowup::class ? ConnexityFollowupCallbackFixture::class : ConnexitySolutionCallbackFixture::class;
        foreach (['owner', 'cancel', 'input', 'netzero'] as $mode) {
            $current = $read($table, $id)['items_id'];
            $next = $current === $problem ? $otherProblem : $problem;
            $callback::$mode = $mode;
            $callback::$target = $mode === 'netzero' ? $current : $next;
            $input = ['id' => $id, 'content' => 'Final callback ' . $mode];
            if ($mode === 'cancel' || $mode === 'netzero') {
                $input['problems_id'] = $next;
            }
            $instance = new $callback();
            verify($instance->update($input, 0), 'Public ITIL callback update succeeds with effective write-set: ' . $model . '/' . $mode);
            $expected = $mode === 'owner' ? $next : $current;
            verify($read($table, $id)['items_id'] === $expected && $instance->fields['items_id'] === $expected
                && $instance->input['_job']->getID() === $expected, 'Stored, in-memory and callback job owners agree: ' . $model . '/' . $mode);
            if ($model === ITILSolution::class) {
                verify($cache->getValue($instance)->getID() === $expected, 'History-free callback refreshes cached solution owner: ' . $mode);
            }
        }
        $callback::$mode = 'cancel-content';
        $before = $read($table, $id);
        $instance = new $callback();
        verify($instance->update(['id' => $id, 'content' => 'Cancelled prepared content']), 'Public callback may cancel a non-endpoint write: ' . $model);
        verify($read($table, $id)['content'] === $before['content'] && $instance->fields['content'] === $before['content']
            && $instance->input['content'] === $before['content'] && !isset($instance->oldvalues['content']), 'Post-update content/context matches the final write-set: ' . $model);
        $otherId = $fixtures->create($table, ['itemtype' => 'Problem', 'items_id' => $problem,
            'users_id' => Session::getLoginUserID(), 'content' => 'Untouched operation target']);
        $before = $read($table, $id);
        $otherBefore = $read($table, $otherId);
        $beforeHistory = $history();
        $callback::$mode = 'identity';
        $callback::$target = $otherId;
        verify(!(new $callback())->update(['id' => $id, 'content' => 'Redirected callback']), 'A late callback cannot redirect operation identity: ' . $model);
        verify($read($table, $id) === $before && $read($table, $otherId) === $otherBefore && $history() === $beforeHistory, 'Refused operation redirect preserves both rows/history: ' . $model);
    }

    $rack = $fixtures->create('glpi_racks', ['number_units' => 1]);
    $largeModel = $fixtures->create('glpi_computermodels', ['required_units' => 2]);
    $large = $fixtures->create('glpi_computers', ['computermodels_id' => $largeModel]);
    $placement = $fixtures->create('glpi_items_racks', ['racks_id' => $rack, 'itemtype' => 'Computer', 'items_id' => $source, 'position' => 1]);
    ConnexityRackCallbackFixture::$target = $large;
    $rackColumn = EntityRegistry::discriminatedReferences('glpi_items_racks')['items_id']['selections']['Computer']['column'];
    ConnexityRackCallbackFixture::$column = $rackColumn;
    $before = $read('glpi_items_racks', $placement);
    verify($read('glpi_racks', $rack)['number_units'] === 1 && $read('glpi_computermodels', $largeModel)['required_units'] === 2, 'Native rack/model fixture has actual 1U capacity and 2U target');
    $beforeHistory = $history();
    verify(array_key_exists($rackColumn, $before), 'Rack callback changes a real mapped owning field');
    verify(!(new Item_Rack())->update(['id' => $placement, $rackColumn => $large]), 'Ordinary public preparation refuses the same impossible placement');
    $callback = new ConnexityRackCallbackFixture();
    $result = $callback->update(['id' => $placement, 'orientation' => Rack::REAR]);
    verify(ConnexityRackCallbackFixture::$calls === 1 && !$result, 'Final authorized target still must fit the actual rack geometry');
    verify($read('glpi_items_racks', $placement) === $before && $history() === $beforeHistory, 'Late rack business-policy refusal preserves placement/history');

    $_SESSION['glpiactiveentities'] = [0, $hiddenEntity];
    $_SESSION['glpiactiveentities_string'] = '0,' . $hiddenEntity;
    $osAssignment = $fixtures->create('glpi_items_operatingsystems', ['itemtype' => 'Computer', 'items_id' => $source]);
    $before = $read('glpi_items_operatingsystems', $osAssignment);
    ConnexityOsCallbackFixture::$target = $hidden;
    ConnexityOsCallbackFixture::$column = EntityRegistry::discriminatedReferences('glpi_items_operatingsystems')['items_id']['selections']['Computer']['column'];
    $os = new ConnexityOsCallbackFixture();
    verify($os->update(['id' => $osAssignment, 'is_dynamic' => !$before['is_dynamic']]), 'Authorized late OS owner mutation succeeds');
    $row = $read('glpi_items_operatingsystems', $osAssignment);
    $subject = new Computer();
    verify($subject->getFromDB($hidden), 'Read actual OS owner context');
    verify($row['items_id'] === $hidden && $row['entities_id'] === $subject->getEntityID()
        && (bool)$row['is_recursive'] === (bool)$subject->isRecursive(), 'Final OS entity/recursion context derives from its actual final owning asset');
    $fixtures->create('glpi_items_operatingsystems', ['itemtype' => 'Computer', 'items_id' => $visible]);
    ConnexityOsCallbackFixture::$target = $visible;
    $before = $read('glpi_items_operatingsystems', $osAssignment);
    $beforeHistory = $history();
    verify(!(new ConnexityOsCallbackFixture())->update(['id' => $osAssignment, 'is_dynamic' => !$before['is_dynamic']]), 'Final OS policy rejects an authorized duplicate assignment including null components');
    verify($read('glpi_items_operatingsystems', $osAssignment) === $before && $history() === $beforeHistory, 'Late OS duplicate refusal preserves actual owner context/history');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';

    $ticket = $fixtures->create('glpi_tickets');
    $closed = $fixtures->create('glpi_tickets', ['status' => CommonITILObject::CLOSED]);
    $satisfaction = $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $ticket, 'satisfaction' => 1]);
    $otherSatisfaction = $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $closed, 'satisfaction' => 2]);
    foreach (['id' => $otherSatisfaction, 'tickets_id' => $closed] as $column => $target) {
        ConnexitySatisfactionCallbackFixture::$column = $column;
        ConnexitySatisfactionCallbackFixture::$target = $target;
        $before = $read('glpi_ticketsatisfactions', $satisfaction);
        $otherBefore = $read('glpi_ticketsatisfactions', $otherSatisfaction);
        $beforeHistory = $history();
        verify(!(new ConnexitySatisfactionCallbackFixture())->update(['tickets_id' => $ticket, 'satisfaction' => 4]), 'Different public lookup key cannot redirect physical or public operation identity: ' . $column);
        verify($read('glpi_ticketsatisfactions', $satisfaction) === $before && $read('glpi_ticketsatisfactions', $otherSatisfaction) === $otherBefore
            && $history() === $beforeHistory, 'Refused alternate-index callback preserves both actual records/history: ' . $column);
    }
    $before = $read('glpi_entities', 0);
    $beforeHistory = $history();
    $root = new ConnexityRootEntityCallbackFixture();
    verify(!$root->update(['id' => 0, 'comment' => 'Cancelled root operation']), 'Actual root Entity zero identity cannot become NULL in a late callback');
    verify(
        $read('glpi_entities', 0) === $before && $history() === $beforeHistory && $root->getID() === 0,
        'Rejected root identity mutation preserves database, history and model identity'
    );
    $ticketLink = $fixtures->create('glpi_items_tickets', ['tickets_id' => $ticket, 'itemtype' => 'Computer', 'items_id' => $source]);
    $before = $read('glpi_items_tickets', $ticketLink);
    verify($api->retarget(Item_Ticket::class, ['id' => $ticketLink, 'tickets_id' => $closed])[0][$ticketLink] === false, 'Existing Item_Ticket closed-owner CREATE rule is enforced on parent retarget');
    verify($read('glpi_items_tickets', $ticketLink) === $before, 'Closed-owner retarget preserves the original ticket relationship');

    $location = $fixtures->create('glpi_locations');
    $otherLocation = $fixtures->create('glpi_locations', ['entities_id' => $hiddenEntity]);
    [$assetTable, $assetLink] = $links[Appliance_Item::class];
    $hiddenAppliance = $fixtures->create('glpi_appliances', ['entities_id' => $hiddenEntity]);
    $hiddenAssetLink = $fixtures->create($assetTable, ['appliances_id' => $hiddenAppliance, 'itemtype' => 'Computer', 'items_id' => $hidden]);
    $nested = $fixtures->create('glpi_appliances_items_relations', ['appliances_items_id' => $assetLink, 'itemtype' => 'Location', 'items_id' => $location]);
    verify($api->retarget(Appliance_Item_Relation::class, ['id' => $nested, 'locations_id' => $otherLocation])[0][$nested] === true, 'Nested recipient changes preserve actual containing-appliance-only CREATE policy');
    $before = $read('glpi_appliances_items_relations', $nested);
    verify($api->retarget(Appliance_Item_Relation::class, ['id' => $nested, 'appliances_items_id' => $hiddenAssetLink])[0][$nested] === false, 'Nested parent retarget rechecks the proposed containing appliance');
    verify($read('glpi_appliances_items_relations', $nested) === $before, 'Refused nested parent preserves its exact recipient and owner');

    // Notification recipient selection is not the child's Notification owner.
    $notification = $fixtures->create('glpi_notifications');
    $group = $fixtures->create('glpi_groups');
    $otherGroup = $fixtures->create('glpi_groups', ['entities_id' => $hiddenEntity]);
    $recipient = $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => 3, 'items_id' => $group]);
    verify($api->retarget(NotificationTarget::class, ['id' => $recipient, 'groups_id' => $otherGroup])[0][$recipient] === true, 'Actual API preserves distinct notification-recipient authorization');
    verify($read('glpi_notificationtargets', $recipient)['items_id'] === $otherGroup, 'Recipient normalizer remains its own authoritative policy');

    $project = $fixtures->create('glpi_projects');
    $contact = $fixtures->create('glpi_contacts');
    $otherContact = $fixtures->create('glpi_contacts');
    $member = $fixtures->create('glpi_projectteams', ['projects_id' => $project, 'itemtype' => 'Contact', 'items_id' => $contact]);
    $_SESSION['glpiactiveprofile']['contact_enterprise'] = READ;
    verify(!Session::haveRight('contact_enterprise', UPDATE), 'Fixture has no Contact write grant');
    verify((new ProjectTeam())->can($member, UPDATE), 'Project team is writable through the project owner with only Contact view rights');
    verify((new ProjectTeam())->can($member, DELETE) && (new ProjectTeam())->can($member, PURGE), 'Project team actor may remove the original membership with only Contact view rights');
    $newMember = ['projects_id' => $project, 'itemtype' => 'Contact', 'contacts_id' => $otherContact];
    verify((new ProjectTeam())->can(-1, CREATE, $newMember), 'Project team actor may create the proposed member with only Contact view rights');
    verify($api->retarget(ProjectTeam::class, ['id' => $member, 'contacts_id' => $otherContact])[0][$member] === true, 'DONT_CHECK member rights retain the actual project-owner policy');
    verify($read('glpi_projectteams', $member)['items_id'] === $otherContact, 'Project team owning member follows permitted retarget');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema remains unchanged after contracts');
echo $DB->getProvider() . ": public/REST owning endpoint authorization, generated identities, callback guards, recipient distinction and member policy passed.\n";
