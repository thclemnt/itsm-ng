<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/optional-references.php /path/to/test-config\n");
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
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\NormalizeOptionalReferences;

$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $storage = new \itsmng\Database\MappedStorage($DB);
    foreach (ReferenceHistory::get('optional', 'MODELS') as $table => $relations) {
        $column = array_key_first($relations);
        $target = $relations[$column];
        $parent = $fixtures->create($target, ['name' => 'Optional model']);
        $replacement = $fixtures->create($target, ['name' => 'Replacement model']);
        $empty = $storage->insert($table, [$column => '0']);
        $linked = $storage->insert($table, [$column => $parent]);
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Legacy zero persists as NULL: ' . $table);
        foreach ([0, '0', '', false, ['=', 0], [0], [0, 0]] as $criterion) {
            verify(array_keys($item->find(['id' => [$empty, $linked], $column => $criterion])) === [$empty], 'Empty reference predicate: ' . $table);
        }
        verify(array_keys($item->find(['id' => [$empty, $linked], $column => ['<>', 0]])) === [$linked], 'Nonempty reference predicate');
        verify(count($item->find(['id' => [$empty, $linked], $column => [0, $parent]])) === 2, 'Mixed empty and concrete reference list');
        verify(array_keys($item->find(['id' => [$empty, $linked], 'NOT' => [$column => 0]])) === [$linked], 'Negated empty reference');
        $raw = new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
        verify($raw->matching($table, ['id' => [$empty, $linked], $column => 0], legacyValues: false) === [], 'Raw ORM criteria do not translate zero');
        verify($item->update(['id' => $empty, $column => $parent]), 'Select a model through model lifecycle');
        $model = getItemForItemtype(getItemTypeForTable($target));
        verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Purge with replacement: ' . $target);
        verify($item->getFromDB($empty) && $item->fields[$column] === $replacement, 'Replacement model retained');
        verify($model->delete(['id' => $replacement], true), 'Purge optional model: ' . $target);
        verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Parent purge clears optional reference');
        verify($item->getFromDB($linked) && $item->fields[$column] === null, 'Parent purge clears every referring item');
    }
    $memory = $fixtures->create('glpi_devicememories', ['designation' => 'Recursive component', 'entities_id' => 0, 'is_recursive' => true]);
    $local = $fixtures->create('glpi_computers', ['entities_id' => 0]);
    $fixtures->create('glpi_items_devicememories', ['devicememories_id' => $memory, 'itemtype' => 'Computer', 'items_id' => $local, 'entities_id' => 0]);
    $device = new DeviceMemory();
    verify($device->getFromDB($memory) && $device->canUnrecurs(), 'Component can stop recursion when its assets remain in scope');
    $entity = (new Entity())->add(['name' => 'Component child entity', 'entities_id' => 0]);
    verify((int)$entity > 0, 'Child entity fixture');
    $foreign = $fixtures->create('glpi_computers', ['entities_id' => $entity]);
    $fixtures->create('glpi_items_devicememories', ['devicememories_id' => $memory, 'itemtype' => 'Computer', 'items_id' => $foreign, 'entities_id' => 0]);
    verify(!$device->canUnrecurs(), 'Check every linked asset, even when association entity metadata is stale');

    $model = $fixtures->create('glpi_rackmodels', ['name' => 'Search optional model']);
    $prefix = 'Optional rack ' . bin2hex(random_bytes(4));
    $emptyRack = $storage->insert('glpi_racks', ['name' => $prefix . ' empty', 'rackmodels_id' => 0]);
    $modelRack = $storage->insert('glpi_racks', ['name' => $prefix . ' model', 'rackmodels_id' => $model]);
    $rack = new Rack();
    $option = $rack->getSearchOptionIDByField('table', 'glpi_rackmodels');
    verify((int)$option > 0, 'Rack model search option');
    foreach (['equals' => [$emptyRack], 'notequals' => [$modelRack]] as $operator => $expected) {
        $results = Search::getDatas('Rack', ['reset' => 'reset', 'list_limit' => 50, 'criteria' => [
            ['field' => 1, 'searchtype' => 'contains', 'value' => $prefix, 'link' => 'AND'],
            ['field' => $option, 'searchtype' => $operator, 'value' => '0', 'link' => 'AND'],
        ]]);
        verify(array_map('intval', array_column($results['data']['rows'] ?? [], 'id')) === $expected, 'Search engine empty model: ' . $operator);
    }
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned optional references');
} finally {
    $DB->rollBack();
}

// Reconstruct pre-migration data on the disposable database. DDL must be outside
// the fixture transaction because MySQL ALTER TABLE implicitly commits.
$platform = $connection->getDatabasePlatform();
$removed = [];
try {
    foreach (ReferenceHistory::get('optional', 'MODELS') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $removed[] = [$table, $column];
        }
    }
    $DB->beginTransaction();
    try {
        $fixtures = new FixtureRecords($DB);
        $zeroRows = [];
        foreach (ReferenceHistory::get('optional', 'MODELS') as $table => $relations) {
            $column = array_key_first($relations);
            $zeroRows[$table] = [$column, $fixtures->create($table, [$column => 0])];
        }
        $migration = new NormalizeOptionalReferences();
        verify(array_sum($migration->plan($connection)) === count($zeroRows), 'Plan counts every zero without changing it');
        $table = 'glpi_devicebatteries';
        $column = 'devicebatterymodels_id';
        $orphan = $fixtures->create($table, [$column => 2147483647]);
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Nonzero orphaned references');
        }
        verify($rejected, 'Nonzero orphan blocks normalization');
        verify((int)$connection->fetchOne('SELECT devicebatterymodels_id FROM glpi_devicebatteries WHERE id = ?', [$zeroRows[$table][1]]) === 0, 'Failed migration preserves zeros');
        $connection->delete($table, ['id' => $orphan]);
        $model = $fixtures->create('glpi_devicebatterymodels', ['name' => 'Real zero identifier']);
        $connection->update('glpi_devicebatterymodels', ['id' => 0], ['id' => $model]);
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Zero is a real model identifier');
        }
        verify($rejected, 'Real zero model identifier must not be erased');
        $connection->delete('glpi_devicebatterymodels', ['id' => 0]);
        verify(array_sum($migration->apply($connection)) === count($zeroRows), 'Normalize every audited empty reference');
        foreach ($zeroRows as $table => [$column, $id]) {
            $value = $connection->fetchOne('SELECT ' . $platform->quoteIdentifier($column) . ' FROM ' . $platform->quoteIdentifier($table) . ' WHERE id = ?', [$id]);
            verify($value === null, 'Migration stores NULL: ' . $table);
        }
        verify($migration->apply($connection) === [], 'Migration is idempotent');
        verify((new ForeignKeys())->audit($connection) === [], 'Normalized references pass FK audit');
    } finally {
        $DB->rollBack();
    }
} finally {
    if ($removed) {
        (new ForeignKeys())->apply($connection);
    }
}
echo $DB->getProvider() . ": optional model references, empty-selection criteria, replacement/purge and safe normalization passed.\n";
