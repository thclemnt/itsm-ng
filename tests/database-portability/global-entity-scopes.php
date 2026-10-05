<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\FieldUnicityRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/global-entity-scopes.php /path/to/test-config\n");
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
$connection = $DB->getDoctrineConnection();
$create = (new FixtureRecords($DB))->create(...);
$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_fieldunicities', 'id', $id);
$repository = new FieldUnicityRepository(Orm::create($DB));
$reject = static function (callable $operation, string $exception) use ($connection): void {
    try {
        $connection->transactional($operation);
        throw new LogicException('Operation unexpectedly succeeded');
    } catch (Throwable $error) {
        if (!$error instanceof $exception) {
            throw $error;
        }
    }
};
$DB->beginTransaction();
try {
    $id = 100 + (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_entities');
    // Non-monotonic IDs prove precedence follows the hierarchy, not ID order.
    $grand = $create('glpi_entities', ['id' => $id + 100, 'entities_id' => 0, 'name' => 'Scope grandparent', 'level' => 2]);
    $parent = $create('glpi_entities', ['id' => $id + 10, 'entities_id' => $grand, 'name' => 'Scope parent', 'level' => 3]);
    $child = $create('glpi_entities', ['id' => $id + 20, 'entities_id' => $parent, 'name' => 'Scope child', 'level' => 4]);
    $outside = (int)(new Entity())->add(['name' => 'Scope unrelated entity', 'entities_id' => 0]);
    $rules = [];
    $add = static function (?int $entity, string $name, bool $recursive = false, bool $active = true) use (&$rules): int {
        $model = new FieldUnicity();
        $id = (int)$model->add(['name' => $name, 'itemtype' => 'Computer', '_fields' => ['serial'], 'entities_id' => $entity ?? -1, 'is_recursive' => $recursive, 'is_active' => $active]);
        verify($id > 0, 'Create scoped field-unicity rule');
        $rules[] = $id;
        return $id;
    };
    $global = $add(null, 'Scope global');
    $root = $add(0, 'Scope root');
    $root2 = $add(0, 'Scope second root');
    $grandRule = $add($grand, 'Scope grandparent rule', true);
    $parentRule = $add($parent, 'Scope parent rule', true);
    $parentRule2 = $add($parent, 'Scope second parent rule', true);
    $childRule = $add($child, 'Scope child inactive', false, false);
    verify($read($global)['entities_id'] === null && $read($root)['entities_id'] === 0, 'Global and real root are separate');
    $config = static fn (int $entity, bool $active = true): array => array_column(FieldUnicity::getUnicityFieldsConfig('Computer', $entity, $active), 'id');
    verify($config($child) === [$parentRule, $parentRule2], 'Nearest recursive ancestor wins even with a lower ID');
    verify($config($child, false) === [$childRule], 'Inactive direct scope is included only on request');
    verify($config(0) === [$root, $root2], 'Every rule at root is returned without mixing global scope');
    verify($config($outside) === [$global], 'Unrelated nonrecursive root rules do not suppress global fallback');
    verify((new FieldUnicity())->update(['id' => $childRule, 'is_active' => true, '_fields' => ['serial']]), 'Activate child rule');
    verify($config($child) === [$childRule], 'Direct entity takes precedence');
    verify((new FieldUnicity())->update(['id' => $global, 'entities_id' => '-2', '_fields' => ['serial']]) && $read($global)['entities_id'] === null, 'Legacy negative input normalizes at model boundary');
    $records = new RecordRepository(Orm::create($DB));
    verify(array_column($records->matching('glpi_fieldunicities', ['id' => $rules, 'entities_id' => [-1, 0]], 'id'), 'id') === [$global, $root, $root2], 'Mixed global and root criteria are distinct mapped predicates');
    $reject(fn () => $connection->update('glpi_fieldunicities', ['entities_id' => 2147483647], ['id' => $global]), ForeignKeyConstraintViolationException::class);
    $reject(fn () => $connection->update('glpi_fieldunicities', ['entities_id' => -1], ['id' => $global]), ForeignKeyConstraintViolationException::class);
    $replace = (int)(new Entity())->add(['name' => 'Scope purge replacement', 'entities_id' => 0]);
    $purged = (int)(new Entity())->add(['name' => 'Scope purge source', 'entities_id' => 0]);
    $purgeRule = $add($purged, 'Scope purge rule');
    $saved = $create('glpi_savedsearches', ['entities_id' => $purged, 'itemtype' => 'Computer']);
    $readSaved = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_savedsearches', 'id', $id);
    verify((new Entity())->delete(['id' => $purged, '_replace_by' => $replace], true), 'Purge scope with replacement');
    verify($read($purgeRule)['entities_id'] === $replace && $read($global)['entities_id'] === null, 'Replacement keeps specific scope and global stays global');
    verify($readSaved($saved)['entities_id'] === $replace, 'Saved-search scope follows entity replacement');
    verify((new Entity())->delete(['id' => $replace], true) && $read($purgeRule)['entities_id'] === 0, 'Purge without replacement falls back to root, not global');
    verify($readSaved($saved)['entities_id'] === 0, 'Saved-search purge falls back to root');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$outside];
    $_SESSION['glpiactiveentities_string'] = (string)$outside;
    $_SESSION['glpiparententities'] = [0];
    $criteria = getEntitiesRestrictCriteria('glpi_fieldunicities');
    verify(in_array($global, array_column($records->matching('glpi_fieldunicities', $criteria), 'id'), true), 'Structured entity restrictions include global rules');
    $search = Search::getDatas('FieldUnicity', ['criteria' => [['field' => 2, 'value' => (string)$global, 'searchtype' => 'equals']], 'reset' => 'reset']);
    verify(array_map('intval', array_column($search['data']['rows'] ?? [], 'id')) === [$global], 'Search SQL entity restriction includes the global scope');
    $viewer = (int)Session::getLoginUserID();
    $other = $create('glpi_users', ['name' => 'Global search other owner']);
    $publicSearch = $create('glpi_savedsearches', ['entities_id' => null, 'itemtype' => 'Computer', 'is_private' => false, 'users_id' => $other]);
    $privateSearch = $create('glpi_savedsearches', ['entities_id' => null, 'itemtype' => 'Computer', 'is_private' => true, 'users_id' => $viewer]);
    $otherPrivate = $create('glpi_savedsearches', ['entities_id' => null, 'itemtype' => 'Computer', 'is_private' => true, 'users_id' => $other]);
    $savedRepo = new \itsmng\Database\Repository\SavedSearchRepository(Orm::create($DB));
    $scope = getEntitiesRestrictCriteria('glpi_savedsearches', '', '', true);
    $visible = $savedRepo->visible($viewer, true, $scope);
    verify(isset($visible['public'][$publicSearch], $visible['private'][$privateSearch]) && !isset($visible['public'][$saved]) && !isset($visible['private'][$otherPrivate]), 'Global saved searches retain public permissions, private ownership and root scope separation');
    verify($savedRepo->visible($viewer, false, $scope)['public'] === [], 'Global scope does not confer public permission');
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    $_SESSION['glpiparententities'] = [];
    $emptyScope = getEntitiesRestrictCriteria('glpi_savedsearches', '', '', true);
    $visible = $savedRepo->visible($viewer, true, $emptyScope);
    verify(isset($visible['public'][$publicSearch]) && !isset($visible['public'][$saved]), 'Empty entity selection retains global scopes alone');
    $search = Search::getDatas('FieldUnicity', ['criteria' => [['field' => 2, 'value' => (string)$global, 'searchtype' => 'equals']], 'reset' => 'reset']);
    verify(array_map('intval', array_column($search['data']['rows'] ?? [], 'id')) === [$global], 'Search global scope remains valid with an empty entity selection');
    $_SESSION['glpiactiveentities'] = [$outside];
    $_SESSION['glpiactiveentities_string'] = (string)$outside;
    $_SESSION['glpiparententities'] = [0];
    $searchModel = new SavedSearch();
    verify($searchModel->getFromDB($publicSearch) && $searchModel->checkEntity(true), 'Global saved search is independent of root entity access');
    $searchModel->setEntityRecur([$publicSearch], 0, false);
    verify($readSaved($publicSearch)['entities_id'] === 0 && $searchModel->getFromDB($publicSearch), 'Bulk assignment retains real root');
    verify(!$searchModel->checkEntity(), 'Real root still requires root entity access');
    $searchModel->setEntityRecur([$publicSearch], -1, false);
    verify($readSaved($publicSearch)['entities_id'] === null, 'Bulk negative scope maps to NULL association');
    $reject(fn () => $connection->update('glpi_savedsearches', ['entities_id' => 2147483647], ['id' => $publicSearch]), ForeignKeyConstraintViolationException::class);
    $reject(fn () => $connection->update('glpi_savedsearches', ['entities_id' => -1], ['id' => $publicSearch]), ForeignKeyConstraintViolationException::class);
    foreach ([['same', $outside, false], ['same', $outside, false], ['same', $outside, true], ['same', 0, false], ['', $outside, false], ['', $outside, false]] as [$serial, $scope, $template]) {
        $create('glpi_computers', ['entities_id' => $scope, 'serial' => $serial, 'is_template' => $template]);
    }
    verify($repository->duplicates('glpi_computers', ['serial'], [$outside], true) === [['cpt' => 2, 'serial' => 'same']], 'Duplicate query excludes empty values, templates and other entities');
    verify($repository->duplicates('glpi_computers', ['serial'], [], true) === [], 'Empty duplicate scope matches nothing');
    $create('glpi_computers', ['entities_id' => 0, 'serial' => 'root-zero']);
    $create('glpi_computers', ['entities_id' => 0, 'serial' => 'root-zero']);
    verify(in_array(['cpt' => 2, 'entities_id' => 0, 'serial' => 'root-zero'], $repository->duplicates('glpi_computers', ['entities_id', 'serial'], [0], true), true), 'Root FK zero remains a real grouping value');
    $plugin = $create('glpi_fieldunicities', ['itemtype' => 'PluginScopeFixtureComputer']);
    $DB->listFields('glpi_fieldunicities');
    $DB->listFields('glpi_computers');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $SQL_TOTAL_REQUEST = 0;
    $DEBUG_SQL = [];
    verify($config($outside) === [$global], 'Mapped application configuration path');
    $object = new FieldUnicity();
    verify($object->getFromDB($global), 'Load global rule through model');
    verify($object->getAdditionalFields()[0]['value'] === -1, 'Editing global rules preserves the global scope');
    ob_start();
    FieldUnicity::showDoubles($object);
    $html = ob_get_clean();
    verify(str_contains($html, 'same') && !str_contains($html, 'root-zero'), 'Rendered global duplicate report uses visible entities');
    FieldUnicity::deleteForItemtype('ScopeFixture');
    verify($read($plugin) === null, 'Plugin uninstall deletes matching rules through DQL');
    verify($SQL_TOTAL_REQUEST === 0, 'Rule selection and duplicate reporting execute no adapter SQL: ' . json_encode($DEBUG_SQL['queries'] ?? []));
    verify((new ForeignKeys())->audit($connection) === [], 'Lifecycle leaves no dangling references');
} finally {
    $DB->rollBack();
}

// Reconstruct the old sentinel column in this disposable installation only.
$migration = new NullableReferences(ReferenceHistory::get('global', 'RELATIONS'), 'global configuration entity', -1);
$platform = $connection->getDatabasePlatform();
$manager = $connection->createSchemaManager();
$quote = $platform->quoteIdentifier(...);
$created = [];
try {
    foreach (array_keys(ReferenceHistory::get('global', 'RELATIONS')) as $table) {
        $before = $manager->introspectTable($table);
        foreach ($before->getForeignKeys() as $key) {
            if (in_array('entities_id', $key->getLocalColumns(), true)) {
                $connection->executeStatement($platform->getDropForeignKeySQL($key->getName(), $table));
            }
        }
        $connection->executeStatement('UPDATE ' . $quote($table) . ' SET entities_id = -1 WHERE entities_id IS NULL');
        $before = $connection->createSchemaManager()->introspectTable($table);
        $after = clone $before;
        $after->getColumn('entities_id')->setNotnull(true)->setDefault(-1);
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
        foreach ([-1, -2, 0] as $scope) {
            $id = $create($table, ['name' => 'Scope migration fixture', 'entities_id' => 0, 'itemtype' => 'Computer']);
            $connection->update($table, ['entities_id' => $scope], ['id' => $id]);
            $created[$table][] = $id;
        }
    }
    $connection->update('glpi_savedsearches', ['entities_id' => 2147483647], ['id' => $created['glpi_savedsearches'][0]]);
    $reject(fn () => $migration->apply($connection), RuntimeException::class);
    foreach (array_keys($created) as $table) {
        verify($connection->createSchemaManager()->listTableColumns($table)['entities_id']->getNotnull(), 'Orphan preflight refuses all DDL');
    }
    $connection->update('glpi_savedsearches', ['entities_id' => -1], ['id' => $created['glpi_savedsearches'][0]]);
    $migration->apply($connection);
    foreach ($created as $table => [$global, $negative, $root]) {
        $records = new RecordRepository(Orm::create($DB));
        verify($records->find($table, 'id', $global)['entities_id'] === null && $records->find($table, 'id', $negative)['entities_id'] === null && $records->find($table, 'id', $root)['entities_id'] === 0, 'Upgrade normalizes negative scopes and preserves root');
    }
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []], 'Migration retry is idempotent');
} finally {
    foreach ($created as $table => $ids) {
        foreach ($ids as $id) {
            $connection->delete($table, ['id' => $id]);
        }
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": global configuration FK, scope precedence, duplicate reports, purge and migration passed.\n";
