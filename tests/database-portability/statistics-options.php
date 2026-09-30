<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILStatisticsOptionsRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/statistics-options.php /path/to/test-config\n");
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
$create = (new FixtureRecords($DB))->create(...);
$repository = new ITILStatisticsOptionsRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $entity = (int)(new Entity())->add(['name' => 'Statistics options entity', 'entities_id' => 0]);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $title = $create('glpi_usertitles', ['name' => 'Requester title']);
    $otherTitle = $create('glpi_usertitles', ['name' => 'Technician title']);
    $category = $create('glpi_usercategories', ['name' => 'Requester category']);
    $requester = $create('glpi_users', ['name' => 'options-requester', 'realname' => 'Options A', 'firstname' => 'Requester', 'usertitles_id' => $title, 'usercategories_id' => $category]);
    $technician = $create('glpi_users', ['name' => 'options-technician', 'realname' => 'Options B', 'firstname' => 'Technician', 'usertitles_id' => $otherTitle]);
    $observer = $create('glpi_users', ['name' => 'options-observer']);
    $denied = $create('glpi_users', ['name' => 'options-denied']);
    $requestGroup = $create('glpi_groups', ['entities_id' => $entity, 'name' => 'Requester group', 'completename' => 'Requester group']);
    $assignGroup = $create('glpi_groups', ['entities_id' => $entity, 'name' => 'Assigned group', 'completename' => 'Assigned group']);
    $supplier = $create('glpi_suppliers', ['entities_id' => $entity, 'name' => 'Options supplier']);
    $requestType = $create('glpi_requesttypes', ['name' => 'Options request type']);
    $profile = $create('glpi_profiles', ['name' => 'Options eligible profile']);
    $create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'ticket', 'rights' => Ticket::OWN]);
    // Eligibility is deliberately independent of the selected parent entity.
    $create('glpi_profiles_users', ['users_id' => $technician, 'profiles_id' => $profile, 'entities_id' => 0]);
    $create('glpi_profiles_users', ['users_id' => $technician, 'profiles_id' => $profile, 'entities_id' => $entity]);
    $noOwn = $create('glpi_profiles', ['name' => 'Options ineligible profile']);
    $create('glpi_profilerights', ['profiles_id' => $noOwn, 'name' => 'ticket', 'rights' => READ]);
    $create('glpi_profiles_users', ['users_id' => $denied, 'profiles_id' => $noOwn, 'entities_id' => $entity]);
    $create('glpi_dropdowntranslations', ['itemtype' => 'UserTitle', 'items_id' => $title, 'field' => 'name', 'language' => 'fr_FR', 'value' => 'Titre traduit']);
    $create('glpi_dropdowntranslations', ['itemtype' => 'UserTitle', 'items_id' => $otherTitle, 'field' => 'name', 'language' => 'fr_FR', 'value' => '']);
    $_SESSION['glpi_dropdowntranslations']['UserTitle']['name'] = true;
    $_SESSION['glpilanguage'] = 'fr_FR';
    $definitions = [
        'Ticket' => ['tickets', 'tickets_users', 'groups_tickets', 'suppliers_tickets', 'tickettasks'],
        'Problem' => ['problems', 'problems_users', 'groups_problems', 'problems_suppliers', 'problemtasks'],
        'Change' => ['changes', 'changes_users', 'changes_groups', 'changes_suppliers', 'changetasks'],
    ];
    $sameId = 100 + (int)$DB->getDoctrineConnection()->fetchOne('SELECT GREATEST(COALESCE((SELECT MAX(id) FROM glpi_tickets), 0), COALESCE((SELECT MAX(id) FROM glpi_problems), 0), COALESCE((SELECT MAX(id) FROM glpi_changes), 0))');
    $solutions = [];
    foreach ($definitions as $type => [$parent, $users, $groups, $suppliers, $tasks]) {
        $base = ['entities_id' => $entity, 'urgency' => 2, 'impact' => 3];
        if ($type === 'Ticket') {
            $base['requesttypes_id'] = $requestType;
        }
        $first = $create('glpi_' . $parent, ['id' => $sameId, 'date' => '2025-01-01 00:00:00', 'users_id_recipient' => $requester, 'priority' => 1] + $base);
        $closed = $create('glpi_' . $parent, ['date' => '2024-12-01 00:00:00', 'closedate' => '2025-01-15 12:00:00', 'priority' => 2] + $base);
        $create('glpi_' . $parent, ['date' => '2025-01-31 23:59:59', 'priority' => 3] + $base);
        $create('glpi_' . $parent, ['date' => '2025-02-01 00:00:00', 'priority' => 8] + $base);
        $create('glpi_' . $parent, ['date' => '2024-12-01 00:00:00', 'closedate' => '2025-02-01 00:00:00', 'priority' => 7] + $base);
        $create('glpi_' . $parent, ['date' => '2025-01-10 00:00:00', 'priority' => 9, 'is_deleted' => true] + $base);
        $create('glpi_' . $parent, ['date' => '2025-01-10 00:00:00', 'priority' => 6, 'entities_id' => 0] + $base);
        $create('glpi_' . $parent, ['date' => null, 'closedate' => null, 'priority' => 5] + $base);
        foreach ([$first, $closed] as $id) {
            $create('glpi_' . $users, [$parent . '_id' => $id, 'users_id' => $requester, 'type' => CommonITILActor::REQUESTER]);
        }
        $create('glpi_' . $users, [$parent . '_id' => $first, 'users_id' => $technician, 'type' => CommonITILActor::ASSIGN]);
        $create('glpi_' . $users, [$parent . '_id' => $first, 'users_id' => $observer, 'type' => CommonITILActor::OBSERVER]);
        $create('glpi_' . $users, [$parent . '_id' => $first, 'users_id' => null, 'type' => CommonITILActor::REQUESTER, 'alternative_email' => 'options@example.invalid']);
        $create('glpi_' . $groups, [$parent . '_id' => $first, 'groups_id' => $requestGroup, 'type' => CommonITILActor::REQUESTER]);
        $create('glpi_' . $groups, [$parent . '_id' => $first, 'groups_id' => $assignGroup, 'type' => CommonITILActor::ASSIGN]);
        $create('glpi_' . $suppliers, [$parent . '_id' => $first, 'suppliers_id' => $supplier, 'type' => CommonITILActor::ASSIGN]);
        foreach ([$technician, $technician, $denied, null] as $author) {
            $create('glpi_' . $tasks, [$parent . '_id' => $first, 'users_id' => $author, 'date' => '2020-01-01 00:00:00']);
        }
        $solutions[$type] = $create('glpi_solutiontypes', ['name' => $type . ' solution']);
        $create('glpi_itilsolutions', ['itemtype' => $type, 'items_id' => $first, 'solutiontypes_id' => $solutions[$type]]);
        $create('glpi_itilsolutions', ['itemtype' => $type, 'items_id' => $closed, 'solutiontypes_id' => null]);
    }
    foreach ($definitions as $type => $definition) {
        $item = new $type();
        $ids = static fn (string $method, string $begin = '2025-01-01', string $end = '2025-01-31'): array => array_column($item->$method($begin, $end), 'id');
        verify($ids('getUsedAuthorBetween') === [$requester], $type . ' requester excludes technicians, observers and email-only actors; fan-out is distinct');
        verify($ids('getUsedRecipientBetween') === [null, $requester], $type . ' nullable recipient');
        verify($ids('getUsedTechBetween') === [null, $technician], $type . ' assigned technician and unassigned option');
        verify($ids('getUsedTechTaskBetween') === [$technician], $type . ' task author has any eligible profile, without membership fan-out or task-date filtering');
        verify($ids('getUsedGroupBetween') === [$requestGroup], $type . ' requester groups');
        verify($ids('getUsedAssignGroupBetween') === [null, $assignGroup], $type . ' assigned groups');
        verify($ids('getUsedSupplierBetween') === [null, $supplier], $type . ' assigned suppliers');
        verify($ids('getUsedPriorityBetween') === [1, 2, 3], $type . ' opening OR closure within bounds, not overlap, deleted or foreign-entity records');
        verify($ids('getUsedUrgencyBetween') === [2] && $ids('getUsedImpactBetween') === [3], $type . ' severity fields');
        verify($ids('getUsedPriorityBetween', '2025-01-31 23:59:58', '2025-01-31 23:59:59') === [3], $type . ' exact timestamp end');
        verify($ids('getUsedPriorityBetween', '2025-02-01', '2025-01-31') === [], $type . ' reversed dates');
        verify(in_array(5, $ids('getUsedPriorityBetween', '', ''), true), $type . ' unbounded dates retain undated parents');
        $titles = $item->getUsedUserTitleOrTypeBetween('2025-01-01', '2025-01-31');
        verify($titles === [['id' => null, 'link' => '&nbsp;'], ['id' => $title, 'link' => 'Titre traduit'], ['id' => $otherTitle, 'link' => 'Technician title']], $type . ' all-role titles retain null and translated/fallback labels');
        verify(array_column($item->getUsedUserTitleOrTypeBetween('2025-01-01', '2025-01-31', false), 'id') === [null, $category], $type . ' all-role categories');
        verify($ids('getUsedSolutionTypeBetween') === [null, $solutions[$type]], $type . ' solution item type prevents same-ID collisions');
        if ($type === 'Ticket') {
            verify($item->getUsedRequestTypeBetween('2025-01-01', '2025-01-31') === [['id' => $requestType, 'link' => 'Options request type']], 'Ticket request types');
        }
        verify($repository->options($type, 'priority', '2025-01-01', '2025-01-31', []) === [], $type . ' empty entity scope');
        verify(array_column($repository->options($type, 'priority', '2025-01-01', '2025-01-31', [0]), 'id') === [6], $type . ' root entity is real');
        verify(array_column($repository->options($type, 'priority', '2025-01-01', '2025-01-31', null), 'id') === [1, 2, 3, 6], $type . ' explicit unrestricted scope');
        try {
            $repository->options($type, 'priority', '2025-02-30', '', [$entity]);
            throw new RuntimeException('Invalid date accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    foreach (array_keys($definitions) as $type) {
        foreach (['user', 'technicien', 'technicien_followup', 'users_id_recipient', 'group', 'groups_id_assign', 'suppliers_id_assign', 'usertitles_id', 'usercategories_id', 'priority', 'urgency', 'impact', 'solutiontypes_id'] as $dimension) {
            Stat::getItems($type, '2025-01-01', '2025-01-31', $dimension);
        }
    }
    Stat::getItems('Ticket', '2025-01-01', '2025-01-31', 'requesttypes_id');
    verify($SQL_TOTAL_REQUEST === 0, 'Application selectors execute no legacy SQL: ' . json_encode($DEBUG_SQL['queries'] ?? []));
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": ITIL statistics options, roles, scopes, dates and labels passed.\n";
