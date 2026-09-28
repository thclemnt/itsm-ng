<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\PlanningMetadataReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/planning-metadata.php /path/to/test-config\n");
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
$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $storage = new \itsmng\Database\MappedStorage($DB);
    foreach (OptionalReferences::PLANNING_METADATA as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Planning original']);
            $replacement = $fixtures->create($target, ['name' => 'Planning replacement']);
            $other = $fixtures->create($target, ['name' => 'Planning unrelated']);
            $extra = ['name' => 'Planning dependent'];
            if ($table === 'glpi_planningexternalevents') {
                $extra += ['begin' => '2026-09-28 10:00:00', 'end' => '2026-09-28 12:00:00', 'users_id' => Session::getLoginUserID(), 'text' => '', 'rrule' => ''];
            }
            $id = $fixtures->create($table, [$column => $parent] + $extra);
            $otherId = $fixtures->create($table, [$column => $other] + $extra);
            $empty = $fixtures->create($table, [$column => null] + $extra);
            $storage->update($table, $empty, [$column => 0]);
            $item = getItemForItemtype(getItemTypeForTable($table));
            verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Empty planning reference: ' . $table . '.' . $column);
            verify(count($item->find(['id' => $empty, $column => 0])) === 1, 'Legacy empty criteria');
            $model = getItemForItemtype(getItemTypeForTable($target));
            verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace planning metadata');
            verify($item->getFromDB($id) && (int)$item->fields[$column] === $replacement, 'Replacement applied: ' . $table . '.' . $column);
            verify($model->delete(['id' => $replacement], true), 'Purge planning metadata');
            verify($item->getFromDB($id) && $item->fields[$column] === null, 'Purge clears reference: ' . $table . '.' . $column);
            verify($item->getFromDB($otherId) && (int)$item->fields[$column] === $other, 'Unrelated metadata preserved');
        }
    }
    $owner = (int)Session::getLoginUserID();
    $otherUser = $fixtures->create('glpi_users', ['name' => 'Calendar other']);
    $category = $fixtures->create('glpi_planningeventcategories', ['name' => 'Calendar category', 'color' => '#123456']);
    $group = $fixtures->create('glpi_groups', ['name' => 'Calendar group']);
    $fixtures->create('glpi_groups', ['name' => 'Calendar nonmember']);
    $foreignEntity = (new Entity())->add(['name' => 'Calendar foreign entity', 'entities_id' => 0]);
    $fixtures->create('glpi_groups', ['name' => 'Calendar foreign group', 'entities_id' => $foreignEntity]);
    $make = static fn (array $values) => $fixtures->create('glpi_planningexternalevents', $values + [
        'name' => "Mapped O'Reilly event", 'text' => 'Calendar text', 'users_id' => $owner,
        'begin' => '2026-09-28 10:00:00', 'end' => '2026-09-28 12:00:00',
        'state' => Planning::TODO, 'rrule' => '', 'uuid' => bin2hex(random_bytes(16)),
    ]);
    $plain = $make([]);
    $colored = $make(['planningeventcategories_id' => $category]);
    $guest = $make(['users_id' => $otherUser, 'users_id_guests' => json_encode([(string)$owner])]);
    $groupEvent = $make(['users_id' => $otherUser, 'groups_id' => $group]);
    $hidden = $make(['users_id' => $otherUser]);
    $boundary = $make(['end' => '2026-09-28 10:00:00']);
    $done = $make(['state' => Planning::DONE]);
    $info = $make(['state' => Planning::INFO]);
    $recurring = $make(['begin' => '2026-09-27 10:00:00', 'end' => '2026-09-27 12:00:00',
        'rrule' => json_encode(['freq' => 'daily', 'interval' => 1, 'count' => 3])]);
    $options = ['who' => $owner, 'whogroup' => 0, 'begin' => '2026-09-28 10:00:00', 'end' => '2026-09-28 12:00:00'];
    $events = PlanningExternalEvent::populatePlanning($options);
    $byId = array_column($events, null, 'id');
    verify(isset($byId[$plain], $byId[$colored], $byId[$guest], $byId[$done], $byId[$info], $byId[$recurring]), 'Calendar includes owner, guest, uncategorized and recurring events');
    verify(!isset($byId[$hidden]) && !isset($byId[$boundary]) && !isset($byId[$groupEvent]), 'Actor isolation and exclusive overlap');
    verify($byId[$plain]['event_cat_color'] === '' && $byId[$colored]['event_cat_color'] === '#123456', 'Optional category color');
    $events = PlanningExternalEvent::populatePlanning($options + ['check_planned' => true]);
    $byId = array_column($events, null, 'id');
    verify(!isset($byId[$info]) && isset($byId[$recurring]), 'Availability excludes informational events and expands recurrence');
    verify($byId[$recurring]['begin'] === '2026-09-28 10:00:00', 'Recurrence expands in requested window');
    $byId = array_column(PlanningExternalEvent::populatePlanning($options + ['display_done_events' => false]), null, 'id');
    verify(!isset($byId[$done]) && isset($byId[$plain]), 'Done-event filter');
    $_SESSION['glpigroups'] = [$group];
    $events = PlanningExternalEvent::populatePlanning(array_replace($options, ['whogroup' => 'mine']));
    verify(in_array($groupEvent, array_column($events, 'id')), 'Group subscription adds group events to owned and guest events');
    verify(!str_contains(implode('', array_keys($events)), 'Array'), 'Array group scope has stable keys');
    $_SESSION['glpigroups'] = [];
    verify(PlanningExternalEvent::populatePlanning(array_replace($options, ['who' => 0, 'whogroup' => 'mine'])) === [], 'Empty group scope does not select unassigned events');
    $events = PlanningExternalEvent::populatePlanning(array_replace($options, ['whogroup' => 'mine']));
    verify(in_array($plain, array_column($events, 'id')) && !in_array($groupEvent, array_column($events, 'id')), 'Empty group scope retains only user events');
    $calendars = PlanningExternalEvent::getUserItemsAsVCalendars($owner);
    $uids = array_map(static fn ($calendar) => (string)$calendar->VEVENT->UID, $calendars);
    $event = new PlanningExternalEvent();
    verify($event->getFromDB($guest) && in_array($event->fields['uuid'], $uids, true), 'iCalendar includes invited user');
    verify($event->getFromDB($hidden) && !in_array($event->fields['uuid'], $uids, true), 'iCalendar excludes unrelated users');
    verify(count(PlanningExternalEvent::getGroupItemsAsVCalendars([$group])) === 1, 'Group iCalendar scope');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    (new \itsmng\Database\Repository\PlanningRepository(\itsmng\Database\Orm::create($DB)))->externalEvents(['users_id' => $owner]);
    PlanningExternalEvent::populatePlanning($options);
    PlanningExternalEvent::getUserItemsAsVCalendars($owner);
    $_SESSION['glpiactiveprofile']['planning'] = READ;
    $_SESSION['glpigroups'] = [$group];
    ob_start();
    Planning::showAddGroupForm();
    $html = ob_get_clean();
    verify(str_contains($html, 'Calendar group') && !str_contains($html, 'Calendar nonmember') && !str_contains($html, 'Calendar foreign group'), 'Group selector renders permitted memberships');
    ob_start();
    Planning::showAddGroupUsersForm();
    $html = ob_get_clean();
    verify(str_contains($html, 'Calendar nonmember') && !str_contains($html, 'Calendar foreign group'), 'Group-user selector stays in active entity');
    verify($SQL_TOTAL_REQUEST === 0, 'Calendar query and group selectors use ORM');
    verify((new ForeignKeys())->audit($connection) === [], 'Planning relationship graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new PlanningMetadataReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::PLANNING_METADATA as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_projects');
    $connection->insert('glpi_projects', ['id' => $legacyId, 'name' => 'Legacy planning metadata']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Planning metadata migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT projectstates_id FROM glpi_projects WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_projects', ['projectstates_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned planning metadata');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_projects')['projectstates_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_projects', ['projectstates_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT projectstates_id FROM glpi_projects WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Planning metadata migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_projects', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": planning metadata lifecycle, calendar projections and migration passed.\n";
