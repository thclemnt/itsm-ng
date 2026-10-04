<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DeletionOutcome;
use itsmng\Database\DeletionCancelled;
use itsmng\Database\DeletionUnit;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\LifecycleNotifications;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\TransactionOwnershipMismatch;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/deletion-owned-frames.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Actual lifecycle callback, including actual child audit and persisted writes. */
class DeletionFrameSupplier extends Supplier
{
    public static ?Throwable $failure = null;
    public static int $callbacks = 0;
    public static bool $vetoPurge = false;

    public static function getTable($classname = null)
    {
        return Supplier::getTable();
    }

    public static function getType()
    {
        return Supplier::getType();
    }

    public function pre_deleteItem()
    {
        return !self::$vetoPurge && parent::pre_deleteItem();
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

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable deletion frame database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$savedSession = $_SESSION;
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Fixture owns an initially idle supplied writer');
$seed = null;
$model = new DeletionFrameSupplier();
$primary = null;
$cleanup = [];
$handlerInstalled = false;
$replacement = null;
$caller = null;
$nativeRows = static function () use ($connection): array {
    $rows = [];
    foreach (['glpi_suppliers', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
        $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
    }
    return $rows;
};
try {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        return false;
    });
    $handlerInstalled = true;
    $seed = (new FixtureRecords($DB))->create('glpi_suppliers', ['name' => 'Deletion frame ' . bin2hex(random_bytes(6))]);
    verify($model->getFromDB($seed), 'Load actual participating child model');
    $before = $nativeRows();
    foreach (['absent', 'null'] as $inputState) {
        if ($inputState === 'absent') {
            unset($model->input);
        } else {
            $model->input = null;
        }
        $checkpoint = LifecycleModelJournal::state($model);
        $restores = 0;
        $connection->beginTransaction();
        $caller = $connection->captureManagedTransactionScope();
        $result = DeletionUnit::run($connection, static function () use ($connection, $model, $seed): DeletionOutcome {
            verify(DeletionUnit::isActive($connection), 'Actual deletion frame authorizes its participating public child update');
            verify($model->update(['id' => $seed, 'name' => 'Cancelled deletion child']), 'Public child update and actual hooks run inside deletion frame');
            verify(LifecycleNotifications::defer($connection, Supplier::class, $seed), 'Deletion barrier defers child delivery');
            DeletionUnit::requireSuccess($connection, false);
            throw new LogicException('Refused child must interrupt parent operation');
        }, static function () use (&$restores): void {
            ++$restores;
        });
        $caller->assertActive();
        verify($result->outcome === DeletionOutcome::Cancelled && $restores === 1, 'Required child veto rolls back its own frame and restores parent once');
        verify($nativeRows() === $before && $connection->getTransactionNestingLevel() === 1, 'Actual child rows, audit and queues roll back while original caller remains active');
        verify(LifecycleModelJournal::state($model) === $checkpoint, 'Participating model journal preserves absent versus explicit null input and all pending fields');
        verify(!DeletionUnit::isActive($connection), 'Completed cancelled unit retires its authority');
        $connection->rollBack();
        $caller = null;
    }

    $restores = [];
    $result = DeletionUnit::run($connection, static function () use ($connection, $model, $seed, &$restores): DeletionOutcome {
        $child = DeletionUnit::run($connection, static function () use ($model, $seed): DeletionOutcome {
            verify($model->update(['id' => $seed, 'name' => 'Nested veto child']), 'Nested public child write genuinely executes');
            return DeletionOutcome::Cancelled;
        }, static function () use (&$restores): void {
            $restores[] = 'child';
        });
        verify($child->outcome === DeletionOutcome::Cancelled && DeletionUnit::isActive($connection), 'Nested cancellation retires child and retains original parent authority');
        return DeletionOutcome::Deleted; // A swallowed child veto still cancels the parent.
    }, static function () use (&$restores): void {
        $restores[] = 'parent';
    });
    verify($result->outcome === DeletionOutcome::Cancelled && $restores === ['child', 'parent'] && $nativeRows() === $before, 'Nested veto propagates and restores each actual owned frame exactly once');

    $actualPrimary = new RuntimeException('Actual deletion child post-update failure');
    $secondary = new RuntimeException('Actual parent model restoration callback failure');
    DeletionFrameSupplier::$failure = $actualPrimary;
    $callbacks = DeletionFrameSupplier::$callbacks;
    $checkpoint = LifecycleModelJournal::state($model);
    try {
        DeletionUnit::run($connection, static function () use ($model, $seed): DeletionOutcome {
            $model->update(['id' => $seed, 'name' => 'Throwing deletion child']);
            return DeletionOutcome::Deleted;
        }, static function () use ($secondary): void {
            throw $secondary;
        });
        throw new LogicException('Actual callback and restoration failures must propagate');
    } catch (MutationCleanupFailure $error) {
        verify($error->primary === $actualPrimary && $error->cleanup === $secondary && !$error->rollbackUnproven, 'Actual original callback failure survives separately inspectable restoration failure');
        verify(DeletionFrameSupplier::$callbacks === $callbacks + 1 && $nativeRows() === $before
            && LifecycleModelJournal::state($model) === $checkpoint, 'Proven rollback restores child before independent failing parent cleanup');
    } finally {
        DeletionFrameSupplier::$failure = null;
    }

    $actualCancellation = null;
    $secondary = new RuntimeException('Actual cancelled parent restoration failure');
    $checkpoint = LifecycleModelJournal::state($model);
    try {
        DeletionUnit::run($connection, static function () use ($connection, $model, $seed, &$actualCancellation): DeletionOutcome {
            verify($model->update(['id' => $seed, 'name' => 'Required cancelled child before cleanup failure']), 'Actual child write precedes required veto and failing restoration');
            try {
                DeletionUnit::requireSuccess($connection, false);
            } catch (DeletionCancelled $cancelled) {
                $actualCancellation = $cancelled;
                throw $cancelled;
            }
            throw new LogicException('Actual required veto must throw its cancellation');
        }, static function () use ($secondary): void {
            throw $secondary;
        });
        throw new LogicException('Cancellation cleanup failure must propagate both actual errors');
    } catch (MutationCleanupFailure $error) {
        verify(
            $actualCancellation instanceof DeletionCancelled && $error->primary === $actualCancellation
            && $error->cleanup === $secondary && $error->getPrevious() === $actualCancellation && !$error->rollbackUnproven,
            'The actual first required-child cancellation survives an independent restoration failure'
        );
        verify($nativeRows() === $before && LifecycleModelJournal::state($model) === $checkpoint
            && !DeletionUnit::isActive($connection), 'Cancellation cleanup failure follows proven native/model rollback and retired authority');
    }

    // Legitimate deeper owner layers retain deletion authority; balanced nested
    // savepoints must not be confused with replacement of the captured frame.
    $connection->beginTransaction();
    $caller = $connection->captureManagedTransactionScope();
    $restores = 0;
    $result = DeletionUnit::run($connection, static function () use ($connection, $model, $seed): DeletionOutcome {
        $connection->beginTransaction();
        verify(DeletionUnit::isActive($connection), 'Deletion scope remains valid across a genuine deeper managed layer');
        $connection->commit();
        verify($model->update(['id' => $seed, 'name' => 'Accepted child under caller']), 'Accepted deletion participant uses actual public update');
        return DeletionOutcome::ScopedDetachment;
    }, static function () use (&$restores): void {
        ++$restores;
    });
    $caller->assertActive();
    verify($result->outcome === DeletionOutcome::ScopedDetachment && $restores === 0
        && $connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id = ?', [$seed]) === 'Accepted child under caller', 'Accepted owned savepoint retains state and never rewinds caller-owned work');
    $connection->rollBack();
    $caller = null;
    verify($model->getFromDB($seed) && $nativeRows() === $before, 'Caller alone rolls back its accepted savepoint and child audit');

    $connection->beginTransaction();
    $caller = $connection->captureManagedTransactionScope();
    DeletionFrameSupplier::$vetoPurge = true;
    verify(!$model->delete(['id' => $seed], 1) && $nativeRows() === $before, 'Actual public purge veto preserves complete native child, history and queue state');
    DeletionFrameSupplier::$vetoPurge = false;
    verify($model->delete(['id' => $seed], 1), 'Actual public purge uses the modern deletion owner and original lifecycle hooks');
    verify($connection->fetchOne('SELECT id FROM glpi_suppliers WHERE id = ?', [$seed]) === false, 'Accepted public purge removes the actual selected row inside caller frame');
    verify(!$model->delete(['id' => $seed], 1), 'Repeated public purge remains an idempotent missing-row refusal');
    $caller->assertActive();
    $connection->rollBack();
    $caller = null;
    verify($model->getFromDB($seed) && $nativeRows() === $before, 'Only original caller rollback restores its accepted public purge and audit effects');

    // No application DML: a callback's commit is irreversible, and a replacement
    // frame must survive refused cleanup. The fixture alone owns its replacement.
    foreach (['commit', 'rollBack', 'close'] as $endFrame) {
        $restores = 0;
        $reentered = false;
        $actualPrimary = new RuntimeException('Deletion callback lost frame through ' . $endFrame);
        $casePrimary = null;
        try {
            try {
                DeletionUnit::run($connection, static function () use ($connection, $model, $endFrame, $actualPrimary, &$replacement, &$reentered): DeletionOutcome {
                    LifecycleModelJournal::capture($connection, $model);
                    $model->fields['name'] = 'Retained state after ' . $endFrame;
                    $connection->$endFrame();
                    $connection->beginTransaction();
                    $replacement = $connection->captureManagedTransactionScope();
                    try {
                        DeletionUnit::run($connection, static function () use (&$reentered): DeletionOutcome {
                            $reentered = true;
                            return DeletionOutcome::Deleted;
                        });
                        throw new LogicException('Stale parent must refuse reentrant deletion before acquiring a frame');
                    } catch (TransactionOwnershipMismatch) {
                        // Admission refusal is required before callback, barrier or BEGIN.
                    }
                    throw $actualPrimary;
                }, static function () use (&$restores): void {
                    ++$restores;
                });
                throw new LogicException('Lost deletion frame must refuse cleanup');
            } catch (MutationRollbackFailure $error) {
                verify($error->primary === $actualPrimary && $error->cleanup instanceof TransactionOwnershipMismatch && $error->rollbackUnproven, 'Primary actual callback and lost-owner rollback refusal remain inspectable');
                $replacement->assertActive();
                verify(!$reentered && $restores === 0 && $connection->getTransactionNestingLevel() === 1
                    && $model->fields['name'] === 'Retained state after ' . $endFrame, 'Stale same-depth or reconnected frame cannot authorize SQL cleanup, model rewind or reentrant callbacks');
                verify(!DeletionUnit::isActive($connection), 'Failed deletion scope retires without retaining stale authority');
            }
        } catch (Throwable $error) {
            $casePrimary = $error;
        } finally {
            if ($replacement !== null) {
                try {
                    $replacement->assertActive();
                    $connection->rollBack();
                    $replacement = null;
                } catch (Throwable $cleanupError) {
                    $casePrimary = $casePrimary === null ? $cleanupError : new MutationCleanupFailure($casePrimary, $cleanupError);
                }
            }
        }
        if ($casePrimary !== null) {
            throw $casePrimary;
        }
        verify($model->getFromDB($seed) && $nativeRows() === $before, 'Fixture explicitly reloads after no-DML owner-loss control');
    }
    $restores = 0;
    $result = DeletionUnit::run($connection, static fn (): DeletionOutcome => DeletionOutcome::Deleted, static function () use (&$restores): void {
        ++$restores;
    });
    verify($result->outcome === DeletionOutcome::Deleted && $restores === 0 && !DeletionUnit::isActive($connection)
        && !LifecycleNotifications::defer($connection, Supplier::class, $seed), 'Fresh terminal unit commits once, restores nothing and retires notification/authority scopes');
    verify($nativeRows() === $before, 'All frame controls preserve complete native supplier, history and queue vectors');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    DeletionFrameSupplier::$failure = null;
    DeletionFrameSupplier::$vetoPurge = false;
    foreach ([$replacement, $caller] as $owned) {
        if ($owned !== null) {
            try {
                $owned->assertActive();
                $connection->rollBack();
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
    }
    if ($seed !== null) {
        try {
            $connection->assertManagedTransaction();
            if ($connection->getTransactionNestingLevel() !== 0) {
                throw new TransactionOwnershipMismatch('Fixture cannot delete its seed through an unretired caller or replacement frame.');
            }
            $connection->delete('glpi_suppliers', ['id' => $seed]);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    $_SESSION = $savedSession;
    if ($handlerInstalled) {
        restore_error_handler();
    }
}
if ($primary !== null || $cleanup) {
    $first = $primary ?? $cleanup[0];
    try {
        fwrite(STDERR, (string)$first . "\n");
        foreach ($cleanup as $error) {
            fwrite(STDERR, 'Additional deletion fixture cleanup failure: ' . (string)$error . "\n");
        }
    } catch (Throwable) {
        // Diagnostic failure cannot turn the actual failed contract into success.
    }
    exit(1);
}
echo $DB->getProvider() . ": $assertions deletion owner frame, child callback, veto and restoration contracts passed.\n";
