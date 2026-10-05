<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\NetworkPortVlanRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/vlan-current-read-admission.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
verify(method_exists(MySQLConnection::class, 'assertCurrentReads'), 'Compose the existing current-read policy prerequisite before this contract');
Session::start();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$writer = $DB;
$connection = $writer->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0 && !$connection->getNativeConnection()->inTransaction(), 'Start with the actual idle supplied writer');
$mysql = $writer->getProvider() === 'mysql';
$originalCapability = $mysql ? MySQLConnection::snapshotIsolation($connection) : null;
verify($originalCapability !== true, 'The supplied session was admitted with traditional current locking reads');
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$savedNotifications = $CFG_GLPI['use_notifications'];
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$tables = [NetworkPort_Vlan::getTable(), NetworkPort::getTable(), Vlan::getTable(), Computer::getTable(), Entity::getTable(), Log::getTable(), QueuedNotification::getTable()];
$snapshot = static function () use ($connection, $tables): array {
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY ' . $connection->quoteIdentifier('id'));
    }
    $rows['itsmng_migrations'] = $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version');
    return $rows;
};
$beforeAll = $snapshot();
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before admission controls');
$frame = OwnedMutationFrame::begin($connection);
$primary = null;
$cleanup = [];
$rolledBack = false;
$phase = '';
$flips = 0;
try {
    $CFG_GLPI['use_notifications'] = false;
    $plugins->setValue(null, [...$savedPlugins, 'vlan_current_read_fixture']);
    foreach (['pre_item_add' => 'add', 'pre_item_update' => 'update', 'pre_item_purge' => 'purge'] as $hook => $kind) {
        $PLUGIN_HOOKS[$hook]['vlan_current_read_fixture'][NetworkPort_Vlan::class] = static function (NetworkPort_Vlan $model) use ($kind, &$phase, &$flips, $writer, $connection): void {
            if ($phase !== $kind) {
                return;
            }
            verify($GLOBALS['DB'] === $writer && $writer->getDoctrineConnection() === $connection, 'Callback retains the supplied writer');
            verify($connection->getTransactionNestingLevel() > 1 && $connection->getNativeConnection()->inTransaction(), 'Real callback runs after admission of the nested owning command frame');
            $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
            verify(MySQLConnection::snapshotIsolation($connection) === true, 'Real owned SESSION change is observable after initial admission');
            $_SESSION['vlan_current_read_attempt'] = $kind;
            ++$flips;
        };
    }
    $fixtures = new FixtureRecords($writer);
    $prefix = 'VLAN current admission ' . bin2hex(random_bytes(5));
    $computer = $fixtures->create(Computer::getTable(), ['name' => $prefix]);
    $port = $fixtures->create(NetworkPort::getTable(), ['name' => $prefix . ' port', 'itemtype' => Computer::class, 'items_id' => $computer]);
    $port2 = $fixtures->create(NetworkPort::getTable(), ['name' => $prefix . ' second port', 'itemtype' => Computer::class, 'items_id' => $computer]);
    $vlan = $fixtures->create(Vlan::getTable(), ['name' => $prefix . ' VLAN']);
    $vlan2 = $fixtures->create(Vlan::getTable(), ['name' => $prefix . ' second VLAN']);
    $identity = (new NetworkPort_Vlan())->assignVlan($port, $vlan, 0);
    verify(is_int($identity) && $identity > 0, 'Real public command creates its owning membership');
    $manager = Orm::create($writer);
    try {
        verify($manager->getConnection() === $connection, 'ORM preserves the supplied application connection');
        $repository = new NetworkPortVlanRepository($manager);
        verify($repository->membership($identity) === ['id' => $identity, 'networkports_id' => $port, 'vlans_id' => $vlan, 'tagged' => false], 'Ordinary ORM projection sees the writer uncommitted identity');
        verify($repository->membership($identity, current: true)['id'] === $identity && $repository->selectedPair($port, $vlan, current: true)['id'] === $identity, 'Both current membership entry points retain the real selected relation identity');
        verify($repository->currentPort($port)['id'] === $port && $repository->currentVlan($vlan)['id'] === $vlan, 'Native current endpoint queries run on the admitted supplied writer');
        verify($repository->containsEntity(0, 0), 'Entity ancestry includes the actual root-zero parent');
    } finally {
        $manager->clear();
    }

    // The selected missing parent is absent on the actual writer, not a guessed orphan.
    verify($connection->fetchOne('SELECT id FROM glpi_networkports WHERE id = ?', [PHP_INT_MAX]) === false, 'Native FK missing port is proved absent');
    $selected = array_values(array_filter($connection->createSchemaManager()->listTableForeignKeys(NetworkPort_Vlan::getTable()), static fn ($key): bool => $key->getLocalColumns() === ['networkports_id'] && $key->getForeignTableName() === NetworkPort::getTable()));
    verify(count($selected) === 1, 'Select the actual owning port constraint');
    $before = $snapshot();
    $invalid = OwnedMutationFrame::begin($connection);
    $refusal = null;
    $nativePrimary = null;
    try {
        $connection->insert(NetworkPort_Vlan::getTable(), ['networkports_id' => PHP_INT_MAX, 'vlans_id' => $vlan, 'tagged' => 0]);
    } catch (ForeignKeyConstraintViolationException $error) {
        $refusal = $error;
        $nativePrimary = $error;
    } catch (Throwable $error) {
        $nativePrimary = $error;
        throw $error;
    } finally {
        try {
            $invalid->rollBack();
        } catch (Throwable $error) {
            throw $nativePrimary === null ? $error : new MutationCleanupFailure($nativePrimary, $error, true);
        }
    }
    verify($refusal !== null && str_contains($refusal->getMessage(), $selected[0]->getName()), 'Actual configured driver classifies the selected native FK failure');
    verify($snapshot() === $before, 'Native refusal preserves complete endpoint/membership/history/queue bags and raw receipts');
    $frame->assertActive();

    if ($originalCapability !== null) {
        foreach (['add', 'update', 'purge'] as $phase) {
            $model = new NetworkPort_Vlan();
            verify($model->getFromDB($identity), 'Load the real existing membership before callback ' . $phase);
            $beforeFields = $model->fields;
            $beforeSession = $_SESSION;
            $before = $snapshot();
            $operation = match ($phase) {
                'add' => static fn () => (new NetworkPort_Vlan())->assignVlan($port2, $vlan2, 1),
                'update' => static fn () => $model->update(['id' => $identity, 'tagged' => 1]),
                'purge' => static fn () => $model->unassignVlan($port, $vlan),
            };
            $failure = null;
            try {
                $operation();
            } catch (CurrentReadUnavailable $error) {
                $failure = $error;
            }
            verify($failure !== null, 'Actual public callback change refuses before incompatible pessimistic SQL: ' . $phase);
            $frame->assertActive();
            verify($connection->getTransactionNestingLevel() === 1 && $connection->getNativeConnection()->inTransaction(), 'Caller physical transaction survives the refused command');
            verify($snapshot() === $before && $model->fields === $beforeFields, 'Owned rollback preserves every selected row and loaded model');
            verify($_SESSION === $beforeSession, 'Owned rollback removes callback Session effects');
            verify(MySQLConnection::snapshotIsolation($connection) === true, 'Refusal does not secretly repair the caller SESSION policy');
            $phase = '';
            $connection->executeStatement('SET SESSION innodb_snapshot_isolation = OFF');
            verify(MySQLConnection::snapshotIsolation($connection) === $originalCapability, 'Test owner explicitly restores its captured SESSION capability');
            $result = $operation();
            verify($result !== false && $result !== null, 'The same real public command succeeds after explicit owner reset');
            if (is_int($result)) {
                verify((new NetworkPort_Vlan())->unassignVlan($port2, $vlan2), 'Remove only the successful retry assignment through public lifecycle');
            } elseif ($connection->fetchOne('SELECT id FROM glpi_networkports_vlans WHERE id = ?', [$identity]) === false) {
                $identity = (new NetworkPort_Vlan())->assignVlan($port, $vlan, 0);
                verify(is_int($identity) && $identity > 0, 'Recreate only the successfully removed control through public lifecycle');
            } else {
                verify($model->update(['id' => $identity, 'tagged' => 0]), 'Restore the successful tagged retry through public lifecycle');
            }
        }
        verify($flips === 3, 'All three actual lifecycle callbacks changed the owned SESSION');

        $before = $snapshot();
        $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
        $manager = Orm::create($writer);
        try {
            $repository = new NetworkPortVlanRepository($manager);
            foreach ([
                static fn () => $repository->membership($identity, current: true),
                static fn () => $repository->selectedPair($port, $vlan, current: true),
                static fn () => $repository->currentPort($port),
                static fn () => $repository->currentVlan($vlan),
                static fn () => $repository->containsEntity(0, 0),
            ] as $lockingRead) {
                $refused = false;
                try {
                    $lockingRead();
                } catch (CurrentReadUnavailable) {
                    $refused = true;
                }
                verify($refused && MySQLConnection::snapshotIsolation($connection) === true, 'Every direct owning repository pessimistic read admits the actual SESSION without changing it');
                $frame->assertActive();
                verify($connection->getNativeConnection()->inTransaction() && $snapshot() === $before, 'Direct read refusal preserves physical caller frame and complete data');
            }
            verify($repository->membership($identity)['id'] === $identity, 'Ordinary nonlocking ORM reads retain supplied read routing without requiring current-read admission');
        } finally {
            $manager->clear();
        }
        $connection->executeStatement('SET SESSION innodb_snapshot_isolation = OFF');
        verify(MySQLConnection::snapshotIsolation($connection) === $originalCapability, 'Explicit final reset restores the original owned SESSION capability');
    } else {
        echo $writer->getProvider() . ": mutable MySQL snapshot capability is absent; native public/ORM/FK controls ran, SESSION-change cases are inapplicable.\n";
    }
    verify((new SchemaCheck())->differences($connection) === [], 'No schema changes were introduced by owning read admission');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        $frame->rollBack();
        $rolledBack = true;
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        if ($rolledBack && $GLOBALS['DB'] === $writer && $writer->getDoctrineConnection() === $connection
            && $connection->getTransactionNestingLevel() === 0 && !$connection->getNativeConnection()->inTransaction()) {
            if ($originalCapability !== null) {
                $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ?', [$originalCapability ? 1 : 0], [ParameterType::INTEGER]);
                verify(MySQLConnection::snapshotIsolation($connection) === $originalCapability, 'Cleanup restores only its captured SESSION policy');
            }
            verify($snapshot() === $beforeAll, 'Outer owning rollback restores all original data and raw migration receipts');
            verify((new SchemaCheck())->differences($connection) === [], 'Final canonical schema remains exact');
        } else {
            throw new RuntimeException('VLAN current-read fixture cannot inspect or reset an unproven writer scope');
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $plugins->setValue(null, $savedPlugins);
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI['use_notifications'] = $savedNotifications;
    $_SESSION = $savedSession;
}
foreach ($cleanup as $error) {
    $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error, true);
}
if ($primary !== null) {
    throw $primary;
}
echo 'VLAN current-read admission: ' . $assertions . " assertions passed\n";
