<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\FinancialReferences;
use itsmng\Database\OptionalReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/financial-references.php /path/to/test-config\n");
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
    foreach (OptionalReferences::FINANCIAL as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Financial parent']);
            $values = [$column => $parent];
            if ($table === 'glpi_infocoms') {
                $values += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
            }
            if ($table === 'glpi_projectcosts') {
                $values += ['begin_date' => '2026-09-01', 'end_date' => '2026-09-30'];
            }
            $id = $fixtures->create($table, $values);
            $model = getItemForItemtype(getItemTypeForTable($table));
            $type = getItemForItemtype(getItemTypeForTable($target));
            if ($target !== 'glpi_budgets') {
                $replacement = $fixtures->create($target, ['name' => 'Replacement financial type']);
                verify($type->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace financial type');
                verify($model->getFromDB($id) && (int)$model->fields[$column] === $replacement, 'Replacement retained: ' . $table);
                $parent = $replacement;
            }
            verify($type->delete(['id' => $parent], true), 'Purge financial parent: ' . $target);
            verify($model->getFromDB($id) && $model->fields[$column] === null, 'Purge preserves child without reference: ' . $table);
            verify(array_keys($model->find(['id' => $id, $column => 0])) === [$id], 'Legacy zero criteria finds NULL: ' . $table);
            verify($model->update(['id' => $id, $column => 0]), 'Legacy zero write accepted');
            verify($model->getFromDB($id) && $model->fields[$column] === null, 'Legacy zero write normalized');
            if ($table === 'glpi_projectcosts') {
                verify($model->fields['begin_date'] === '2026-09-01' && $model->fields['end_date'] === '2026-09-30', 'Budget purge and partial updates preserve project cost dates');
                verify($model->update(['id' => $id, 'begin_date' => '2026-10-01']), 'Move project cost start');
                verify($model->getFromDB($id) && $model->fields['end_date'] === '2026-10-01', 'Changing start clamps an earlier end');
            }
        }
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Financial graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new FinancialReferences();
$legacyId = null;
try {
    foreach (OptionalReferences::FINANCIAL as $table => $relations) {
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
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_contracts');
    $connection->insert('glpi_contracts', ['id' => $legacyId, 'name' => 'Legacy financial']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'Financial migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT contracttypes_id FROM glpi_contracts WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_contracts', ['contracttypes_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned financial');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_budgets')['budgettypes_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_contracts', ['contracttypes_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT contracttypes_id FROM glpi_contracts WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Financial migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_contracts', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": financial FK lifecycle, legacy zeros, migration refusal and retry passed.\n";
