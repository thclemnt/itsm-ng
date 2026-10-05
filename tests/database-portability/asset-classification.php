<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;

use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/asset-classification.php /path/to/test-config\n");
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
    $storage = new MappedStorage($DB);
    $tested = 0;
    foreach (ReferenceHistory::get('optional', 'ASSET_CLASSIFICATION') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Asset classification']);
            $replacement = $fixtures->create($target, ['name' => 'Replacement classification']);
            $empty = $fixtures->create($table);
            $linked = $fixtures->create($table, [$column => $parent]);
            $item = getItemForItemtype(getItemTypeForTable($table));
            foreach ([0, '0', '', false] as $value) {
                $storage->update($table, $empty, [$column => $value]);
                verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Legacy empty classification becomes NULL: ' . $table . '.' . $column);
                verify(array_keys($item->find(['id' => [$empty, $linked], $column => $value])) === [$empty], 'Empty classification criteria');
            }
            verify($item->update(['id' => $empty, $column => $parent]), 'Model selects classification');
            $parentModel = getItemForItemtype(getItemTypeForTable($target));
            verify($parentModel->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace classification: ' . $target);
            verify($item->getFromDB($empty) && (int)$item->fields[$column] === $replacement, 'Replacement applied');
            verify($parentModel->delete(['id' => $replacement], true), 'Purge classification: ' . $target);
            foreach ([$empty, $linked] as $id) {
                verify($item->getFromDB($id) && $item->fields[$column] === null, 'Purge preserves asset and clears reference');
            }
            ++$tested;
        }
    }
    verify($tested === 12, 'All asset models/types covered');
    $nullCost = $fixtures->create('glpi_monitors', ['ticket_tco' => null]);
    $monitor = new Monitor();
    verify($monitor->getFromDB($nullCost) && $monitor->fields['ticket_tco'] === null, 'Explicit NULL on insert is not replaced by the mapped cost default');

    $computerId = $fixtures->create('glpi_computers', ['name' => 'Linked computer']);
    $otherComputerId = $fixtures->create('glpi_computers', ['name' => 'Unrelated computer']);
    $computer = new Computer();
    verify($computer->getFromDB($computerId), 'Load computer');
    $expected = [];
    $models = [];
    foreach (['Monitor', 'Printer', 'Phone', 'Peripheral'] as $type) {
        $item = new $type();
        $id = $fixtures->create($item::getTable(), ['name' => 'Connected ' . $type, 'is_global' => true]);
        verify($item->getFromDB($id) && $item->getLinkedItems() === [], 'Unconnected asset has no links');
        $fixtures->create('glpi_computers_items', ['computers_id' => $computerId, 'itemtype' => $type, 'items_id' => $id]);
        $fixtures->create('glpi_computers_items', ['computers_id' => $computerId, 'itemtype' => $type, 'items_id' => $id, 'is_deleted' => true, 'is_dynamic' => true]);
        $fixtures->create('glpi_computers_items', ['computers_id' => $otherComputerId, 'itemtype' => $type, 'items_id' => $id]);
        verify($item->getLinkedItems() === ['Computer' => [$computerId => $computerId, $otherComputerId => $otherComputerId]], 'Linked computers deduplicate and retain deleted links');
        $expected[$type] = [$id => $id];
        $models[] = $item;
    }
    // A polymorphic plugin discriminator with an overlapping ID must remain distinct.
    $fixtures->create('glpi_computers_items', ['computers_id' => $computerId, 'itemtype' => 'PluginFixtureAsset', 'items_id' => $models[0]->getID()]);
    $expected['PluginFixtureAsset'] = [(int)$models[0]->getID() => (int)$models[0]->getID()];
    verify($computer->getLinkedItems() === $expected, 'Computer links preserve target types and deduplicate IDs');
    $derivedComputer = new class () extends Computer {};
    $derivedComputer->fields = $computer->fields;
    verify($derivedComputer->getLinkedItems() === $expected, 'Inherited computer lookup keeps the computer side of the relationship');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $computer->getLinkedItems();
    foreach ($models as $model) {
        $model->getLinkedItems();
    }
    verify($SQL_TOTAL_REQUEST === 0, 'Linked item queries bypass legacy SQL execution');
    // Unknown plugin types have no lifecycle class; remove the isolated fixture before hook-driven purge.
    foreach (\itsmng\Database\MappedReads::identifiers($DB, 'glpi_computers_items', 'id', ['computers_id' => $computerId, 'itemtype' => 'PluginFixtureAsset']) as $link) {
        $storage->delete('glpi_computers_items', $link);
    }
    verify($computer->delete(['id' => $computerId], true), 'Computer purge cleans required associations');
    foreach ($models as $model) {
        verify($model->getFromDB($model->getID()), 'Computer purge preserves attached assets');
        verify($model->getLinkedItems() === ['Computer' => [$otherComputerId => $otherComputerId]], 'Computer purge preserves unrelated links');
    }
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned classification or computer references');
} finally {
    $DB->rollBack();
}

// Exercise a real old-schema upgrade, including refusal before any DDL.
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NullableReferences(ReferenceHistory::get('optional', 'ASSET_CLASSIFICATION'), 'asset classification');
$legacyId = null;
try {
    foreach (ReferenceHistory::get('optional', 'ASSET_CLASSIFICATION') as $table => $relations) {
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
    $connection->insert('glpi_computers', ['id' => $legacyId, 'name' => 'Legacy classification']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) >= 2, 'Read-only plan reports schema and normalization');
    verify((int)$connection->fetchOne('SELECT computermodels_id FROM glpi_computers WHERE id = ?', [$legacyId]) === 0, 'Plan leaves data unchanged');
    $connection->update('glpi_computers', ['computertypes_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned asset classification');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_computers')['computermodels_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_computers', ['computertypes_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT computermodels_id FROM glpi_computers WHERE id = ?', [$legacyId]) === null, 'Legacy classification becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_computers', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": twelve asset classifications, computer connections, purge and audited migration passed.\n";
