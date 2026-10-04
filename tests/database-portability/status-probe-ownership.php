<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Glpi\System\Status\StatusChecker;
use itsmng\Database\DatabaseHealthProbe;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/status-probe-ownership.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/** The inherited configuration and real public connect remain the transport authority. */
class HealthOwnershipAdapter extends DB
{
    public static array $observations = [];
    public static ?int $privateComputer = null;

    public function retainBorrowedOwner(\Doctrine\DBAL\Connection $connection): void
    {
        $this->doctrine = $connection;
    }

    public function connect($choice = null)
    {
        $connected = parent::connect($choice);
        if ($connected) {
            $connection = $this->getDoctrineConnection();
            self::$observations[] = [
                'adapter' => $this,
                'connection' => $connection,
                'physical_id' => $connection->fetchOne($this->getProvider() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()'),
                'params' => $connection->getParams(),
                'slave' => $this->isSlave(),
                'choice' => $choice,
                'private_count' => self::$privateComputer === null ? null : (int)$connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier('glpi_computers') . ' WHERE id = ?',
                    [self::$privateComputer]
                ),
            ];
        }
        return $connected;
    }
}

class HealthOwnershipFaultAdapter extends HealthOwnershipAdapter
{
    public ?Throwable $primaryFailure = null;
    private bool $faultAfterConnect = false;

    public function connect($choice = null)
    {
        $connected = parent::connect($choice);
        if ($connected) {
            $this->faultAfterConnect = true;
            throw ($this->primaryFailure ?? new LogicException('The health fault control requires its original error'));
        }
        return $connected;
    }

    public function close()
    {
        $closed = parent::close();
        if ($this->faultAfterConnect) {
            $this->faultAfterConnect = false;
            throw new LogicException('Owned health cleanup fixture failure');
        }
        return $closed;
    }
}

verify($DB instanceof DBAdapter && str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable configured database required');
$caller = $DB;
$connection = $caller->getDoctrineConnection();
$caller->assertManagedTransaction();
verify(!$connection->isTransactionActive(), 'Health contract starts with its idle configured owner');
$native = $connection->getNativeConnection();
$physicalQuery = $caller->getProvider() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()';
$physicalId = $connection->fetchOne($physicalQuery);
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedRequests = $SQL_TOTAL_REQUEST;
$savedDebug = $DEBUG_SQL;
$savedRouting = $caller->slave;
$baselineSchema = (new SchemaCheck())->inspect($connection)->differences;
$snapshot = static function () use ($connection): array {
    $result = [];
    foreach (['glpi_computers', 'glpi_logs', 'glpi_queuednotifications', 'itsmng_migrations'] as $table) {
        $result[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table)
            . ' ORDER BY ' . $connection->quoteIdentifier($table === 'itsmng_migrations' ? 'version' : 'id'));
    }
    return $result;
};
$baseline = $snapshot();
$frame = null;
$nested = null;
$primary = null;
$cleanup = [];
$assertCaller = static function (int $depth) use ($caller, $connection, $native, $physicalId, $physicalQuery): void {
    verify($GLOBALS['DB'] === $caller && $caller->getDoctrineConnection() === $connection, 'Public health leaves the actual supplied adapter and DBAL owner in place');
    verify($connection->getNativeConnection() === $native && $connection->fetchOne($physicalQuery) === $physicalId, 'Public health retains the actual supplied physical session');
    verify($connection->getTransactionNestingLevel() === $depth, 'Public health retains caller frame depth');
    $caller->assertManagedTransaction();
};
$factory = static function (bool $slave = false): HealthOwnershipAdapter {
    $adapter = (new ReflectionClass(HealthOwnershipAdapter::class))->newInstanceWithoutConstructor();
    $adapter->slave = $slave;
    return $adapter;
};
$assertProbesClosed = static function () use ($connection, $physicalId): void {
    verify(HealthOwnershipAdapter::$observations !== [], 'The selected public health callback really opens its independent configured probe');
    foreach (HealthOwnershipAdapter::$observations as $observation) {
        verify($observation['connection'] !== $connection && $observation['physical_id'] !== $physicalId, 'Health inspection uses a distinct actual native connection');
        verify($observation['params'] === $connection->getParams(), 'Healthy probes retain the complete configured database, charset and TLS parameters privately');
        verify(!$observation['adapter']->connected && !$observation['connection']->isConnected(), 'Health closes each probe transport after inspection');
        verify($observation['private_count'] === 0, 'Health does not adopt or commit the caller private row');
    }
};
try {
    $frame = OwnedMutationFrame::begin($connection);
    $fixtures = new FixtureRecords($caller);
    $computer = $fixtures->create('glpi_computers', ['name' => 'Private health ownership ' . bin2hex(random_bytes(8))]);
    HealthOwnershipAdapter::$privateComputer = $computer;
    $default = StatusChecker::getDBStatus();
    verify($default['master']['status'] === StatusChecker::STATUS_OK, 'The unchanged public configured health entry point admits the installed master');
    $full = StatusChecker::getFullStatus(true);
    verify(is_array($full) && $full['db']['master']['status'] === StatusChecker::STATUS_OK
        && is_string(StatusChecker::getFullStatus(true, false)), 'Real public aggregate health preserves array and text status formats');
    $frame->assertActive();
    $assertCaller(1);

    $lazyBorrowed = $factory();
    $lazyBorrowed->retainBorrowedOwner($connection);
    verify(!$lazyBorrowed->connected, 'The borrowed-owner negative control has no connected flag');
    try {
        StatusChecker::getDBStatus(true, new DatabaseHealthProbe(static fn () => $lazyBorrowed));
        throw new RuntimeException('A retained lazy owner was accepted as a disposable probe');
    } catch (LogicException $error) {
        verify($error->getMessage() === 'A health transport factory must return a new disconnected adapter.', 'Health rejects an existing owner before adapter connect can close it');
    }
    verify($lazyBorrowed->getDoctrineConnection() === $connection, 'Refusal leaves the borrowed owner attached without closing or adopting it');
    $assertCaller(1);

    $healthy = new DatabaseHealthProbe(static fn () => $factory());
    $status = StatusChecker::getDBStatus(true, $healthy);
    verify($status === ['status' => StatusChecker::STATUS_OK, 'master' => ['status' => StatusChecker::STATUS_OK],
        'slaves' => ['status' => StatusChecker::STATUS_NO_DATA, 'servers' => []]], 'Healthy public status preserves its exact ordinary shape');
    $assertCaller(1);
    $assertProbesClosed();

    $missingDatabase = 'itsm_port_health_missing_' . bin2hex(random_bytes(8));
    $refused = new DatabaseHealthProbe(static function () use ($factory, $missingDatabase): DBAdapter {
        $adapter = $factory();
        $adapter->dbdefault = $missingDatabase;
        return $adapter;
    });
    $status = StatusChecker::getDBStatus(true, $refused);
    verify($status['master']['status'] === StatusChecker::STATUS_PROBLEM && $status['status'] === StatusChecker::STATUS_PROBLEM,
        'An actual independently refused database reports unavailable without replacing the caller');
    $assertCaller(1);
    $frame->assertActive();

    $fault = (new ReflectionClass(HealthOwnershipFaultAdapter::class))->newInstanceWithoutConstructor();
    $faultPrimary = new DomainException('Owned health connect fixture failure');
    $fault->primaryFailure = $faultPrimary;
    try {
        StatusChecker::getDBStatus(true, new DatabaseHealthProbe(static fn () => $fault));
        throw new RuntimeException('Unexpected probe failure was suppressed');
    } catch (DomainException $error) {
        verify($error === $faultPrimary && !$fault->connected, 'Owned close failure preserves the exact original health callback error and releases its transport');
    }
    $assertCaller(1);

    $withoutConfiguration = StatusChecker::getDBStatus(true, new DatabaseHealthProbe(static fn () => null));
    verify($withoutConfiguration['master']['status'] === StatusChecker::STATUS_PROBLEM, 'Missing startup configuration is an availability result');
    $borrowed = new DatabaseHealthProbe(static fn () => $caller);
    try {
        StatusChecker::getDBStatus(true, $borrowed);
        throw new RuntimeException('A borrowed application transport was accepted as an owned health probe');
    } catch (LogicException $error) {
        verify($error->getMessage() === 'A health transport factory must return a new disconnected adapter.', 'Borrowed transport refusal is the explicit owning boundary');
    }
    $assertCaller(1);

    $caller->slave = true;
    $readerStatus = StatusChecker::getDBStatus(true, $healthy);
    verify($caller->isSlave() && $readerStatus['master']['status'] === StatusChecker::STATUS_OK, 'A caller selected for reads retains its route while a distinct master is inspected');
    $caller->slave = $savedRouting;
    $replicas = new DatabaseHealthProbe(static fn () => $factory(), static fn (int|string $position) => $factory(true), [3, 'configured_read']);
    $status = StatusChecker::getDBStatus(true, $replicas);
    verify($status['slaves']['status'] === StatusChecker::STATUS_OK && count($status['slaves']['servers']) === 2
        && $status['slaves']['servers'][3]['replication_delay'] === 0 && $status['slaves']['servers']['configured_read']['replication_delay'] === 0,
        'Distinct configured read probes retain equal-history replica status and positions');
    verify(count(array_filter(HealthOwnershipAdapter::$observations, static fn (array $observation): bool => $observation['slave'])) === 2,
        'Both replica positions are actually inspected through read-routed independent transports');
    verify(array_keys($status['slaves']['servers']) === [3, 'configured_read']
        && array_column(array_values(array_filter(HealthOwnershipAdapter::$observations, static fn (array $observation): bool => $observation['slave'])), 'choice') === [3, 'configured_read'],
        'Configured host keys and native connect selectors retain their original order without renumbering');
    $assertCaller(1);
    $assertProbesClosed();

    $nested = OwnedMutationFrame::begin($connection);
    $nestedComputer = $fixtures->create('glpi_computers', ['name' => 'Nested private health ownership ' . bin2hex(random_bytes(8))]);
    StatusChecker::getDBStatus(true, $healthy);
    $nested->assertActive();
    $assertCaller(2);
    $nested->rollBack();
    $nested = null;
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_computers WHERE id = ?', [$nestedComputer]) === 0
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_computers WHERE id = ?', [$computer]) === 1,
        'Health leaves nested rollback and outer private data under the actual caller owner');
    $frame->assertActive();
    $frame->rollBack();
    $frame = null;
    $assertCaller(0);
    verify($snapshot() === $baseline, 'Caller rollback restores the complete protected rows and migration ledger');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    foreach (['nested', 'frame'] as $owned) {
        if ($$owned !== null) {
            try {
                $$owned->rollBack();
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
    }
    $caller->slave = $savedRouting;
    foreach (HealthOwnershipAdapter::$observations as $observation) {
        if ($observation['adapter']->connected) {
            try {
                $observation['adapter']->close();
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
    }
    try {
        $assertCaller(0);
        verify($snapshot() === $baseline, 'Health inspection and failure cleanup preserve all protected native rows');
        verify((new SchemaCheck())->inspect($connection)->differences === $baselineSchema, 'Health inspection performs no schema installation or migration changes');
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $SQL_TOTAL_REQUEST = $savedRequests;
    $DEBUG_SQL = $savedDebug;
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Secondary health cleanup failure: ' . $error::class . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
echo "Database health transport ownership assertions passed\n";
