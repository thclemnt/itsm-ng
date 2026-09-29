<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Seed once in an isolated installed database, then run web-reports.py --asset-fixtures.
if (!isset($argv[1]) || !is_file($argv[1] . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/seed-report-web.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', $argv[1]);
require GLPI_ROOT . '/inc/includes.php';
require GLPI_ROOT . '/tests/database-portability/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable fresh report database required');
}
$records = new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
if ($records->countMatching('glpi_computers', ['name' => 'ORM report visible']) !== 0) {
    throw new RuntimeException('Report fixture already exists; use a fresh disposable installation');
}
$f = new FixtureRecords($DB);
$DB->beginTransaction();
try {
    $entityId = (int)$DB->getDoctrineConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $f->create('glpi_entities', ['id' => $entityId, 'entities_id' => 0, 'name' => 'ORM report hidden scope']);
    $contract = $f->create('glpi_contracts', ['name' => 'ORM report contract', 'begin_date' => '2025-01-01', 'duration' => 12]);
    foreach (['visible' => [], 'deleted' => ['is_deleted' => true], 'template' => ['is_template' => true], 'hidden' => ['entities_id' => $entity], 'uncontracted' => []] as $kind => $values) {
        $id = $f->create('glpi_computers', $values + ['name' => 'ORM report ' . $kind, 'entities_id' => 0]);
        $f->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $id, 'buy_date' => '2025-12-31', 'warranty_duration' => 24]);
        if ($kind !== 'uncontracted') {
            $f->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => 'Computer', 'items_id' => $id]);
        }
    }
    $DB->commit();
} catch (Throwable $e) {
    $DB->rollBack();
    throw $e;
}
echo "Report HTTP fixture seeded.\n";
