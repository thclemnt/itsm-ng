<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\SoftwareMetadataReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\SoftwareRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/software-metadata.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$DB->beginTransaction();
try {
    foreach (ReferenceHistory::get('optional', 'SOFTWARE_METADATA') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Software metadata parent']);
            $replacement = $fixtures->create($target, ['name' => 'Software metadata replacement']);
            $other = $fixtures->create($target, ['name' => 'Software metadata other']);
            $values = $table === 'glpi_softwares' ? ['is_update' => true] : [];
            $child = $fixtures->create($table, [$column => $parent, 'name' => 'Metadata dependent'] + $values);
            $unrelated = $fixtures->create($table, [$column => $other, 'name' => 'Metadata unrelated'] + $values);
            $model = getItemForItemtype(getItemTypeForTable($target));
            verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace ' . $target);
            verify((int)$read($table, $child)[$column] === $replacement, 'Replacement updates ' . $column);
            verify($model->delete(['id' => $replacement], true), 'Purge replacement ' . $target);
            verify($read($table, $child) !== null && $read($table, $child)[$column] === null, 'Purge clears ' . $column);
            verify((int)$read($table, $unrelated)[$column] === $other, 'Unrelated reference unchanged ' . $column);
        }
    }
    $software = $fixtures->create('glpi_softwares', ['name' => 'Listed software']);
    $entity = (new Entity())->add(['name' => 'License foreign entity', 'entities_id' => 0]);
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software, 'name' => 'Purchase version']);
    $use = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software, 'name' => 'Use version']);
    $type = $fixtures->create('glpi_softwarelicensetypes', ['name' => 'License type']);
    $state = $fixtures->create('glpi_states', ['name' => 'License state']);
    $newLicense = static fn (array $values) => $fixtures->create('glpi_softwarelicenses', $values + ['softwares_id' => $software, 'name' => 'License']);
    $labeled = $newLicense(['name' => 'A', 'softwareversions_id_buy' => $version, 'softwareversions_id_use' => $use,
        'softwarelicensetypes_id' => $type, 'states_id' => $state, 'expire' => '2030-01-16']);
    $missing = $newLicense(['name' => 'B', 'expire' => null]);
    $hidden = $newLicense(['name' => 'C', 'entities_id' => $entity]);
    $template = $newLicense(['name' => 'D', 'is_template' => true]);
    $repo = new SoftwareRepository(Orm::create($DB));
    $scope = ['entities_id' => 0];
    $rows = $repo->licenses($software, $scope, 'name', 'ASC', 10, 0);
    verify(array_column($rows, 'id') === [$labeled, $missing], 'License list excludes templates and foreign entities');
    verify($rows[0]['buyname'] === 'Purchase version' && $rows[0]['usename'] === 'Use version'
        && $rows[0]['typename'] === 'License type' && $rows[0]['statename'] === 'License state', 'Association labels');
    verify(array_column($repo->licenses($software, $scope, 'name', 'DESC', 1, 1), 'id') === [$labeled], 'Descending pagination');
    verify(array_column($repo->licenses($software, $scope, 'buyname', 'ASC', 10, 0), 'id') === [$missing, $labeled], 'NULL labels sort first ascending');
    verify(array_column($repo->licenses($software, $scope, 'buyname', 'DESC', 10, 0), 'id') === [$labeled, $missing], 'NULL labels sort last descending');
    verify($repo->licenses($software, $scope, 'name', 'ASC', 10, 20) === [], 'Out-of-range page');
    verify(count($repo->licenses($software, $scope, 'r.id); DROP TABLE x', 'invalid', 10, 0)) === 2, 'Sort whitelist');
    $today = new DateTimeImmutable('2030-01-15');
    $due = $newLicense(['name' => 'Due', 'expire' => '2030-01-15']);
    $past = $newLicense(['name' => 'Past', 'expire' => '2030-01-14']);
    $alerted = $newLicense(['name' => 'Alerted', 'expire' => '2030-01-14']);
    $fixtures->create('glpi_alerts', ['itemtype' => 'SoftwareLicense', 'items_id' => $alerted, 'type' => Alert::END, 'date' => '2030-01-14 12:00:00']);
    $fixtures->create('glpi_contracts', ['id' => $past]);
    $fixtures->create('glpi_alerts', ['itemtype' => 'Contract', 'items_id' => $past, 'type' => Alert::END, 'date' => '2030-01-14 12:00:00']);
    $foreignSoftware = $fixtures->create('glpi_softwares', ['name' => 'Foreign software', 'entities_id' => $entity]);
    $foreignLicense = $newLicense(['softwares_id' => $foreignSoftware, 'expire' => '2030-01-14']);
    $deletedSoftware = $fixtures->create('glpi_softwares', ['name' => 'Deleted software', 'is_deleted' => true]);
    $deleted = $newLicense(['softwares_id' => $deletedSoftware, 'expire' => '2030-01-14']);
    $ids = array_column($repo->expiringLicenses(0, 1, $today), 'id');
    verify(in_array($due, $ids, true) && in_array($past, $ids, true), 'Today and earlier included before tomorrow');
    foreach ([$labeled, $missing, $alerted, $foreignLicense, $deleted] as $excluded) {
        verify(!in_array($excluded, $ids, true), 'Cutoff, NULL, alert, entity and deletion exclusions');
    }
    verify(!in_array($due, array_column($repo->expiringLicenses(0, 0, $today), 'id'), true), 'Strict zero-day cutoff');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->licenses($software, $scope, 'expire', 'DESC', 10, 0);
    $repo->expiringLicenses(0, 1, $today);
    verify($SQL_TOTAL_REQUEST === 0, 'License reads bypass legacy SQL transport');
    $model = new Software();
    verify($model->getFromDB($software), 'Load software view');
    $_GET = ['sort' => 'buyname', 'order' => 'DESC'];
    ob_start();
    SoftwareLicense::showForSoftware($model);
    $html = ob_get_clean();
    verify(str_contains($html, 'Purchase version') && str_contains($html, 'Use version'), 'License view renders association labels');
    $treeParent = (int)(new SoftwareLicense())->add(['name' => 'License tree parent', 'entities_id' => 0, 'softwares_id' => $software]);
    $treeChild = (int)(new SoftwareLicense())->add(['name' => 'License tree child', 'entities_id' => 0, 'softwares_id' => $software, 'softwarelicenses_id' => $treeParent]);
    $treeGrandchild = (int)(new SoftwareLicense())->add(['name' => 'License tree grandchild', 'entities_id' => 0, 'softwares_id' => $software, 'softwarelicenses_id' => $treeChild]);
    verify($treeParent > 0 && $treeChild > 0 && $treeGrandchild > 0, 'Create license hierarchy through model hooks');
    $licenseModel = new SoftwareLicense();
    verify(!$licenseModel->update(['id' => $treeParent, 'softwarelicenses_id' => $treeGrandchild]), 'Cannot move license beneath its descendant');
    verify($licenseModel->getFromDB($treeParent), 'Load license hierarchy');
    ob_start();
    SoftwareLicense::getSonsOf($licenseModel);
    $childrenHtml = ob_get_clean();
    verify(str_contains($childrenHtml, 'License tree child') && !str_contains($childrenHtml, 'License tree grandchild'), 'ORM child list contains immediate children');
    verify($licenseModel->delete(['id' => $treeParent], true), 'Purge license tree parent');
    verify($read('glpi_softwarelicenses', $treeChild)['softwarelicenses_id'] === null, 'Parent purge promotes child to root');
    verify((int)$read('glpi_softwarelicenses', $treeGrandchild)['softwarelicenses_id'] === $treeChild, 'Grandchild relationship survives promotion');
    verify((new ForeignKeys())->audit($connection) === [], 'Software relationship graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new SoftwareMetadataReferences();
$legacy = $legacyLicense = null;
try {
    foreach (ReferenceHistory::get('optional', 'SOFTWARE_METADATA') as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_softwares');
    $connection->insert('glpi_softwares', ['id' => $legacy, 'name' => 'Legacy software metadata']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy software metadata migration has a plan');
    verify((int)$connection->fetchOne('SELECT softwarecategories_id FROM glpi_softwares WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_softwares', ['softwarecategories_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned software metadata');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_softwares')['softwarecategories_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_softwares', ['softwarecategories_id' => 0], ['id' => $legacy]);
    $legacyLicense = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_softwarelicenses');
    $connection->insert('glpi_softwarelicenses', ['id' => $legacyLicense, 'softwares_id' => $legacy, 'softwarelicenses_id' => $legacyLicense]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Cyclic software license parents');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_softwares')['softwarecategories_id']->getNotnull(), 'Cyclic license hierarchy rejected before any DDL');
    $connection->update('glpi_softwarelicenses', ['softwarelicenses_id' => 0], ['id' => $legacyLicense]);
    $migration->apply($connection);
    $row = $connection->fetchAssociative('SELECT softwarelicenses_id, softwareversions_id_buy, softwareversions_id_use FROM glpi_softwarelicenses WHERE id = ?', [$legacyLicense]);
    verify(array_values($row) === [null, null, null], 'All legacy license references normalize to NULL');
    verify($connection->fetchOne('SELECT softwarecategories_id FROM glpi_softwares WHERE id = ?', [$legacy]) === null, 'Legacy category becomes NULL');
    verify($migration->apply($connection) === [], 'Software metadata migration is idempotent');
} finally {
    if ($legacyLicense !== null) {
        $connection->delete('glpi_softwarelicenses', ['id' => $legacyLicense]);
    }
    if ($legacy !== null) {
        $connection->delete('glpi_softwares', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": software metadata lifecycle, license views, expiry selection and migration passed.\n";
