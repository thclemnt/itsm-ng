<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\InventoryMetadataReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/inventory-metadata.php /path/to/test-config\n");
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
    $storage = new \itsmng\Database\MappedStorage($DB);
    foreach (OptionalReferences::INVENTORY_METADATA as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Inventory original']);
            $replacement = $fixtures->create($target, ['name' => 'Inventory replacement']);
            $other = $fixtures->create($target, ['name' => 'Inventory unrelated']);
            $extra = [];
            if (in_array($table, ['glpi_items_operatingsystems', 'glpi_items_disks'], true)) {
                $extra = ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
            } elseif ($table === 'glpi_computervirtualmachines') {
                $extra = ['computers_id' => $fixtures->create('glpi_computers')];
            }
            $id = $fixtures->create($table, [$column => $parent] + $extra);
            if (isset($extra['items_id'])) {
                $extra['items_id'] = $fixtures->create('glpi_computers');
            }
            $otherId = $fixtures->create($table, [$column => $other] + $extra);
            if (isset($extra['items_id'])) {
                $extra['items_id'] = $fixtures->create('glpi_computers');
            }
            $empty = $fixtures->create($table, [$column => null] + $extra);
            $storage->update($table, $empty, [$column => 0]);
            $item = getItemForItemtype(getItemTypeForTable($table));
            verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Empty inventory reference: ' . $table . '.' . $column);
            verify(count($item->find(['id' => $empty, $column => 0])) === 1, 'Legacy empty criteria');
            $model = getItemForItemtype(getItemTypeForTable($target));
            verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace inventory metadata');
            verify($item->getFromDB($id) && (int)$item->fields[$column] === $replacement, 'Replacement applied: ' . $table . '.' . $column);
            verify($model->delete(['id' => $replacement], true), 'Purge inventory metadata');
            verify($item->getFromDB($id) && $item->fields[$column] === null, 'Purge clears reference: ' . $table . '.' . $column);
            verify($item->getFromDB($otherId) && (int)$item->fields[$column] === $other, 'Unrelated metadata preserved');
        }
    }
    $computerId = $fixtures->create('glpi_computers', ['name' => 'Inventory views']);
    $computer = new Computer();
    verify($computer->getFromDB($computerId), 'Load inventory owner');
    $os = $fixtures->create('glpi_operatingsystems', ['name' => "Mapped O'Reilly OS"]);
    $version = $fixtures->create('glpi_operatingsystemversions', ['name' => 'Version B']);
    $first = $fixtures->create('glpi_items_operatingsystems', ['items_id' => $computerId, 'itemtype' => 'Computer', 'operatingsystems_id' => $os, 'operatingsystemversions_id' => $version]);
    $second = $fixtures->create('glpi_items_operatingsystems', ['items_id' => $computerId, 'itemtype' => 'Computer', 'is_deleted' => true]);
    $fixtures->create('glpi_items_operatingsystems', ['items_id' => $computerId, 'itemtype' => 'Printer', 'operatingsystems_id' => $os]);
    $connection->beginTransaction();
    try {
        $rejected = false;
        try {
            $fixtures->create('glpi_items_operatingsystems', ['items_id' => $computerId, 'itemtype' => 'Computer']);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
            $rejected = true;
        }
        verify($rejected, 'Missing OS and architecture still participate in uniqueness');
    } finally {
        $connection->rollBack();
    }

    $rows = Item_OperatingSystem::getFromItem($computer, 'name', 'ASC');
    verify(array_column($rows, 'assocID') === [$second, $first], 'OS scope, deleted history and NULL ordering');
    verify($rows[1]['name'] === "Mapped O'Reilly OS" && $rows[1]['version'] === 'Version B', 'OS mapped labels');
    verify(array_column(Item_OperatingSystem::getFromItem($computer, '0', 'DESC'), 'assocID') === [$first, $second], 'OS UI column sorting');
    $filesystem = $fixtures->create('glpi_filesystems', ['name' => 'ext4']);
    $diskId = $fixtures->create('glpi_items_disks', ['items_id' => $computerId, 'itemtype' => 'Computer', 'name' => 'Volume', 'filesystems_id' => $filesystem]);
    $fixtures->create('glpi_items_disks', ['items_id' => $computerId, 'itemtype' => 'Printer']);
    $disks = Item_Disk::getFromItem($computer);
    verify(count($disks) === 1 && (int)$disks[0]['id'] === $diskId && $disks[0]['fsname'] === 'ext4', 'Disk labels and polymorphic identity');
    ob_start();
    Item_Disk::showForItem($computer);
    $html = ob_get_clean();
    verify(str_contains($html, 'ext4') && str_contains($html, 'Volume'), 'Disk rendering consumes mapped rows');
    $cloneId = $fixtures->create('glpi_computers', ['name' => 'Inventory clone']);
    $deprecations = 0;
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        if ($level === E_USER_DEPRECATED && $message === 'Use clone') {
            ++$deprecations;
            return true;
        }
        return false;
    });
    try {
        Item_OperatingSystem::cloneItem('Computer', $computerId, $cloneId);
        Item_Disk::cloneItem('Computer', $computerId, $cloneId);
    } finally {
        restore_error_handler();
    }
    verify($deprecations === 2, 'Legacy clone APIs retain deprecation notices');
    verify((new \Log())->find(['itemtype' => 'OperatingSystem', 'items_id' => 0]) === [], 'No history is written for an absent OS');
    $changed = new Item_OperatingSystem();
    verify($changed->getFromDB($first), 'Load OS relationship for history');
    $changed->oldvalues = ['operatingsystems_id' => null];
    $historyItems = $changed->getItemsForLog('OperatingSystem', 'operatingsystems_id');
    verify($historyItems['previous'] === false && (int)$historyItems['new']->getID() === $os, 'History retains an explicitly NULL previous association');
    $clonedOS = (new Item_OperatingSystem())->find(['itemtype' => 'Computer', 'items_id' => $cloneId]);
    verify(count($clonedOS) === 2 && count(array_filter($clonedOS, static fn ($row) => (bool)$row['is_deleted'])) === 1, 'OS cloning preserves full history and flags');
    $clonedDisks = (new Item_Disk())->find(['itemtype' => 'Computer', 'items_id' => $cloneId]);
    verify(count($clonedDisks) === 1 && (int)reset($clonedDisks)['filesystems_id'] === $filesystem, 'Disk cloning preserves filesystem association');
    $uuid = '564D77D0-6BEF-3DDA-4D67-5C80A952E2C9';
    $vm = $fixtures->create('glpi_computers', ['uuid' => $uuid]);
    verify(ComputerVirtualMachine::findVirtualMachine(['uuid' => strtolower($uuid)]) === $vm, 'Case-insensitive UUID match');
    verify(ComputerVirtualMachine::findVirtualMachine(['uuid' => '56 4d 77 d0 6b ef 3d da-4d 67 5c 80 a9 52 e2 c9']) === $vm, 'Legacy UUID formatting');
    $fixtures->create('glpi_computers', ['uuid' => strtolower($uuid)]);
    verify(ComputerVirtualMachine::findVirtualMachine(['uuid' => $uuid]) === false, 'Ambiguous UUID is not resolved arbitrarily');
    verify(ComputerVirtualMachine::findVirtualMachine([]) === false, 'Missing UUID');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Item_OperatingSystem::getFromItem($computer);
    Item_Disk::getFromItem($computer);
    ComputerVirtualMachine::findVirtualMachine(['uuid' => 'unmatched']);
    verify($SQL_TOTAL_REQUEST === 0, 'Inventory queries use ORM execution');
    verify((new ForeignKeys())->audit($connection) === [], 'Inventory graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new InventoryMetadataReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::INVENTORY_METADATA as $table => $relations) {
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
    $index = \itsmng\Database\Migration\InventoryUniqueness::indexName($platform);
    $connection->executeStatement($platform->getDropIndexSQL($index, 'glpi_items_operatingsystems'));
    try {
        for ($i = 0; $i < 2; ++$i) {
            $connection->insert('glpi_items_operatingsystems', ['items_id' => 2147483500, 'itemtype' => 'Computer']);
        }
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Duplicate OS/architecture assignments');
        }
        verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_clusters')['autoupdatesystems_id']->getNotnull(), 'Duplicate audit precedes nullable DDL');
    } finally {
        $connection->delete('glpi_items_operatingsystems', ['items_id' => 2147483500, 'itemtype' => 'Computer']);
    }
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_computers');
    $connection->insert('glpi_computers', ['id' => $legacyId, 'name' => 'Legacy inventory metadata']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Inventory metadata migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT networks_id FROM glpi_computers WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_computers', ['networks_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned inventory metadata');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_clusters')['autoupdatesystems_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_computers', ['networks_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT networks_id FROM glpi_computers WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($connection->createSchemaManager()->introspectTable('glpi_items_operatingsystems')->hasIndex($index), 'Retry restores a missing OS unique index');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Inventory metadata migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_computers', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": inventory metadata relationships, OS/disk views, UUID lookup and migration passed.\n";
