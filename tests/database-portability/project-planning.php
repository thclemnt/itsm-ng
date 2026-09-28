<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/project-planning.php /path/to/test-config\n");
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
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $entity = (new Entity())->add(['name' => 'Planning child', 'entities_id' => 0]);
    $foreign = (new Entity())->add(['name' => 'Planning foreign', 'entities_id' => 0]);
    $central = $fixtures->create('glpi_profiles', ['name' => 'Planning central', 'interface' => 'central']);
    $helpdesk = $fixtures->create('glpi_profiles', ['name' => 'Planning helpdesk', 'interface' => 'helpdesk']);
    $users = [];
    foreach (['Alice', 'Bob', 'Recursive', 'Foreign', 'Helpdesk'] as $name) {
        $users[$name] = $fixtures->create('glpi_users', ['name' => 'Planning ' . $name]);
    }
    foreach ([['Alice', $central, $entity, false], ['Bob', $central, 0, false], ['Recursive', $central, 0, true], ['Foreign', $central, $foreign, false], ['Helpdesk', $helpdesk, $entity, false]] as [$name, $profile, $scope, $recursive]) {
        $fixtures->create('glpi_profiles_users', ['users_id' => $users[$name], 'profiles_id' => $profile, 'entities_id' => $scope, 'is_recursive' => $recursive]);
    }
    $group = $fixtures->create('glpi_groups', ['name' => 'Planning group']);
    $group2 = $fixtures->create('glpi_groups', ['name' => 'Other planning group']);
    $project = $fixtures->create('glpi_projects', ['name' => 'Planning project']);
    $closed = $fixtures->create('glpi_projectstates', ['name' => 'Finished', 'is_finished' => true]);
    $make = static function (array $values, array $actors) use ($fixtures, $project): int {
        $id = $fixtures->create('glpi_projecttasks', $values + [
            'projects_id' => $project, 'name' => 'Planning task', 'content' => 'Planning content',
            'plan_start_date' => '2026-09-28 10:00:00', 'plan_end_date' => '2026-09-28 12:00:00',
            'date' => '2026-09-28 11:00:00', 'planned_duration' => 3600,
        ]);
        foreach ($actors as [$type, $actor]) {
            $fixtures->create('glpi_projecttaskteams', ['projecttasks_id' => $id, 'itemtype' => $type, 'items_id' => $actor]);
        }
        return $id;
    };
    $alice = [['User', $users['Alice']]];
    $bob = [['User', $users['Bob']]];
    $planned = $make([], array_merge($alice, $bob));
    $boundary = $make(['plan_start_date' => '2026-09-28 09:00:00', 'plan_end_date' => '2026-09-28 10:00:00'], $alice);
    $outside = $make(['plan_start_date' => '2026-09-28 08:00:00', 'plan_end_date' => '2026-09-28 09:59:59'], $alice);
    $done = $make(['percent_done' => 100], $alice);
    $finished = $make(['projectstates_id' => $closed], $alice);
    $bobTask = $make([], $bob);
    $groupTask = $make([], [['Group', $group], ['Group', $group2]]);
    $recursiveTask = $make([], [['User', $users['Recursive']]]);
    $foreignTask = $make([], [['User', $users['Foreign']]]);
    $helpdeskTask = $make([], [['User', $users['Helpdesk']]]);
    $unassigned = $make([], []);
    $unplannedValues = ['plan_start_date' => null, 'plan_end_date' => null];
    $unplanned = $make($unplannedValues, $alice);
    $unplannedOther = $make($unplannedValues, $bob);
    $unplannedDone = $make($unplannedValues + ['percent_done' => 100], $alice);
    $unplannedFinished = $make($unplannedValues + ['projectstates_id' => $closed], $alice);
    $zeroDuration = $make($unplannedValues + ['planned_duration' => 0], $alice);
    $distant = $make($unplannedValues + ['date' => '2026-09-29 11:00:00'], $alice);
    $partial = $make(['plan_end_date' => null], $alice);
    $noDate = $make($unplannedValues + ['date' => null], $alice);
    $options = ['who' => $users['Alice'], 'whogroup' => 0, 'begin' => '2026-09-28 10:00:00', 'end' => '2026-09-28 12:00:00'];
    $ids = static fn (array $events) => array_map('intval', array_column($events, 'id'));
    $events = ProjectTask::populatePlanning($options);
    $found = $ids($events);
    sort($found);
    $expected = [$planned, $boundary];
    sort($expected);
    verify($found === $expected, 'Explicit actor and inclusive planned overlap');
    $boundaryEvent = array_values(array_filter($events, fn ($event) => $event['id'] === $boundary))[0];
    verify($boundaryEvent['begin'] === $options['begin'] && $boundaryEvent['end'] === $options['begin'], 'Event bounds clip to requested window');
    verify(count(ProjectTask::populatePlanning($options + ['display_done_events' => true])) === 4, 'Completed and finished events can be requested');
    $notPlanned = ProjectTask::populateNotPlanned($options);
    verify($ids($notPlanned) === [$unplanned], 'Unplanned dates retain actor and completion filters');
    verify(reset($notPlanned)['begin'] === '2026-09-28 10:00:00' && reset($notPlanned)['end'] === '2026-09-28 12:00:00', 'Portable creation-date plus/minus duration');
    verify(count(ProjectTask::populateNotPlanned($options + ['display_done_events' => true])) === 3, 'Unplanned done option retains actor scope');
    verify($ids(ProjectTask::populatePlanning(array_replace($options, ['whogroup' => $group]))) === [$groupTask], 'Explicit group takes precedence over user');
    $_SESSION['glpigroups'] = [$group, $group2];
    $groupEvents = ProjectTask::populatePlanning(array_replace($options, ['whogroup' => 'mine']));
    verify($ids($groupEvents) === [$groupTask], 'Several matching groups produce one event');
    verify(!str_contains((string)array_key_first($groupEvents), 'Array'), 'Group event key uses explicit IDs');
    $_SESSION['glpigroups'] = [];
    verify(ProjectTask::populatePlanning(array_replace($options, ['whogroup' => 'mine'])) === [], 'Empty mine scope returns no events');
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $all = $ids(ProjectTask::populatePlanning(array_replace($options, ['who' => 0])));
    verify(in_array($planned, $all, true) && in_array($recursiveTask, $all, true), 'Active and recursive ancestor central profiles qualify');
    verify(!array_intersect([$bobTask, $foreignTask, $helpdeskTask, $groupTask, $unassigned], $all), 'Profile fallback excludes foreign, nonrecursive and helpdesk-only users');
    verify(ProjectTask::populatePlanning(array_replace($options, ['begin' => '2026-09-29 00:00:00'])) === [], 'Reversed interval returns no rows');
    verify(ProjectTask::populatePlanning(['begin' => 'NULL']) === [], 'Missing bounds preserve empty contract');
    // Group membership hooks update only subscribers, preserving unrelated settings.
    $currentUser = (int)Session::getLoginUserID();
    $groupKey = 'group_' . $group . '_users';
    $settings = ['plannings' => [$groupKey => ['display' => true, 'type' => 'group_users', 'users' => []]], 'note' => "O'Reilly 日本語"];
    $writer = new \itsmng\Database\Repository\RecordWriter(\itsmng\Database\Orm::create($DB));
    $encoded = json_encode($settings, JSON_THROW_ON_ERROR);
    foreach ([$currentUser, $users['Alice']] as $subscriber) {
        $writer->update('glpi_users', $subscriber, ['plannings' => $encoded]);
    }
    $lookalike = ['plannings' => ['groupX' . $group . 'Xusers' => ['users' => []]]];
    $writer->update('glpi_users', $users['Foreign'], ['plannings' => json_encode($lookalike)]);
    $writer->update('glpi_users', $users['Helpdesk'], ['plannings' => 'not JSON: ' . $groupKey]);
    $membership = new Group_User();
    $membershipId = $membership->add(['groups_id' => $group, 'users_id' => $users['Bob']]);
    verify((bool)$membershipId, 'Add membership invokes mapped subscription hook');
    $userModel = new User();
    foreach ([$currentUser, $users['Alice']] as $subscriber) {
        verify($userModel->getFromDB($subscriber), 'Load subscriber');
        $updated = json_decode($userModel->fields['plannings'], true);
        verify(isset($updated['plannings'][$groupKey]['users']['user_' . $users['Bob']]), 'Subscribed group gains member');
        verify($updated['note'] === $settings['note'], 'Unrelated Unicode and quoted settings are preserved');
    }
    verify(isset($_SESSION['glpi_plannings']['plannings'][$groupKey]['users']['user_' . $users['Bob']]), 'Current subscriber session is updated');
    verify($userModel->getFromDB($users['Foreign']) && json_decode($userModel->fields['plannings'], true) === $lookalike, 'LIKE wildcard lookalikes do not create subscriptions');
    verify($userModel->getFromDB($users['Helpdesk']) && $userModel->fields['plannings'] === 'not JSON: ' . $groupKey, 'Malformed unrelated settings are not overwritten');
    verify($membership->delete(['id' => $membershipId], true), 'Purge membership invokes mapped subscription hook');
    verify($userModel->getFromDB($currentUser) && json_decode($userModel->fields['plannings'], true) === $settings, 'Removing member preserves the rest of the subscription');
    verify($_SESSION['glpi_plannings'] === $settings, 'Session follows membership removal');
    $planningRepo = new \itsmng\Database\Repository\PlanningRepository(\itsmng\Database\Orm::create($DB));
    $calls = 0;
    $failed = false;
    try {
        $planningRepo->updateGroupSubscriptions($group, $currentUser, static function (array $data, string $key) use (&$calls): array {
            if (++$calls === 2) {
                throw new RuntimeException('Subscription rollback probe');
            }
            $data['probe'] = true;
            return $data;
        });
    } catch (RuntimeException $error) {
        $failed = $error->getMessage() === 'Subscription rollback probe';
        if (!$failed) {
            fwrite(STDERR, (string)$error . PHP_EOL);
        }
    }
    verify($failed && $DB->inTransaction(), 'A failed subscription batch retains the caller transaction');
    verify($userModel->getFromDB($currentUser) && json_decode($userModel->fields['plannings'], true) === $settings, 'Earlier subscriber changes roll back on failure');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repo = new \itsmng\Database\Repository\ProjectTaskRepository(\itsmng\Database\Orm::create($DB));
    $rows = $repo->planning($users['Alice'], null, [], new DateTime($options['begin']), new DateTime($options['end']), false, true);
    $planningRepo->updateGroupSubscriptions($group, $currentUser, static fn (array $settings, string $key) => $settings);
    verify(array_column($rows, 'id') === [$unplanned] && $SQL_TOTAL_REQUEST === 0, 'Planning and subscription writes execute directly through mapped ORM');
} finally {
    $_SESSION = $savedSession;
    $DB->rollBack();
}
echo $DB->getProvider() . ": project planning dates, actors, groups, completion and profile scope passed.\n";
