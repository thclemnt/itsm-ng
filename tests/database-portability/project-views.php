<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/project-views.php /path/to/test-config\n");
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
    $user = (int)Session::getLoginUserID();
    $group = $fixtures->create('glpi_groups', ['name' => 'Kanban group']);
    $childEntity = (new Entity())->add(['name' => 'Kanban child', 'entities_id' => 0]);
    $foreignEntity = (new Entity())->add(['name' => 'Kanban foreign', 'entities_id' => 0]);
    $open = $fixtures->create('glpi_projectstates', ['name' => 'Open', 'is_finished' => false, 'color' => '#ffffff']);
    $closed = $fixtures->create('glpi_projectstates', ['name' => 'Closed', 'is_finished' => true]);
    $owned = $fixtures->create('glpi_projects', ['name' => 'Owned', 'users_id' => $user, 'projectstates_id' => $open]);
    $byGroup = $fixtures->create('glpi_projects', ['name' => 'Group owner', 'groups_id' => $group]);
    $byTeam = $fixtures->create('glpi_projects', ['name' => 'User team']);
    $byTeamGroup = $fixtures->create('glpi_projects', ['name' => 'Group team']);
    $hidden = $fixtures->create('glpi_projects', ['name' => 'Hidden']);
    $inactive = $fixtures->create('glpi_projects', ['name' => 'Inactive', 'users_id' => $user, 'projectstates_id' => $closed]);
    $deleted = $fixtures->create('glpi_projects', ['name' => 'Deleted', 'users_id' => $user, 'is_deleted' => true]);
    $foreign = $fixtures->create('glpi_projects', ['name' => 'Foreign', 'users_id' => $user, 'entities_id' => $foreignEntity]);
    $recursive = $fixtures->create('glpi_projects', ['name' => 'Recursive', 'users_id' => $user, 'is_recursive' => true]);
    $child = $fixtures->create('glpi_projects', ['name' => 'Child entity', 'users_id' => $user, 'entities_id' => $childEntity]);
    foreach ([[$byTeam, 'User', $user], [$byTeam, 'Group', $group], [$byTeamGroup, 'Group', $group]] as [$project, $type, $actor]) {
        $fixtures->create('glpi_projectteams', ['projects_id' => $project, 'itemtype' => $type, 'items_id' => $actor]);
    }
    $task = $fixtures->create('glpi_projecttasks', ['projects_id' => $owned, 'name' => 'Visible task']);
    $nestedTask = $fixtures->create('glpi_projecttasks', ['projects_id' => null, 'projecttasks_id' => $task, 'name' => 'Checklist subtask']);
    $hiddenTask = $fixtures->create('glpi_projecttasks', ['projects_id' => $hidden, 'name' => 'Hidden task']);
    $closedTask = $fixtures->create('glpi_projecttasks', ['projects_id' => $inactive, 'name' => 'Closed project task']);
    $unassigned = $fixtures->create('glpi_projecttasks', ['projects_id' => null, 'name' => 'Unassigned task']);
    $_SESSION['glpiactiveprofile']['project'] = Project::READMY;
    $_SESSION['glpigroups'] = [$group];
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpishowallentities'] = false;
    $choices = Project::getAllForKanban();
    foreach ([$owned, $byGroup, $byTeam, $byTeamGroup, $recursive] as $id) {
        verify(isset($choices[$id]), 'Owner and team visibility: ' . $id);
    }
    foreach ([$hidden, $inactive, $deleted, $foreign, $child] as $id) {
        verify(!isset($choices[$id]), 'Filtered project: ' . $id);
    }
    verify(isset(Project::getAllForKanban(true, $inactive)[$inactive]), 'Visible inactive selection is preserved');
    verify(!isset(Project::getAllForKanban(true, $hidden)[$hidden]) && !isset(Project::getAllForKanban(true, $foreign)[$foreign]), 'Selected ID cannot bypass owner or entity scope');
    $all = Project::getAllForKanban(false);
    verify(isset($all[$inactive], $all[$deleted]) && !isset($all[$hidden]), 'Inactive list retains visibility and has a valid state join');
    $cards = Project::getDataToDisplayOnKanban(0);
    $projectIds = array_column(array_filter($cards, fn ($row) => $row['_itemtype'] === 'Project'), 'id');
    verify(count(array_keys($projectIds, $byTeam, true)) === 1, 'Multiple matching memberships yield one card');
    $taskIds = array_column(array_filter($cards, fn ($row) => $row['_itemtype'] === 'ProjectTask'), 'id');
    verify(in_array($task, $taskIds, true) && !array_intersect([$hiddenTask, $closedTask, $unassigned], $taskIds), 'Global tasks are limited to visible active projects');
    $ownedCards = array_values(array_filter($cards, fn ($row) => $row['_itemtype'] === 'Project' && $row['id'] === $owned));
    verify(array_column($ownedCards[0]['_steps'], 'id') === [$task], 'Batched project checklists retain their owner');
    $taskCards = array_values(array_filter($cards, fn ($row) => $row['_itemtype'] === 'ProjectTask' && $row['id'] === $task));
    verify(array_column($taskCards[0]['_steps'], 'id') === [$nestedTask], 'Batched task checklists retain standalone descendants');
    verify(Project::getDataToDisplayOnKanban($hidden) === [] && Project::getDataToDisplayOnKanban($foreign) === [], 'Direct project board respects visibility');
    verify(Project::getDataToDisplayOnKanban($owned, ['projects_id' => $hidden]) === [], 'Caller criteria cannot replace the selected owner');
    $_SESSION['glpiactiveentities'] = [$childEntity];
    $choices = Project::getAllForKanban();
    verify(isset($choices[$recursive], $choices[$child]) && !isset($choices[$owned], $choices[$foreign]), 'Recursive ancestors and active entity are distinguished');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveprofile']['project'] = Project::READALL;
    verify(isset(Project::getAllForKanban()[$hidden]) && !isset(Project::getAllForKanban()[$foreign]), 'READALL retains entity scope');
    $_SESSION['glpiactiveprofile']['project'] = 0;
    verify(Project::getAllForKanban(true, $owned) === [-1 => __('Global')] && Project::getDataToDisplayOnKanban(0) === [], 'No project right exposes no project or unassigned tasks');
    $_SESSION = $savedSession;

    $typeA = $fixtures->create('glpi_projecttasktypes', ['name' => 'Alpha type']);
    $typeZ = $fixtures->create('glpi_projecttasktypes', ['name' => 'Zeta type']);
    $listingProject = $fixtures->create('glpi_projects', ['name' => 'Listing project']);
    $parent = $fixtures->create('glpi_projecttasks', ['projects_id' => $listingProject, 'name' => 'Task parent', 'projecttasktypes_id' => $typeZ, 'projectstates_id' => $open]);
    $listed = $fixtures->create('glpi_projecttasks', ['projects_id' => $listingProject, 'projecttasks_id' => $parent, 'name' => 'Task child', 'uuid' => 'de8fb774-cc9f-4f22-a370-273ab68678a2', 'date' => '2026-09-28 10:00:00', 'date_mod' => '2026-09-28 10:00:00', 'projecttasktypes_id' => $typeA, 'projectstates_id' => $open]);
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'ProjectTaskType', 'items_id' => $typeA, 'field' => 'name', 'language' => 'fr_FR', 'value' => 'Type traduit']);
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'ProjectState', 'items_id' => $open, 'field' => 'name', 'language' => 'fr_FR', 'value' => 'Ouvert']);
    $em = \itsmng\Database\Orm::create($DB);
    $repo = new \itsmng\Database\Repository\ProjectTaskRepository($em);
    $rows = $repo->listing(['projects_id' => $listingProject], ['tname ASC'], 'fr_FR', 'fr_FR');
    verify(array_column($rows, 'id') === [$listed, $parent], 'Ordering by joined task type');
    verify($rows[0]['transname2'] === 'Type traduit' && $rows[0]['transname3'] === 'Ouvert' && $rows[0]['fname'] === 'Task parent', 'Mapped labels, translation and parent joins');
    verify($rows[1]['transname2'] === null && $rows[1]['fname'] === null, 'Missing translation and root parent retain NULL');
    verify(array_column($repo->listing(['projects_id' => $listingProject], ['tname DESC'], null, null), 'id') === [$parent, $listed], 'Descending joined sort without translations');
    $fixtures->create('glpi_projecttaskteams', ['projecttasks_id' => $listed, 'itemtype' => 'User', 'items_id' => $user]);
    $fixtures->create('glpi_projecttaskteams', ['projecttasks_id' => $listed, 'itemtype' => 'Group', 'items_id' => $group]);
    $teamRows = $repo->forTeam(['glpi_projecttaskteams.itemtype' => 'User', 'glpi_projecttaskteams.items_id' => $user]);
    verify(array_column($teamRows, 'id') === [$listed], 'Calendar team query hydrates task IDs, not relation IDs');
    verify(array_column($repo->forTeam(['items_id' => [$user, $group]]), 'id') === [$listed], 'Calendar selection deduplicates matching team relations');
    $calendars = ProjectTask::getUserItemsAsVCalendars($user);
    verify(count($calendars) === 1 && (string)$calendars[0]->getBaseComponent()->UID === 'de8fb774-cc9f-4f22-a370-273ab68678a2', 'Calendar export uses the assigned task identity');
    verify((string)$calendars[0]->getBaseComponent()->SUMMARY === 'Task child', 'Calendar export retains task fields');
    $ticket1 = $fixtures->create('glpi_tickets', ['actiontime' => 60]);
    $ticket2 = $fixtures->create('glpi_tickets', ['actiontime' => 90]);
    $fixtures->create('glpi_projecttasks_tickets', ['projecttasks_id' => $listed, 'tickets_id' => $ticket1]);
    $fixtures->create('glpi_projecttasks_tickets', ['projecttasks_id' => $listed, 'tickets_id' => $ticket2]);
    $durations = $repo->effectiveDurations([$listed, $parent]);
    verify($durations[$listed] === 150 && $durations[$parent] === 0, 'Batched durations preserve multiple tickets and tasks without tickets');
    verify($repo->effectiveDurations([]) === [], 'Empty task batch remains empty');
    $members = (new \itsmng\Database\Repository\ProjectRepository($em))->teamMembers('glpi_users', [$user, $user], ['id', 'firstname', 'realname']);
    verify(count($members) === 1 && array_keys($members[0]) === ['id', 'firstname', 'realname'], 'Team projection reads only display fields');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Project::getAllForKanban();
    $repo->listing(['projects_id' => $listingProject], ['name'], null, null);
    verify($SQL_TOTAL_REQUEST === 0, 'Mapped visibility and listing bypass legacy SQL execution');
    $projectModel = new Project();
    verify($projectModel->getFromDB($listingProject), 'Load rendered project');
    ob_start();
    ProjectTask::showFor($projectModel);
    $html = ob_get_clean();
    verify(str_contains($html, 'Task child') && str_contains($html, 'Alpha type'), 'Task listing renderer consumes mapped rows');
} finally {
    $_SESSION = $savedSession;
    $DB->rollBack();
}
echo $DB->getProvider() . ": project visibility, Kanban scope, team projections and translated task listings passed.\n";
