<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\StateReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/states.php /path/to/test-config\n");
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
    $stateId = $fixtures->create('glpi_states', ['name' => 'Original item state']);
    $replacement = $fixtures->create('glpi_states', ['name' => 'Replacement item state']);
    $unrelated = $fixtures->create('glpi_states', ['name' => 'Unrelated item state']);
    $children = [];
    $others = [];
    foreach (OptionalReferences::STATES as $table => $relations) {
        $values = ['states_id' => $stateId];
        $otherValues = ['states_id' => $unrelated];
        if (str_starts_with($table, 'glpi_items_device')) {
            $values += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
            $otherValues += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
        }
        $children[$table] = $fixtures->create($table, $values);
        $others[$table] = $fixtures->create($table, $otherValues);
    }
    $state = new State();
    verify($state->delete(['id' => $stateId, '_replace_by' => $replacement], true), 'Replace all state assignments');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && (int)$item->fields['states_id'] === $replacement, 'State replacement retained: ' . $table);
    }
    verify($state->delete(['id' => $replacement], true), 'Purge referenced state');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && $item->fields['states_id'] === null, 'State purge preserves item: ' . $table);
        verify(array_keys($item->find(['id' => $id, 'states_id' => 0])) === [$id], 'Legacy empty state criteria: ' . $table);
        verify($item->update(['id' => $id, 'states_id' => 0]), 'Legacy empty state update');
        verify($item->getFromDB($id) && $item->fields['states_id'] === null, 'Empty update stays NULL');
        verify($item->getFromDB($others[$table]) && (int)$item->fields['states_id'] === $unrelated, 'Other state assignments retained: ' . $table);
    }
    $tree = new State();
    $parentA = $tree->add(['name' => 'State parent A', 'is_visible_computer' => 0]);
    $parentB = (new State())->add(['name' => 'State parent B']);
    $childA = (new State())->add(['name' => "O'Reilly state", 'states_id' => $parentA]);
    $childB = (new State())->add(['name' => 'Initial child', 'states_id' => $parentB]);
    $otherChildB = (new State())->add(['name' => 'Other child', 'states_id' => $parentB]);
    verify($parentA && $parentB && $childA && $childB && $otherChildB, 'Create state hierarchy');
    $child = new State();
    verify($child->getFromDB($childA) && (int)$child->fields['is_visible_computer'] === 0, 'Child inherits native visibility flag');
    verify($child->getFromDB($childB) && $child->update(['id' => $childB, 'name' => addslashes("O'Reilly state")]), 'Same name is allowed under another parent on partial rename');
    verify(!$child->update(['id' => $childB, 'states_id' => $parentA]), 'Moving to a duplicate sibling name is refused');
    verify($child->getFromDB($childB) && (int)$child->fields['states_id'] === (int)$parentB, 'Refused move leaves parent intact');
    verify($child->update(['id' => $childB, 'name' => 'Moved child']), 'Rename before moving');
    verify($child->update(['id' => $childB, 'states_id' => $parentA]), 'Partial parent update compares full sibling key');
    verify($child->getFromDB($childB) && $child->fields['completename'] === 'State parent A > Moved child', 'Moving maintains tree names');
    verify($child->update(['id' => $childB, 'name' => 'Moved child']), 'Unchanged own key is allowed');
    $locationRoot = (new Location())->add(['name' => 'Location rename parent']);
    $locationChild = (new Location())->add(['name' => 'Location before rename', 'locations_id' => $locationRoot]);
    $location = new Location();
    verify($location->getFromDB($locationChild) && $location->update(['id' => $locationChild, 'name' => 'Location after rename']), 'Rename another tree-dropdown type');
    verify($location->getFromDB($locationChild) && (int)$location->fields['locations_id'] === (int)$locationRoot && $location->fields['completename'] === 'Location rename parent > Location after rename', 'Partial tree rename retains its parent outside state trees');
    $newRoot = $location->add(['name' => 'Reused location new root']);
    verify($newRoot && $location->getFromDB($newRoot) && (int)$location->fields['locations_id'] === 0, 'Adding with a reused tree object does not inherit its previous parent');
    $stateEntity = (new Entity())->add(['name' => 'State report scope', 'entities_id' => 0]);
    $reportState = (new State())->add(['name' => 'Visible report state', 'entities_id' => $stateEntity]);
    $hiddenState = (new State())->add(['name' => 'Hidden report state', 'entities_id' => 0]);
    $recursiveState = (new State())->add(['name' => 'Recursive report state', 'entities_id' => 0, 'is_recursive' => 1]);
    foreach (['none' => null, 'visible' => $reportState, 'recursive' => $recursiveState, 'hidden_state' => $hiddenState] as $label => $stateValue) {
        $fixtures->create('glpi_computers', ['name' => 'State report ' . $label, 'entities_id' => $stateEntity, 'states_id' => $stateValue]);
    }
    foreach (['is_deleted', 'is_template'] as $flag) {
        $fixtures->create('glpi_computers', ['entities_id' => $stateEntity, 'states_id' => $reportState, $flag => 1]);
    }
    $fixtures->create('glpi_computers', ['entities_id' => 0, 'states_id' => $reportState]);
    $em = \itsmng\Database\Orm::create($DB);
    $repository = new \itsmng\Database\Repository\StateRepository($em);
    $counts = array_column($repository->counts('Computer', [$stateEntity]), 'cpt', 'states_id');
    verify(count($counts) === 4 && $counts[0] === 1 && $counts[$reportState] === 1, 'Summary counts NULL states and scopes entity/deleted/template flags');
    verify($repository->counts('Computer', []) === [], 'Empty state-summary scope');
    foreach ($CFG_GLPI['state_types'] as $type) {
        $repository->counts($type, [$stateEntity]);
    }
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$stateEntity];
    $_SESSION['glpiactive_entity'] = $stateEntity;
    ob_start();
    State::showSummary();
    $html = ob_get_clean();
    verify(str_contains($html, 'Visible report state') && str_contains($html, 'Recursive report state') && !str_contains($html, 'Hidden report state'), 'State summary honors recursive state visibility');
    verify(str_contains($html, "<td>---</td><td class='numeric'>1</td>"), 'NULL assignments populate the no-state summary row');
    ob_start();
    State::dropdownBehaviour('mapped_state', 'Clear status', $reportState);
    $html = ob_get_clean();
    verify(str_contains($html, 'Keep status') && str_contains($html, 'Clear status') && str_contains($html, 'Visible report state'), 'Behavior selector keeps sentinel actions and mapped states');
    $em->clear();
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->counts('Computer', [$stateEntity]);
    $child->isUnique(['name' => 'New unique name']);
    verify($SQL_TOTAL_REQUEST === 0, 'State count and uniqueness queries bypass legacy execution');
    verify((new ForeignKeys())->audit($connection) === [], 'State assignment graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new StateReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::STATES as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_lines');
    $connection->insert('glpi_lines', ['id' => $legacyId, 'name' => 'Legacy state']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'State migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT states_id FROM glpi_lines WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_lines', ['states_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned state');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_appliances')['states_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_lines', ['states_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT states_id FROM glpi_lines WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'State migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_lines', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": state assignment replacement/purge, scoped summaries, hierarchy uniqueness and migration passed.\n";
