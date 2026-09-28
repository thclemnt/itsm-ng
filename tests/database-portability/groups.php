<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\GroupReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/groups.php /path/to/test-config\n");
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
    $groupId = $fixtures->create('glpi_groups', ['name' => 'Original item group']);
    $replacement = $fixtures->create('glpi_groups', ['name' => 'Replacement item group']);
    $unrelated = $fixtures->create('glpi_groups', ['name' => 'Unrelated item group']);
    $children = [];
    $others = [];
    foreach (OptionalReferences::GROUPS as $table => $relations) {
        $values = array_fill_keys(array_keys($relations), $groupId);
        $otherValues = array_fill_keys(array_keys($relations), $unrelated);
        if ($table === 'glpi_groups') {
            $values['name'] = 'Dependent child';
            $otherValues['name'] = 'Other dependent child';
        }
        if ($table === 'glpi_users') {
            $values['name'] = 'Group user';
            $otherValues['name'] = 'Other group user';
        }
        if (str_starts_with($table, 'glpi_items_device')) {
            $values += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
            $otherValues += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
        }
        $children[$table] = $fixtures->create($table, $values);
        $others[$table] = $fixtures->create($table, $otherValues);
    }
    $fixtures->create('glpi_groups_users', ['groups_id' => $replacement, 'users_id' => $children['glpi_users']]);
    $nonmember = $fixtures->create('glpi_users', ['name' => 'Default group without replacement membership', 'groups_id' => $groupId]);
    $group = new Group();
    verify(!$group->getFromDB(null), 'Absent optional parent is not a database identifier');
    verify((new Entity())->getFromDB(0), 'The real root entity remains loadable');
    verify($group->delete(['id' => $groupId, '_replace_by' => $replacement], true), 'Replace all group assignments');
    $defaultUser = new User();
    verify($defaultUser->getFromDB($nonmember) && $defaultUser->fields['groups_id'] === null, 'Replacement clears a default when the user does not belong to the replacement group');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id), 'Reload child');
        foreach (OptionalReferences::GROUPS[$table] as $column => $target) {
            verify((int)$item->fields[$column] === $replacement, 'Group replacement retained: ' . $table . '.' . $column);
        }
    }
    verify($group->delete(['id' => $replacement], true), 'Purge referenced group');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        foreach (OptionalReferences::GROUPS[$table] as $column => $target) {
            verify($item->getFromDB($id) && $item->fields[$column] === null, 'Group purge preserves child: ' . $table . '.' . $column);
            verify(array_keys($item->find(['id' => $id, $column => 0])) === [$id], 'Legacy empty criteria');
            verify($item->update(['id' => $id, $column => 0]), 'Legacy empty update');
            verify($item->getFromDB($id) && $item->fields[$column] === null, 'Empty update stays NULL');
            verify($item->getFromDB($others[$table]) && (int)$item->fields[$column] === $unrelated, 'Unrelated group retained');
        }
    }
    $child = new Group();
    verify($child->getFromDB($children['glpi_groups']) && $child->fields['completename'] === 'Dependent child' && (int)$child->fields['level'] === 1, 'Purging a root reparents its child and recomputes tree fields');
    $root = (new Group())->add(['name' => 'Listed group']);
    $subgroup = (new Group())->add(['name' => 'Listed subgroup', 'groups_id' => $root]);
    $grandchild = (new Group())->add(['name' => 'Listed grandchild', 'groups_id' => $subgroup]);
    $tree = new Group();
    verify($tree->getFromDB($subgroup) && $tree->update(['id' => $subgroup, 'groups_id' => null]), 'Explicit NULL moves a group to the root');
    verify($tree->getFromDB($subgroup) && $tree->fields['groups_id'] === null && $tree->fields['completename'] === 'Listed subgroup', 'Root group stores NULL and its own name');
    verify($child->getFromDB($grandchild) && $child->fields['completename'] === 'Listed subgroup > Listed grandchild', 'Moving a group updates descendant names');
    verify($tree->update(['id' => $subgroup, 'groups_id' => $root]), 'Restore group hierarchy');
    $member = $fixtures->create('glpi_users', ['name' => 'Listed group member']);
    foreach ([$root, $subgroup] as $id) {
        $fixtures->create('glpi_groups_users', ['groups_id' => $id, 'users_id' => $member]);
    }
    $direct = $fixtures->create('glpi_computers', ['name' => 'B direct', 'groups_id' => $root]);
    $unnamed = $fixtures->create('glpi_computers', ['name' => null, 'groups_id' => $root]);
    $memberAsset = $fixtures->create('glpi_computers', ['name' => 'A member', 'users_id' => $member]);
    $childAsset = $fixtures->create('glpi_computers', ['name' => 'C descendant', 'groups_id' => $subgroup]);
    $fixtures->create('glpi_computers', ['name' => 'Another assigned group', 'groups_id' => $unrelated, 'users_id' => $member]);
    foreach (['is_template', 'is_deleted'] as $flag) {
        $fixtures->create('glpi_computers', ['name' => 'Hidden ' . $flag, 'groups_id' => $root, $flag => true]);
    }
    $foreignEntity = (new Entity())->add(['name' => 'Group foreign entity', 'entities_id' => 0]);
    $fixtures->create('glpi_computers', ['name' => 'Foreign group asset', 'groups_id' => $root, 'entities_id' => $foreignEntity]);
    $monitor = $fixtures->create('glpi_monitors', ['name' => 'Listed monitor', 'groups_id' => $root]);
    $model = $fixtures->create('glpi_consumableitems', ['name' => 'Group consumables']);
    $stock = [];
    for ($i = 0; $i < 2; ++$i) {
        $stock[] = $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'items_id' => $root, 'itemtype' => 'Group', 'date_out' => '2026-09-01']);
    }
    $otherStock = $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'items_id' => $root, 'itemtype' => 'User', 'date_out' => '2026-09-01']);
    $foreignModel = $fixtures->create('glpi_consumableitems', ['name' => 'Hidden stock model', 'entities_id' => $foreignEntity]);
    $fixtures->create('glpi_consumables', ['consumableitems_id' => $foreignModel, 'items_id' => $root, 'itemtype' => 'Group']);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpilist_limit'] = 2;
    $group->getFromDB($root);
    $rows = [];
    verify($group->getDataItems(['Computer', 'Monitor', 'Consumable'], 'groups_id', false, true, 2, $rows) === 6, 'Counts honor scope, flags and member fallback without replacing another group');
    verify($rows === [['itemtype' => 'Computer', 'items_id' => $direct], ['itemtype' => 'Monitor', 'items_id' => $monitor]], 'Bounded page crosses type boundary with portable NULL/name/ID order');
    verify($group->getDataItems(['Consumable'], 'groups_id', false, false, 0, $rows) === 2 && array_column($rows, 'items_id') === $stock, 'Consumable pages return stock IDs, not model IDs');
    verify($group->getDataItems(['Computer'], 'groups_id', false, false, 99, $rows) === 2 && array_column($rows, 'items_id') === [$unnamed, $direct], 'Out-of-range offset resets to first page');
    $_SESSION['glpilist_limit'] = 10;
    verify($group->getDataItems(['Computer'], 'groups_id', true, true, 0, $rows) === 4 && array_column($rows, 'items_id') === [$unnamed, $memberAsset, $direct, $childAsset], 'Child groups and overlapping memberships do not duplicate assets');
    $em = \itsmng\Database\Orm::create($DB);
    $repository = new \itsmng\Database\Repository\GroupItemRepository($em);
    foreach (array_unique(array_merge($CFG_GLPI['linkgroup_types'], $CFG_GLPI['linkgroup_tech_types'])) as $type) {
        $item = getItemForItemtype($type);
        foreach (['groups_id', 'groups_id_tech'] as $column) {
            if ($type === 'Consumable' || $item->isField($column)) {
                $repository->ids($type, $column, [$root], false, [], 1, 0);
            }
        }
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->ids('Computer', 'groups_id', [$root], true, [], 1, 0);
    verify($SQL_TOTAL_REQUEST === 0, 'Group item repository bypasses legacy execution');
    $em->clear();
    verify($group->delete(['id' => $root, '_replace_by' => $unrelated], true), 'Group replacement includes consumable recipients');
    $consumable = new Consumable();
    verify($consumable->getFromDB($stock[0]) && (int)$consumable->fields['items_id'] === $unrelated && $consumable->fields['date_out'] === '2026-09-01', 'Replacing group preserves usage history');
    verify($group->delete(['id' => $unrelated], true), 'Purge group with stock recipients');
    verify($consumable->getFromDB($stock[0]) && (int)$consumable->fields['items_id'] === 0 && $consumable->fields['itemtype'] === null && $consumable->fields['date_out'] === null, 'Purging group returns its stock');
    verify($consumable->getFromDB($otherStock) && $consumable->fields['itemtype'] === 'User' && $consumable->fields['date_out'] === '2026-09-01', 'Other recipient type with the same ID is preserved');
    verify((new ForeignKeys())->audit($connection) === [], 'Group assignment graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new GroupReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::GROUPS as $table => $relations) {
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
    $connection->insert('glpi_lines', ['id' => $legacyId, 'name' => 'Legacy group']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Group migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT groups_id FROM glpi_lines WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_lines', ['groups_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned group');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_appliances')['groups_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_lines', ['groups_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT groups_id FROM glpi_lines WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Group migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_lines', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": group assignment replacement/purge, scoped item listings and migration passed.\n";
