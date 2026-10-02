<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\NetworkPortAggregateOrigins;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NetworkPortAggregateRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php aggregate-origins.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
$migration = new NetworkPortAggregateOrigins();
$migration->apply($connection);
$DB->clearSchemaCache();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$repo = fn (): NetworkPortAggregateRepository => new NetworkPortAggregateRepository(Orm::create($DB));
$read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
verify(!$connection->createSchemaManager()->introspectTable('glpi_networkportaggregates')->hasColumn('networkports_id_list'), 'Serialized storage is removed');
$DB->beginTransaction();
try {
    $host = $fixtures->create('glpi_networkequipments');
    $ports = [];
    foreach (range(1, 5) as $number) {
        $ports[] = $fixtures->create('glpi_networkports', ['items_id' => $host, 'itemtype' => 'NetworkEquipment', 'instantiation_type' => 'NetworkPortEthernet', 'name' => 'Origin ' . $number, 'logical_number' => 6 - $number]);
    }
    $aggregate = new NetworkPortAggregate();
    $id = $aggregate->add(['networkports_id' => $ports[4], 'networkports_id_list' => [$ports[1], $ports[0], $ports[1]]]);
    verify(is_int($id) && $id > 0 && $id !== $ports[4], 'Public parent key differs from the membership aggregate physical ID');
    verify($repo()->originIds($id) === [$ports[1], $ports[0]], 'Ordered unique membership is persisted');
    foreach (['networkportaggregates_id', 'networkports_id'] as $column) {
        try {
            $connection->transactional(static function () use ($connection, $id, $ports, $column): void {
                $values = ['networkportaggregates_id' => $id, 'networkports_id' => $ports[3], 'position' => 99];
                $values[$column] = 2147483647;
                $connection->insert(NetworkPortAggregateOrigins::TABLE, $values);
            });
            throw new LogicException('Raw orphan accepted');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
        }
    }
    foreach (['glpi_networkportaggregates' => $id, 'glpi_networkports' => $ports[0]] as $table => $target) {
        try {
            $connection->transactional(static fn () => $connection->delete($table, ['id' => $target]));
            throw new LogicException('Referenced raw parent deletion accepted');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            // PostgreSQL reports RESTRICT as 23001, which DBAL does not classify as 23503.
            if (!$error instanceof \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException && $error->getSQLState() !== '23001') {
                throw $error;
            }
        }
    }
    verify(importArrayFromDB($aggregate->fields['networkports_id_list']) === [$ports[1], $ports[0]], 'Legacy public field is a projection');
    verify($aggregate->update(['networkports_id' => $ports[4], 'date_mod' => $_SESSION['glpi_currenttime']]), 'Partial update');
    verify($repo()->originIds($id) === [$ports[1], $ports[0]], 'Omitted membership input is preserved');
    verify($aggregate->update(['networkports_id' => $ports[4], 'networkports_id_list' => [$ports[2], $ports[1]]]), 'Public membership update');
    verify($repo()->originIds($id) === [$ports[2], $ports[1]], 'Ordering is replaced');
    $hostModel = new NetworkEquipment();
    $portModel = new NetworkPort();
    verify($hostModel->getFromDB($host) && $portModel->getFromDB($ports[4]), 'Load form owners');
    $form = $aggregate->showInstantiationForm($portModel, [], [$hostModel]);
    $originInput = array_values(array_filter(current($form)['inputs'], static fn (array $input): bool => ($input['name'] ?? '') === 'networkports_id_list'))[0];
    verify(array_keys($originInput['options']) === array_reverse($ports) && $originInput['values'] === [$ports[2], $ports[1]], 'Form keeps actual port IDs and selected canonical origins');
    try {
        $aggregate->update(['networkports_id' => $ports[4], 'date_mod' => '2001-01-01 00:00:00', 'networkports_id_list' => [2147483647]]);
        throw new LogicException('Unknown origin accepted');
    } catch (InvalidArgumentException) {
    }
    verify($repo()->originIds($id) === [$ports[2], $ports[1]] && $read('glpi_networkportaggregates', $id)['date_mod'] !== '2001-01-01 00:00:00', 'Invalid membership rolls back the aggregate update too');
    try {
        (new NetworkPortAggregate())->add(['networkports_id' => $ports[3], 'networkports_id_list' => [2147483647]]);
        throw new LogicException('Unknown add origin accepted');
    } catch (InvalidArgumentException) {
    }
    verify((new NetworkPortAggregate())->getFromDB($ports[3]) === false, 'Invalid membership rolls back aggregate creation');
    verify($repo()->aggregatesForPort($ports[2]) === [['id' => $ports[4]]] && $repo()->aggregatesForPort($ports[0]) === [], 'Reverse lookup is exact membership');
    verify(array_column($repo()->availablePorts('NetworkEquipment', $host, 'NetworkPortEthernet'), 'id') === array_reverse($ports), 'Available ports use mapped type/owner and stable logical order');
    $alias = $fixtures->create('glpi_networkportaliases', ['networkports_id' => $ports[3], 'networkports_id_alias' => $ports[2]]);
    verify($repo()->virtualPorts($ports[2]) === [['networkports_id' => $ports[3]], ['networkports_id' => $ports[4]]], 'Alias and aggregate virtual peers use typed associations');
    verify((new NetworkPort())->delete(['id' => $ports[2], '_replace_by' => $ports[1]], true), 'Replace origin port');
    verify($repo()->originIds($id) === [$ports[1]], 'Replacement deduplicates a destination already present');
    verify((new NetworkPort())->delete(['id' => $ports[1]], true), 'Purge origin port');
    verify($repo()->originIds($id) === [], 'Purge removes membership');
    $aggregate->update(['networkports_id' => $ports[4], 'networkports_id_list' => [$ports[0]]]);
    verify((new NetworkPort())->delete(['id' => $ports[4]], true) && $read('glpi_networkportaggregates', $id) === null, 'Aggregate parent purge removes memberships before subtype');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned membership ends');
} finally {
    $DB->rollBack();
}
// Rebuild an old installation shape and prove refusal before destructive DDL.
$legacy = $connection->createSchemaManager()->introspectTable('glpi_networkportaggregates');
$withList = clone $legacy;
$withList->addColumn('networkports_id_list', 'text', ['notnull' => false]);
foreach ($connection->getDatabasePlatform()->getAlterTableSQL($connection->createSchemaManager()->createComparator()->compareTables($legacy, $withList)) as $sql) {
    $connection->executeStatement($sql);
}
$connection->createSchemaManager()->dropTable(NetworkPortAggregateOrigins::TABLE);
$DB->clearSchemaCache();
$port = $fixtures->create('glpi_networkports');
$aggregateId = $fixtures->create('glpi_networkportaggregates');
$otherId = $fixtures->create('glpi_networkportaggregates');
try {
    $connection->update('glpi_networkportaggregates', ['networkports_id_list' => '[2147483647]'], ['id' => $aggregateId]);
    try {
        $migration->apply($connection);
        throw new LogicException('Orphan migration accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Orphaned legacy aggregate origin'), 'Orphan preflight');
    }
    verify(!$connection->createSchemaManager()->tablesExist([NetworkPortAggregateOrigins::TABLE]), 'Refusal creates no table and preserves legacy data');
    $connection->update('glpi_networkportaggregates', ['networkports_id_list' => 'broken'], ['id' => $aggregateId]);
    try {
        $migration->plan($connection);
        throw new LogicException('Malformed migration accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Malformed'), 'Malformed selection is rejected');
    }
    $connection->update('glpi_networkportaggregates', ['networkports_id_list' => json_encode([$port, $port])], ['id' => $aggregateId]);
    $connection->update('glpi_networkportaggregates', ['networkports_id_list' => '0=>' . $port], ['id' => $otherId]);
    $migration->apply($connection);
    $migration->apply($connection);
    $DB->clearSchemaCache();
    verify($repo()->originIds($aggregateId) === [$port] && $repo()->originIds($otherId) === [$port], 'JSON and old key/value encoding migrate, deduplicate and retry');
    verify($migration->plan($connection)['create_sql'] === [] && $migration->plan($connection)['drop_sql'] === [], 'Migration is idempotent');
    verify((new ForeignKeys())->audit($connection) === [], 'Migrated memberships enforce both ends');
} finally {
    $repo()->removeForAggregate($aggregateId);
    $repo()->removeForAggregate($otherId);
    $connection->delete('glpi_networkportaggregates', ['id' => $aggregateId]);
    $connection->delete('glpi_networkportaggregates', ['id' => $otherId]);
    $connection->delete('glpi_networkports', ['id' => $port]);
}
echo $DB->getProvider() . ": normalized aggregate origins, ordered ORM lifecycle, exact peers, rollback and frozen upgrade passed.\n";
