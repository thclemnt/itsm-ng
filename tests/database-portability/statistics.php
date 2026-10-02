<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILStatisticsRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/statistics.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$create = $fixtures->create(...);
$repository = static fn () => new ITILStatisticsRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $entity = (int)(new Entity())->add(['name' => 'Statistics entity', 'entities_id' => 0]);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $title = $create('glpi_usertitles', ['name' => 'Statistics title']);
    $category = $create('glpi_usercategories', ['name' => 'Statistics user category']);
    $users = [
        $create('glpi_users', ['name' => 'Statistics author one', 'usertitles_id' => $title, 'usercategories_id' => $category]),
        $create('glpi_users', ['name' => 'Statistics author two', 'usertitles_id' => $title, 'usercategories_id' => $category]),
    ];
    $group = (int)(new Group())->add(['name' => 'Statistics group', 'entities_id' => $entity, 'is_requester' => 1, 'is_assign' => 1]);
    $childGroup = (int)(new Group())->add(['name' => 'Statistics child group', 'entities_id' => $entity, 'groups_id' => $group, 'is_requester' => 1, 'is_assign' => 1]);
    $supplier = $create('glpi_suppliers', ['name' => 'Statistics supplier', 'entities_id' => $entity]);
    $itilCategory = (int)(new ITILCategory())->add(['name' => 'Statistics category', 'entities_id' => $entity]);
    $childCategory = (int)(new ITILCategory())->add(['name' => 'Statistics child category', 'entities_id' => $entity, 'itilcategories_id' => $itilCategory]);
    $location = (int)(new Location())->add(['name' => 'Statistics location', 'entities_id' => $entity]);
    $childLocation = (int)(new Location())->add(['name' => 'Statistics child location', 'entities_id' => $entity, 'locations_id' => $location]);
    $solution = $create('glpi_solutiontypes', ['name' => 'Statistics solution']);
    $request = $create('glpi_requesttypes', ['name' => 'Statistics request']);
    $computerType = $create('glpi_computertypes', ['name' => 'Statistics computer type']);
    $os = $create('glpi_operatingsystems', ['name' => 'Statistics OS']);
    $device = $create('glpi_deviceprocessors', ['designation' => 'Statistics processor']);
    $computers = [];
    foreach ([false, false, true] as $template) {
        $computer = $create('glpi_computers', ['entities_id' => $entity, 'computertypes_id' => $computerType, 'is_template' => $template]);
        $create('glpi_items_operatingsystems', ['itemtype' => 'Computer', 'items_id' => $computer, 'operatingsystems_id' => $os]);
        foreach ([1, 2] as $frequency) {
            $create('glpi_items_deviceprocessors', ['itemtype' => 'Computer', 'items_id' => $computer, 'deviceprocessors_id' => $device, 'entities_id' => $entity, 'frequency' => $frequency]);
        }
        $computers[] = $computer;
    }
    $parents = [];
    foreach (['Ticket', 'Problem', 'Change'] as $type) {
        $model = new $type();
        $table = $model->getTable();
        $fk = $model->getForeignKeyField();
        $linkUsers = $model->userlinkclass::getTable();
        $linkGroups = $model->grouplinkclass::getTable();
        $linkSuppliers = $model->supplierlinkclass::getTable();
        $links = match ($type) {
            'Ticket' => 'glpi_items_tickets', 'Problem' => 'glpi_items_problems', 'Change' => 'glpi_changes_items',
        };
        $base = ['entities_id' => $entity, 'status' => $model->getClosedStatusArray()[0], 'date' => '2025-01-05 12:00:00',
            'solvedate' => '2025-01-10 12:00:00', 'closedate' => '2025-01-15 12:00:00', 'time_to_resolve' => '2025-01-09 12:00:00',
            'solve_delay_stat' => 100, 'close_delay_stat' => 200, 'actiontime' => 300, 'itilcategories_id' => $childCategory, 'users_id_recipient' => $users[0]];
        if ($type === 'Ticket') {
            $base += ['takeintoaccount_delay_stat' => 50, 'locations_id' => $childLocation, 'requesttypes_id' => $request];
        }
        $first = $create($table, $base);
        $second = $create($table, array_replace($base, ['solve_delay_stat' => 300, 'close_delay_stat' => 600, 'actiontime' => 900, 'time_to_resolve' => '2025-01-10 12:00:00']));
        $parents[$type] = [$first, $second];
        // Scope, deletion, unresolved and end-day exclusions protect every metric.
        $create($table, array_replace($base, ['entities_id' => 0]));
        $create($table, array_replace($base, ['is_deleted' => true]));
        $create($table, array_replace($base, ['date' => '2025-02-01 00:00:00', 'solvedate' => '2025-02-01 00:00:00', 'closedate' => '2025-02-01 00:00:00']));
        foreach ([$first, $second] as $index => $id) {
            foreach (array_slice($users, 0, $index === 0 ? 2 : 1) as $user) {
                foreach ([CommonITILActor::REQUESTER, CommonITILActor::ASSIGN] as $role) {
                    $create($linkUsers, [$fk => $id, 'users_id' => $user, 'type' => $role]);
                }
            }
            foreach ([$group, $childGroup] as $assignedGroup) {
                foreach ([CommonITILActor::REQUESTER, CommonITILActor::ASSIGN] as $role) {
                    $create($linkGroups, [$fk => $id, 'groups_id' => $assignedGroup, 'type' => $role]);
                }
            }
            $create($linkSuppliers, [$fk => $id, 'suppliers_id' => $supplier, 'type' => CommonITILActor::ASSIGN]);
            foreach ([100, $index === 0 ? 200 : 600, 0] as $actiontime) {
                $create(getTableForItemType($type . 'Task'), [$fk => $id, 'users_id' => $users[0], 'actiontime' => $actiontime]);
            }
            foreach ([1, 2] as $ignored) {
                $create('glpi_itilsolutions', ['itemtype' => $type, 'items_id' => $id, 'solutiontypes_id' => $solution]);
            }
            foreach ($computers as $computer) {
                $create($links, [$fk => $id, 'itemtype' => 'Computer', 'items_id' => $computer]);
            }
            if ($type === 'Ticket') {
                $create('glpi_ticketsatisfactions', ['tickets_id' => $id, 'date_answered' => $index === 0 ? '2025-01-16 12:00:00' : null, 'satisfaction' => $index === 0 ? 4 : 1]);
            }
        }
        $measure = static fn (string $metric, string $dimension = '', mixed $value = '', mixed $secondary = '') => Stat::constructEntryValues($type, $metric, '2025-01-01', '2025-01-31', $dimension, $value, $secondary);
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $SQL_TOTAL_REQUEST = 0;
        foreach (['inter_total' => 2, 'inter_solved' => 2, 'inter_closed' => 2, 'inter_solved_late' => 1,
            'inter_solved_with_actiontime' => 2, 'inter_avgsolvedtime' => 200, 'inter_avgclosedtime' => 400, 'inter_avgactiontime' => 600] as $metric => $expected) {
            verify($measure($metric)['2025-01'] == $expected, 'Numeric metric: ' . $type . '/' . $metric);
        }
        $dimensions = [
            ['technicien', $users[0], ''], ['user', $users[0], ''], ['usertitles_id', $title, ''], ['usercategories_id', $category, ''],
            ['group', $group, ''], ['groups_id_assign', $group, ''], ['group_tree', $group, -1], ['groups_tree_assign', $group, -1],
            ['technicien_followup', $users[0], ''], ['suppliers_id_assign', $supplier, ''], ['solutiontypes_id', $solution, ''],
            ['itilcategories_id', $childCategory, ''], ['itilcategories_tree', $itilCategory, -1],
            ['users_id_recipient', $users[0], ''], ['priority', $base['priority'] ?? 1, ''], ['urgency', 1, ''], ['impact', 1, ''],
            ['device', $device, 'DeviceProcessor'], ['comp_champ', $computerType, 'ComputerType'], ['comp_champ', $os, 'OperatingSystem'],
        ];
        if ($type === 'Ticket') {
            $dimensions = [...$dimensions, ['locations_id', $childLocation, ''], ['locations_tree', $location, -1], ['requesttypes_id', $request, ''], ['type', Ticket::INCIDENT_TYPE, '']];
            foreach (['inter_opensatisfaction' => 2, 'inter_answersatisfaction' => 1, 'inter_avgsatisfaction' => 4, 'inter_avgtakeaccount' => 50] as $metric => $expected) {
                verify($measure($metric)['2025-01'] == $expected, 'Ticket metric ' . $metric);
            }
        }
        foreach ($dimensions as [$dimension, $value, $secondary]) {
            verify($measure('inter_total', $dimension, $value, $secondary)['2025-01'] === 2, 'Dimension count: ' . $type . '/' . $dimension);
            $expected = $dimension === 'technicien_followup' ? 250 : 600;
            verify($measure('inter_avgactiontime', $dimension, $value, $secondary)['2025-01'] == $expected, 'No relationship fan-out: ' . $type . '/' . $dimension);
            verify($measure('inter_avgsolvedtime', $dimension, $value, $secondary)['2025-01'] == 200, 'Solved averages count each parent once: ' . $type . '/' . $dimension);
            if ($type === 'Ticket') {
                verify($measure('inter_avgsatisfaction', $dimension, $value, $secondary)['2025-01'] == 4, 'Satisfaction dimension: ' . $dimension);
            }
        }
        verify($measure('inter_avgsolvedtime', 'technicien_followup', $users[0])['2025-01'] == 200, 'Non-task averages count each parent once');
        verify($measure('inter_total', 'itilcategories_tree', $itilCategory, $itilCategory)['2025-01'] === 0, 'Tree selection can exclude descendants');
        verify($measure('inter_total', 'group', 2147483647)['2025-01'] === 0, 'Unmatched dimension stays empty');
        verify($SQL_TOTAL_REQUEST === 0, 'Monthly statistics do not invoke legacy SQL: ' . $type);
        $scope = [$entity];
        $args = [$type, 'inter_total', '2025-01-01', '2025-01-31', '', '', '', $scope, array_merge($model->getClosedStatusArray(), $model->getSolvedStatusArray()), $model->getClosedStatusArray()];
        verify($repository()->monthly(...[...$args, ['WHERE' => ['id' => $first]]])['2025-01'] === 1, 'Mapped extension criteria');
        try {
            $repository()->monthly(...[...$args, ['SELECT' => 'NOW()']]);
            throw new RuntimeException('Raw statistics extension accepted');
        } catch (InvalidArgumentException) {
        }
        $unrestricted = $args;
        $unrestricted[7] = null;
        verify($repository()->monthly(...$unrestricted)['2025-01'] === 3, 'Explicit unrestricted scope still excludes deleted rows');
        verify(Stat::constructEntryValues($type, 'inter_total', '2025-01-01', '2025-03-31')['2025-03'] === 0, 'Empty months are zero filled');
        $_SESSION['glpiactiveentities'] = [];
        verify($measure('inter_total')['2025-01'] === 0, 'Empty visibility scope matches nothing');
        $_SESSION['glpiactiveentities'] = [$entity];
    }
    // Optional relationships retain the old zero selection at the API boundary.
    $anonymous = $create('glpi_tickets', ['entities_id' => $entity, 'date' => '2025-01-01 00:00:00', 'solvedate' => '2025-01-01 12:00:00', 'actiontime' => 1]);
    $create('glpi_tickets_users', ['tickets_id' => $anonymous, 'users_id' => null, 'alternative_email' => 'stats@example.invalid', 'type' => CommonITILActor::REQUESTER]);
    $create('glpi_tickettasks', ['tickets_id' => $anonymous, 'users_id' => null, 'actiontime' => 30]);
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', 'user', 0)['2025-01'] === 1, 'Anonymous requester uses NULL');
    verify(Stat::constructEntryValues('Ticket', 'inter_avgactiontime', '2025-01-01', '2025-01-31', 'technicien_followup', 0)['2025-01'] == 30, 'Anonymous task author uses NULL');
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', 'users_id_recipient', 0)['2025-01'] === 1, 'Unspecified recipient uses NULL');
    $unclassified = $create('glpi_users', ['name' => 'Unclassified statistics author']);
    $create('glpi_tickets_users', ['tickets_id' => $anonymous, 'users_id' => $unclassified, 'type' => CommonITILActor::REQUESTER]);
    $create('glpi_suppliers_tickets', ['tickets_id' => $anonymous, 'suppliers_id' => null, 'alternative_email' => 'supplier-stats@example.invalid', 'type' => CommonITILActor::ASSIGN]);
    $create('glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $anonymous, 'solutiontypes_id' => null]);
    foreach (['usertitles_id', 'usercategories_id', 'suppliers_id_assign', 'solutiontypes_id'] as $dimension) {
        verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', $dimension, 0)['2025-01'] === 1, 'Empty association dimension: ' . $dimension);
    }
    $otherSolution = $create('glpi_solutiontypes', ['name' => 'Different item type solution']);
    $overlappingProblem = $create('glpi_problems', ['id' => $anonymous, 'name' => 'Overlapping solution subject']);
    $create('glpi_itilsolutions', ['itemtype' => 'Problem', 'items_id' => $overlappingProblem, 'solutiontypes_id' => $otherSolution]);
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', 'solutiontypes_id', $otherSolution)['2025-01'] === 0, 'Solution discriminator prevents ID collisions');
    $templateType = $create('glpi_computertypes', ['name' => 'Only templates']);
    $template = $create('glpi_computers', ['entities_id' => $entity, 'is_template' => true, 'computertypes_id' => $templateType]);
    $create('glpi_items_tickets', ['tickets_id' => $anonymous, 'items_id' => $template, 'itemtype' => 'Computer']);
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', 'comp_champ', $templateType, 'ComputerType')['2025-01'] === 0, 'Computer classifications exclude templates');
    $untypedComputer = $create('glpi_computers', ['entities_id' => $entity]);
    $create('glpi_items_operatingsystems', ['itemtype' => 'Computer', 'items_id' => $untypedComputer]);
    $create('glpi_items_tickets', ['tickets_id' => $anonymous, 'items_id' => $untypedComputer, 'itemtype' => 'Computer']);
    foreach (['ComputerType', 'OperatingSystem'] as $classification) {
        verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', 'comp_champ', 0, $classification)['2025-01'] === 1, 'Empty computer classification: ' . $classification);
    }
    $rootComputer = $create('glpi_computers', ['entities_id' => 0]);
    $create('glpi_items_tickets', ['tickets_id' => $anonymous, 'items_id' => $rootComputer, 'itemtype' => 'Computer']);
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01', '2025-01-31', 'comp_champ', 0, 'Entity')['2025-01'] === 1, 'Root is a real computer entity classification');
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01 00:00:00', '2025-01-01 00:00:00')['2025-01'] === 1, 'Exact inclusive timestamp bounds');
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-01-01 00:00:01', '2025-01-01 23:59:59')['2025-01'] === 0, 'Exact timestamp bounds exclude earlier rows');
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '', '', 'user', 0)['2025-01'] === 1, 'Open date bounds');
    $export = Stat::getData('Ticket', 'priority', '2025-01-01', '2025-01-31', 0, [['id' => 1, 'link' => 'Statistics priority']]);
    verify($export['opened']['Statistics priority'] === 3 && $export['solved']['Statistics priority'] === 2 && $export['closed']['Statistics priority'] === 2, 'Statistics export uses the mapped series');
    foreach (['2025-02-30', 'invalid', '2025-01-01 OR 1=1'] as $date) {
        try {
            Stat::constructEntryValues('Ticket', 'inter_total', $date, '2025-01-31');
            throw new RuntimeException('Invalid date accepted');
        } catch (InvalidArgumentException) {
        }
    }
    verify(Stat::constructEntryValues('Ticket', 'inter_total', '2025-02-01', '2025-01-01') === [], 'Reversed dates return no series');
    verify(Stat::constructEntryValues('Ticket', 'unknown', '2025-01-01', '2025-01-31') === [], 'Unknown metric has no series');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped monthly statistics, metrics, dimensions, scoping and fan-out protection passed.\n";
