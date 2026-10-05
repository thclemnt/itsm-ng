<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CartridgeRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/stock.php /path/to/test-config\n");
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
$previousRepeat = $CFG_GLPI['cartridges_alert_repeat'] ?? null;
$CFG_GLPI['cartridges_alert_repeat'] = 7;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    foreach (['glpi_cartridgeitems', 'glpi_consumableitems'] as $table) {
        foreach (ReferenceHistory::get('optional', 'STOCK')[$table] as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Stock type']);
            $replacement = $fixtures->create($target, ['name' => 'Replacement stock type']);
            $id = $fixtures->create($table, [$column => $parent]);
            $model = getItemForItemtype(getItemTypeForTable($table));
            $type = getItemForItemtype(getItemTypeForTable($target));
            verify($type->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace stock type');
            verify($model->getFromDB($id) && (int)$model->fields[$column] === $replacement, 'Stock type replacement retained');
            verify($type->delete(['id' => $replacement], true), 'Purge stock type');
            verify($model->getFromDB($id) && $model->fields[$column] === null, 'Type purge preserves inventory model');
        }
    }
    $type = $fixtures->create('glpi_cartridgeitemtypes', ['name' => 'Mapped toner type']);
    $model = $fixtures->create('glpi_cartridgeitems', ['name' => 'Mapped toner', 'cartridgeitemtypes_id' => $type]);
    $printerModel = $fixtures->create('glpi_printermodels', ['name' => 'Compatible printer model']);
    $fixtures->create('glpi_cartridgeitems_printermodels', ['printermodels_id' => $printerModel, 'cartridgeitems_id' => $model]);
    $printerId = $fixtures->create('glpi_printers', ['name' => 'Stock test printer', 'last_pages_counter' => 123, 'printermodels_id' => $printerModel]);
    $otherPrinter = $fixtures->create('glpi_printers', ['name' => 'Other stock printer']);
    $unrelated = $fixtures->create('glpi_cartridges', ['printers_id' => $otherPrinter, 'date_use' => '2026-01-01']);
    $ids = [];
    foreach (['2026-01-01', '2026-01-02', '2026-01-03'] as $date) {
        $ids[] = $fixtures->create('glpi_cartridges', ['cartridgeitems_id' => $model, 'date_in' => $date]);
    }
    $cartridge = new Cartridge();
    verify(Cartridge::getTotalNumber($model) === 3 && Cartridge::getUnusedNumber($model) === 3, 'Initial stock counts');
    foreach ($ids as $id) {
        verify($cartridge->install($printerId, $model), 'Claim stock cartridge');
        verify($cartridge->getFromDB($id) && (int)$cartridge->fields['printers_id'] === $printerId, 'Stock selection is deterministic');
    }
    verify(!$cartridge->install($otherPrinter, $model), 'Exhausted stock cannot be claimed again');
    verify(Cartridge::getUsedNumber($model) === 3 && Cartridge::getUsedNumberForPrinter($printerId) === 3, 'Installed stock counts');
    verify($cartridge->uninstall($ids[0]), 'End cartridge life');
    verify(!$cartridge->uninstall($ids[0]), 'Repeated unchanged end-of-life is a no-op');
    verify($cartridge->getFromDB($ids[0]) && (int)$cartridge->fields['pages'] === 123, 'End-of-life captures printer counter');
    verify(Cartridge::getOldNumber($model) === 1 && Cartridge::getOldNumberForPrinter($printerId) === 1, 'Worn cartridge counts');
    $em = Orm::create($DB);
    $repository = new CartridgeRepository($em);
    $old = $repository->forPrinter($printerId, true);
    verify(count($old) === 1 && $old[0]['typename'] === 'Mapped toner type' && $old[0]['type'] === 'Mapped toner', 'Mapped printer view joins model and type');
    $em->clear();
    verify($cartridge->backToStock(['id' => $ids[0]]), 'Return used cartridge to stock');
    verify(!$cartridge->backToStock(['id' => $ids[0]]), 'Returning unchanged stock is a no-op');
    verify($cartridge->getFromDB($ids[0]) && $cartridge->fields['printers_id'] === null && $cartridge->fields['date_use'] === null && $cartridge->fields['date_out'] === null, 'Stock has no printer or use dates');
    verify((int)$cartridge->fields['pages'] === 123, 'Returning stock retains counter history');
    verify(Cartridge::getUnusedNumber($model) === 1 && Cartridge::getTotalNumberForPrinter($printerId) === 2, 'Returned stock no longer belongs to printer');
    verify(array_keys($cartridge->find(['id' => $ids, 'printers_id' => 0])) === [$ids[0]], 'Legacy zero printer criteria selects NULL');
    $em = Orm::create($DB);
    $rows = (new CartridgeRepository($em))->forModel($model, false);
    verify(array_column($rows, 'id') === $ids && $rows[0]['printID'] === null && (int)$rows[1]['printID'] === $printerId, 'Model view keeps unassigned stock first and joins optional printer');
    $em->clear();
    $printer = new Printer();
    verify($printer->getFromDB($printerId), 'Load printer');
    $entity = (new Entity())->add(['name' => 'Stock notification settings', 'entities_id' => 0, 'cartridges_alert_repeat' => 14]);
    $hiddenModel = $fixtures->create('glpi_cartridgeitems', ['name' => 'Hidden toner', 'entities_id' => $entity]);
    $fixtures->create('glpi_cartridges', ['cartridgeitems_id' => $hiddenModel]);
    $fixtures->create('glpi_cartridgeitems_printermodels', ['printermodels_id' => $printerModel, 'cartridgeitems_id' => $hiddenModel]);
    $options = CartridgeItem::dropdownForPrinter($printer);
    verify(array_keys($options) === [$model] && str_contains($options[$model], '(1)'), 'Compatible stock selector counts unused cartridges and respects entity scope');
    ob_start();
    Cartridge::showForPrinter($printer);
    $html = ob_get_clean();
    verify(str_contains($html, 'Mapped toner') && str_contains($html, 'Mapped toner type'), 'Printer cartridge view renders mapped rows');
    $modelObject = new CartridgeItem();
    verify($modelObject->getFromDB($model), 'Load cartridge model');
    ob_start();
    Cartridge::showForCartridgeItem($modelObject);
    $html = ob_get_clean();
    verify(str_contains($html, 'Stock test printer'), 'Model cartridge view renders mapped printer association');
    verify($printer->delete(['id' => $printerId], true), 'Printer purge with stock FK');
    verify($cartridge->getFromDB($ids[1]) && $cartridge->fields['printers_id'] === null && $cartridge->fields['date_use'] !== null, 'Purge preserves cartridge usage history');
    verify($cartridge->getFromDB($unrelated) && (int)$cartridge->fields['printers_id'] === $otherPrinter, 'Purge preserves unrelated assignments');
    verify((int)Cartridge::getNotificationParameters($entity) === 14, 'Mapped entity alert setting');
    verify(Cartridge::getNotificationParameters(-1) === $CFG_GLPI['cartridges_alert_repeat'], 'Missing entity uses global alert setting');
    verify((new ForeignKeys())->audit($connection) === [], 'Stock graph remains valid');
} finally {
    $DB->rollBack();
    if ($previousRepeat === null) {
        unset($CFG_GLPI['cartridges_alert_repeat']);
    } else {
        $CFG_GLPI['cartridges_alert_repeat'] = $previousRepeat;
    }
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NullableReferences(ReferenceHistory::get('optional', 'STOCK'), 'stock');
$legacyId = null;
try {
    foreach (ReferenceHistory::get('optional', 'STOCK') as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_cartridgeitems');
    $connection->insert('glpi_cartridgeitems', ['id' => $legacyId, 'name' => 'Legacy stock']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Stock migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT cartridgeitemtypes_id FROM glpi_cartridgeitems WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_cartridgeitems', ['cartridgeitemtypes_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned stock');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_cartridges')['printers_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_cartridgeitems', ['cartridgeitemtypes_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT cartridgeitemtypes_id FROM glpi_cartridgeitems WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Stock migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_cartridgeitems', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": stock associations, install/end-of-life/return, counts, views, purge and migration passed.\n";
