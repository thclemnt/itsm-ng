<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketCollectionRepository;
use itsmng\Database\Repository\TicketVisibility;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/ticket-collections.php /path/to/test-config\n");
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
final class CollectionResponse extends RuntimeException
{
}
final class TicketCollectionProbe extends \Glpi\Api\APIRest
{
    public function collection(int $client, array $params = [], array $parent = []): array
    {
        $this->session_write = true;
        $this->app_tokens = [$client => 'collection-fixture-token'];
        $this->parameters = ['app_token' => 'collection-fixture-token', 'session_token' => session_id()] + $parent;
        $total = 0;
        $rows = $this->getItems('Ticket', ['get_hateoas' => false] + $params, $total);
        return ['rows' => $rows, 'total' => $total];
    }
    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new CollectionResponse(json_encode($response), $httpcode);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $entity = $fixtures->create('glpi_entities', ['name' => 'Ticket collection scope']);
    $foreign = $fixtures->create('glpi_entities', ['name' => 'Hidden collection scope']);
    $viewer = $fixtures->create('glpi_users', ['name' => 'Collection viewer ' . bin2hex(random_bytes(4))]);
    $group = $fixtures->create('glpi_groups');
    $client = $fixtures->create('glpi_apiclients', ['name' => 'Collection fixture', 'dolog_method' => 0]);
    $ids = [];
    foreach (['requester', 'observer', 'recipient', 'assigned', 'group_requester', 'group_observer', 'group_assigned', 'validator', 'new', 'unrelated', 'deleted', 'foreign'] as $name) {
        $ids[$name] = $fixtures->create('glpi_tickets', ['name' => 'Collection ' . $name, 'entities_id' => $name === 'foreign' ? $foreign : $entity,
            'status' => $name === 'new' ? CommonITILObject::INCOMING : CommonITILObject::ASSIGNED,
            'users_id_recipient' => $name === 'recipient' ? $viewer : null, 'is_deleted' => $name === 'deleted']);
    }
    foreach (['requester' => CommonITILActor::REQUESTER, 'observer' => CommonITILActor::OBSERVER, 'assigned' => CommonITILActor::ASSIGN] as $name => $role) {
        $fixtures->create('glpi_tickets_users', ['tickets_id' => $ids[$name], 'users_id' => $viewer, 'type' => $role]);
    }
    // Multiple roles on one ticket must not inflate COUNT or repeat a page record.
    $fixtures->create('glpi_tickets_users', ['tickets_id' => $ids['requester'], 'users_id' => $viewer, 'type' => CommonITILActor::OBSERVER]);
    foreach (['group_requester' => CommonITILActor::REQUESTER, 'group_observer' => CommonITILActor::OBSERVER, 'group_assigned' => CommonITILActor::ASSIGN] as $name => $role) {
        $fixtures->create('glpi_groups_tickets', ['tickets_id' => $ids[$name], 'groups_id' => $group, 'type' => $role]);
    }
    $fixtures->create('glpi_ticketvalidations', ['tickets_id' => $ids['validator'], 'users_id_validate' => $viewer]);
    $em = Orm::create($DB);
    $repository = new TicketCollectionRepository($em);
    $selected = static function (int $rights, array $groups = [], bool $validate = false) use ($repository, $viewer, $entity): array {
        return array_column($repository->page(new TicketVisibility($viewer, [$entity], $groups, $rights, $validate), ['list_limit' => 100])['rows'], 'id');
    };
    verify($selected(Ticket::READMY) === [$ids['requester'], $ids['observer'], $ids['recipient']], 'Requester/observer/recipient visibility and duplicate-role isolation');
    verify($selected(Ticket::READGROUP, [$group]) === [$ids['group_requester'], $ids['group_observer']], 'Group visibility excludes assigned role without READASSIGN');
    verify($selected(Ticket::OWN) === [$ids['assigned']], 'Ownership permits own assigned tickets');
    verify($selected(Ticket::READASSIGN, [$group]) === [$ids['assigned'], $ids['group_assigned']], 'Assigned user and group visibility');
    verify($selected(Ticket::READASSIGN | Ticket::ASSIGN, [$group]) === [$ids['assigned'], $ids['group_assigned'], $ids['new']], 'Dispatch permission includes incoming tickets');
    verify($selected(0, [], true) === [$ids['validator']], 'Validation visibility targets the actual validator association');
    verify($selected(0) === [] && $selected(Ticket::READGROUP) === [], 'No rights or empty group scope cannot leak tickets');
    $access = new TicketVisibility($viewer, [$entity], [$group], Ticket::READALL);
    $page = $repository->page($access, ['start' => 1, 'list_limit' => 2]);
    verify($page['total'] === 10 && array_column($page['rows'], 'id') === [$ids['observer'], $ids['recipient']], 'Count excludes trash/foreign entities and pagination is stable');
    verify($repository->page($access, ['is_deleted' => 'false'])['total'] === 10, 'HTTP false string retains non-deleted selection');
    verify($repository->page($access, ['searchText' => ['is_deleted' => 0]])['total'] === 10, 'Boolean zero text filter uses entity type and remains meaningful');
    verify($repository->page($access, ['searchText' => ['is_deleted' => '^1$'], 'is_deleted' => true])['total'] === 1, 'Anchored boolean text filter binds real true');
    verify($repository->page(new TicketVisibility($viewer, [], [], Ticket::READALL), []) === ['rows' => [], 'total' => 0], 'READALL never overrides an empty entity scope');
    verify(array_column($repository->page($access, ['is_deleted' => true])['rows'], 'id') === [$ids['deleted']], 'Trash flag uses real boolean semantics');
    $literal = "Collection O'Reilly C:\\new\\日本語_";
    $special = $fixtures->create('glpi_tickets', ['name' => $literal, 'entities_id' => $entity]);
    $wildcardNeighbor = $fixtures->create('glpi_tickets', ['name' => str_replace('_', 'X', $literal), 'entities_id' => $entity]);
    foreach (['name', 'all'] as $field) {
        verify(array_column($repository->page($access, ['searchText' => [$field => '^' . $literal . '$']])['rows'], 'id') === [$special], 'Bound text preserves quotes/backslashes/UTF-8 and underscore literals: ' . $field);
    }
    verify(array_column($repository->page($access, ['searchText' => ['name' => '^collection observer$']])['rows'], 'id') === [$ids['observer']], 'Case-insensitive anchored search');
    $task = $fixtures->create('glpi_tickettasks', ['tickets_id' => $ids['requester']]);
    $parent = ['table' => 'glpi_tickettasks', 'foreign_key' => 'tickettasks_id', 'id' => $task];
    verify(array_column($repository->page($access, [], $parent)['rows'], 'id') === [$ids['requester']], 'Parent child relation uses owning ticket association');
    verify($repository->page($access, [], ['table' => 'glpi_entities', 'foreign_key' => 'entities_id', 'id' => $foreign])['total'] === 0, 'Parent restriction cannot override authorized entity scope');
    $connection = $DB->getDoctrineConnection();
    $postgres = $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
    $timezone = $connection->fetchOne($postgres ? 'SHOW TIME ZONE' : 'SELECT @@session.time_zone');
    $connection->executeStatement($postgres ? "SET TIME ZONE 'UTC'" : "SET time_zone = '+00:00'");
    $dated = $fixtures->create('glpi_tickets', [
        'name' => 'Collection calendar', 'entities_id' => $entity,
        'date' => new DateTimeImmutable('2030-07-14 22:45:06', new DateTimeZone('UTC')),
        'date_mod' => new DateTimeImmutable('2030-07-15 00:00:00', new DateTimeZone('UTC')),
        'closedate' => null,
    ]);
    $fixtures->create('glpi_tickets', [
        'name' => 'Collection neighboring calendar', 'entities_id' => $entity,
        'date' => new DateTimeImmutable('2030-07-14 22:45:07', new DateTimeZone('UTC')),
        'closedate' => new DateTimeImmutable('2030-07-15 00:00:00', new DateTimeZone('UTC')),
    ]);
    $purchase = $fixtures->create('glpi_infocoms', ['buy_date' => new DateTimeImmutable('2030-07-14', new DateTimeZone('UTC'))]);
    $purchaseDates = static function () use ($em, $purchase): array {
        $query = $em->createQueryBuilder()->select('r.id')->from(\itsmng\Database\Entity\Infocom::class, 'r');
        $compiler = new \itsmng\Database\RecordCriteria($query, $em->getClassMetadata(\itsmng\Database\Entity\Infocom::class), legacyValues: false);
        return array_column($query->where($compiler->where(['id' => $purchase, 'buy_date' => ['LIKE', '2030-07-14']]))->getQuery()->getScalarResult(), 'id');
    };
    verify($purchaseDates() === [$purchase], 'Typed DATE filters use a calendar date without a time suffix');
    $em->clear();
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $api = new TicketCollectionProbe();
    $result = $api->collection($client, ['range' => '0-0', 'searchText' => ['name' => '^' . $literal . '$'], 'only_id' => true]);
    verify($result === ['rows' => [['id' => $special]], 'total' => 1], 'Public API preserves range, bound text, total count and only-ID formatting');
    verify($SQL_TOTAL_REQUEST === 0, 'Public ticket collection bypasses legacy SQL including endpoint/client checks');
    verify($api->collection($client, ['is_deleted' => true])['total'] === 1, 'Public API boolean trash selection');
    foreach (['date' => '^2030-07-14 22:45:06$', 'date_mod' => '^2030-07-15 00:00:00$'] as $field => $pattern) {
        $result = $api->collection($client, ['searchText' => [$field => $pattern], 'only_id' => true]);
        verify($result === ['rows' => [['id' => $dated]], 'total' => 1], 'Public API exact timestamp filter uses legacy second precision without an offset: ' . $field);
    }
    verify($api->collection($client, ['searchText' => ['date' => '^2030-07-14'], 'only_id' => true])['total'] === 2, 'Public API timestamp prefix matches calendar date and excludes NULL timestamps');
    verify($api->collection($client, ['searchText' => ['name' => '^Collection calendar$', 'closedate' => 'NULL'], 'only_id' => true]) === ['rows' => [['id' => $dated]], 'total' => 1], 'Public API NULL date filtering remains distinct from formatted calendar text');
    try {
        // A fixed MySQL offset also works without populated timezone tables.
        $connection->executeStatement($postgres ? "SET TIME ZONE 'Europe/Paris'" : "SET time_zone = '+02:00'");
        $result = $api->collection($client, ['searchText' => ['date' => '^2030-07-15 00:45:06$'], 'only_id' => true]);
        verify($result === ['rows' => [['id' => $dated]], 'total' => 1], 'Public API timestamp filters follow the connection timezone across midnight');
        verify($api->collection($client, ['searchText' => ['date' => '^2030-07-14 22:45:06$']])['total'] === 0, 'Local calendar filters do not accidentally search the UTC instant text');
        verify($purchaseDates() === [$purchase], 'DATE calendar filtering remains stable when the connection timezone changes');
    } finally {
        $connection->executeStatement($postgres ? 'SELECT set_config(?, ?, false)' : 'SET time_zone = ?', $postgres ? ['TimeZone', $timezone] : [$timezone]);
    }
    foreach ([['sort' => 'unknown_field'], ['order' => 'ASC; DELETE'], ['range' => 'bad'], ['range' => '9-2'], ['is_deleted' => 'unknown'], ['searchText' => ['not_a_field' => 'value']]] as $invalid) {
        try {
            $api->collection($client, $invalid);
            throw new RuntimeException('Invalid collection input accepted');
        } catch (CollectionResponse $error) {
            verify($error->getCode() === 400, 'Invalid API input reports bad request');
        }
    }
    $parentResult = $api->collection($client, [], ['parent_itemtype' => 'TicketTask', 'parent_id' => $task]);
    verify(array_column($parentResult['rows'], 'id') === [$ids['requester']], 'Public API checks parent then scopes its owning ticket');
    try {
        $api->collection($client, [], ['parent_itemtype' => 'TicketTask', 'parent_id' => 999999999]);
        throw new RuntimeException('Missing API parent accepted');
    } catch (CollectionResponse $error) {
        verify($error->getCode() === 404, 'Missing parent returns not-found before querying tickets');
    }
} finally {
    $_SESSION = $savedSession;
    $DB->rollBack();
}
echo $DB->getProvider() . ": ticket collection actor/validator authorization, entity and parent scopes, real booleans, bound text/date filters, calendar timezone and NULL semantics, stable pagination/counts and public API formatting passed.\n";
