<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\LocationReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/locations.php /path/to/test-config\n");
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
    $locationId = $fixtures->create('glpi_locations', ['name' => 'Original item location']);
    $replacement = $fixtures->create('glpi_locations', ['name' => 'Replacement item location']);
    $unrelated = $fixtures->create('glpi_locations', ['name' => 'Unrelated item location']);
    $children = [];
    $others = [];
    foreach (OptionalReferences::LOCATIONS as $table => $relations) {
        $values = ['locations_id' => $locationId];
        $otherValues = ['locations_id' => $unrelated];
        if ($table === 'glpi_users') {
            $values['name'] = 'Location user';
            $otherValues['name'] = 'Other location user';
        }
        if (str_starts_with($table, 'glpi_items_device')) {
            $values += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
            $otherValues += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
        }
        $children[$table] = $fixtures->create($table, $values);
        $others[$table] = $fixtures->create($table, $otherValues);
    }
    $location = new Location();
    verify(!$location->getFromDB(null), 'Absent optional parent is not a database identifier');
    verify((new Entity())->getFromDB(0), 'The real root entity remains loadable');
    verify($location->delete(['id' => $locationId, '_replace_by' => $replacement], true), 'Replace all location assignments');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && (int)$item->fields['locations_id'] === $replacement, 'Location replacement retained: ' . $table);
    }
    verify($location->delete(['id' => $replacement], true), 'Purge referenced location');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && $item->fields['locations_id'] === null, 'Location purge preserves item: ' . $table);
        verify(array_keys($item->find(['id' => $id, 'locations_id' => 0])) === [$id], 'Legacy empty location criteria: ' . $table);
        verify($item->update(['id' => $id, 'locations_id' => 0]), 'Legacy empty location update');
        verify($item->getFromDB($id) && $item->fields['locations_id'] === null, 'Empty update stays NULL');
        verify($item->getFromDB($others[$table]) && (int)$item->fields['locations_id'] === $unrelated, 'Other location assignments retained: ' . $table);
    }
    $scopeEntity = (new Entity())->add(['name' => 'Location listing entity', 'entities_id' => 0]);
    $listingLocation = (new Location())->add(['name' => 'Listed location', 'entities_id' => $scopeEntity]);
    $ids = [];
    foreach (array_unique($CFG_GLPI['location_types']) as $type) {
        $item = getItemForItemtype($type);
        if (!$item || !$item->maybeLocated()) {
            continue;
        }
        $values = ['locations_id' => $listingLocation, 'entities_id' => $scopeEntity];
        if ($type === 'User') {
            $values['name'] = 'Listed location user';
        }
        $ids[$type] = $fixtures->create($item::getTable(), $values);
    }
    $computer = new Computer();
    verify($computer->update(['id' => $ids['Computer'], 'name' => 'Visible location computer', 'serial' => 'Location serial']), 'Name listed computer');
    $template = $fixtures->create('glpi_computers', ['name' => 'Location template', 'locations_id' => $listingLocation, 'entities_id' => $scopeEntity, 'is_template' => true]);
    $fixtures->create('glpi_computers', ['name' => 'Deleted location computer', 'locations_id' => $listingLocation, 'entities_id' => $scopeEntity, 'is_deleted' => true]);
    $fixtures->create('glpi_computers', ['name' => 'Foreign location computer', 'locations_id' => $listingLocation, 'entities_id' => 0]);
    $fixtures->create('glpi_computers', ['name' => 'Unassigned location computer', 'entities_id' => $scopeEntity]);
    $em = \itsmng\Database\Orm::create($DB);
    $repository = new \itsmng\Database\Repository\LocationRepository($em);
    $scope = ['entities_id' => $scopeEntity];
    foreach ($ids as $type => $id) {
        $rows = $repository->items($type, $listingLocation, $scope);
        $expected = $type === 'Computer' ? [$id, $template] : [$id];
        verify(array_column(array_column($rows, 'fields'), 'id') === $expected, 'Scoped location listing: ' . $type);
        verify(str_contains($rows[0]['entity_name'], 'Location listing entity'), 'Joined entity label: ' . $type);
    }
    verify($repository->items('Computer', $listingLocation, ['entities_id' => [-1]]) === [], 'Empty location listing scope');
    $fixtures->create('glpi_dropdowntranslations', ['items_id' => $scopeEntity, 'itemtype' => 'Entity', 'field' => 'completename', 'language' => 'fr_FR', 'value' => 'Entité traduite']);
    verify($repository->items('Computer', $listingLocation, $scope, 'fr_FR')[0]['entity_name'] === 'Entité traduite', 'Translated entity label');
    verify(str_contains($repository->items('Computer', $listingLocation, $scope, 'de_DE')[0]['entity_name'], 'Location listing entity'), 'Missing translation uses entity name');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$scopeEntity];
    $_SESSION['glpiactive_entity'] = $scopeEntity;
    $location = new Location();
    verify($location->getFromDB($listingLocation), 'Load listing location');
    ob_start();
    $location->showItems();
    $html = ob_get_clean();
    verify(str_contains($html, 'Visible location computer') && str_contains($html, 'Location serial') && str_contains($html, 'Location template'), 'Location tab renders populated ORM rows');
    verify(!str_contains($html, 'Deleted location computer') && !str_contains($html, 'Foreign location computer') && !str_contains($html, 'Unassigned location computer'), 'Location tab excludes deleted, foreign and unassigned items');
    $_REQUEST['criterion'] = 'Computer';
    ob_start();
    $location->showItems();
    $filtered = ob_get_clean();
    verify(str_contains($filtered, 'Visible location computer') && !str_contains($filtered, 'Listed location user'), 'Location type filter selects only the requested mapped type');
    unset($_REQUEST['criterion'], $_SESSION['glpi_saved']['Location']['criterion']);
    $em->clear();
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->items('Computer', $listingLocation, $scope, 'fr_FR');
    verify($SQL_TOTAL_REQUEST === 0, 'Location repository bypasses legacy execution');
    verify((new ForeignKeys())->audit($connection) === [], 'Location assignment graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new LocationReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::LOCATIONS as $table => $relations) {
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
    $connection->insert('glpi_lines', ['id' => $legacyId, 'name' => 'Legacy location']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Location migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT locations_id FROM glpi_lines WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_lines', ['locations_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned location');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_appliances')['locations_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_lines', ['locations_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT locations_id FROM glpi_lines WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Location migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_lines', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": location assignment replacement/purge, scoped item listings and migration passed.\n";
