<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;

use itsmng\Database\ForeignKeys;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/projects.php /path/to/test-config\n");
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
$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $project = $fixtures->create('glpi_projects', ['name' => 'Mapped project', 'auto_percent_done' => true]);
    $other = $fixtures->create('glpi_projects', ['name' => 'Unrelated project']);
    $subproject = $fixtures->create('glpi_projects', ['projects_id' => $project, 'percent_done' => 80]);
    $fixtures->create('glpi_projects', ['projects_id' => $project, 'percent_done' => 100, 'is_deleted' => true]);
    $task = $fixtures->create('glpi_projecttasks', ['projects_id' => $project, 'name' => 'First task', 'effective_duration' => 100, 'planned_duration' => 1000, 'percent_done' => 10, 'auto_percent_done' => true]);
    $task2 = $fixtures->create('glpi_projecttasks', ['projects_id' => $project, 'effective_duration' => 200, 'planned_duration' => 2000, 'percent_done' => 20]);
    $fixtures->create('glpi_projecttasks', ['projects_id' => $other, 'effective_duration' => 9999]);
    $child = new ProjectTask();
    $childId = $child->add(['projects_id' => 0, 'projecttasks_id' => $task, 'name' => 'Standalone subtask', 'percent_done' => 40, 'effective_duration' => 30, 'planned_duration' => 40]);
    verify((bool)$childId && $child->fields['projects_id'] === null, 'A subtask can omit its direct project');
    $child2 = $fixtures->create('glpi_projecttasks', ['projects_id' => null, 'projecttasks_id' => $task, 'percent_done' => 41]);
    $ticket = $fixtures->create('glpi_tickets', ['actiontime' => 60]);
    $ticket2 = $fixtures->create('glpi_tickets', ['actiontime' => 90]);
    foreach ([[$task, $ticket], [$task, $ticket2], [$task2, $ticket]] as [$owner, $target]) {
        $fixtures->create('glpi_projecttasks_tickets', ['projecttasks_id' => $owner, 'tickets_id' => $target]);
    }
    $fixtures->create('glpi_projecttasks_tickets', ['projecttasks_id' => $childId, 'tickets_id' => $ticket]);
    verify(ProjectTask::getTotalEffectiveDurationForProject(0) === 90 && ProjectTask::getTotalPlannedDurationForProject(0) === 40, 'Legacy empty project aggregate selects NULL owners');
    verify(ProjectTask::getAllTicketsForProject(0) === [$ticket], 'Legacy empty project ticket selection');
    verify(ProjectTask::getTotalEffectiveDuration($task) === 250, 'Task duration adds each linked ticket');
    verify(ProjectTask::getTotalEffectiveDurationForProject($project) === 510, 'Project duration sums tasks once and tickets per association');
    verify(ProjectTask::getTotalPlannedDurationForProject($project) === 3000, 'Planned aggregate is scoped');
    verify(ProjectTask::getTotalEffectiveDuration(-1) === 0 && ProjectTask::getTotalPlannedDurationForProject(-1) === 0, 'Missing owners return zero');
    verify(ProjectTask::getAllTicketsForProject($project) === [$ticket, $ticket2, $ticket], 'Ticket associations preserve shared ticket multiplicity');
    verify(count(ProjectTask::getAllForProject($project)) === 2 && count(ProjectTask::getAllForProjectTask($task)) === 2, 'Project and subtask selectors');
    verify(ProjectTask::recalculatePercentDone($task), 'Recalculate task progress');
    verify($child->getFromDB($task) && (int)$child->fields['percent_done'] === 41, 'Portable rounding of fractional mean');
    verify(Project::recalculatePercentDone($project), 'Recalculate project progress');
    $model = new Project();
    verify($model->getFromDB($project) && (int)$model->fields['percent_done'] === 47, 'Weighted mean over active subprojects and direct tasks');
    verify(!Project::recalculatePercentDone($other), 'Manual progress is preserved');
    $emptyAuto = $fixtures->create('glpi_projecttasks', ['projects_id' => $other, 'auto_percent_done' => true, 'percent_done' => 50]);
    verify(ProjectTask::recalculatePercentDone($emptyAuto), 'Empty task aggregate');
    verify($child->getFromDB($emptyAuto) && (int)$child->fields['percent_done'] === 0, 'No subtasks means zero progress');

    $user = $fixtures->create('glpi_users', ['name' => 'Project team member']);
    $fixtures->create('glpi_projectteams', ['projects_id' => $project, 'itemtype' => 'User', 'items_id' => $user]);
    $fixtures->create('glpi_projecttaskteams', ['projecttasks_id' => $task, 'itemtype' => 'User', 'items_id' => $user]);
    $cost1 = $fixtures->create('glpi_projectcosts', ['projects_id' => $project, 'end_date' => '2026-01-01', 'cost' => '10.50']);
    $cost2 = $fixtures->create('glpi_projectcosts', ['projects_id' => $project, 'end_date' => '2026-02-01', 'cost' => '20.25']);
    $computer = $fixtures->create('glpi_computers');
    $fixtures->create('glpi_items_projects', ['projects_id' => $project, 'itemtype' => 'Computer', 'items_id' => $computer]);
    $fixtures->create('glpi_itils_projects', ['projects_id' => $project, 'itemtype' => 'Ticket', 'items_id' => $ticket]);
    verify((int)(new ProjectCost())->getLastCostForProject($project)['id'] === $cost2, 'Latest cost ordering');
    verify(count(ProjectTeam::getTeamFor($project)['User']) === 1 && count(ProjectTaskTeam::getTeamFor($task)['User']) === 1, 'Mapped team lists');
    $GLPI_CACHE->delete('sons_cache_glpi_projects_0');
    $roots = getSonsOf('glpi_projects', 0);
    verify(isset($roots[$project], $roots[$subproject]), 'Shared tree traversal understands nullable roots');
    $gantt = ProjectTask::getDataToDisplayOnGanttForProject($project);
    verify(count($gantt) === 4, 'Gantt includes NULL-root tasks and standalone descendants');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    ProjectTask::getAllTicketsForProject($project);
    ProjectTask::getTotalEffectiveDurationForProject($project);
    ProjectTask::getAllForProject($project);
    ProjectTask::getAllForProjectTask($task);
    ProjectTeam::getTeamFor($project);
    ProjectTaskTeam::getTeamFor($task);
    (new ProjectCost())->getLastCostForProject($project);
    verify($SQL_TOTAL_REQUEST === 0, 'Project selectors and aggregates bypass legacy SQL execution');
    verify($model->delete(['id' => $project], true), 'Project purge with all child foreign keys');
    foreach (['glpi_items_projects', 'glpi_itils_projects', 'glpi_projectcosts', 'glpi_projecttasks', 'glpi_projectteams'] as $table) {
        verify(countElementsInTable($table, ['projects_id' => $project]) === 0, 'Purged child relationship: ' . $table);
    }
    verify(countElementsInTable('glpi_projecttaskteams', ['projecttasks_id' => $task]) === 0, 'Task purge removes team');
    verify($child->getFromDB($childId) && $child->fields['projecttasks_id'] === null, 'Standalone descendant survives with cleared parent');
    verify($model->getFromDB($subproject) && $model->fields['projects_id'] === null, 'Subproject survives with cleared parent');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned relationships');
} finally {
    $DB->rollBack();
}

// Recreate legacy NOT NULL/default-zero ancestry in an otherwise disposable schema.
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$projectId = $taskId = null;
try {
    foreach (ReferenceHistory::get('optional', 'PROJECT_HIERARCHY') as $table => $relations) {
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
    // Explicit IDs avoid running the upgraded model against the old schema.
    $projectId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_projects');
    $taskId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_projecttasks');
    $connection->insert('glpi_projects', ['id' => $projectId, 'name' => 'Legacy hierarchy', 'projects_id' => 0]);
    $connection->insert('glpi_projecttasks', ['id' => $taskId, 'projects_id' => 0, 'projecttasks_id' => 0]);
    $migration = new NullableReferences(ReferenceHistory::get('optional', 'PROJECT_HIERARCHY'), 'hierarchy');
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) >= 3, 'Read-only plan reports DDL and zeros');
    verify((int)$connection->fetchOne('SELECT projects_id FROM glpi_projecttasks WHERE id = ?', [$taskId]) === 0, 'Plan preserves legacy data');
    $connection->update('glpi_projecttasks', ['projecttasks_id' => 2147483647], ['id' => $taskId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned hierarchy');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_projects')['projects_id']->getNotnull(), 'Orphan refusal occurs before any schema change');
    $connection->update('glpi_projecttasks', ['projecttasks_id' => 0], ['id' => $taskId]);
    $connection->update('glpi_projects', ['id' => 0], ['id' => $projectId]);
    try {
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Empty selection is a real hierarchy identifier');
        }
        verify($rejected, 'Real zero project identifiers require explicit repair');
    } finally {
        $connection->update('glpi_projects', ['id' => $projectId], ['id' => 0]);
    }
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT projects_id FROM glpi_projecttasks WHERE id = ?', [$taskId]) === null, 'Migration normalizes empty task owner');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Schema and data migration is idempotent');
} finally {
    if ($taskId !== null) {
        $connection->delete('glpi_projecttasks', ['id' => $taskId]);
    }
    if ($projectId !== null) {
        $connection->delete('glpi_projects', ['id' => $projectId]);
    }
    (new NullableReferences(ReferenceHistory::get('optional', 'PROJECT_HIERARCHY'), 'hierarchy'))->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": project aggregates, hierarchy, Gantt roots, teams, purge and schema migration passed.\n";
