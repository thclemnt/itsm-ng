<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/accepted-update-finalization.php /path/to/test-config\n");
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
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/** Exercise the real shared public lifecycle and its model-owned final decision. */
class AcceptedUpdateSoftware extends Software
{
    public static string $mode = 'accept';
    public static array $events = [];
    public static array $stored = [];

    public static function getTable($classname = null)
    {
        return Software::getTable();
    }

    public static function getType()
    {
        return Software::getType();
    }

    public function pre_updateInDB()
    {
        self::$events[] = 'pre_updateInDB';
        parent::pre_updateInDB();
        if (self::$mode === 'cancel') {
            $this->fields = self::$stored;
            $this->updates = [];
            $this->oldvalues = [];
        }
    }

    protected function finalizeLifecycleUpdate(array $storedFields): bool
    {
        self::$events[] = 'finalize';
        if (!parent::finalizeLifecycleUpdate($storedFields) || self::$mode === 'refuse') {
            return false;
        }
        if (self::$mode === 'identity') {
            ++$this->fields['id'];
        }
        return true;
    }

    public function post_updateItem($history = 1)
    {
        self::$events[] = 'post_updateItem';
        parent::post_updateItem($history);
    }
}

/** Genuine model post-update failure inside the existing allocation owner. */
class AcceptedUpdateAllocation extends Item_SoftwareLicense
{
    public static int $software;

    public static function getTable($classname = null)
    {
        return Item_SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return Item_SoftwareLicense::getType();
    }

    public function post_updateItem($history = 1)
    {
        parent::post_updateItem($history);
        verify((new QueuedNotification())->add(['itemtype' => Software::class, 'items_id' => self::$software,
            'name' => 'Finalized no-change rollback', 'send_time' => '2026-01-01 00:00:00']) > 0,
            'Real post-update callback creates an actual queue row inside the owning command');
        throw new RuntimeException('Actual finalized no-change post-update refusal');
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'accepted_update_fixture']);
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Fixture starts outside caller frames');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$fixtures = new FixtureRecords($DB);
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): array => $records()->find($table, 'id', $id);
$allRows = static fn (string $table): array => $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
$beforeRows = [];
foreach (['glpi_softwares', 'glpi_softwareversions', 'glpi_softwarelicenses', 'glpi_monitors', 'glpi_items_softwarelicenses', 'glpi_items_softwareversions', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
    $beforeRows[$table] = $allRows($table);
}
$connection->beginTransaction();
$primary = null;
$cleanupErrors = [];
try {
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $software = $fixtures->create('glpi_softwares', ['name' => 'Accepted finalization software',
        'date_mod' => new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'))]);
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software, 'name' => 'Accepted finalization version']);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'number' => -1]);
    $monitor = $fixtures->create('glpi_monitors', ['name' => 'Accepted finalization monitor']);
    $assignment = (new Item_SoftwareLicense())->add(['itemtype' => 'Monitor', 'items_id' => $monitor, 'softwarelicenses_id' => $license]);
    $installation = (new Item_SoftwareVersion())->add(['itemtype' => 'Monitor', 'items_id' => $monitor, 'softwareversions_id' => $version]);
    verify($assignment > 0 && $installation > 0, 'Actual allocation and installation dependency graph');
    $nativeSnapshot = static function () use ($allRows): array {
        $snapshot = [];
        foreach (['glpi_softwares', 'glpi_softwareversions', 'glpi_softwarelicenses', 'glpi_monitors', 'glpi_items_softwarelicenses', 'glpi_items_softwareversions', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
            $snapshot[$table] = $allRows($table);
        }
        return $snapshot;
    };

    $PLUGIN_HOOKS['item_update']['accepted_update_fixture'][Software::class] = static function (Software $item): void {
        AcceptedUpdateSoftware::$events[] = 'item_update_hook';
    };
    foreach (['accept', 'refuse', 'identity'] as $mode) {
        AcceptedUpdateSoftware::$mode = $mode;
        AcceptedUpdateSoftware::$events = [];
        $before = $nativeSnapshot();
        $model = new AcceptedUpdateSoftware();
        $result = $model->update(['id' => $software, 'name' => $read('glpi_softwares', $software)['name']]);
        verify($result === ($mode === 'accept'), 'Actual unchanged public update respects final decision ' . $mode);
        verify(AcceptedUpdateSoftware::$events === ($mode === 'accept' ? ['finalize', 'post_updateItem'] : ['finalize']),
            'Unchanged input finalizes once without real-write callback; refusal stops lifecycle ' . $mode);
        verify($nativeSnapshot() === $before, 'Unchanged finalization preserves all exact native values/date/history/queue ' . $mode);
        verify((int)$model->fields['id'] === $software && $model->updates === [], 'Final refusal preserves loaded identity and no pending writes ' . $mode);
    }
    AcceptedUpdateSoftware::$mode = 'accept';
    AcceptedUpdateSoftware::$events = [];
    $before = $read('glpi_softwares', $software);
    $PLUGIN_HOOKS['pre_item_update']['accepted_update_fixture'][Software::class] = static function (Software $item): void {
        $item->input['name'] = 'Actual plugin-created software write';
    };
    try {
        verify((new AcceptedUpdateSoftware())->update(['id' => $software, 'name' => $before['name']]) === true,
            'Public hook can create a real write from originally unchanged input');
    } finally {
        unset($PLUGIN_HOOKS['pre_item_update']['accepted_update_fixture']);
    }
    $after = $read('glpi_softwares', $software);
    verify($after['name'] === 'Actual plugin-created software write' && $after['date_mod'] !== $before['date_mod'],
        'Hook-created real write stores its value and normal date modification');
    verify(AcceptedUpdateSoftware::$events === ['pre_updateInDB', 'finalize', 'item_update_hook', 'post_updateItem'],
        'Hook-created write retains exactly one actual callback and finalization');

    AcceptedUpdateSoftware::$mode = 'cancel';
    AcceptedUpdateSoftware::$events = [];
    $model = new AcceptedUpdateSoftware();
    verify($model->getFromDB($software), 'Load cancellation checkpoint');
    AcceptedUpdateSoftware::$stored = $model->fields;
    $before = $nativeSnapshot();
    verify($model->update(['id' => $software, 'name' => 'Cancelled actual write']) === true, 'Actual final callback may cancel the prepared write');
    verify(AcceptedUpdateSoftware::$events === ['pre_updateInDB', 'finalize', 'post_updateItem'], 'Cancelled write still finalizes actual model once');
    verify($nativeSnapshot() === $before, 'Cancelled write preserves exact date/history/native rows');
    AcceptedUpdateSoftware::$mode = 'accept';

    foreach ([Item_SoftwareLicense::class => [$assignment, 'softwarelicenses_id', $license],
        Item_SoftwareVersion::class => [$installation, 'softwareversions_id', $version]] as $kind => [$id, $parentField, $parent]) {
        $before = $nativeSnapshot();
        $model = new $kind();
        verify($model->update([$parentField => $parent, 'id' => $id]) === true,
            'Unchanged owning relationship accepts reordered prepared input with strict writer guard ' . $kind);
        verify($model->updates === [] && $nativeSnapshot() === $before,
            'Unchanged relationship retains owning projections/context and no aggregate/history/date writes ' . $kind);
        verify($connection->getTransactionNestingLevel() === 1, 'Relationship releases only its owned nested frame ' . $kind);
        $scope = $_SESSION;
        $_SESSION['glpiactiveentities'] = [];
        $_SESSION['glpiactiveentities_string'] = '';
        try {
            verify((new $kind())->update(['id' => $id, $parentField => $parent]) === false,
                'Unchanged owning command still refuses empty entity scope ' . $kind);
            verify($nativeSnapshot() === $before, 'Scope refusal has no native/history/aggregate side effects ' . $kind);
        } finally {
            $_SESSION = $scope;
        }
    }

    $model = new AcceptedUpdateAllocation();
    AcceptedUpdateAllocation::$software = $software;
    verify($model->getFromDB($assignment), 'Load actual owning rollback checkpoint');
    $storedFields = $model->fields;
    $before = $nativeSnapshot();
    $caught = false;
    try {
        $model->update(['id' => $assignment, 'softwarelicenses_id' => $license]);
    } catch (RuntimeException $error) {
        $caught = $error->getMessage() === 'Actual finalized no-change post-update refusal';
    }
    verify($caught, 'Original public callback error survives unchanged finalization');
    verify($nativeSnapshot() === $before && $model->fields === $storedFields,
        'Owning rollback restores exact database graph/queue/audit and actual stored model fields');
    verify($connection->getTransactionNestingLevel() === 1, 'Rejected no-change callback preserves caller frame');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    try {
        $plugins->setValue(null, $savedPlugins);
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    try {
        verify($DB->getDoctrineConnection() === $connection && $connection->getTransactionNestingLevel() === 1,
            'Fixture only ends its supplied original outer frame');
        $connection->rollBack();
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
}
if ($primary !== null) {
    foreach ($cleanupErrors as $cleanupError) {
        try { fwrite(STDERR, 'Secondary fixture cleanup failure: ' . $cleanupError::class . "\n"); } catch (Throwable) { }
    }
    throw $primary;
}
if ($cleanupErrors !== []) {
    throw $cleanupErrors[0];
}
foreach ($beforeRows as $table => $before) {
    verify($allRows($table) === $before, 'Caller rollback preserves original native rows ' . $table);
}
verify($connection->getTransactionNestingLevel() === 0, 'Original caller frame ended');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after tests');
echo 'Accepted-update finalization contracts passed (' . $assertions . " assertions)\n";
