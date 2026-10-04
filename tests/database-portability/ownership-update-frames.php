<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\TransactionOwnershipMismatch;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/ownership-update-frames.php /path/to/test-config\n");
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

/** The fixture may end only the caller frame it actually captured. */
function withinCaller(DBAdapter $database, callable $operation): void
{
    $connection = $database->getDoctrineConnection();
    $connection->beginTransaction();
    $caller = $database->captureManagedTransactionScope();
    $primary = null;
    try {
        $operation($caller);
    } catch (Throwable $error) {
        $primary = $error;
    } finally {
        try {
            $caller->assertActive();
            $connection->rollBack();
        } catch (Throwable $cleanup) {
            $primary = $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
        }
    }
    if ($primary !== null) {
        throw $primary;
    }
}

/** Actual public update callback; no alternate persistence pipeline. */
class OwnershipFrameSupplier extends Supplier
{
    public static ?Throwable $failure = null;
    public static int $callbacks = 0;

    public static function getTable($classname = null)
    {
        return Supplier::getTable();
    }

    public static function getType()
    {
        return Supplier::getType();
    }

    public function post_updateItem($history = 1)
    {
        parent::post_updateItem($history);
        ++self::$callbacks;
        if (self::$failure !== null) {
            throw self::$failure;
        }
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable ownership update database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$savedSession = $_SESSION;
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Fixture owns an initially idle supplied writer');
$seed = (new FixtureRecords($DB))->create('glpi_suppliers', ['name' => 'Owned frame ' . bin2hex(random_bytes(6))]);
$model = new OwnershipFrameSupplier();
verify($model->getFromDB($seed), 'Load actual public Supplier model');
$originalFields = $model->fields;
$nativeRows = static function () use ($connection): array {
    $rows = [];
    foreach (['glpi_suppliers', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
        $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
    }
    return $rows;
};
$before = $nativeRows();
$primary = null;
$cleanup = [];
$handlerInstalled = false;
try {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        return false;
    });
    $handlerInstalled = true;
    foreach (['absent', 'null'] as $inputState) {
        if ($inputState === 'absent') {
            unset($model->input);
        } else {
            $model->input = null;
        }
        $checkpoint = LifecycleModelJournal::state($model);
        withinCaller($DB, static function ($caller) use ($DB, $model, $seed, $originalFields, $connection, $nativeRows, $before, $checkpoint, $inputState): void {
            verify(OwnershipUpdateUnit::run($DB, $model, $originalFields, static function () use ($model, $seed): bool {
                verify($model->update(['id' => $seed, 'name' => 'Cancelled public supplier update']), 'Actual public update persists inside the owning frame');
                $_SESSION['ownership_frame_effect'] = 'cancelled';
                Session::addMessageAfterRedirect('Ownership fixture refusal', true, ERROR, false);
                return false;
            }) === false, 'Actual cancellation rolls back only its owned savepoint');
            $caller->assertActive();
            verify($connection->getTransactionNestingLevel() === 1 && $nativeRows() === $before, 'Cancelled public lifecycle restores native supplier, history and queue while caller stays usable');
            $restored = LifecycleModelJournal::state($model);
            verify(array_key_exists('input', $restored) === ($inputState === 'null')
                && ($inputState !== 'null' || $restored['input'] === null), 'Checkpoint preserves absent versus supplied null input without a property warning');
            verify($restored['fields'] === $checkpoint['fields'] && $restored['updates'] === [] && $restored['oldvalues'] === [], 'Proven owned rollback restores original model fields and clears pending writes');
            verify(!isset($_SESSION['ownership_frame_effect'])
                && in_array('Ownership fixture refusal', $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [], true), 'Rollback restores session while retaining useful refusal feedback');
        });
    }

    $actualCallbackFailure = new RuntimeException('Actual public Supplier post-update failure');
    OwnershipFrameSupplier::$failure = $actualCallbackFailure;
    $callbacks = OwnershipFrameSupplier::$callbacks;
    try {
        OwnershipUpdateUnit::run($DB, $model, $originalFields, static fn (): bool => $model->update(['id' => $seed, 'name' => 'Failing public supplier update']));
        throw new LogicException('Public callback failure must propagate');
    } catch (RuntimeException $error) {
        verify($error === $actualCallbackFailure && OwnershipFrameSupplier::$callbacks === $callbacks + 1, 'Exact original Throwable from real public callback survives successful owned cleanup');
    } finally {
        OwnershipFrameSupplier::$failure = null;
    }
    verify($nativeRows() === $before && $model->fields === $originalFields
        && $connection->getTransactionNestingLevel() === 0, 'Failed real lifecycle restores native rows and model after proven rollback');

    withinCaller($DB, static function ($caller) use ($DB, $model, $seed, $originalFields, $connection): void {
        verify(OwnershipUpdateUnit::run($DB, $model, $originalFields, static fn (): bool => $model->update(['id' => $seed, 'name' => 'Accepted public supplier update'])), 'Accepted public lifecycle releases its own savepoint');
        $caller->assertActive();
        verify($model->fields['name'] === 'Accepted public supplier update'
            && $connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id = ?', [$seed]) === 'Accepted public supplier update', 'Accepted state remains current inside actual caller frame');
    });
    verify($model->getFromDB($seed), 'Reload after caller deliberately rolls back its own accepted savepoint');
    verify($nativeRows() === $before, 'Caller rollback removes its supplier update and actual audit effects');

    // These continuity controls perform no application DML. A rogue callback's
    // physical COMMIT is not reversible; retained state must not claim otherwise.
    foreach (['commit', 'rollBack'] as $endFrame) {
        $replacement = null;
        $casePrimary = null;
        $actualPrimary = new RuntimeException('Ownership callback removed its frame through ' . $endFrame);
        try {
            OwnershipUpdateUnit::run($DB, $model, $originalFields, static function () use ($connection, $endFrame, $model, $actualPrimary, &$replacement): bool {
                $model->fields['name'] = 'Known callback state after ' . $endFrame;
                $_SESSION['ownership_frame_effect'] = $endFrame;
                $connection->$endFrame();
                $connection->beginTransaction();
                $replacement = $connection->captureManagedTransactionScope();
                throw $actualPrimary;
            });
            throw new LogicException('Removed owning frame must refuse cleanup');
        } catch (Throwable $error) {
            try {
                if (!$error instanceof MutationRollbackFailure) {
                    throw $error;
                }
                verify($error->primary === $actualPrimary && $error->cleanup instanceof TransactionOwnershipMismatch
                    && $error->rollbackUnproven, 'Actual callback primary and scope refusal both remain inspectable');
                verify($connection->getTransactionNestingLevel() === 1 && $replacement !== null, 'Cleanup leaves the same-depth replacement frame untouched');
                $replacement->assertActive();
                verify($model->fields['name'] === 'Known callback state after ' . $endFrame
                    && $_SESSION['ownership_frame_effect'] === $endFrame, 'Unproved rollback cannot authorize a model or session rewind');
                verify($nativeRows() === $before, 'No-DML continuity control leaves every native row unchanged');
            } catch (Throwable $verificationError) {
                $casePrimary = $verificationError;
            }
        } finally {
            try {
                if ($replacement !== null) {
                    $replacement->assertActive();
                    $connection->rollBack(); // Only this fixture owns the replacement it created.
                }
                unset($_SESSION['ownership_frame_effect']);
                verify($model->getFromDB($seed), 'Explicit reload resolves retained model state after refused replacement cleanup');
            } catch (Throwable $cleanupError) {
                $casePrimary = $casePrimary === null ? $cleanupError : new MutationCleanupFailure($casePrimary, $cleanupError);
            }
        }
        if ($casePrimary !== null) {
            throw $casePrimary;
        }
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($handlerInstalled) {
        restore_error_handler();
    }
    try {
        $connection->close(); // Fixture starts idle and owns every deliberately created frame.
        $connection->delete('glpi_suppliers', ['id' => $seed]);
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $_SESSION = $savedSession;
}
if ($primary !== null) {
    fwrite(STDERR, (string)$primary . "\n");
    foreach ($cleanup as $error) {
        fwrite(STDERR, 'Additional ownership update fixture cleanup failure: ' . (string)$error . "\n");
    }
    exit(1);
}
if ($cleanup) {
    throw new RuntimeException('Ownership update fixture cleanup failed.', previous: $cleanup[0]);
}
echo $DB->getProvider() . ": $assertions ownership update frame, real callback, absent/null and rollback contracts passed.\n";
