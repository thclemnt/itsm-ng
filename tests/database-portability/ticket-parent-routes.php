<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\EntityRegistry;
use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketCollectionRepository;
use itsmng\Database\Repository\TicketVisibility;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/ticket-parent-routes.php /path/to/test-config\n");
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
final class ParentRouteResponse extends RuntimeException
{
}
final class ParentRouteProbe extends \Glpi\Api\APIRest
{
    public function collection(int $client, string $kind, int $id, array $params = []): array
    {
        $this->session_write = true;
        $this->app_tokens = [$client => 'parent-route-fixture-token'];
        $this->parameters = ['app_token' => 'parent-route-fixture-token', 'session_token' => session_id(),
            'parent_itemtype' => $kind, 'parent_id' => $id];
        $total = 0;
        $rows = $this->getItems('Ticket', ['get_hateoas' => false, 'only_id' => true] + $params, $total);
        return ['rows' => $rows, 'total' => $total];
    }
    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new ParentRouteResponse(json_encode($response), $httpcode);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $entity = $fixtures->create('glpi_entities', ['name' => 'Ticket parent scope']);
    $foreign = $fixtures->create('glpi_entities', ['name' => 'Foreign parent scope']);
    $viewer = $fixtures->create('glpi_users', ['name' => 'Parent viewer ' . bin2hex(random_bytes(4))]);
    $client = $fixtures->create('glpi_apiclients', ['name' => 'Parent route fixture', 'dolog_method' => 0]);
    $category = $fixtures->create('glpi_itilcategories', ['entities_id' => $entity, 'name' => 'Owned category']);
    $ids = [];
    foreach (['first', 'second', 'unrelated', 'foreign', 'deleted', 'recipient', 'updated', 'actor'] as $name) {
        $ids[$name] = $fixtures->create('glpi_tickets', [
            'name' => 'Parent route ' . $name,
            'entities_id' => $name === 'foreign' ? $foreign : $entity,
            'itilcategories_id' => in_array($name, ['first', 'second', 'foreign', 'deleted']) ? $category : null,
            'users_id_recipient' => $name === 'recipient' ? $viewer : null,
            'users_id_lastupdater' => $name === 'updated' ? $viewer : null,
            'is_deleted' => $name === 'deleted',
        ]);
    }
    $fixtures->create('glpi_tickets_users', ['tickets_id' => $ids['first'], 'users_id' => $viewer, 'type' => CommonITILActor::REQUESTER]);
    $fixtures->create('glpi_tickets_users', ['tickets_id' => $ids['first'], 'users_id' => $viewer, 'type' => CommonITILActor::OBSERVER]);
    $fixtures->create('glpi_tickets_users', ['tickets_id' => $ids['actor'], 'users_id' => $viewer, 'type' => CommonITILActor::REQUESTER]);
    $task = $fixtures->create('glpi_tickettasks', ['tickets_id' => $ids['first']]);
    $paired = $fixtures->create('glpi_tickets_tickets', ['tickets_id_1' => $ids['first'], 'tickets_id_2' => $ids['second']]);
    $em = Orm::create($DB);
    $repository = new TicketCollectionRepository($em);
    $access = new TicketVisibility($viewer, [$entity], [], Ticket::READALL);
    $parent = static fn (string $table, int $id): array => ['table' => $table, 'id' => $id];
    $select = static fn (string $table, int $id, array $params = []): array => $repository->page($access, $params, $parent($table, $id));
    verify($em->getConnection() === $DB->getDoctrineConnection(), 'Repository retains the supplied connection and sees its uncommitted fixtures');
    verify(array_column($select('glpi_itilcategories', $category)['rows'], 'id') === [$ids['first'], $ids['second']], 'Direct category ownership restricts the route and retains entity/trash scopes');
    verify(array_column($select('glpi_users', $viewer)['rows'], 'id') === [$ids['recipient'], $ids['updated']], 'Unqualified User parent includes recipient and updater ownership without implicitly traversing actor membership');
    verify(array_column($select('glpi_tickettasks', $task)['rows'], 'id') === [$ids['first']], 'Inverse task ownership resolves its actual Ticket entity');
    verify(array_column($select('glpi_tickets_tickets', $paired)['rows'], 'id') === [$ids['first'], $ids['second']], 'Paired relation includes both declared Ticket ends without a column-name heuristic');
    verify($repository->page(new TicketVisibility($viewer, [], [], Ticket::READALL), [], $parent('glpi_itilcategories', $category)) === ['rows' => [], 'total' => 0], 'Parent ownership never overrides empty entity scope');
    verify($repository->page($access, [], ['table' => 'glpi_entities', 'id' => $foreign, 'foreign_key' => 'entities_id'])['total'] === 0, 'Foreign entity parent cannot override the visibility conjunction');
    // Even a misleading legacy hint cannot alter the declared relationship.
    verify(array_column($repository->page($access, [], ['table' => 'glpi_tickettasks', 'id' => $task, 'foreign_key' => 'entities_id'])['rows'], 'id') === [$ids['first']], 'Parent target resolves from entity ownership rather than a supplied foreign-key name');

    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactiveprofile']['ticket'] = Ticket::READALL;
    $api = new ParentRouteProbe();
    verify($api->collection($client, 'ITILCategory', $category, ['range' => '1-1']) === ['rows' => [['id' => $ids['second']]], 'total' => 2], 'Public direct parent route retains stable pagination and total count');
    verify($api->collection($client, 'User', $viewer) === ['rows' => [['id' => $ids['recipient']], ['id' => $ids['updated']]], 'total' => 2], 'Public User route includes every declared direct owner role');
    verify($api->collection($client, 'TicketTask', $task) === ['rows' => [['id' => $ids['first']]], 'total' => 1], 'Public inverse route preserves parent checks and child ownership');
    verify($api->collection($client, 'Ticket_Ticket', $paired) === ['rows' => [['id' => $ids['first']], ['id' => $ids['second']]], 'total' => 2], 'Public paired route resolves both owning ends');

    $subjects = EntityRegistry::discriminatedReferences('glpi_items_tickets')['items_id']['selections'];
    $computer = null;
    foreach ($subjects as $kind => $selection) {
        $subjectValues = in_array('entities_id', EntityRegistry::columnNames($selection['target']), true) ? ['entities_id' => $entity] : [];
        if ($kind === 'Computer') {
            $connection = $DB->getDoctrineConnection();
            $subjectValues['id'] = 100 + max((int)$connection->fetchOne('SELECT MAX(id) FROM glpi_computers'), (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_monitors'));
        } elseif ($kind === 'DomainRecord') {
            $subjectValues['domains_id'] = $fixtures->create('glpi_domains', ['entities_id' => $entity]);
        } elseif ($kind === 'Item_DeviceSimcard') {
            $subjectValues['items_id'] = $fixtures->create('glpi_computers', ['entities_id' => $entity]);
            $subjectValues['itemtype'] = 'Computer';
            $subjectValues['devicesimcards_id'] = $fixtures->create('glpi_devicesimcards', ['entities_id' => $entity]);
        }
        $subject = $fixtures->create($selection['target'], $subjectValues);
        foreach (['first', 'second', 'foreign', 'deleted'] as $name) {
            $fixtures->create('glpi_items_tickets', ['tickets_id' => $ids[$name], 'itemtype' => $kind, 'items_id' => $subject]);
        }
        $page = $select($selection['target'], $subject, ['start' => 1, 'list_limit' => 1]);
        verify($page['total'] === 2 && array_column($page['rows'], 'id') === [$ids['second']], 'Typed asset parent retains count, pagination and entity/trash scope: ' . $kind);
        if ($kind === 'Computer') {
            $computer = $subject;
            // A different discriminator with the same numeric identity must never match.
            $monitor = $fixtures->create('glpi_monitors', ['id' => $subject, 'entities_id' => $entity]);
            $fixtures->create('glpi_items_tickets', ['tickets_id' => $ids['unrelated'], 'itemtype' => 'Monitor', 'items_id' => $monitor]);
            verify(array_column($select('glpi_computers', $computer)['rows'], 'id') === [$ids['first'], $ids['second']], 'Asset parent discrimination excludes an overlapping Monitor identity');
        }
        // Nested subjects retain access checks on their domain/device owners as well.
        verify($api->collection($client, $kind, $subject, ['range' => '0-0']) === ['rows' => [['id' => $ids['first']]], 'total' => 2], 'Public typed asset route resolves its property-declared association: ' . $kind);
    }
    verify($computer !== null, 'Computer route fixture');
    $_SESSION['glpiactiveprofile']['ticket'] = Ticket::READMY;
    $_SESSION['glpiID'] = $viewer;
    verify($api->collection($client, 'Computer', $computer) === ['rows' => [['id' => $ids['first']]], 'total' => 1], 'Asset route remains conjunctive with actor visibility without actor fanout');
    $_SESSION['glpiactiveprofile']['ticket'] = Ticket::READALL;
    $foreignComputer = $fixtures->create('glpi_computers', ['entities_id' => $foreign]);
    $project = $fixtures->create('glpi_projects', ['entities_id' => $entity, 'name' => 'Unsupported parent']);
    foreach ([['Computer', 999999999, 404], ['Computer', $foreignComputer, 403], ['Project', $project, 400], ['Ticket', $ids['first'], 400]] as [$kind, $id, $expected]) {
        try {
            $api->collection($client, $kind, $id);
            throw new RuntimeException('Invalid parent route accepted: ' . $kind);
        } catch (ParentRouteResponse $error) {
            verify($error->getCode() === $expected, 'Missing/denied/unsupported parent status: ' . $kind . '; actual ' . $error->getCode());
            if ($expected === 400) {
                verify(str_contains($error->getMessage(), 'No ticket relationship is defined'), 'Unsupported nested route gives an explicit client error');
            }
        }
    }
} finally {
    $_SESSION = $savedSession;
    $DB->rollBack();
}
echo $DB->getProvider() . ': ticket parent routes use direct/inverse ownership and ' . count($subjects) . " typed asset subjects, preserve visibility/counts/pagination, and reject unsupported routes.\n";
