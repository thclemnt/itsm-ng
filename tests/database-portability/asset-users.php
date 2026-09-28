<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\AssetUserReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/asset-users.php /path/to/test-config\n");
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
    $original = $fixtures->create('glpi_users', ['name' => 'Asset original']);
    $replacement = $fixtures->create('glpi_users', ['name' => 'Asset replacement']);
    $unrelated = $fixtures->create('glpi_users', ['name' => 'Asset unrelated']);
    $records = [];
    $others = [];
    foreach (OptionalReferences::ASSET_USERS as $table => $relations) {
        $extra = $table === 'glpi_items_devicesimcards'
            ? ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')]
            : ['name' => 'Assigned ' . $table];
        $records[$table] = $fixtures->create($table, array_fill_keys(array_keys($relations), $original) + $extra);
        $others[$table] = $fixtures->create($table, array_fill_keys(array_keys($relations), $unrelated) + $extra);
    }
    $user = new User();
    verify($user->delete(['id' => $original, '_replace_by' => $replacement], true), 'Replace assigned user');
    foreach (OptionalReferences::ASSET_USERS as $table => $relations) {
        $model = getItemForItemtype(getItemTypeForTable($table));
        verify($model->getFromDB($records[$table]), 'Replacement preserves asset');
        foreach ($relations as $column => $target) {
            verify((int)$model->fields[$column] === $replacement, 'Assignment replacement: ' . $table . '.' . $column);
        }
    }
    $private = $fixtures->create('glpi_savedsearches', ['name' => 'Private user search', 'users_id' => $replacement, 'is_private' => true, 'itemtype' => 'Computer']);
    $public = $fixtures->create('glpi_savedsearches', ['name' => 'Public user search', 'users_id' => $replacement, 'is_private' => false, 'itemtype' => 'Computer']);
    $otherSearch = $fixtures->create('glpi_savedsearches', ['name' => 'Unrelated search', 'users_id' => $unrelated, 'is_private' => false, 'itemtype' => 'Computer']);
    $stock = $fixtures->create('glpi_consumables', ['itemtype' => 'User', 'items_id' => $replacement, 'date_out' => '2026-09-29']);
    $groupStock = $fixtures->create('glpi_consumables', ['itemtype' => 'Group', 'items_id' => $replacement, 'date_out' => '2026-09-29']);
    verify($user->delete(['id' => $replacement], true), 'Purge assigned user');
    foreach (OptionalReferences::ASSET_USERS as $table => $relations) {
        $model = getItemForItemtype(getItemTypeForTable($table));
        verify($model->getFromDB($records[$table]), 'Purge preserves asset');
        foreach ($relations as $column => $target) {
            verify($model->fields[$column] === null, 'Purge clears assignment: ' . $table . '.' . $column);
            verify(count($model->find(['id' => $records[$table], $column => 0])) === 1, 'Legacy empty-assignment criteria');
        }
        verify($model->getFromDB($others[$table]), 'Unrelated asset retained');
        foreach ($relations as $column => $target) {
            verify((int)$model->fields[$column] === $unrelated, 'Unrelated user assignment retained');
        }
    }
    $search = new SavedSearch();
    verify(!$search->getFromDB($private), 'Private saved searches are deleted through lifecycle');
    verify($search->getFromDB($public) && (int)$search->fields['users_id'] === 0, 'Public search remains with no owner');
    verify($search->getFromDB($otherSearch) && (int)$search->fields['users_id'] === $unrelated, 'Other public search retains owner');
    $consumable = new Consumable();
    verify($consumable->getFromDB($stock) && $consumable->fields['itemtype'] === null && $consumable->fields['date_out'] === null && (int)$consumable->fields['items_id'] === 0, 'User consumables return to stock');
    verify($consumable->getFromDB($groupStock) && $consumable->fields['itemtype'] === 'Group' && (int)$consumable->fields['items_id'] === $replacement, 'Equal group recipient ID is not cleared');

    $owner = $fixtures->create('glpi_users', ['name' => 'Asset view owner']);
    $group = $fixtures->create('glpi_groups', ['name' => 'Asset view group']);
    $fixtures->create('glpi_groups_users', ['users_id' => $owner, 'groups_id' => $group]);
    $foreignEntity = (new Entity())->add(['name' => 'Asset foreign entity', 'entities_id' => 0]);
    $make = static fn (array $values) => $fixtures->create('glpi_computers', $values + ['name' => 'Owned visible', 'users_id' => $owner]);
    $visible = $make([]);
    $unnamed = $make(['name' => null]);
    $foreign = $make(['name' => 'Owned foreign', 'entities_id' => $foreignEntity]);
    $deleted = $make(['name' => 'Owned deleted', 'is_deleted' => true]);
    $template = $make(['name' => 'Owned template', 'is_template' => true]);
    $technician = $make(['name' => 'Technical visible', 'users_id' => null, 'users_id_tech' => $owner]);
    $groupItem = $make(['name' => 'Group visible', 'users_id' => null, 'groups_id' => $group]);
    $make(['name' => 'Group foreign', 'users_id' => null, 'groups_id' => $group, 'entities_id' => $foreignEntity]);
    $repo = new \itsmng\Database\Repository\UserItemRepository(\itsmng\Database\Orm::create($DB));
    verify(array_column($repo->groups($owner), 'groups_id') === [$group], 'Mapped group membership');
    $scope = ['entities_id' => 0];
    verify(array_column(iterator_to_array($repo->items('Computer', 'users_id', [$owner], $scope)), 'id') === [$visible, $unnamed], 'Owner listing excludes deleted, template, foreign and technician-only assets');
    verify(array_column(iterator_to_array($repo->items('Computer', 'users_id_tech', [$owner], $scope)), 'id') === [$technician], 'Technician listing');
    verify(array_column(iterator_to_array($repo->items('Computer', 'groups_id', [$group], $scope)), 'id') === [$groupItem], 'Group listing keeps entity scope');
    verify(iterator_to_array($repo->items('Computer', 'users_id', [], $scope)) === [], 'Empty assignment scope returns no assets');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $user = new User();
    verify($user->getFromDB($owner), 'Load inventory owner');
    ob_start();
    $user->showItems(false);
    $html = ob_get_clean();
    verify(str_contains($html, 'Owned visible') && str_contains($html, 'Group visible'), 'User inventory renders direct and group assignments');
    foreach (['Owned foreign', 'Group foreign', 'Owned deleted', 'Owned template', 'Technical visible'] as $hidden) {
        verify(!str_contains($html, $hidden), 'Inventory excludes ' . $hidden);
    }
    ob_start();
    $user->showItems(true);
    $html = ob_get_clean();
    verify(str_contains($html, 'Technical visible') && !str_contains($html, 'Owned visible'), 'Technician tab renders managed assets');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->groups($owner);
    iterator_to_array($repo->items('Computer', 'users_id', [$owner], $scope));
    $repo->releaseUserResources($owner);
    verify($SQL_TOTAL_REQUEST === 0, 'Assignment reads and user-resource cleanup use ORM');
    verify((new ForeignKeys())->audit($connection) === [], 'Asset-user graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new AssetUserReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::ASSET_USERS as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_computers');
    $connection->insert('glpi_computers', ['id' => $legacyId, 'name' => 'Legacy asset user']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'asset user migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_computers WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_computers', ['users_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned asset user');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_computers')['users_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_computers', ['users_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_computers WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'asset user migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_computers', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": asset user assignments, scoped inventory, user purge and migration passed.\n";
