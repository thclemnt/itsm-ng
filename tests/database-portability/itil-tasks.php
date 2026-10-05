<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-tasks.php /path/to/test-config\n");
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
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $user = (int)Session::getLoginUserID();
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'Task scope', 'entities_id' => 0]);
    $outside = $fixtures->create('glpi_entities', ['id' => $entityId + 1, 'name' => 'Other task scope', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactiveentities_string'] = (string)$entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $group = $fixtures->create('glpi_groups', ['name' => 'Task technicians', 'entities_id' => $entity]);
    $_SESSION['glpigroups'] = [$group];
    $otherUser = $fixtures->create('glpi_users', ['name' => 'Other task user']);
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Task central profile', 'interface' => 'central']);
    $fixtures->create('glpi_profiles_users', ['profiles_id' => $profile, 'users_id' => $user, 'entities_id' => $entity]);
    $repo = static fn () => new \itsmng\Database\Repository\ITILTaskRepository(Orm::create($DB));
    $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
    $begin = new DateTimeImmutable('2035-01-01 09:00:00');
    $end = new DateTimeImmutable('2035-01-01 10:00:00');
    foreach (['Ticket', 'Problem', 'Change'] as $type) {
        $taskType = $type . 'Task';
        $parent = $fixtures->create($type::getTable(), ['name' => $type . ' task parent', 'entities_id' => $entity, 'status' => $type::INCOMING]);
        $hidden = $fixtures->create($type::getTable(), ['name' => 'Hidden task parent', 'entities_id' => $outside, 'status' => $type::INCOMING]);
        $deleted = $fixtures->create($type::getTable(), ['name' => 'Deleted task parent', 'entities_id' => $entity, 'is_deleted' => true]);
        $closed = $fixtures->create($type::getTable(), ['name' => 'Closed task parent', 'entities_id' => $entity, 'status' => $type::CLOSED]);
        $fk = $type::getForeignKeyField();
        $create = static fn (array $values = []): int => $fixtures->create($taskType::getTable(), $values + [
            'content' => 'Task projection fixture',
            $fk => $parent, 'users_id_tech' => $user, 'users_id' => $user, 'state' => Planning::TODO,
            'begin' => '2035-01-01 09:15:00', 'end' => '2035-01-01 09:45:00',
            'date' => '2035-01-01 09:45:00', 'date_mod' => '2035-01-01 10:00:00',
        ]);
        $first = $create();
        $second = $create();
        $groupTask = $create(['users_id_tech' => $otherUser, 'groups_id_tech' => $group]);
        $hiddenTask = $create([$fk => $hidden]);
        $deletedTask = $create([$fk => $deleted]);
        $closedTask = $create([$fk => $closed]);
        $done = $create(['state' => Planning::DONE]);
        $unplanned = $create(['begin' => null, 'end' => null, 'actiontime' => 3600, 'date' => '2035-01-01 10:30:00']);
        $late = $create(['begin' => null, 'end' => null, 'actiontime' => 60, 'date' => '2035-01-01 12:00:00']);
        $scope = getEntitiesRestrictCriteria($type::getTable());
        $profileScope = getEntitiesRestrictCriteria('glpi_profiles_users', '', $entity, true);
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $SQL_TOTAL_REQUEST = 0;
        $personal = $ids($taskType::getTaskList('todo', false));
        verify(in_array($first, $personal, true) && !in_array($groupTask, $personal, true) && !in_array($hiddenTask, $personal, true) && !in_array($closedTask, $personal, true) && !in_array($done, $personal, true), $type . ' homepage respects technician, entity, parent status and task state');
        verify($ids($taskType::getTaskList('todo', true)) === [$groupTask], $type . ' group homepage selects assigned groups');
        verify($taskType::getTaskList('todo', false, 1, 2) === array_slice($taskType::getTaskList('todo', false), 1, 2), $type . ' stable pagination');
        $_SESSION['glpigroups'] = [];
        verify($taskType::getTaskList('todo', true) === [], 'Empty group scope is empty');
        $_SESSION['glpigroups'] = [$group];
        $calendar = $ids($repo()->calendarTasks($taskType, ['users_id_tech' => $user]));
        verify(in_array($first, $calendar, true) && !in_array($deletedTask, $calendar, true) && !in_array($groupTask, $calendar, true), $type . ' calendar selects assignee and excludes deleted parents');
        $statuses = array_merge($type::getSolvedStatusArray(), $type::getClosedStatusArray());
        $planned = $ids($repo()->planningTasks($taskType, $begin, $end, false, $user, [$group], $profileScope, false, $statuses));
        verify(in_array($first, $planned, true) && in_array($groupTask, $planned, true) && !in_array($deletedTask, $planned, true) && !in_array($closedTask, $planned, true) && !in_array($done, $planned, true) && !in_array($unplanned, $planned, true), $type . ' planning intersects time, actors and open states');
        $unplannedRows = $repo()->planningTasks($taskType, $begin, $end, true, $user, [], $profileScope, true, $statuses);
        verify($ids($unplannedRows) === [$unplanned] && $unplannedRows[0]['notp_date'] === '2035-01-01 09:30:00' && $unplannedRows[0]['notp_edate'] === '2035-01-01 10:30:00', $type . ' unplanned interval uses portable date subtraction');
        $central = $ids($repo()->planningTasks($taskType, $begin, $end, false, 0, [], $profileScope, true, $statuses));
        verify(in_array($first, $central, true) && !in_array($groupTask, $central, true), $type . ' unfiltered planning requires scoped central-profile membership');
        verify($SQL_TOTAL_REQUEST === 0, 'Task queries bypass legacy SQL: ' . json_encode(array_slice($DEBUG_SQL['queries'] ?? [], 0, $SQL_TOTAL_REQUEST)));
        $solution = $fixtures->create('glpi_itilsolutions', ['itemtype' => $type, 'items_id' => $parent]);
        verify(ITILSolution::countFor($type, $parent) === 1 && ITILSolution::countFor($type, $hidden) === 0, 'Solution counts retain polymorphic scope');
        $events = $taskType::genericPopulatePlanning($taskType, [
            'who' => $user, 'whogroup' => 'mine', 'begin' => $begin->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'),
        ]);
        verify(in_array($first, $ids($events), true) && !in_array($deletedTask, $ids($events), true), $type . ' planning integration preserves readable events');
        $events = $taskType::genericPopulatePlanning($taskType, [
            'who' => $user, 'whogroup' => 0, 'begin' => $begin->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'), 'not_planned' => true,
        ]);
        verify(in_array($unplanned, $ids($events), true), $type . ' unplanned event integration');

    }
    $parent = $fixtures->create('glpi_tickets', ['name' => 'Origin parent']);
    $source = $fixtures->create('glpi_tickets', ['name' => 'Merge source']);
    $promoted = $fixtures->create('glpi_tickets', ['name' => 'Promoted ticket']);
    $replacement = $fixtures->create('glpi_tickets', ['name' => 'Origin replacement']);
    $followup = new ITILFollowup();
    $fid = $followup->add(['itemtype' => 'Ticket', 'items_id' => $parent, 'content' => 'Historical followup', 'sourceitems_id' => $source, 'sourceof_items_id' => $promoted]);
    verify($fid > 0, 'Followup writes typed origins');
    $task = new TicketTask();
    $tid = $task->add(['tickets_id' => $parent, 'content' => 'Historical task', 'sourceitems_id' => $source]);
    verify($tid > 0, 'Task writes typed source');
    $sid = $fixtures->create('glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $parent, 'itilfollowups_id' => $fid]);
    $origin = (new \itsmng\Database\Repository\ITILOriginRepository(Orm::create($DB)))->promotionSource($promoted);
    verify((int)$origin['id'] === $fid && (int)$origin['items_id'] === $parent, 'Promotion source query preserves parent identity');
    $originalFollowup = $read('glpi_itilfollowups', $fid);
    $originalTask = $read('glpi_tickettasks', $tid);
    verify((new Ticket())->delete(['id' => $source, '_replace_by' => $replacement], true), 'Replace merge source');
    verify($read('glpi_tickettasks', $tid)['sourceitems_id'] === $replacement && $read('glpi_itilfollowups', $fid)['sourceitems_id'] === $replacement, 'Origins follow replacement without moving actual parent');
    verify((new Ticket())->delete(['id' => $replacement], true) && (new Ticket())->delete(['id' => $promoted], true), 'Purge origin tickets');
    verify($read('glpi_tickettasks', $tid)['sourceitems_id'] === null && $read('glpi_tickettasks', $tid)['tickets_id'] === $parent && $read('glpi_itilfollowups', $fid)['sourceof_items_id'] === null, 'Historical children retained with NULL origins');
    verify($read('glpi_itilfollowups', $fid)['date_mod'] === $originalFollowup['date_mod'] && $read('glpi_tickettasks', $tid)['date_mod'] === $originalTask['date_mod'], 'Origin maintenance preserves historical modification dates');
    verify($followup->delete(['id' => $fid], true), 'Purge origin followup');
    verify($read('glpi_itilsolutions', $sid)['itilfollowups_id'] === null, 'Solution retained after linked followup purge');
    verify($task->update(['id' => $tid, 'sourceitems_id' => 0]) && $read('glpi_tickettasks', $tid)['sourceitems_id'] === null, 'Legacy zero source writes normalize');
    verify((new ForeignKeys())->audit($connection) === [], 'ITIL graph remains valid');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}

// Reconstruct the legacy representation outside the application transaction:
// MySQL DDL commits implicitly. Only this disposable fixture database is altered.
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new \itsmng\Database\Migration\V220\NullableReferences(\itsmng\Database\Migration\V220\ReferenceHistory::get('optional', 'ITIL_ORIGINS'), 'ITIL origin');
$relations = ReferenceHistory::get('optional', 'ITIL_ORIGINS');
$created = [];
try {
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Valid migration origin']);
    $created[] = ['glpi_tickets', $ticket];
    $followup = $fixtures->create('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticket]);
    $created[] = ['glpi_itilfollowups', $followup];
    foreach ($relations as $table => $columns) {
        foreach ($columns as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($columns as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
        $first = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM ' . $quote($table));
        foreach ([false, true] as $offset => $valid) {
            $values = ['id' => $first + $offset];
            $values += $table === 'glpi_tickettasks' ? ['tickets_id' => $ticket] : ['itemtype' => 'Ticket', 'tickets_id' => $ticket];
            foreach ($columns as $column => $target) {
                $values[$column] = $valid ? ($target === 'glpi_tickets' ? $ticket : $followup) : 0;
            }
            $connection->insert($table, $values);
            $created[] = [$table, $first + $offset];
        }
        $samples[$table] = $first;
    }
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && count($plan['counts']) === 4, 'All four origin columns planned without mutation');
    foreach ($relations as $table => $columns) {
        foreach ($columns as $column => $target) {
            verify((int)$connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$samples[$table]]) === 0, 'Planning preserves zero origins');
            $connection->update($table, [$column => 2147483647], ['id' => $samples[$table]]);
            $rejected = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $rejected = str_contains($error->getMessage(), 'Nonzero orphaned ITIL origin');
            }
            verify($rejected && $connection->createSchemaManager()->listTableColumns($table)[$column]->getNotnull(), 'Orphaned origin rejected before DDL: ' . $table . '.' . $column);
            $connection->update($table, [$column => 0], ['id' => $samples[$table]]);
        }
    }
    $migration->apply($connection);
    foreach ($relations as $table => $columns) {
        foreach ($columns as $column => $target) {
            verify($connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$samples[$table]]) === null, 'Zero origin becomes NULL');
            verify((int)$connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$samples[$table] + 1]) === ($target === 'glpi_tickets' ? $ticket : $followup), 'Nonzero origin retained');
        }
    }
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Origin migration retries are idempotent');
} finally {
    foreach (array_reverse($created) as [$table, $id]) {
        $connection->delete($table, ['id' => $id]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": ITIL task projections, planning, origin lifecycle, solution counts and migration passed.\n";
