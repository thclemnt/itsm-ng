<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Shared physical transaction outcomes; only a disposable installed database. */
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\Supplier;
use itsmng\Database\Orm;
use itsmng\Database\PostgresConnection;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php postgresql-transactions.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
function expectState(callable $operation, string $state, string $message): void
{
    try {
        $operation();
    } catch (DriverException $error) {
        verify($error->getSQLState() === $state, $message);
        return;
    }
    throw new RuntimeException($message . ': no driver exception.');
}
function expectInactive(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (NoActiveTransaction $error) {
        verify(true, $message);
        return;
    }
    throw new RuntimeException($message . ': no NoActiveTransaction exception.');
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required.');
$connection = $DB->getDoctrineConnection();
verify($DB->getVersion() === $connection->getServerVersion(), 'Adapter version uses its supplied Doctrine connection.');
if ($DB->getProvider() !== 'pgsql') {
    verify(!$connection instanceof PostgresConnection, 'Other providers retain their own transaction connection.');
    expectInactive(fn () => $connection->commit(), 'Other-provider no-active transaction behavior is unchanged.');
    echo "$assertions MySQL/MariaDB boundary assertions; PostgreSQL-specific cases are inapplicable.\n";
    exit(0);
}
verify($connection instanceof PostgresConnection, 'PostgreSQL uses the shared transaction outcome connection.');
verify(!$connection->isTransactionActive(), 'Contract starts outside a transaction.');
$pid = (int)$connection->fetchOne('SELECT pg_backend_pid()');
$result = $DB->query('SELECT pg_backend_pid()');
verify($DB->fetchRow($result)[0] === $pid, 'Legacy and Doctrine queries share the same physical backend.');
$DB->freeResult($result);
verify($DB->getVersion() === $connection->fetchOne("SELECT current_setting('server_version')"), 'Version is the selected physical server version.');
$timezone = $connection->fetchOne("SELECT current_setting('TimeZone')");
$searchPath = $connection->fetchOne("SELECT current_setting('search_path')");
$applicationName = $connection->fetchOne("SELECT current_setting('application_name')");
$endpoint = $connection->fetchAssociative('SELECT current_database() AS database_name, current_user AS user_name, inet_server_addr()::text AS server_address, inet_server_port() AS server_port');
$table = 'itsm_pg_transaction_outcomes';
$connection->executeStatement("CREATE TEMPORARY TABLE $table (marker TEXT NOT NULL)");
$rawActive = false;
$extra = null;
try {
    // Direct DBAL must refuse the successful-looking PostgreSQL aborted COMMIT.
    $connection->beginTransaction();
    $connection->insert($table, ['marker' => 'direct aborted']);
    expectState(fn () => $connection->executeStatement('SELECT 1 / 0'), '22012', 'Real database failure aborts the outer transaction.');
    expectState(fn () => $connection->commit(), '25P02', 'Direct DBAL commit refuses an aborted transaction.');
    verify($connection->getTransactionNestingLevel() === 1, 'Refused DBAL commit preserves nesting and rollback capability.');
    $connection->rollBack();
    verify((int)$connection->fetchOne("SELECT COUNT(*) FROM $table") === 0, 'Explicit rollback removes pre-error writes.');

    // A hook may swallow its original SQL exception; transaction completion
    // must still reject the physical failure and let DBAL roll back the work.
    expectState(fn () => $connection->transactional(static function () use ($connection, $table): void {
        $connection->insert($table, ['marker' => 'swallowed failure']);
        expectState(fn () => $connection->executeStatement('SELECT 1 / 0'), '22012', 'Callback observes and catches its statement failure.');
    }), '25P02', 'DBAL transactional completion refuses a swallowed statement error.');
    verify(!$connection->isTransactionActive()
        && (int)$connection->fetchOne("SELECT COUNT(*) FROM $table") === 0, 'DBAL transactional rolls back after commit refusal.');

    // Exercise actual ORM persistence/query failure on the same outer transaction.
    $em = Orm::create($DB);
    verify($em->getConnection() === $connection, 'ORM retains the supplied transaction owner.');
    $connection->beginTransaction();
    $supplier = new Supplier();
    $supplier->entities = $em->getReference(Entity::class, 0);
    $supplier->name = 'Postgres transaction ownership ' . bin2hex(random_bytes(8));
    $em->persist($supplier);
    $em->flush();
    $supplierId = $supplier->id;
    verify($supplierId > 0, 'ORM flush writes inside the caller-owned outer transaction.');
    expectState(fn () => $em->createQuery('SELECT e.id / 0 FROM ' . Entity::class . ' e WHERE e.id = 0')->getSingleScalarResult(), '22012', 'ORM query failure reaches the shared physical transaction.');
    expectState(fn () => $em->getConnection()->commit(), '25P02', 'ORM-supplied connection refuses aborted commit.');
    verify($connection->getTransactionNestingLevel() === 1, 'ORM commit refusal preserves outer rollback.');
    $connection->rollBack();
    $em->clear();
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_suppliers WHERE id = ?', [$supplierId]) === 0, 'ORM persistence rolls back with the physical transaction.');

    // The adapter preserves false specifically for aborted transactions.
    $DB->beginTransaction();
    $connection->insert($table, ['marker' => 'legacy aborted']);
    expectState(fn () => $connection->executeStatement('SELECT 1 / 0'), '22012', 'Legacy-owned transaction is really aborted.');
    verify($DB->commit() === false, 'Legacy adapter maps aborted-transaction refusal to false.');
    verify($DB->inTransaction() && $connection->getTransactionNestingLevel() === 1, 'Legacy refusal retains shared transaction state.');
    $DB->rollBack();
    verify((int)$connection->fetchOne("SELECT COUNT(*) FROM $table") === 0, 'Legacy rollback removes its pre-error writes.');

    // Recovering a failed nested savepoint must not poison the outer transaction.
    $connection->beginTransaction();
    $connection->insert($table, ['marker' => 'outer recovered']);
    $connection->beginTransaction();
    $connection->insert($table, ['marker' => 'inner discarded']);
    expectState(fn () => $connection->executeStatement('SELECT 1 / 0'), '22012', 'Nested SQL failure aborts the current savepoint.');
    expectState(fn () => $connection->commit(), '25P02', 'Failed nested commit refuses before releasing or losing the savepoint.');
    verify($connection->getTransactionNestingLevel() === 2, 'Failed nested commit preserves both nesting levels.');
    $connection->rollBack();
    verify($connection->getTransactionNestingLevel() === 1, 'Savepoint rollback recovers the outer transaction.');
    $connection->insert($table, ['marker' => 'after recovery']);
    verify($DB->commit() === true, 'Recovered outer transaction commits through the adapter.');
    verify($connection->fetchFirstColumn("SELECT marker FROM $table ORDER BY marker") === ['after recovery', 'outer recovered'], 'Recovered commit retains outer/new writes and discards the inner write.');

    // A raw BEGIN changes physical state without changing DBAL nesting.
    verify($DB->query('BEGIN') === true, 'Legacy raw BEGIN remains supported.');
    $rawActive = true;
    verify(!$connection->isTransactionActive(), 'Raw BEGIN does not pretend to be DBAL-owned nesting.');
    expectState(fn () => $connection->executeStatement('SELECT 1 / 0'), '22012', 'Raw transaction is physically aborted.');
    expectInactive(fn () => $connection->commit(), 'Direct DBAL preserves NoActiveTransaction precedence at nesting zero.');
    verify($DB->commit() === false, 'Legacy raw aborted transaction refuses before DBAL no-active delegation.');
    verify($DB->query('ROLLBACK') === true, 'Raw aborted transaction remains explicitly recoverable.');
    $rawActive = false;
    verify($DB->query('BEGIN') === true, 'Healthy raw transaction starts.');
    $rawActive = true;
    expectInactive(fn () => $DB->commit(), 'Healthy raw transaction retains existing DBAL no-active refusal.');
    verify($DB->query('ROLLBACK') === true, 'Healthy raw transaction remains available for explicit rollback.');
    $rawActive = false;
    expectInactive(fn () => $DB->commit(), 'Idle legacy adapter still reports NoActiveTransaction.');
    expectInactive(fn () => $connection->commit(), 'Idle direct DBAL still reports NoActiveTransaction.');

    verify((int)$connection->fetchOne('SELECT pg_backend_pid()') === $pid, 'Guards never create an auxiliary connection.');
    verify($connection->fetchOne("SELECT current_setting('TimeZone')") === $timezone
        && $connection->fetchOne("SELECT current_setting('search_path')") === $searchPath
        && $connection->fetchOne("SELECT current_setting('application_name')") === $applicationName, 'Guard/version ownership preserves selected session settings.');

    // Use fresh configured adapters, never clones sharing a live native handle.
    $fresh = static function () use ($DB): DBpgsql {
        $adapter = (new ReflectionClass($DB))->newInstanceWithoutConstructor();
        verify($adapter->connect() === true, 'Exclusive configured probe connection opens.');
        return $adapter;
    };
    $extra = $fresh();
    $cold = $extra->getDoctrineConnection();
    verify(!$cold->isConnected(), 'Constructing the facade alone retains lazy driver wrapping.');
    expectInactive(fn () => $cold->commit(), 'Inactive direct commit does not probe or connect the cold facade.');
    verify(!$cold->isConnected(), 'No-active precedence leaves the facade unconnected.');
    verify($extra->close() === true && !$extra->connected, 'Cold facade close releases its native handle.');
    verify($extra->close() === false, 'Repeated closed-adapter close retains its existing result.');
    verify($extra->connect() === true, 'Closed adapter reconnects using its own endpoint.');
    $warm = $extra->getDoctrineConnection();
    verify($warm !== $cold && $extra->getVersion() === $warm->getServerVersion(), 'Reconnect creates a new facade with vendor-owned version reporting.');
    verify(Orm::create($extra)->getConnection() === $warm && $warm !== $connection, 'ORM retains an explicitly supplied secondary connection without selecting the global writer.');
    verify($warm->isConnected(), 'Version retrieval wraps the existing selected native handle.');
    verify($extra->close() === true && !$warm->isConnected(), 'Warm facade close closes its shared driver connection.');
    verify($extra->connect() === true, 'Warm-close reconnect restores selected connection/session initialization.');
    $outage = $extra->getDoctrineConnection();
    $outagePid = (int)$outage->fetchOne('SELECT pg_backend_pid()');
    verify($outagePid !== $pid, 'Outage probe owns a separate backend from the application test connection.');
    $outage->beginTransaction();
    verify((bool)$connection->fetchOne('SELECT pg_terminate_backend(?)', [$outagePid]), 'Terminate only this contract-owned secondary backend.');
    try {
        $extra->commit();
        throw new RuntimeException('Connection loss was reported as commit success or an aborted-transaction false.');
    } catch (DriverException $error) {
        verify($error->getSQLState() !== '25P02', 'Connection loss propagates instead of being mapped to legacy false.');
        verify($error instanceof \Doctrine\DBAL\Exception\ConnectionLost, 'Owned backend termination exercises DBAL automatic close.');
    }
    verify(!$outage->isConnected(), 'DBAL automatically closes its lost physical driver.');
    verify($extra->close() === true && !$extra->connected, 'Explicit adapter close after automatic DBAL close avoids double-closing its handle.');
    verify($extra->connect() === true, 'Explicit reconnect opens a new physical handle after connection loss.');
    $reconnected = $extra->getDoctrineConnection();
    verify($reconnected !== $outage && !$reconnected->isConnected()
        && !$reconnected->getDriver()->hasTransferredConnection(), 'New untransferred handle has new ownership state after prior automatic close.');
    verify($extra->close() === true, 'New cold handle is still closed after a previous handle transferred ownership.');
    verify($extra->connect() === true, 'The adapter remains reusable after cold reclose.');
    verify($extra->getDoctrineConnection()->fetchAssociative('SELECT current_database() AS database_name, current_user AS user_name, inet_server_addr()::text AS server_address, inet_server_port() AS server_port') === $endpoint, 'Reconnect retains the configured writer database, user and endpoint.');
    verify((int)$connection->fetchOne('SELECT pg_backend_pid()') === $pid, 'Application connection survives the exclusive outage probe.');
} finally {
    if ($extra !== null) {
        $extra->close();
    }
    while ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    if ($rawActive) {
        $DB->query('ROLLBACK');
    }
    $connection->executeStatement("DROP TABLE $table");
}
echo "$assertions PostgreSQL transaction/server-version assertions passed.\n";
