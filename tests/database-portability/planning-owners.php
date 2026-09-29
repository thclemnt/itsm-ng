<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\PlanningOwnerReferences;
use itsmng\Database\OptionalReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/planning-owners.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $owner = $fixtures->create('glpi_users', ['name' => 'planning-owner']);
    $other = $fixtures->create('glpi_users', ['name' => 'planning-other']);
    $owned = [];
    foreach (OptionalReferences::PLANNING_OWNERS as $table => $columns) {
        $owned[$table] = $fixtures->create($table, ['users_id' => $owner, 'name' => 'Owned planning record']);
    }
    $unowned = $fixtures->create('glpi_projects', ['users_id' => null, 'name' => 'Unowned project']);
    $projectRepository = new \itsmng\Database\Repository\ProjectRepository(Orm::create($DB));
    verify(array_keys($projectRepository->visibleProjects(['id' => [$unowned, $owned['glpi_projects']]], false, $owner, [], false)) === [$owned['glpi_projects']], 'Project ownership is a mapped association');
    verify($projectRepository->visibleProjects(['id' => $unowned], false, 0, [], false) === [], 'Anonymous viewer does not own NULL projects');
    $group = $fixtures->create('glpi_groups', ['name' => 'Planning group']);
    $fixtures->create('glpi_projectteams', ['projects_id' => $unowned, 'itemtype' => 'Group', 'items_id' => $group]);
    verify(array_keys($projectRepository->visibleProjects(['id' => $unowned], false, 0, [$group], false)) === [$unowned], 'Group visibility remains independent of owner');
    $begin = '2030-01-01 12:00:00';
    $event = $owned['glpi_planningexternalevents'];
    $when = [];
    foreach (['early' => [$owner, 3600], 'start' => [$other, 0]] as $name => [$user, $offset]) {
        $when[$name] = $fixtures->create('glpi_planningrecalls', ['users_id' => $user, 'itemtype' => 'PlanningExternalEvent', 'items_id' => $event, 'before_time' => $offset]);
    }
    $untouched = $fixtures->create('glpi_planningrecalls', ['users_id' => $owner, 'itemtype' => 'ProjectTask', 'items_id' => $event, 'before_time' => 3600]);
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $_SESSION['glpiplanningreminder_isavailable'] = true;
    verify(PlanningRecall::managePlanningUpdates('PlanningExternalEvent', $event, $begin), 'Recall rescheduling succeeds');
    verify(!isset($_SESSION['glpiplanningreminder_isavailable']), 'Availability cache invalidated');
    verify($read('glpi_planningrecalls', $when['early'])['when'] === '2030-01-01 11:00:00', 'Per-recipient offset applied');
    verify($read('glpi_planningrecalls', $when['start'])['when'] === $begin, 'Zero offset retains start time');
    verify($read('glpi_planningrecalls', $untouched)['when'] === null, 'Rescheduling respects polymorphic type');
    verify(PlanningRecall::managePlanningUpdates('PlanningExternalEvent', $event, $begin), 'Repeated reschedule succeeds');
    verify($read('glpi_planningrecalls', $when['early'])['when'] === '2030-01-01 11:00:00', 'Repeated scheduling does not compound offsets');
    $late = $fixtures->create('glpi_planningrecalls', ['users_id' => $owner, 'itemtype' => 'PlanningExternalEvent', 'items_id' => $event + 100000, 'before_time' => -10]);
    PlanningRecall::managePlanningUpdates('PlanningExternalEvent', $event + 100000, $begin);
    verify($read('glpi_planningrecalls', $late)['when'] === '2030-01-01 12:00:10', 'Signed offsets remain supported');
    $repo = new \itsmng\Database\Repository\PlanningRepository(Orm::create($DB));
    $at = new DateTimeImmutable($begin);
    $ours = static fn (array $rows): array => array_values(array_intersect(array_column($rows, 'id'), array_values($when)));
    verify($ours($repo->dueRecalls($at)) === [$when['early']], 'Strict time boundary and NULL dates excluded');
    $fixtures->create('glpi_alerts', ['itemtype' => 'PlanningRecall', 'items_id' => $when['early'], 'type' => Alert::ACTION + 100, 'date' => $begin]);
    $fixtures->create('glpi_alerts', ['itemtype' => 'ProjectTask', 'items_id' => $when['early'], 'type' => Alert::ACTION, 'date' => $begin]);
    verify($ours($repo->dueRecalls($at)) === [$when['early']], 'Other alert types and item types do not suppress recall');
    $alert = $fixtures->create('glpi_alerts', ['itemtype' => 'PlanningRecall', 'items_id' => $when['early'], 'type' => Alert::ACTION, 'date' => $begin]);
    verify($ours($repo->dueRecalls($at)) === [], 'Delivered recall excluded');
    verify($SQL_TOTAL_REQUEST === 0, 'Selection and rescheduling bypass adapter SQL');
    $model = new PlanningExternalEvent();
    $model->getEmpty();
    verify($model->fields['users_id'] === Session::getLoginUserID(), 'New event defaults to current user despite nullable mapping');
    $model->getFromDB($event);
    $original = $model->fields;
    verify((new User())->delete(['id' => $owner], true), 'User purge succeeds with ownership and recipient FKs');
    foreach ($owned as $table => $id) {
        verify($read($table, $id)['users_id'] === null, 'User purge releases ' . $table);
    }
    $remaining = $read('glpi_planningexternalevents', $event);
    verify($remaining['begin'] === $original['begin'] && $remaining['end'] === $original['end'] && $remaining['date_mod'] === $original['date_mod'], 'Ownership maintenance does not replay scheduling or modification hooks');
    verify($read('glpi_planningrecalls', $when['early']) === null && $read('glpi_alerts', $alert) === null, 'User purge removes personal recalls and their delivery markers');
    verify($read('glpi_planningrecalls', $when['start'])['users_id'] === $other, 'Other recipient survives');
    $model->getFromDB($event);
    verify(in_array('70', array_column($model->rawSearchOptions(), 'id')), 'Ownerless events retain owner search option');
    $replacement = $fixtures->create('glpi_users', ['name' => 'planning-replacement']);
    $reassigned = $fixtures->create('glpi_planningexternalevents', ['users_id' => $other, 'name' => 'Reassign']);
    verify((new User())->delete(['id' => $other, '_replace_by' => $replacement], true), 'Explicit owner replacement succeeds');
    verify($read('glpi_planningexternalevents', $reassigned)['users_id'] === $replacement, 'Replacement user receives event ownership');
    verify($read('glpi_planningrecalls', $when['start']) === null, 'Personal recalls are not inherited by replacement user');
    verify((new ForeignKeys())->audit($connection) === [], 'Ownership graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new PlanningOwnerReferences();
$legacy = null;
try {
    foreach (OptionalReferences::PLANNING_OWNERS as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_projects');
    $connection->insert('glpi_projects', ['id' => $legacy, 'name' => 'legacy-name']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy planning owner migration has a plan');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_projects WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_projects', ['users_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned planning owner');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_projects')['users_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_projects', ['users_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_projects WHERE id = ?', [$legacy]) === null, 'Legacy owner default becomes NULL');
    verify($migration->apply($connection) === [], 'planning owner migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_projects', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Planning ownership, recall selection and rescheduling, purge and migration passed.\n";
