<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ManufacturerReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/manufacturers.php /path/to/test-config\n");
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
    $brand = $fixtures->create('glpi_manufacturers', ['name' => 'Original manufacturer']);
    $replacement = $fixtures->create('glpi_manufacturers', ['name' => 'Replacement manufacturer']);
    $unrelated = $fixtures->create('glpi_manufacturers', ['name' => 'Unrelated manufacturer']);
    $children = [];
    $others = [];
    foreach (OptionalReferences::MANUFACTURERS as $table => $relations) {
        $children[$table] = $fixtures->create($table, ['manufacturers_id' => $brand]);
        $others[$table] = $fixtures->create($table, ['manufacturers_id' => $unrelated]);
    }
    $manufacturer = new Manufacturer();
    verify($manufacturer->delete(['id' => $brand, '_replace_by' => $replacement], true), 'Replace manufacturer across all associations');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && (int)$item->fields['manufacturers_id'] === $replacement, 'Manufacturer replacement retained: ' . $table);
    }
    verify($manufacturer->delete(['id' => $replacement], true), 'Purge referenced manufacturer');
    foreach ($children as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && $item->fields['manufacturers_id'] === null, 'Manufacturer purge preserves child: ' . $table);
        verify(array_keys($item->find(['id' => $id, 'manufacturers_id' => 0])) === [$id], 'Legacy empty manufacturer criteria: ' . $table);
        verify($item->update(['id' => $id, 'manufacturers_id' => 0]), 'Legacy empty manufacturer update');
        verify($item->getFromDB($id) && $item->fields['manufacturers_id'] === null, 'Empty update stays NULL');
        verify($item->getFromDB($others[$table]) && (int)$item->fields['manufacturers_id'] === $unrelated, 'Other manufacturers remain unchanged: ' . $table);
    }
    $computerId = $fixtures->create('glpi_computers', ['name' => 'Antivirus owner']);
    $cloneId = $fixtures->create('glpi_computers', ['name' => 'Antivirus clone']);
    $antivirus = $fixtures->create('glpi_computerantiviruses', ['computers_id' => $computerId, 'manufacturers_id' => $unrelated, 'name' => "Mapped O'Reilly antivirus", 'is_active' => 1, 'is_uptodate' => 1, 'antivirus_version' => '2.0', 'signature_version' => '2026.09', 'date_expiration' => '2026-12-31 00:00:00']);
    $deleted = $fixtures->create('glpi_computerantiviruses', ['computers_id' => $computerId, 'name' => 'Deleted antivirus', 'is_deleted' => 1]);
    $computer = new Computer();
    verify($computer->getFromDB($computerId), 'Load antivirus owner');
    ob_start();
    ComputerAntivirus::showForComputer($computer);
    $html = ob_get_clean();
    verify(str_contains($html, 'Mapped O') && str_contains($html, 'Unrelated manufacturer') && !str_contains($html, 'Deleted antivirus'), 'Antivirus list renders mapped flags and manufacturer');
    $deprecation = false;
    set_error_handler(static function (int $severity, string $message) use (&$deprecation): bool {
        if ($severity === E_USER_DEPRECATED && $message === 'Use clone') {
            $deprecation = true;
            return true;
        }
        return false;
    });
    try {
        ComputerAntivirus::cloneComputer($computerId, $cloneId);
    } finally {
        restore_error_handler();
    }
    verify($deprecation, 'Legacy clone API retains its deprecation notice');
    $clones = (new ComputerAntivirus())->find(['computers_id' => $cloneId]);
    verify(count($clones) === 2, 'Legacy antivirus clone keeps the complete source history');
    $rows = array_column($clones, null, 'name');
    verify((int)$rows["Mapped O'Reilly antivirus"]['manufacturers_id'] === $unrelated && (int)$rows["Mapped O'Reilly antivirus"]['is_active'] === 1, 'Clone preserves manufacturer, quotes and native flags');
    verify($computer->delete(['id' => $computerId], true), 'Computer purge with required antivirus FK');
    verify((new ComputerAntivirus())->find(['computers_id' => $computerId]) === [], 'Computer purge removes all antivirus children');
    verify(count((new ComputerAntivirus())->find(['computers_id' => $cloneId])) === 2, 'Computer purge retains unrelated antivirus records');
    verify((new ForeignKeys())->audit($connection) === [], 'Manufacturer and antivirus graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new ManufacturerReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::MANUFACTURERS as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_softwares');
    $connection->insert('glpi_softwares', ['id' => $legacyId, 'name' => 'Legacy manufacturer']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Manufacturer migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT manufacturers_id FROM glpi_softwares WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_softwares', ['manufacturers_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned manufacturer');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_appliances')['manufacturers_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_softwares', ['manufacturers_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT manufacturers_id FROM glpi_softwares WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Manufacturer migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_softwares', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": manufacturer replacement/purge, antivirus ownership/views/cloning and migration passed.\n";
