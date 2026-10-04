<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DeletionOutcome;
use itsmng\Database\DeletionUnit;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\ManagedTransactionConnection;
use itsmng\Database\MySQLConnection;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\PostgresConnection;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Domain\TransferCoordinator;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/managed-transactions.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function refuse(callable $operation, string $message): void
{
    try {
        $operation();
        throw new LogicException($message);
    } catch (TransactionOwnershipMismatch) {
        verify(true, $message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable managed ownership database required');
$connection = $DB->getDoctrineConnection();
verify($connection instanceof ManagedTransactionConnection && !$connection->isTransactionActive(), 'Canonical managed owner starts logically idle');
$DB->assertManagedTransaction();
$postgres = $DB->getProvider() === 'pgsql';
$observer = $postgres ? PostgresConnection::create($connection->getParams()) : MySQLConnection::create($connection->getParams());
$fixtures = new FixtureRecords($DB);
$seed = null;
$session = $_SESSION;
$primary = null;
$cleanup = [];
$rawOwned = false;
try {
    $prefix = 'Managed raw caller ' . bin2hex(random_bytes(5));
    $seed = $fixtures->create('glpi_suppliers', ['name' => $prefix]);
    $model = new Supplier();
    verify($model->getFromDB($seed), 'Load actual public model before raw caller transaction');
    $model->input = ['guard_marker' => 'unchanged'];
    $stored = LifecycleModelJournal::state($model);
    $queued = $connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id');
    foreach (['BEGIN', 'START TRANSACTION'] as $command) {
        $rawOwned = $DB->query($command) === true;
        verify($rawOwned && $connection->getTransactionNestingLevel() === 0, 'Actual raw SQL changes physical state without DBAL ownership');
        $marker = $prefix . ' ' . $command;
        $connection->update('glpi_suppliers', ['name' => $marker], ['id' => $seed]);
        verify($observer->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $prefix, 'Caller marker is genuinely uncommitted on an independent physical connection');
        $called = false;
        refuse($DB->assertManagedTransaction(...), 'Supplied adapter diagnoses unmanaged BEGIN before taking ownership');
        refuse(static fn () => OwnershipUpdateUnit::run($DB, $model, $model->fields, static function () use (&$called): bool {
            $called = true;
            return true;
        }), 'Shared update boundary refuses before journal/barrier/operation');
        refuse(static fn () => DeletionUnit::run($connection, static function () use (&$called): DeletionOutcome {
            $called = true;
            return DeletionOutcome::Deleted;
        }), 'Deletion boundary refuses before its frame and operation');
        refuse(static fn () => (new TransferCoordinator($DB))->run(static function () use (&$called): bool {
            $called = true;
            return true;
        }), 'Direct actual transfer coordinator refuses without taking caller ownership');
        $transfer = new Transfer();
        $transferState = LifecycleModelJournal::state($transfer);
        verify(
            $transfer->moveItems([], 0, []) === false && LifecycleModelJournal::state($transfer) === $transferState,
            'Actual public transfer refuses before its model journal/coordinator mutation'
        );
        verify(!$called && !DeletionUnit::isActive($connection) && $connection->getTransactionNestingLevel() === 0
            && LifecycleModelJournal::state($model) === $stored, 'No callback, logical frame, deletion frame or public-model mutation');
        verify(
            $connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $marker
            && $observer->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $prefix
            && $connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id') === $queued,
            'Refusal neither commits nor rolls back caller data nor changes the notification queue'
        );
        verify($DB->query('ROLLBACK') === true, 'Only the raw caller ends its transaction');
        $rawOwned = false;
        verify($connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $prefix, 'Explicit caller rollback remains effective');
        $DB->assertManagedTransaction();
    }
    if ($postgres) {
        $rawOwned = $DB->query('BEGIN') === true;
        verify($rawOwned, 'Actual PostgreSQL raw failure frame');
        try {
            $connection->executeQuery('SELECT 1/0')->free();
            throw new LogicException('Expected actual PostgreSQL failure');
        } catch (Doctrine\DBAL\Exception\DriverException $error) {
            verify($error->getSQLState() === '22012', 'Actual driver division-by-zero establishes aborted caller frame');
        }
        refuse($DB->assertManagedTransaction(...), 'PDO state guard diagnoses aborted raw ownership without diagnostic SQL');
        verify($connection->getTransactionNestingLevel() === 0 && $DB->query('ROLLBACK') === true, 'Aborted raw caller remains explicitly recoverable');
        $rawOwned = false;
    }
    $connection->beginTransaction();
    $DB->assertManagedTransaction();
    verify(DeletionUnit::run($connection, static fn (): DeletionOutcome => DeletionOutcome::Cancelled)->outcome === DeletionOutcome::Cancelled
        && $connection->getTransactionNestingLevel() === 1, 'Balanced managed savepoint cancellation preserves outer caller frame');
    $connection->rollBack();
    $DB->assertManagedTransaction();
    $connection->beginTransaction();
    verify($DB->query('ROLLBACK') === true, 'Actual raw control removes a logically managed physical transaction');
    refuse($DB->assertManagedTransaction(...), 'Logical-active/physical-idle mismatch refuses before another frame');
    $connection->close(); // Explicit owner reset; there is no surviving caller transaction in this case.
    $DB->assertManagedTransaction();
    verify($connection->getTransactionNestingLevel() === 0, 'Physical reconnect re-establishes canonical idle ownership');
    $params = $connection->getParams();
    unset($params['wrapperClass'], $params['driverClass']);
    $params['driver'] = $postgres ? 'pdo_pgsql' : 'pdo_mysql';
    $unknown = Doctrine\DBAL\DriverManager::getConnection($params);
    try {
        refuse(static fn () => TransactionOwnership::assertManaged($unknown), 'Unknown owner capability refuses instead of assuming idle');
        verify(!$unknown->isConnected(), 'Unknown-owner refusal does not create a physical connection');
    } finally {
        $unknown->close();
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        if ($rawOwned) {
            $DB->query('ROLLBACK'); // Only a raw frame deliberately opened by this fixture.
        }
        $connection->close();
        if ($seed !== null) {
            $connection->delete('glpi_suppliers', ['id' => $seed]);
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        $observer->close();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $_SESSION = $session;
}
if ($primary !== null) {
    fwrite(STDERR, (string)$primary . "\n");
    foreach ($cleanup as $error) {
        fwrite(STDERR, 'Additional owned fixture cleanup failure: ' . (string)$error . "\n");
    }
    exit(1);
}
if ($cleanup) {
    throw new RuntimeException('Managed ownership fixture cleanup failed.', previous: $cleanup[0]);
}
echo $DB->getProvider() . ": physical/logical ownership, real raw BEGIN/START, refusal, managed savepoints and reconnect passed.\n";
