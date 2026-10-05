<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\TransactionIsolationLevel;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\Repository\KanbanRepository;
use itsmng\Database\Repository\SoftwareAssignmentRepository;
use itsmng\Database\Repository\SoftwareInstallationRepository;
use itsmng\Database\Repository\SoftwareRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/mysql-current-read-session.php /path/to/test-config\n");
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
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}

final class SessionPolicyLicense extends SoftwareLicense
{
    public static int $writes = 0;

    public static function getTable($classname = null)
    {
        return SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return SoftwareLicense::getType();
    }

    public function updateInDB($updates, $oldvalues = [])
    {
        ++self::$writes;
        return parent::updateInDB($updates, $oldvalues);
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
if ($DB->getProvider() === 'pgsql') {
    echo "pgsql: MySQL/MariaDB session policy is inapplicable; existing PostgreSQL current-read and strong-snapshot contracts remain unchanged.\n";
    exit(0);
}
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$originalAdapter = $DB;
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$savedNotifications = $CFG_GLPI['use_notifications'];
$CFG_GLPI['use_notifications'] = false;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'current_read_session_fixture']);
$hookCalls = 0;
$hook = static function ($model) use (&$hookCalls): void {
    ++$hookCalls;
};
$PLUGIN_HOOKS['pre_item_update']['current_read_session_fixture'][SessionPolicyLicense::class] = $hook;
$PLUGIN_HOOKS['pre_item_purge']['current_read_session_fixture'][Project::class] = $hook;
$adapter = (new ReflectionClass($originalAdapter))->newInstanceWithoutConstructor();
$other = (new ReflectionClass($originalAdapter))->newInstanceWithoutConstructor();
$created = [];
$primary = null;
$cleanup = [];
$originalIsolation = null;
$external = null;
$globalCapability = $originalAdapter->getDoctrineConnection()->fetchAllNumeric("SHOW GLOBAL VARIABLES WHERE Variable_name = 'innodb_snapshot_isolation'");
try {
    verify($adapter->connect() === true && $other->connect() === true, 'Two full configured application writers connect');
    $connection = $adapter->getDoctrineConnection();
    $secondary = $other->getDoctrineConnection();
    verify($connection->getParams() === $originalAdapter->getDoctrineConnection()->getParams()
        && $secondary->getParams() === $connection->getParams(), 'Writer clones preserve all actual configured TLS and endpoint parameters');
    verify($connection->fetchOne('SELECT CONNECTION_ID()') !== $secondary->fetchOne('SELECT CONNECTION_ID()'), 'Distinct physical writers');
    foreach (['initial', 'same-DBAL reconnect', 'adapter reconnect'] as $phase) {
        if ($phase === 'same-DBAL reconnect') {
            $connection->close();
        } elseif ($phase === 'adapter reconnect') {
            $adapter->close();
            verify($adapter->connect() === true, 'Explicit adapter reconnect');
            $connection = $adapter->getDoctrineConnection();
        }
        verify(MySQLConnection::snapshotIsolation($connection) !== true, 'Every actual physical admission establishes the current-read capability: ' . $phase);
        MySQLConnection::assertStrict($connection);
    }
    $GLOBALS['DB'] = $adapter;
    $fixtures = new FixtureRecords($adapter);
    $record = static function (string $table, array $values) use ($fixtures, &$created): int {
        $id = $fixtures->create($table, $values);
        $created[] = [$table, $id];
        return $id;
    };
    $prefix = 'Session current read ' . bin2hex(random_bytes(5));
    $project = $record('glpi_projects', ['name' => $prefix]);
    $software = $record('glpi_softwares', ['name' => $prefix, 'entities_id' => 0]);
    $license = $record('glpi_softwarelicenses', ['name' => $prefix, 'entities_id' => 0, 'softwares_id' => $software, 'number' => 2, 'is_valid' => 1]);
    $historyBefore = $connection->fetchFirstColumn('SELECT id FROM glpi_logs');
    $accepted = new SessionPolicyLicense();
    verify($accepted->update(['id' => $license, 'number' => 3]) && $hookCalls > 0 && SessionPolicyLicense::$writes > 0,
        'Supported actual public command proves its real persistence and registered lifecycle hook');
    foreach (array_diff($connection->fetchFirstColumn('SELECT id FROM glpi_logs'), $historyBefore) as $id) {
        $created[] = ['glpi_logs', (int)$id];
    }
    $hookCalls = SessionPolicyLicense::$writes = 0;
    $originalIsolation = $connection->getTransactionIsolation();
    $connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
    $connection->beginTransaction();
    $connection->fetchOne('SELECT COUNT(*) FROM glpi_items_kanbans');
    $secondary->insert('glpi_items_kanbans', ['itemtype' => Project::class, 'items_id' => $project,
        'users_id' => Session::getLoginUserID(), 'state' => '{"current":"kept"}']);
    $board = (int)$secondary->lastInsertId();
    $created[] = ['glpi_items_kanbans', $board];
    $repository = new KanbanRepository(Orm::create($adapter));
    verify(array_map('intval', array_column($repository->statesForItem(Project::class, $project), 'id')) === [$board], 'Actual RR locking projection sees a board committed after its snapshot');
    $connection->rollBack();

    if (MySQLConnection::snapshotIsolation($connection) !== null) {
        $connection->beginTransaction();
        $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' caller marker']);
        $scope = $connection->captureManagedTransactionScope();
        $model = new SessionPolicyLicense();
        verify($model->getFromDB($license), 'Public licence command loads its real owner');
        $stored = $model->fields;
        $snapshot = static function () use ($connection, $project, $software, $license): array {
            return [
                $connection->fetchAllAssociative('SELECT * FROM glpi_projects WHERE id = ?', [$project]),
                $connection->fetchAllAssociative('SELECT * FROM glpi_softwares WHERE id = ?', [$software]),
                $connection->fetchAllAssociative('SELECT * FROM glpi_softwarelicenses WHERE id = ?', [$license]),
                $connection->fetchAllAssociative('SELECT * FROM glpi_items_kanbans WHERE items_id = ? AND itemtype = ? ORDER BY id', [$project, Project::class]),
                $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id'),
                $connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id'),
                $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version'),
            ];
        };
        $before = $snapshot();
        // Deliberately change this owned caller session, never the GLOBAL default.
        $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
        try {
            $connection->beginTransaction();
            throw new LogicException('Incompatible nested frame was admitted');
        } catch (CurrentReadUnavailable) {
            verify($connection->getTransactionNestingLevel() === 1, 'Refused nested admission never changes caller depth');
        }
        verify(!$model->update(['id' => $license, 'number' => 4]) && SessionPolicyLicense::$writes === 0 && $hookCalls === 0
            && $model->fields === $stored, 'Actual public software refusal precedes persistence and preserves its loaded model');
        try {
            (new Project())->delete(['id' => $project], true);
            throw new LogicException('Incompatible public purge was admitted');
        } catch (CurrentReadUnavailable) {
            verify($snapshot() === $before && $hookCalls === 0, 'Public purge refuses before lifecycle hooks/writes, history or queue changes');
        }
        $manager = Orm::create($adapter);
        foreach ([
            static fn () => (new KanbanRepository($manager))->statesForItem(Project::class, $project),
            static fn () => (new SoftwareAssignmentRepository($manager))->lockLicenses([$license]),
            static fn () => (new SoftwareInstallationRepository($manager))->itemTypes(true, $license, currentRead: true),
            static fn () => (new SoftwareRepository($manager))->hasInvalidLicense($software, currentRead: true),
        ] as $currentRead) {
            try {
                $currentRead();
                throw new LogicException('Incompatible direct ORM current read was admitted');
            } catch (CurrentReadUnavailable) {
                $scope->assertActive();
                verify($snapshot() === $before && MySQLConnection::snapshotIsolation($connection) === true,
                    'Direct current-read refusal preserves all native rows, original ledger and caller-selected capability');
            }
        }
        verify($connection->getNativeConnection()->inTransaction() && $connection->getTransactionNestingLevel() === 1
            && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_suppliers WHERE id = ?', [$marker]) === 1,
            'Caller marker and actual physical frame remain usable; no1020 abort is disguised by logical depth');
        $connection->rollBack();
        verify((int)$secondary->fetchOne('SELECT COUNT(*) FROM glpi_suppliers WHERE id = ?', [$marker]) === 0, 'Actual caller rollback removes its marker');
        $connection->executeStatement('SET SESSION innodb_snapshot_isolation = OFF');
        $connection->beginTransaction();
        try {
            verify($model->update(['id' => $license, 'number' => 4]) && SessionPolicyLicense::$writes > 0 && $hookCalls > 0,
                'Restoring the supported session permits the same actual public command and lifecycle');
            verify((int)$connection->fetchOne('SELECT number FROM glpi_softwarelicenses WHERE id = ?', [$license]) === 4,
                'Supported public retry persists its actual licence quantity');
        } finally {
            $connection->rollBack();
        }
        verify($snapshot() === $before, 'Caller rollback removes the accepted retry and its actual history or queue effects');
    }
    verify($originalAdapter->getDoctrineConnection()->fetchAllNumeric("SHOW GLOBAL VARIABLES WHERE Variable_name = 'innodb_snapshot_isolation'") === $globalCapability, 'Shared server capability/default is unchanged');
    // An ordinary DBAL caller cannot acquire a domain frame by reporting depth.
    $parameters = $connection->getParams();
    unset($parameters['wrapperClass']);
    $external = \Doctrine\DBAL\DriverManager::getConnection($parameters, $connection->getConfiguration());
    if (MySQLConnection::snapshotIsolation($external) !== null) {
        $external->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
    }
    $external->beginTransaction();
    $external->insert('glpi_suppliers', ['name' => $prefix . ' unknown owner marker']);
    $externalMarker = (int)$external->lastInsertId();
    try {
        $unownedManager = new \Doctrine\ORM\EntityManager($external, Orm::configuration($external->getDatabasePlatform()));
        (new SoftwareAssignmentRepository($unownedManager))->lockLicenses([$license]);
        throw new LogicException('Unknown supplied physical transaction was admitted');
    } catch (\itsmng\Database\TransactionOwnershipMismatch) {
        verify($external->getNativeConnection()->inTransaction() && $external->getTransactionNestingLevel() === 1
            && (int)$external->fetchOne('SELECT COUNT(*) FROM glpi_suppliers WHERE id = ?', [$externalMarker]) === 1,
            'Unknown caller refuses before native locking without aborting its transaction or marker');
    }
    $external->rollBack();
    verify((int)$secondary->fetchOne('SELECT COUNT(*) FROM glpi_suppliers WHERE id = ?', [$externalMarker]) === 0, 'Unknown caller retains authority for its real rollback');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($external !== null) {
        try {
            if ($external->getNativeConnection()->inTransaction()) {
                $external->rollBack();
            }
            $external->close();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    try {
        if (isset($connection) && $connection->getNativeConnection()->inTransaction()) {
            $connection->rollBack();
        }
        if (isset($connection) && MySQLConnection::snapshotIsolation($connection) !== null) {
            $connection->executeStatement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        if (isset($connection) && $originalIsolation !== null) {
            $connection->setTransactionIsolation($originalIsolation);
        }
        foreach (array_reverse($created) as [$table, $id]) {
            $adapter->getDoctrineConnection()->delete($table, ['id' => $id]);
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $GLOBALS['DB'] = $originalAdapter;
    $_SESSION = $savedSession;
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI['use_notifications'] = $savedNotifications;
    $plugins->setValue(null, $savedPlugins);
    foreach ([$other, $adapter] as $owned) {
        try {
            $owned->close();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
}
if ($primary !== null) {
    throw $cleanup === [] ? $primary : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup[0]);
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
echo "mysql: physical current-read admission/reconnect, public refusals and caller ownership: $assertions assertions passed.\n";
