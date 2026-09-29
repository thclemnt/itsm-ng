<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\NetworkPortReferences;
use itsmng\Database\OptionalReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/network-ports.php /path/to/test-config\n");
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
    foreach (OptionalReferences::NETWORK_PORT_METADATA as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target);
            $replacement = $fixtures->create($target);
            $other = $fixtures->create($target);
            $child = $fixtures->create($table, [$column => $parent]);
            $unrelated = $fixtures->create($table, [$column => $other]);
            $model = getItemForItemtype(getItemTypeForTable($target));
            $key = $model->getIndexName();
            $parentKey = $read($target, $parent)[$key];
            $replacementKey = $read($target, $replacement)[$key];
            verify($model->delete([$key => $parentKey, '_replace_by' => $replacementKey], true), 'Replace ' . $target);
            verify((int)$read($table, $child)[$column] === $replacement, 'Replacement updates ' . $column);
            verify($model->delete([$key => $replacementKey], true), 'Purge replacement ' . $target);
            verify($read($table, $child) !== null && $read($table, $child)[$column] === null, 'Purge clears ' . $column);
            verify((int)$read($table, $unrelated)[$column] === $other, 'Unrelated reference unchanged ' . $column);
        }
    }
    foreach (['NetworkPortAggregate', 'NetworkPortAlias', 'NetworkPortDialup', 'NetworkPortEthernet', 'NetworkPortFiberchannel', 'NetworkPortLocal', 'NetworkPortWifi'] as $type) {
        $parent = $fixtures->create('glpi_networkports', ['instantiation_type' => '']);
        $table = $type::getTable();
        $id = $fixtures->create($table, ['networkports_id' => $parent]);
        verify((new NetworkPort())->delete(['id' => $parent], true), 'Purge owns subtype even with stale discriminator ' . $type);
        verify($read($table, $id) === null, 'Required subtype removed ' . $type);
    }
    // A Wi-Fi row's public port key can differ from its physical ID.
    $wifi = $fixtures->create('glpi_networkportwifis');
    $dependent = $fixtures->create('glpi_networkportwifis', ['networkportwifis_id' => $wifi]);
    $port = $read('glpi_networkportwifis', $wifi)['networkports_id'];
    verify($port !== $wifi, 'Wi-Fi fixture distinguishes public and physical identities');
    verify((new NetworkPort())->delete(['id' => $port], true), 'Port purge removes Wi-Fi subtype and clears its inbound links');
    verify($read('glpi_networkportwifis', $dependent)['networkportwifis_id'] === null, 'Inbound Wi-Fi link cleared by row identity');
    verify((new ForeignKeys())->audit($connection) === [], 'Network port graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NetworkPortReferences();
$legacy = null;
$host = $fixtures->create('glpi_networkports');
try {
    foreach (OptionalReferences::NETWORK_PORT_METADATA as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_networkportethernets');
    $connection->insert('glpi_networkportethernets', ['id' => $legacy, 'networkports_id' => $host]);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy network port migration has a plan');
    verify((int)$connection->fetchOne('SELECT netpoints_id FROM glpi_networkportethernets WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_networkportethernets', ['netpoints_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned network port');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_networkportethernets')['netpoints_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_networkportethernets', ['netpoints_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT netpoints_id FROM glpi_networkportethernets WHERE id = ?', [$legacy]) === null, 'Legacy outlet becomes NULL');
    verify($migration->apply($connection) === [], 'Network port migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_networkportethernets', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    (new NetworkPort())->delete(['id' => $host], true);
}
echo $DB->getProvider() . ": network port associations, subtype purges, replacement and migration passed.\n";
