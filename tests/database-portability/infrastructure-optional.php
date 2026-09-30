<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/infrastructure-optional.php /path/to/test-config\n");
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
use itsmng\Database\ForeignKeys;

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
use itsmng\Database\OptionalReferences;
use itsmng\Database\Migration\InfrastructureReferences;

$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $storage = new \itsmng\Database\MappedStorage($DB);
    $tested = 0;
    foreach (OptionalReferences::INFRASTRUCTURE as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Optional infrastructure parent']);
            $replacement = $fixtures->create($target, ['name' => 'Replacement infrastructure parent']);
            $values = in_array($table, ['glpi_domains_items'], true) ? ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')] : [];
            $empty = $fixtures->create($table, $values);
            $linked = $fixtures->create($table, [$column => $parent] + $values);
            $storage->update($table, $empty, [$column => '0']);
            $item = getItemForItemtype(getItemTypeForTable($table));
            verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Empty optional reference is stored as NULL: ' . $table . '.' . $column);
            foreach ([0, '0', '', false, ['=', 0], [0]] as $criterion) {
                verify(array_keys($item->find(['id' => [$empty, $linked], $column => $criterion])) === [$empty], 'Legacy empty predicate: ' . $table . '.' . $column);
            }
            verify($item->update(['id' => $empty, $column => $parent]), 'Select optional parent through model hooks');
            $object = getItemForItemtype(getItemTypeForTable($target));
            verify($object->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Purge optional parent with replacement: ' . $target);
            verify($item->getFromDB($empty) && (int)$item->fields[$column] === $replacement, 'Replacement reference preserved');
            verify($object->delete(['id' => $replacement], true), 'Purge optional parent: ' . $target);
            verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Purge clears optional parent');
            verify($item->getFromDB($linked) && $item->fields[$column] === null, 'Purge clears every dependent row without deleting it');
            ++$tested;
        }
    }
    verify($tested === 11, 'All optional infrastructure references tested');
    $relation = new DomainRelation();
    foreach ([DomainRelation::BELONGS, DomainRelation::MANAGE] as $id) {
        verify(!$relation->delete(['id' => $id], true), 'Built-in domain relation remains protected');
    }
    $room = $fixtures->create('glpi_dcrooms', ['name' => 'Search optional room', 'vis_rows' => 3, 'vis_cols' => 3]);
    $prefix = 'Optional room rack ' . bin2hex(random_bytes(4));
    $emptyRack = $storage->insert('glpi_racks', ['name' => $prefix . ' empty', 'dcrooms_id' => 0]);
    $roomRack = $storage->insert('glpi_racks', ['name' => $prefix . ' room', 'dcrooms_id' => $room]);
    $rack = new Rack();
    $option = $rack->getSearchOptionIDByField('table', 'glpi_dcrooms');
    verify((int)$option > 0, 'Room search option');
    foreach (['equals' => [$emptyRack], 'notequals' => [$roomRack]] as $operator => $expected) {
        $results = Search::getDatas('Rack', ['reset' => 'reset', 'list_limit' => 50, 'criteria' => [
            ['field' => 1, 'searchtype' => 'contains', 'value' => $prefix, 'link' => 'AND'],
            ['field' => $option, 'searchtype' => $operator, 'value' => '0', 'link' => 'AND'],
        ]]);
        verify(array_map('intval', array_column($results['data']['rows'] ?? [], 'id')) === $expected, 'Search engine empty room: ' . $operator);
    }
    $datacenterId = $fixtures->create('glpi_datacenters', ['name' => 'Mapped datacenter']);
    $listedRoom = $fixtures->create('glpi_dcrooms', ['name' => 'Mapped datacenter room', 'datacenters_id' => $datacenterId, 'vis_rows' => 3, 'vis_cols' => 3]);
    $fixtures->create('glpi_racks', ['dcrooms_id' => $listedRoom, 'position' => '2,3']);
    $roomObject = new DCRoom();
    verify($roomObject->getFromDB($listedRoom) && $roomObject->getFilled() === ['2,3' => '2,3'], 'Mapped room occupancy');
    verify($roomObject->getFilled('2,3') === [], 'Room occupancy excludes the current position');
    $datacenter = new Datacenter();
    verify($datacenter->getFromDB($datacenterId), 'Load datacenter');
    ob_start();
    DCRoom::showForDatacenter($datacenter);
    $html = ob_get_clean();
    verify(str_contains($html, 'Mapped datacenter room') && !str_contains($html, 'Search optional room'), 'Mapped datacenter room listing scopes its parent');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned infrastructure references');
} finally {
    $DB->rollBack();
}

// Rebuild the old NOT NULL/default-zero schema only in the disposable test DB.
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$legacyId = $zeroParent = null;
$migration = new InfrastructureReferences();
try {
    foreach (OptionalReferences::INFRASTRUCTURE as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_appliances');
    $connection->insert('glpi_appliances', ['id' => $legacyId, 'name' => 'Legacy infrastructure', 'appliancetypes_id' => 0, 'applianceenvironments_id' => 0]);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) >= 2, 'Read-only plan reports schema and zero conversions');
    verify((int)$connection->fetchOne('SELECT appliancetypes_id FROM glpi_appliances WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_appliances', ['applianceenvironments_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned infrastructure');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_appliances')['appliancetypes_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_appliances', ['applianceenvironments_id' => 0], ['id' => $legacyId]);
    $zeroParent = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_applianceenvironments');
    $connection->insert('glpi_applianceenvironments', ['id' => $zeroParent, 'name' => 'Real zero environment']);
    $connection->update('glpi_applianceenvironments', ['id' => 0], ['id' => $zeroParent]);
    try {
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Empty selection is a real infrastructure identifier');
        }
        verify($rejected, 'Real zero parent requires explicit repair');
    } finally {
        $connection->update('glpi_applianceenvironments', ['id' => $zeroParent], ['id' => 0]);
    }
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT appliancetypes_id FROM glpi_appliances WHERE id = ?', [$legacyId]) === null, 'Legacy empty selection becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_appliances', ['id' => $legacyId]);
    }
    if ($zeroParent !== null) {
        $connection->delete('glpi_applianceenvironments', ['id' => $zeroParent]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": eleven optional infrastructure associations, replacement/purge, search and audited schema migration passed.\n";
