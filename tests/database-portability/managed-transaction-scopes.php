<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DeletionOutcome;
use itsmng\Database\DeletionUnit;
use itsmng\Database\ManagedTransactionConnection;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Domain\TransferCoordinator;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/managed-transaction-scopes.php /path/to/test-config\n");
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
function expired(callable $operation, string $message): void
{
    try {
        $operation();
        throw new LogicException($message);
    } catch (TransactionOwnershipMismatch) {
        verify(true, $message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable managed scope database required');
$connection = $DB->getDoctrineConnection();
verify($connection instanceof ManagedTransactionConnection && !$connection->isTransactionActive(), 'Supplied canonical owner starts idle');
$seed = null;
$primary = null;
$cleanup = [];
try {
    expired($DB->captureManagedTransactionScope(...), 'Idle connection cannot manufacture a frame scope');
    $seed = (new FixtureRecords($DB))->create('glpi_suppliers', ['name' => 'Managed scope ' . bin2hex(random_bytes(5))]);
    $model = new Supplier();
    verify($model->getFromDB($seed), 'Load actual public model for deeper lifecycle frames');
    $physical = WeakReference::create($connection->getNativeConnection());
    $connection->beginTransaction();
    $outer = $DB->captureManagedTransactionScope();
    $outerAgain = $connection->captureManagedTransactionScope();
    $connection->beginTransaction();
    $inner = $connection->captureManagedTransactionScope();
    $connection->beginTransaction();
    $deep = $connection->captureManagedTransactionScope();
    $outer->assertActive();
    $inner->assertActive();
    $deep->assertActive();
    $connection->commit();
    expired($deep->assertActive(...), 'Actual inner commit invalidates only its removed frame');
    $inner->assertActive();
    $outer->assertActive();
    $connection->rollBack();
    expired($inner->assertActive(...), 'Actual inner rollback invalidates that frame');
    $outer->assertActive();
    $outerAgain->assertActive();
    verify(OwnershipUpdateUnit::run($DB, $model, $model->fields, static function () use ($outer): bool {
        $outer->assertActive();
        return true;
    }), 'Actual balanced nested ownership lifecycle retains outer capability');
    verify(DeletionUnit::run($connection, static function () use ($outer): DeletionOutcome {
        $outer->assertActive();
        return DeletionOutcome::Cancelled;
    })->outcome === DeletionOutcome::Cancelled, 'Actual cancelled nested deletion retains outer capability');
    verify((new TransferCoordinator($DB))->run(static function () use ($outer): bool {
        $outer->assertActive();
        return true;
    }) === true, 'Actual balanced direct transfer retains outer capability');
    $outer->assertActive();
    $connection->rollBack();
    expired($outer->assertActive(...), 'Ending the captured outer frame invalidates it');
    expired($outerAgain->assertActive(...), 'All scopes of the removed actual frame expire');
    $connection->beginTransaction();
    $replacement = $connection->captureManagedTransactionScope();
    verify($connection->getTransactionNestingLevel() === 1 && $physical->get() === $connection->getNativeConnection(),
        'Same logical depth and same physical PDO are real, not a new connection fixture');
    expired($outer->assertActive(...), 'Same-depth DBAL rollback/reopen cannot revive the old frame identity');
    $replacement->assertActive();
    $connection->commit();
    expired($replacement->assertActive(...), 'Actual outer commit ends its captured scope');
    $connection->beginTransaction();
    $closed = $connection->captureManagedTransactionScope();
    $connection->close();
    expired($closed->assertActive(...), 'Owner close immediately invalidates captured frame');
    verify(!$connection->isConnected(), 'Expired scope refusal does not reconnect the closed owner');
    $DB->assertManagedTransaction();
    verify($physical->get() !== $connection->getNativeConnection(), 'Reconnection replaces the actual PDO identity');
    $connection->beginTransaction();
    $reconnected = $connection->captureManagedTransactionScope();
    expired($closed->assertActive(...), 'A new physical owner/frame cannot revive a closed scope');
    $reconnected->assertActive();
    $connection->rollBack();
    expired($reconnected->assertActive(...), 'Reconnected managed rollback removes its own frame');
    $connection->setAutoCommit(false);
    $automatic = $connection->captureManagedTransactionScope();
    $connection->commit();
    $automaticReplacement = $connection->captureManagedTransactionScope();
    verify($connection->getTransactionNestingLevel() === 1, 'Actual DBAL non-autocommit immediately replaces its outer frame');
    expired($automatic->assertActive(...), 'Automatic same-depth commit/reopen expires the original frame');
    $automaticReplacement->assertActive();
    $connection->rollBack();
    expired($automaticReplacement->assertActive(...), 'Automatic rollback/reopen also expires the original frame');
    $afterAutomaticRollback = $connection->captureManagedTransactionScope();
    $afterAutomaticRollback->assertActive();
    $connection->setAutoCommit(true);
    expired($afterAutomaticRollback->assertActive(...), 'Restoring autocommit ends the actual outstanding frame');
    verify(!$connection->isTransactionActive(), 'Autocommit policy restored through the actual owner API');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        $connection->close(); // This idle-at-admission fixture owns every frame it deliberately created.
        if ($seed !== null) {
            $connection->delete('glpi_suppliers', ['id' => $seed]);
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    fwrite(STDERR, (string)$primary . "\n");
    foreach ($cleanup as $error) {
        fwrite(STDERR, 'Additional owned scope cleanup failure: ' . (string)$error . "\n");
    }
    exit(1);
}
if ($cleanup) {
    throw new RuntimeException('Managed frame scope cleanup failed.', previous: $cleanup[0]);
}
echo $DB->getProvider() . ": opaque actual frame identities, deeper public lifecycles, same-depth replacement, close and reconnect passed.\n";
