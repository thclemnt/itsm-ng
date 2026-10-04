<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\OwnershipUpdateUnit;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/lifecycle-writer-capture.php /path/to/test-config\n");
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
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class CapturedWriterRefusal extends RuntimeException
{
}

/** Test owner composes existing public validation/execution seams, not a new writer. */
trait CapturedWriterProbe
{
    private ?DBAdapter $selectedWriter = null;
    public array $events = [];

    protected function captureLifecycleWriter(DBAdapter $writer): void
    {
        parent::captureLifecycleWriter($writer);
        $this->selectedWriter = $writer;
        $this->events = ['capture'];
    }

    public function writer(): ?DBAdapter
    {
        return $this->selectedWriter;
    }

    private function assertSelectedWriter(): void
    {
        if ($this->selectedWriter !== ($GLOBALS['DB'] ?? null) || $this->selectedWriter->isSlave()) {
            throw new CapturedWriterRefusal('The selected public lifecycle writer changed.');
        }
    }

    public function post_getFromDB()
    {
        $this->events[] = 'load';
        parent::post_getFromDB();
    }

    public function prepareInputForAdd($input)
    {
        $this->assertSelectedWriter();
        $this->events[] = 'prepare_add';
        return parent::prepareInputForAdd($input);
    }

    protected function assertLifecycleUpdateContext(bool $persisted): void
    {
        $this->assertSelectedWriter();
        parent::assertLifecycleUpdateContext($persisted);
    }

    protected function executePreparedAdd(callable $operation, array $priorState): mixed
    {
        $this->assertSelectedWriter();
        $result = false;
        $accepted = OwnershipUpdateUnit::run($this->selectedWriter, $this, $priorState['fields'] ?? [], function () use ($operation, $priorState, &$result): bool {
            $result = parent::executePreparedAdd($operation, $priorState);
            $this->assertSelectedWriter();
            return $result !== false && $result !== null && $result !== 0;
        });
        return $accepted ? $result : false;
    }

    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        $this->assertSelectedWriter();
        return OwnershipUpdateUnit::run($this->selectedWriter, $this, $storedFields, function () use ($operation, $storedFields): bool {
            $result = parent::executePreparedUpdate($operation, $storedFields);
            $this->assertSelectedWriter();
            return $result;
        });
    }
}

class CapturedWriterSupplier extends Supplier
{
    use CapturedWriterProbe;

    public static function getTable($classname = null)
    {
        return Supplier::getTable();
    }

    public static function getType()
    {
        return Supplier::getType();
    }
}

class CapturedWriterOperatingSystem extends Item_OperatingSystem
{
    use CapturedWriterProbe;

    public static function getTable($classname = null)
    {
        return Item_OperatingSystem::getTable();
    }

    public static function getType()
    {
        return Item_OperatingSystem::getType();
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable writer-capture database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$writer = $DB;
$connection = $writer->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Initially idle supplied writer');
$session = $_SESSION;
$config = $CFG_GLPI;
$hooks = $PLUGIN_HOOKS;
$activated = new ReflectionProperty(Plugin::class, 'activated_plugins');
$plugins = $activated->getValue();
$activated->setValue(null, [...$plugins, 'writer_capture_fixture']);
$CFG_GLPI['use_notifications'] = false;
$connection->beginTransaction();
$caller = $writer->captureManagedTransactionScope();
$failure = null;
try {
    $fixtures = new FixtureRecords($writer);
    $supplier = $fixtures->create('glpi_suppliers', ['name' => 'Writer capture source fixture']);
    $computer = $fixtures->create('glpi_computers');
    $replacement = $fixtures->create('glpi_computers');
    $os = $fixtures->create('glpi_operatingsystems');
    $snapshot = static function () use ($connection): array {
        $rows = [];
        foreach (['glpi_suppliers', 'glpi_items_operatingsystems', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
        }
        return $rows;
    };

    $model = new CapturedWriterSupplier();
    $PLUGIN_HOOKS['pre_item_add']['writer_capture_fixture'][CapturedWriterSupplier::class] = static function ($item) use ($writer): void {
        verify($item->writer() === $writer && $item->events === ['capture'], 'Add captured the selected writer before the real pre-add hook');
        $item->events[] = 'pre_add';
    };
    $created = $model->add(['name' => 'Actual captured add', 'entities_id' => 0]);
    verify(is_int($created) && $created > 0, 'Captured owner retains the public identifier return');
    verify(array_slice($model->events, 0, 3) === ['capture', 'pre_add', 'prepare_add'], 'Actual add callback/preparation order is retained');

    $PLUGIN_HOOKS['pre_item_update']['writer_capture_fixture'][CapturedWriterSupplier::class] = static function ($item) use ($writer): void {
        verify($item->writer() === $writer && $item->events === ['capture', 'load'], 'Update captured writer before row-load and real pre-update callback');
        $item->events[] = 'pre_update';
    };
    verify($model->update(['id' => $supplier, 'name' => 'Actual captured update']) === true, 'Accepted actual public update retains boolean success');

    // Another adapter to the same physical connection is sufficient to prove
    // exact selected-adapter continuity; this is not live replica validation.
    $alternate = clone $writer;
    verify($alternate !== $writer && $alternate->getDoctrineConnection() === $connection, 'Distinct selected-adapter control shares only the actual physical writer');
    foreach (['add', 'update'] as $operation) {
        $before = $snapshot();
        $event = $operation === 'add' ? 'pre_item_add' : 'pre_item_update';
        $PLUGIN_HOOKS[$event]['writer_capture_fixture'][CapturedWriterSupplier::class] = static function ($item) use ($writer, $alternate): void {
            verify($item->writer() === $writer, 'Initial selected writer exists before callback substitution');
            $GLOBALS['DB'] = $alternate;
        };
        try {
            $operation === 'add' ? $model->add(['name' => 'Refused substituted writer', 'entities_id' => 0])
                : $model->update(['id' => $supplier, 'name' => 'Refused substituted writer']);
            throw new LogicException('Captured model must refuse a substituted writer');
        } catch (CapturedWriterRefusal $error) {
            verify($model->writer() === $writer, 'Refusal preserves the original selected capability');
        } finally {
            $GLOBALS['DB'] = $writer;
            unset($PLUGIN_HOOKS[$event]['writer_capture_fixture']);
        }
        $caller->assertActive();
        verify($snapshot() === $before && $connection->getTransactionNestingLevel() === 1, 'Early substitution refusal performs no core DML and preserves the actual caller frame');
    }

    foreach (['add', 'update'] as $operation) {
        $before = $snapshot();
        $event = $operation === 'add' ? 'pre_item_add' : 'pre_item_update';
        $PLUGIN_HOOKS[$event]['writer_capture_fixture'][CapturedWriterSupplier::class] = static function ($item) use ($writer): void {
            verify($item->writer() === $writer, 'Explicit pre-hook veto still sees the initial writer');
            $item->input = false;
        };
        $result = $operation === 'add' ? $model->add(['name' => 'Public add veto', 'entities_id' => 0])
            : $model->update(['id' => $supplier, 'name' => 'Public update veto']);
        verify($result === false && $snapshot() === $before, 'Actual explicit hook veto retains boolean false and no-write semantics');
        unset($PLUGIN_HOOKS[$event]['writer_capture_fixture']);
    }

    $before = $snapshot();
    $originalFailure = new RuntimeException('Actual admitted callback failure');
    $PLUGIN_HOOKS['item_update']['writer_capture_fixture'][CapturedWriterSupplier::class] = static function ($item) use ($originalFailure): void {
        verify((new QueuedNotification())->add(['itemtype' => Supplier::class, 'items_id' => $item->getID(), 'name' => 'Owned capture callback queue', 'send_time' => '2026-01-01 00:00:00']) > 0, 'Real callback writes an actual queued row inside the owned frame');
        throw $originalFailure;
    };
    try {
        $model->update(['id' => $supplier, 'name' => 'Actually written then rolled back']);
        throw new LogicException('Original callback Throwable must propagate');
    } catch (RuntimeException $error) {
        verify($error === $originalFailure, 'Exact original Throwable survives successful owned rollback');
    } finally {
        unset($PLUGIN_HOOKS['item_update']['writer_capture_fixture']);
    }
    $caller->assertActive();
    verify($snapshot() === $before && $model->fields['name'] === 'Actual captured update' && $model->updates === [] && $model->oldvalues === [], 'Existing owning unit restores actual rows/history/queue/public model after proven rollback');
    verify($model->update(['id' => $supplier, 'name' => 'Same instance retry']) === true, 'The same captured owner can retry after veto and thrown callback');

    $typed = new CapturedWriterOperatingSystem();
    $typedId = $typed->add(['itemtype' => Computer::class, 'items_id' => $computer, 'operatingsystems_id' => $os]);
    verify($typedId > 0, 'Captured owner uses actual typed public OS add');
    verify($typed->update(['id' => $typedId, 'itemtype' => Computer::class, 'items_id' => $replacement]) === true, 'Existing final typed Connexity guard and entity preparation remain interoperable');
    $row = $connection->fetchAssociative('SELECT items_id, computers_id, itemtype FROM glpi_items_operatingsystems WHERE id = ?', [$typedId]);
    verify((int)$row['computers_id'] === $replacement && (int)$row['items_id'] === $replacement && $row['itemtype'] === Computer::class, 'Actual owning FK and generated compatibility identity stay coherent');

    // Default models acquire no new domain guard or transaction.
    $PLUGIN_HOOKS['pre_item_update']['writer_capture_fixture'][Supplier::class] = static function ($item): void {
        $item->input['name'] = 'Default legitimate callback';
    };
    verify((new Supplier())->update(['id' => $supplier, 'name' => 'Overridden ordinary input']) === true, 'Default model retains actual legitimate plugin callback behavior');
    verify($connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id = ?', [$supplier]) === 'Default legitimate callback', 'Default hook input still reaches the existing persistence path');
    $caller->assertActive();
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $GLOBALS['DB'] = $writer;
    try {
        $caller->assertActive();
        $connection->rollBack();
    } catch (Throwable $cleanup) {
        $failure = $failure === null ? $cleanup : new MutationCleanupFailure($failure, $cleanup);
    }
    $_SESSION = $session;
    $CFG_GLPI = $config;
    $PLUGIN_HOOKS = $hooks;
    $activated->setValue(null, $plugins);
}
if ($failure !== null) {
    throw $failure;
}
echo 'Public lifecycle selected-writer capture passed: ' . $assertions . " assertions\n";
