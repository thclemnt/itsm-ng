<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\LegacyComponentModels;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/legacy-component-models.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$DB->beginTransaction();
try {
    $networkModel = $fixtures->create('glpi_devicenetworkcardmodels', ['name' => 'Legacy network model']);
    $replacement = $fixtures->create('glpi_devicenetworkcardmodels', ['name' => 'Replacement network model']);
    $other = $fixtures->create('glpi_devicenetworkcardmodels', ['name' => 'Unrelated network model']);
    $pciModel = $fixtures->create('glpi_devicepcimodels', ['name' => 'Actual PCI model']);
    $pci = new DevicePci();
    $id = $pci->add(['designation' => 'Dual model PCI device', 'devicepcimodels_id' => $pciModel, 'devicenetworkcardmodels_id' => $networkModel]);
    verify($id > 0 && $pci->getFromDB($id), 'PCI lifecycle persists both independent model references');
    verify($pci->fields['devicenetworkcardmodels_id'] === $networkModel && $pci->fields['devicepcimodels_id'] === $pciModel, 'Both associations hydrate their own identifiers');
    $unrelated = $fixtures->create('glpi_devicepcis', ['designation' => 'Other PCI device', 'devicenetworkcardmodels_id' => $other]);
    $computer = $fixtures->create('glpi_computers', ['name' => 'PCI host']);
    $installation = $fixtures->create('glpi_items_devicepcis', ['itemtype' => 'Computer', 'items_id' => $computer, 'devicepcis_id' => $id]);
    $read = static fn (string $table, int $id): ?array => (new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB)))->find($table, 'id', $id);
    verify((new DeviceNetworkCardModel())->delete(['id' => $networkModel, '_replace_by' => $replacement], true), 'Legacy model replacement follows model lifecycle');
    verify($pci->getFromDB($id) && $pci->fields['devicenetworkcardmodels_id'] === $replacement, 'Legacy model replacement updates PCI reference');
    verify($pci->fields['devicepcimodels_id'] === $pciModel, 'Legacy replacement does not reinterpret the actual PCI model');
    verify((new DeviceNetworkCardModel())->delete(['id' => $replacement], true), 'Legacy model purge clears optional relation');
    verify($pci->getFromDB($id) && $pci->fields['devicenetworkcardmodels_id'] === null, 'PCI record remains with NULL legacy metadata');
    verify($read('glpi_items_devicepcis', $installation)['devicepcis_id'] === $id, 'Legacy model purge preserves installed PCI component');
    verify($read('glpi_devicepcis', $unrelated)['devicenetworkcardmodels_id'] === $other, 'Legacy model purge is scoped');
    verify($pci->update(['id' => $id, 'devicenetworkcardmodels_id' => 0]), 'Legacy zero write remains accepted');
    verify($pci->getFromDB($id) && $pci->fields['devicenetworkcardmodels_id'] === null, 'Zero is normalized to NULL');
    verify(array_keys($pci->find(['id' => $id, 'devicenetworkcardmodels_id' => 0])) === [$id], 'Zero criteria match empty legacy association');
    $results = Search::getDatas('DevicePci', ['reset' => 'reset', 'list_limit' => 50, 'criteria' => [
        ['field' => 17, 'searchtype' => 'equals', 'value' => $pciModel],
    ]]);
    verify(in_array($id, array_column($results['data']['rows'], 'id')), 'PCI model search still uses the actual PCI model');
    verify((new ForeignKeys())->audit($connection) === [], 'Component graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new LegacyComponentModels();
$table = 'glpi_devicepcis';
$column = 'devicenetworkcardmodels_id';
$ids = [];
$legacyModel = null;
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
    $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->getColumn($column)->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $legacyModel = $fixtures->create('glpi_devicenetworkcardmodels', ['name' => 'Preserved legacy model']);
    $firstId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_devicepcis');
    foreach ([0, $legacyModel] as $offset => $value) {
        $ids[] = $firstId + $offset;
        $connection->insert($table, ['id' => $firstId + $offset, 'designation' => 'Legacy PCI migration', $column => $value]);
    }
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && $plan['counts'][$table . '.' . $column] > 0, 'Migration plans nullable DDL and zero normalization');
    verify((int)$connection->fetchOne('SELECT devicenetworkcardmodels_id FROM glpi_devicepcis WHERE id = ?', [$firstId]) === 0, 'Planning preserves legacy data');
    $connection->update($table, [$column => 2147483647], ['id' => $firstId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned legacy component model');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns($table)[$column]->getNotnull(), 'Nonzero orphans rejected before DDL');
    $connection->update($table, [$column => 0], ['id' => $firstId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT devicenetworkcardmodels_id FROM glpi_devicepcis WHERE id = ?', [$firstId]) === null, 'Empty legacy model becomes NULL');
    verify((int)$connection->fetchOne('SELECT devicenetworkcardmodels_id FROM glpi_devicepcis WHERE id = ?', [$firstId + 1]) === $legacyModel, 'Valid nonzero legacy model preserved');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Migration retries are idempotent');
} finally {
    foreach ($ids as $id) {
        $connection->delete($table, ['id' => $id]);
    }
    if ($legacyModel !== null) {
        $connection->delete('glpi_devicenetworkcardmodels', ['id' => $legacyModel]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Legacy PCI model mapping, lifecycle, distinct model search and migration passed.\n";
