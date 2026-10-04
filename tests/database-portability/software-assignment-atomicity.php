<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-assignment-atomicity.php /path/to/test-config\n");
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

/** Mutate the real effective write set after normal public preparation. */
class SoftwareAssignmentFinalCallback extends Item_SoftwareLicense
{
    public static string $mode;
    public static int $target;
    public static int $original;
    public static int $calls = 0;

    public static function getTable($classname = null)
    {
        return Item_SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return Item_SoftwareLicense::getType();
    }

    public function pre_updateInDB()
    {
        ++self::$calls;
        if (self::$mode === 'input') {
            $this->input['softwarelicenses_id'] = self::$target;
        } elseif (self::$mode === 'cancel') {
            $this->updates = array_values(array_diff($this->updates, ['softwarelicenses_id']));
        } else {
            $this->fields['softwarelicenses_id'] = self::$mode === 'reset' ? self::$original : self::$target;
            $this->updates[] = 'softwarelicenses_id';
        }
    }
}

class SoftwareInstallationFinalCallback extends Item_SoftwareVersion
{
    public static string $mode;
    public static int $target;
    public static int $original;
    public static int $calls = 0;

    public static function getTable($classname = null)
    {
        return Item_SoftwareVersion::getTable();
    }

    public static function getType()
    {
        return Item_SoftwareVersion::getType();
    }

    public function pre_updateInDB()
    {
        ++self::$calls;
        if (self::$mode === 'input') {
            $this->input['monitors_id'] = self::$target;
        } elseif (self::$mode === 'cancel') {
            $this->updates = array_values(array_diff($this->updates, \itsmng\Database\ConnexityInput::endpointFields($this)));
        } else {
            $this->fields['monitors_id'] = self::$mode === 'reset' ? self::$original : self::$target;
            $this->updates[] = 'monitors_id';
        }
    }
}

class SoftwareAssignmentAbortedCommit extends Item_SoftwareLicense
{
    public static int $caughtFailures = 0;

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
        try {
            $GLOBALS['DB']->getDoctrineConnection()->executeQuery('SELECT 1 / 0');
            throw new LogicException('Expected native PostgreSQL failure');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            verify($error->getSQLState() === '22012', 'Final real assignment hook catches native division by zero');
            ++self::$caughtFailures;
        }
        // No later query: the same physical commit guard must detect this error.
    }
}

/** Final quantity/owning-parent callbacks keep the real licence alert hook. */
class SoftwareLicenseFinalCallback extends SoftwareLicense
{
    public static string $field;
    public static string $mode;
    public static int $target;
    public static int $original;
    public static int $calls = 0;
    public static int $parentCalls = 0;

    public static function getTable($classname = null)
    {
        return SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return SoftwareLicense::getType();
    }

    public function pre_updateInDB()
    {
        ++self::$calls;
        parent::pre_updateInDB();
        ++self::$parentCalls;
        if (self::$mode === 'cancel') {
            $this->updates = array_values(array_diff($this->updates, [self::$field]));
        } elseif (self::$mode === 'input') {
            $this->input[self::$field] = self::$target;
        } else {
            $this->fields[self::$field] = self::$mode === 'reset' ? self::$original : self::$target;
            $this->updates[] = self::$field;
        }
    }
}

/** Records physical delivery through an actual registered queue transport. */
class PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe
{
    public static array $deliveries = [];

    public static function canCron(): bool
    {
        return true;
    }

    public static function send(array $rows): void
    {
        global $DB;
        foreach ($rows as $row) {
            self::$deliveries[] = [$DB->getDoctrineConnection()->getTransactionNestingLevel(), (int)$row['items_id']];
            verify((new QueuedNotification())->update(['id' => $row['id'], 'is_deleted' => 1]) === true, 'Actual assignment transport marks delivered queue row');
        }
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Standalone cases start outside a caller transaction');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'software_assignment_atomicity_fixture']);
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$created = [];
$prefix = 'Assignment atomic ' . bin2hex(random_bytes(5));
$record = static function (string $table, array $values = []) use ($fixtures, &$created): int {
    $id = $fixtures->create($table, $values);
    $created[] = [$table, $id];
    return $id;
};
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$rows = static fn (string $table, array $criteria = []): array => $records()->matching($table, $criteria, 'id ASC');
$publicAssignment = static function (int $asset, int $licence, bool $dynamic = true) use (&$created): int {
    $id = (new Item_SoftwareLicense())->add(['itemtype' => 'Monitor', 'items_id' => $asset, 'softwarelicenses_id' => $licence, 'is_dynamic' => $dynamic]);
    verify(is_numeric($id) && $id > 0, 'Create actual public licence assignment');
    $created[] = ['glpi_items_softwarelicenses', (int)$id];
    return (int)$id;
};
$graph = static function (string $name, int $entity, string $state = 'active') use ($record, $publicAssignment, $prefix, $read): array {
    $name = $prefix . ' ' . $name;
    $software = $record('glpi_softwares', ['name' => $name, 'entities_id' => $entity]);
    $otherSoftware = $record('glpi_softwares', ['name' => $name . ' other', 'entities_id' => $entity]);
    $licence = $record('glpi_softwarelicenses', ['name' => $name, 'entities_id' => $entity, 'softwares_id' => $software, 'number' => 0]);
    $otherLicence = $record('glpi_softwarelicenses', ['name' => $name . ' other', 'entities_id' => $entity, 'softwares_id' => $otherSoftware, 'number' => 0]);
    $asset = $record('glpi_monitors', ['name' => $name, 'entities_id' => $entity]);
    $deleted = $record('glpi_monitors', ['name' => $name . ' deleted', 'entities_id' => $entity, 'is_deleted' => true]);
    $template = $record('glpi_monitors', ['name' => $name . ' template', 'entities_id' => $entity, 'is_template' => true]);
    $assignment = $state === 'absent' ? null : $publicAssignment($asset, $licence);
    if ($state === 'locked') {
        verify((new Item_SoftwareLicense())->delete(['id' => $assignment]) === true, 'Public dynamic lock fixture');
        verify($read('glpi_items_softwarelicenses', $assignment)['is_deleted'] === 1, 'Lock retains the physical assignment');
    }
    return compact('name', 'software', 'otherSoftware', 'licence', 'otherLicence', 'asset', 'deleted', 'template', 'assignment');
};
$snapshot = static function (array $graph) use ($read, $rows): array {
    $result = [];
    foreach (['software' => 'glpi_softwares', 'otherSoftware' => 'glpi_softwares', 'licence' => 'glpi_softwarelicenses', 'otherLicence' => 'glpi_softwarelicenses', 'asset' => 'glpi_monitors', 'deleted' => 'glpi_monitors', 'template' => 'glpi_monitors', 'assignment' => 'glpi_items_softwarelicenses'] as $key => $table) {
        $result[$key] = isset($graph[$key]) ? $read($table, $graph[$key]) : null;
    }
    $result['assignments'] = $rows('glpi_items_softwarelicenses', ['softwarelicenses_id' => [$graph['licence'], $graph['otherLicence']]]);
    $result['history'] = $rows('glpi_logs');
    $result['queue'] = $rows('glpi_queuednotifications');
    return $result;
};
$run = static function (string $operation, Item_SoftwareLicense $model, array $graph): mixed {
    return match ($operation) {
        'add' => $model->add(['itemtype' => 'Monitor', 'items_id' => $graph['asset'], 'softwarelicenses_id' => $graph['licence'], 'is_dynamic' => 1, 'add' => 'Attempt allocation']),
        'retarget' => $model->update(['id' => $graph['assignment'], 'softwarelicenses_id' => $graph['otherLicence'], 'update' => 'Attempt reassignment']),
        'purge' => $model->delete(['id' => $graph['assignment'], '_purge' => 1], true),
        'lock' => $model->delete(['id' => $graph['assignment'], '_delete' => 1]),
        'restore' => $model->restore(['id' => $graph['assignment'], 'restore' => 1]),
        'deleted' => $model->update(['id' => $graph['assignment'], 'is_deleted' => 1]),
        'deleted-subject' => $model->update(['id' => $graph['assignment'], 'monitors_id' => $graph['deleted']]),
        'template-subject' => $model->update(['id' => $graph['assignment'], 'monitors_id' => $graph['template']]),
    };
};

try {
    $entity = $record('glpi_entities', ['name' => $prefix . ' owner']);
    $destination = $record('glpi_entities', ['name' => $prefix . ' destination']);
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpiactiveentities'] = [0, $entity, $destination];
    $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
    $_SESSION['glpishowallentities'] = false;
    Notification_NotificationTemplate::registerMode('assignmentprobe', 'Assignment probe', 'software_assignment_atomicity_fixture');
    $CFG_GLPI['notifications_assignmentprobe'] = true;
    Notification_NotificationTemplate::getModes();

    // Every operation crosses actual allocation eligibility and both aggregate levels.
    foreach (['standalone', 'caller'] as $context) {
        foreach (['add', 'retarget', 'purge', 'lock', 'restore', 'deleted', 'deleted-subject', 'template-subject'] as $operation) {
            $targets = $operation === 'retarget' ? ['old licence', 'new licence', 'old Software', 'new Software'] : ['old licence', 'old Software'];
            foreach ($targets as $target) {
                $g = $graph($context . ' ' . $operation . ' ' . $target, $entity, $operation === 'add' ? 'absent' : ($operation === 'restore' ? 'locked' : 'active'));
                verify((bool)$read('glpi_softwarelicenses', $g['licence'])['is_valid'] === in_array($operation, ['add', 'restore'], true), 'Fixture starts with the actual eligible allocation validity');
                $before = $snapshot($g);
                $retained = [];
                $vetoes = 0;
                $targetClass = str_contains($target, 'Software') ? Software::class : SoftwareLicense::class;
                $targetId = match ($target) {
                    'old licence' => $g['licence'], 'new licence' => $g['otherLicence'], 'old Software' => $g['software'], 'new Software' => $g['otherSoftware']
                };
                foreach ([SoftwareLicense::class, Software::class] as $class) {
                    $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][$class] = static function (CommonDBTM $item) use ($targetClass, $targetId, &$vetoes): void {
                        if ($item->getType() === $targetClass && (int)$item->getID() === $targetId && array_key_exists('is_valid', $item->input)) {
                            ++$vetoes;
                            Session::addMessageAfterRedirect('Required assignment aggregate refused', false, WARNING);
                            $item->input = false;
                        }
                    };
                    $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'][$class] = static function (CommonDBTM $item) use (&$retained, &$created): void {
                        $retained[] = $item;
                        $queue = (int)(new QueuedNotification())->add(['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'mode' => 'assignmentprobe', 'send_time' => '2026-01-01 00:00:00', 'name' => 'Assignment aggregate attempt']);
                        verify($queue > 0, 'Earlier accepted aggregate queues actual work');
                        $created[] = ['glpi_queuednotifications', $queue];
                        QueuedNotification::forceSendFor($item->getType(), $item->getID());
                        verify(PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === [], 'Actual aggregate delivery waits for the owning physical commit');
                    };
                }
                PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries = [];
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Prior allocation feedback']];
                if ($context === 'caller') {
                    $connection->beginTransaction();
                }
                try {
                    $level = $connection->getTransactionNestingLevel();
                    $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' caller marker']) : null;
                    $model = new Item_SoftwareLicense();
                    if ($g['assignment'] !== null) {
                        verify($model->getFromDB($g['assignment']), 'Load actual retained assignment before refusal');
                    }
                    $storedFields = $model->fields;
                    verify($run($operation, $model, $g) === false && $vetoes === 1, 'Actual ' . $target . ' veto refuses ' . $context . ' public ' . $operation);
                    verify($snapshot($g) === $before, 'Refusal restores assignment, quantities, both aggregates, history and queue');
                    verify($model->fields === $storedFields, 'Refused public model retains its stored fields and no phantom identity');
                    foreach ($retained as $held) {
                        $key = $held instanceof SoftwareLicense ? ((int)$held->getID() === $g['licence'] ? 'licence' : 'otherLicence') : ((int)$held->getID() === $g['software'] ? 'software' : 'otherSoftware');
                        verify(LifecycleModelJournal::state($held) === ['fields' => $before[$key]], 'Later aggregate veto rewinds all four public properties and their original presence on the earlier successful model');
                    }
                    verify(PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === [] && ($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []) === ['Prior allocation feedback'], 'Refusal discards delivery and successful feedback');
                    verify(in_array('Required assignment aggregate refused', $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? [], true), 'Actual refusal warning remains available');
                    verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1 && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Refusal preserves a usable caller frame and its independent marker');
                } finally {
                    unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'], $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture']);
                    if ($context === 'caller') {
                        $connection->rollBack();
                    }
                }
            }
        }
    }

    // Accepted controls prove that rollback assertions did not simply disable writes.
    foreach (['add', 'retarget', 'purge', 'lock', 'restore', 'deleted', 'deleted-subject', 'template-subject'] as $operation) {
        $g = $graph('accepted ' . $operation, $entity, $operation === 'add' ? 'absent' : ($operation === 'restore' ? 'locked' : 'active'));
        $result = $run($operation, new Item_SoftwareLicense(), $g);
        verify($operation === 'add' ? is_numeric($result) && $result > 0 : $result === true, 'Actual public ' . $operation . ' succeeds without a veto');
        if ($operation === 'add') {
            $created[] = ['glpi_items_softwarelicenses', (int)$result];
        }
        $invalidOld = in_array($operation, ['add', 'restore'], true);
        verify((bool)$read('glpi_softwarelicenses', $g['licence'])['is_valid'] === !$invalidOld && (bool)$read('glpi_softwares', $g['software'])['is_valid'] === !$invalidOld, 'Accepted lifecycle reconciles old licence and owning Software eligibility');
        if ($operation === 'retarget') {
            verify(!$read('glpi_softwarelicenses', $g['otherLicence'])['is_valid'] && !$read('glpi_softwares', $g['otherSoftware'])['is_valid'], 'Accepted reassignment reconciles both new aggregates');
        }
    }

    foreach (['standalone', 'caller'] as $context) {
        $g = $graph('delivery ' . $context, $entity);
        $before = $snapshot($g);
        PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries = [];
        $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use (&$created): void {
            $id = (int)(new QueuedNotification())->add(['itemtype' => Software::class, 'items_id' => $item->getID(), 'mode' => 'assignmentprobe', 'send_time' => '2026-01-01 00:00:00', 'name' => 'Accepted assignment probe']);
            verify($id > 0, 'Accepted aggregate creates an actual notification queue row');
            $created[] = ['glpi_queuednotifications', $id];
            QueuedNotification::forceSendFor(Software::class, $item->getID());
            QueuedNotification::forceSendFor(Software::class, $item->getID());
            verify(PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === [], 'Repeated actual force-send remains deferred inside the allocation frame');
        };
        if ($context === 'caller') {
            $connection->beginTransaction();
        }
        try {
            $level = $connection->getTransactionNestingLevel();
            verify((new Item_SoftwareLicense())->update(['id' => $g['assignment'], 'softwarelicenses_id' => $g['otherLicence']]) === true, 'Accepted public assignment succeeds inside its owning frame');
            verify($connection->getTransactionNestingLevel() === $level, 'Accepted assignment releases only its own frame');
            $deliveries = PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries;
            if ($context === 'standalone') {
                verify(count($deliveries) === 2 && array_column($deliveries, 0) === [0, 0], 'Actual old/new Software transport delivery occurs once per queued row after physical commit');
            } else {
                verify($deliveries === [] && count($rows('glpi_queuednotifications')) === count($before['queue']) + 2, 'Caller savepoint success retains queue rows without dispatch');
            }
        } finally {
            unset($PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture']);
            if ($context === 'caller') {
                $connection->rollBack();
            }
        }
        if ($context === 'caller') {
            verify($snapshot($g) === $before, 'Later caller rollback restores accepted assignment, aggregates, history and queued rows');
            // A model returned before its caller rolls back has no automatic PHP
            // rewind guarantee; that broader ownership contract is not asserted.
        }
    }

    foreach (['late', 'cancel', 'reset', 'input', 'nochange', 'early', 'history-zero'] as $mode) {
        $g = $graph('effective ' . $mode, $entity);
        $aggregateCalls = [];
        $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][SoftwareLicense::class] = static function (SoftwareLicense $item) use (&$aggregateCalls): void {
            if (array_key_exists('is_valid', $item->input)) {
                $aggregateCalls[] = (int)$item->getID();
            }
        };
        $model = new SoftwareAssignmentFinalCallback();
        SoftwareAssignmentFinalCallback::$mode = $mode;
        SoftwareAssignmentFinalCallback::$target = $g['otherLicence'];
        SoftwareAssignmentFinalCallback::$original = $g['licence'];
        SoftwareAssignmentFinalCallback::$calls = 0;
        $input = ['id' => $g['assignment'], 'softwarelicenses_id' => in_array($mode, ['late', 'cancel', 'reset', 'history-zero'], true) ? $g['otherLicence'] : $g['licence']];
        $discarded = null;
        if ($mode === 'late') {
            $discarded = $record('glpi_softwarelicenses', ['name' => $prefix . ' discarded parent', 'softwares_id' => $g['software'], 'entities_id' => $entity, 'number' => 0]);
            $input['softwarelicenses_id'] = $discarded;
        }
        if ($mode === 'input') {
            // A real unrelated write invokes the final callback; merely adding a
            // command-only input key would never enter pre_updateInDB.
            $input['is_dynamic'] = 0;
        }
        if (in_array($mode, ['early', 'history-zero', 'nochange'], true)) {
            $model = new Item_SoftwareLicense();
        }
        if ($mode === 'early') {
            $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Item_SoftwareLicense::class] = static function (Item_SoftwareLicense $item) use ($g): void {
                $item->input['softwarelicenses_id'] = $g['otherLicence'];
            };
        }
        $before = $snapshot($g);
        $discardedBefore = $discarded === null ? null : $read('glpi_softwarelicenses', $discarded);
        try {
            verify($model->update($input, $mode === 'history-zero' ? 0 : 1) === true, 'Actual effective callback ' . $mode . ' succeeds');
            if (!in_array($mode, ['early', 'history-zero', 'nochange'], true)) {
                verify(SoftwareAssignmentFinalCallback::$calls === 1, 'Actual final callback was reached exactly once');
            }
            $changesParent = in_array($mode, ['late', 'early', 'history-zero'], true);
            verify((int)$read('glpi_items_softwarelicenses', $g['assignment'])['softwarelicenses_id'] === ($changesParent ? $g['otherLicence'] : $g['licence']), 'Only final persisted parent controls aggregate reconciliation');
            sort($aggregateCalls);
            $expected = $changesParent ? [$g['licence'], $g['otherLicence']] : [];
            sort($expected);
            verify($aggregateCalls === $expected, 'Actual callback ' . $mode . ' reconciles each effective parent once and ignores discarded/input-only parents');
            verify($discarded === null || $read('glpi_softwarelicenses', $discarded) === $discardedBefore, 'Late callback leaves the discarded prepared parent untouched');
            if (!$changesParent) {
                if ($mode === 'input') {
                    $after = $snapshot($g);
                    verify(
                        $after['licence'] === $before['licence'] && $after['otherLicence'] === $before['otherLicence']
                        && $after['software'] === $before['software'] && $after['otherSoftware'] === $before['otherSoftware']
                        && $after['queue'] === $before['queue'] && $after['assignment']['is_dynamic'] === 0,
                        'Input-only discarded parent leaves aggregates untouched while the independent dynamic flag persists'
                    );
                } else {
                    verify($snapshot($g) === $before, 'Net-zero or no-change command preserves rows, aggregates, audit and queue');
                }
            } else {
                verify($read('glpi_softwarelicenses', $g['licence'])['is_valid'] && !$read('glpi_softwarelicenses', $g['otherLicence'])['is_valid'], 'History mode does not disable required aggregate work');
            }
        } finally {
            unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture']);
        }
    }

    // Licence commands themselves must reconcile quantity and old/new owning Software.
    foreach (['standalone', 'caller'] as $context) {
        foreach (['quantity', 'owner'] as $operation) {
            foreach ($operation === 'owner' ? ['old Software', 'new Software'] : ['old Software'] as $target) {
                $g = $graph('licence ' . $context . ' ' . $operation . ' ' . $target, $entity);
                $before = $snapshot($g);
                $targetId = $target === 'old Software' ? $g['software'] : $g['otherSoftware'];
                $calls = 0;
                $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($targetId, &$calls): void {
                    if ((int)$item->getID() === $targetId && array_key_exists('is_valid', $item->input)) {
                        ++$calls;
                        $item->input = false;
                    }
                };
                if ($context === 'caller') {
                    $connection->beginTransaction();
                }
                try {
                    $level = $connection->getTransactionNestingLevel();
                    $licence = new SoftwareLicense();
                    $change = $operation === 'quantity' ? ['number' => 1] : ['softwares_id' => $g['otherSoftware']];
                    verify($licence->update(['id' => $g['licence']] + $change) === false && $calls === 1, 'Required owning Software veto refuses public licence ' . $operation);
                    verify($snapshot($g) === $before && $licence->fields === $before['licence'] && $connection->getTransactionNestingLevel() === $level, 'Licence command restores quantity/owner, both aggregate rows, audit and public model');
                } finally {
                    unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture']);
                    if ($context === 'caller') {
                        $connection->rollBack();
                    }
                }
                verify((new SoftwareLicense())->update(['id' => $g['licence']] + $change) === true, 'Accepted actual licence ' . $operation . ' control');
                verify($read('glpi_softwares', $g['software'])['is_valid'] && ($operation !== 'owner' || !$read('glpi_softwares', $g['otherSoftware'])['is_valid']), 'Accepted quantity/owner command reconciles effective Software aggregates');
            }
        }
    }

    // A prepared validity flag cannot survive cancellation of its quantity.
    foreach (['standalone', 'caller'] as $context) {
        foreach (['number', 'softwares_id'] as $field) {
            foreach (['cancel', 'reset', 'input', 'nochange', 'late'] as $mode) {
                $g = $graph('licence final ' . $context . ' ' . $field . ' ' . $mode, $entity);
                $before = $snapshot($g);
                $oldClock = $_SESSION['glpi_currenttime'];
                verify(is_string($before['licence']['date_mod']), 'Finite-zero fixture has an actual prior validity-write clock');
                $_SESSION['glpi_currenttime'] = $before['licence']['date_mod'];
                $target = $field === 'number' ? 1 : $g['otherSoftware'];
                $original = (int)$before['licence'][$field];
                SoftwareLicenseFinalCallback::$field = $field;
                SoftwareLicenseFinalCallback::$mode = $mode;
                SoftwareLicenseFinalCallback::$target = $target;
                SoftwareLicenseFinalCallback::$original = $original;
                SoftwareLicenseFinalCallback::$calls = SoftwareLicenseFinalCallback::$parentCalls = 0;
                $input = ['id' => $g['licence'], $field => in_array($mode, ['cancel', 'reset'], true) ? $target : $original];
                $comment = 'Independent final licence callback write';
                if ($mode === 'input' || ($mode === 'late' && $field === 'number')) {
                    // This ordinary write reaches the final callback even though
                    // preparation sees the old, invalid allocation quantity.
                    $input['comment'] = $comment;
                }
                $discarded = null;
                if ($mode === 'late' && $field === 'softwares_id') {
                    $discarded = $record('glpi_softwares', ['name' => $prefix . ' discarded prepared licence owner', 'entities_id' => $entity]);
                    $input[$field] = $discarded;
                }
                $discardedBefore = $discarded === null ? null : $read('glpi_softwares', $discarded);
                $aggregateCalls = [];
                $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use (&$aggregateCalls): void {
                    if (array_key_exists('is_valid', $item->input)) {
                        $aggregateCalls[] = (int)$item->getID();
                    }
                };
                if ($context === 'caller') {
                    $connection->beginTransaction();
                }
                try {
                    $level = $connection->getTransactionNestingLevel();
                    $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' final licence caller marker']) : null;
                    $model = new SoftwareLicenseFinalCallback();
                    verify($model->update($input) === true, 'Actual ' . $context . ' final licence ' . $field . ' ' . $mode . ' callback');
                    $calls = $mode === 'nochange' ? 0 : 1;
                    verify(SoftwareLicenseFinalCallback::$calls === $calls && SoftwareLicenseFinalCallback::$parentCalls === $calls, 'Final licence callback preserves the real parent alert hook exactly once');
                    $changed = $mode === 'late';
                    $after = $snapshot($g);
                    verify((int)$after['licence'][$field] === ($changed ? $target : $original), 'Only the final effective licence quantity/owner reaches storage');
                    sort($aggregateCalls);
                    $expected = !$changed ? [] : ($field === 'number' ? [$g['software']] : [$g['software'], $g['otherSoftware']]);
                    sort($expected);
                    verify($aggregateCalls === $expected, 'Canceled, reset and input-only licence targets never refresh discarded aggregates');
                    verify($discarded === null || $read('glpi_softwares', $discarded) === $discardedBefore, 'Late owning Software callback leaves its discarded prepared owner untouched');
                    if (in_array($mode, ['cancel', 'reset', 'nochange'], true)) {
                        verify($after === $before && !array_key_exists($field, $model->oldvalues) && !array_key_exists('is_valid', $model->oldvalues), 'Discarded quantity/parent and derivative validity preserve all rows, aggregate flags, audit and queue');
                    } elseif ($mode === 'input') {
                        $expectedLicence = $before['licence'];
                        $expectedLicence['comment'] = $comment;
                        verify($after['licence'] === $expectedLicence && $after['software'] === $before['software'] && $after['otherSoftware'] === $before['otherSoftware']
                            && $after['queue'] === $before['queue'] && $after['history'] !== $before['history'], 'Input-only quantity/parent preserves aggregates while the actual independent comment write retains its audit');
                    } else {
                        verify($model->oldvalues[$field] === $original && $after['history'] !== $before['history'], 'Late licence callback rebuilds real oldvalues and records the effective change');
                        verify($field === 'number' ? (bool)$after['licence']['is_valid'] && (bool)$after['software']['is_valid']
                            : !$after['licence']['is_valid'] && $after['software']['is_valid'] && !$after['otherSoftware']['is_valid'], 'Writer reconciliation derives validity from the final quantity/owning Software');
                    }
                    verify($connection->getTransactionNestingLevel() === $level && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Final licence callback retains its caller frame and marker');
                } finally {
                    unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture']);
                    if ($context === 'caller') {
                        $connection->rollBack();
                    }
                    $_SESSION['glpi_currenttime'] = $oldClock;
                }
                if ($context === 'caller') {
                    verify($snapshot($g) === $before, 'Outer rollback restores accepted final licence callbacks and their audit');
                }
            }
        }
    }
    foreach (['standalone', 'caller'] as $context) {
        foreach (['number', 'softwares_id'] as $field) {
            foreach ($field === 'number' ? ['old Software'] : ['old Software', 'new Software'] as $target) {
                $g = $graph('late licence veto ' . $context . ' ' . $field . ' ' . $target, $entity);
                $before = $snapshot($g);
                SoftwareLicenseFinalCallback::$field = $field;
                SoftwareLicenseFinalCallback::$mode = 'late';
                SoftwareLicenseFinalCallback::$target = $field === 'number' ? 1 : $g['otherSoftware'];
                SoftwareLicenseFinalCallback::$original = (int)$before['licence'][$field];
                SoftwareLicenseFinalCallback::$calls = SoftwareLicenseFinalCallback::$parentCalls = 0;
                $vetoId = $target === 'old Software' ? $g['software'] : $g['otherSoftware'];
                $vetoes = 0;
                $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($vetoId, &$vetoes): void {
                    if ((int)$item->getID() === $vetoId && array_key_exists('is_valid', $item->input)) {
                        ++$vetoes;
                        $item->input = false;
                    }
                };
                if ($context === 'caller') {
                    $connection->beginTransaction();
                }
                try {
                    $level = $connection->getTransactionNestingLevel();
                    $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' late veto caller marker']) : null;
                    $model = new SoftwareLicenseFinalCallback();
                    verify($model->update(['id' => $g['licence'], 'comment' => 'Actual late callback before required veto']) === false && $vetoes === 1
                        && SoftwareLicenseFinalCallback::$calls === 1 && SoftwareLicenseFinalCallback::$parentCalls === 1, 'Actual owning Software veto refuses the late persisted licence callback');
                    verify($snapshot($g) === $before && $model->fields === $before['licence'] && $model->updates === [] && $model->oldvalues === [], 'Late required veto restores quantity/parent, derived validity, independent write, model, audit and queue');
                    verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1 && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Late licence veto preserves a usable caller frame and marker');
                } finally {
                    unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture']);
                    if ($context === 'caller') {
                        $connection->rollBack();
                    }
                }
            }
        }
    }

    // Owner context belongs to the installation; its own inventory lock survives.
    $recursiveSoftware = $record('glpi_softwares', ['name' => $prefix . ' recursive context', 'entities_id' => 0, 'is_recursive' => true]);
    $recursiveVersion = $record('glpi_softwareversions', ['softwares_id' => $recursiveSoftware, 'entities_id' => 0, 'is_recursive' => true]);
    foreach (['late', 'early', 'cancel', 'reset', 'input', 'nochange'] as $mode) {
        $subject = $record('glpi_monitors', ['name' => $prefix . ' context ' . $mode, 'entities_id' => $entity]);
        $target = $record('glpi_monitors', ['name' => $prefix . ' target ' . $mode, 'entities_id' => $destination, 'is_deleted' => true, 'is_template' => true]);
        $installation = new Item_SoftwareVersion();
        $id = $installation->add(['itemtype' => 'Monitor', 'items_id' => $subject, 'softwareversions_id' => $recursiveVersion, 'is_dynamic' => 1, 'is_deleted' => 1]);
        verify(is_numeric($id) && $id > 0, 'Create actual locked installation for endpoint callback');
        $created[] = ['glpi_items_softwareversions', (int)$id];
        $before = $read('glpi_items_softwareversions', (int)$id);
        $model = in_array($mode, ['early', 'nochange'], true) ? new Item_SoftwareVersion() : new SoftwareInstallationFinalCallback();
        SoftwareInstallationFinalCallback::$mode = $mode;
        SoftwareInstallationFinalCallback::$target = $target;
        SoftwareInstallationFinalCallback::$original = $subject;
        SoftwareInstallationFinalCallback::$calls = 0;
        $input = ['id' => $id, 'monitors_id' => in_array($mode, ['late', 'cancel', 'reset'], true) ? $target : $subject];
        if ($mode === 'late') {
            // General preparation sees a different live source owner. Only the
            // final pure endpoint hook can derive the eventual target caches.
            $intermediate = $record('glpi_monitors', ['name' => $prefix . ' prepared context owner', 'entities_id' => $entity]);
            $input['monitors_id'] = $intermediate;
        }
        if ($mode === 'input') {
            $input['is_dynamic'] = 0;
        }
        if ($mode === 'early') {
            $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Item_SoftwareVersion::class] = static function (Item_SoftwareVersion $item) use ($target): void {
                $item->input['monitors_id'] = $target;
            };
        }
        try {
            verify($model->update($input) === true, 'Actual installation ' . $mode . ' endpoint callback');
            if (!in_array($mode, ['early', 'nochange'], true)) {
                verify(SoftwareInstallationFinalCallback::$calls === 1, 'Installation final callback runs exactly once after preparation');
            }
            $after = $read('glpi_items_softwareversions', (int)$id);
            $changed = in_array($mode, ['late', 'early'], true);
            verify($after['monitors_id'] === ($changed ? $target : $subject) && $after['items_id'] === $after['monitors_id'], 'Installation uses only the final persisted owning endpoint');
            verify(
                $after['entities_id'] === ($changed ? $destination : $entity)
                && (bool)$after['is_deleted_item'] === $changed && (bool)$after['is_template_item'] === $changed,
                'Actual final retarget refreshes persisted subject entity/template/deletion caches'
            );
            verify($after['is_deleted'] === 1 && $after['is_dynamic'] === ($mode === 'input' ? 0 : 1), 'Context refresh preserves the installation lock and independent dynamic write');
            if (!$changed && $mode !== 'input') {
                verify($after === $before, 'Canceled, net-zero and no-change endpoint callbacks preserve the full installation');
            }
        } finally {
            unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture']);
        }
    }
    $reused = new Item_SoftwareVersion();
    foreach ([$entity, $destination] as $owner) {
        $subject = $record('glpi_monitors', ['name' => $prefix . ' reused add', 'entities_id' => $owner, 'is_template' => $owner === $destination]);
        $id = $reused->add(['itemtype' => 'Monitor', 'items_id' => $subject, 'softwareversions_id' => $recursiveVersion, 'is_dynamic' => 1]);
        verify(is_numeric($id) && $id > 0, 'The same actual model supports consecutive massive-add shaped calls');
        $created[] = ['glpi_items_softwareversions', (int)$id];
        $row = $read('glpi_items_softwareversions', (int)$id);
        verify($row['entities_id'] === $owner && (bool)$row['is_template_item'] === ($owner === $destination), 'Reused add derives context from this subject rather than a prior model identity');
    }

    // Public moveItems owns earlier siblings, Infocom writes, copies and quantities.
    foreach (['standalone', 'caller'] as $context) {
        foreach (['Software add', 'Version add', 'licence add', 'installation update', 'assignment update', 'fresh copies then assignment update', 'destination quantity', 'source quantity', 'source trash', 'licence aggregate', 'Software aggregate', 'discard purge'] as $case) {
            $name = $prefix . ' transfer ' . $context . ' ' . $case;
            $earlier = $record('glpi_computers', ['name' => $name . ' earlier', 'entities_id' => $entity]);
            $earlierFinance = $record('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $earlier, 'entities_id' => $entity]);
            $asset = $record('glpi_computers', ['name' => $name, 'entities_id' => $entity]);
            $finance = $record('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $asset, 'entities_id' => $entity]);
            $outside = $record('glpi_computers', ['name' => $name . ' outside', 'entities_id' => $entity]);
            $software = $record('glpi_softwares', ['name' => $name, 'entities_id' => $entity]);
            $version = $record('glpi_softwareversions', ['name' => $name, 'softwares_id' => $software, 'entities_id' => $entity]);
            $number = match ($case) {
                'source trash' => 1, 'licence aggregate', 'Software aggregate' => 0, default => 2
            };
            $licence = $record('glpi_softwarelicenses', ['name' => $name, 'serial' => $name, 'softwares_id' => $software, 'entities_id' => $entity, 'number' => $number, 'softwareversions_id_buy' => $version, 'softwareversions_id_use' => $version]);
            foreach ([$asset, $outside] as $computer) {
                $id = (new Item_SoftwareVersion())->add(['itemtype' => 'Computer', 'items_id' => $computer, 'softwareversions_id' => $version]);
                verify(is_numeric($id) && $id > 0, 'Real outside installation prevents moving Software instead of copying it');
                $created[] = ['glpi_items_softwareversions', (int)$id];
                if ($computer === $asset) {
                    $installation = (int)$id;
                }
            }
            $assignment = (new Item_SoftwareLicense())->add(['itemtype' => 'Computer', 'items_id' => $asset, 'softwarelicenses_id' => $licence]);
            verify(is_numeric($assignment) && $assignment > 0, 'Create actual source licence allocation');
            $created[] = ['glpi_items_softwarelicenses', (int)$assignment];
            $newSoftware = null;
            $newLicence = null;
            if (!in_array($case, ['Software add', 'discard purge', 'fresh copies then assignment update'], true)) {
                $newSoftware = $record('glpi_softwares', ['name' => $name, 'entities_id' => $destination]);
                if ($case !== 'Version add') {
                    $record('glpi_softwareversions', ['name' => $name, 'softwares_id' => $newSoftware, 'entities_id' => $destination]);
                }
                if (!in_array($case, ['licence add', 'assignment update'], true)) {
                    $newLicence = $record('glpi_softwarelicenses', ['name' => $name, 'serial' => $name, 'softwares_id' => $newSoftware, 'entities_id' => $destination, 'number' => 2]);
                }
            }
            $takeSnapshot = static function () use ($read, $rows, $earlier, $asset, $outside, $earlierFinance, $finance, $installation, $assignment, $name): array {
                $softwares = $rows('glpi_softwares', ['name' => $name]);
                return [
                    $read('glpi_computers', $earlier), $read('glpi_computers', $asset), $read('glpi_computers', $outside),
                    $read('glpi_infocoms', $earlierFinance), $read('glpi_infocoms', $finance),
                    $read('glpi_items_softwareversions', $installation), $read('glpi_items_softwarelicenses', (int)$assignment),
                    $softwares, $rows('glpi_softwareversions', ['softwares_id' => array_column($softwares, 'id')]),
                    $rows('glpi_softwarelicenses', ['name' => $name]), $rows('glpi_logs'), $rows('glpi_queuednotifications'),
                ];
            };
            $before = $takeSnapshot();
            $vetoes = 0;
            $heldEarlier = null;
            $heldFinance = null;
            $heldCopies = [];
            $copiedVersionScopes = [];
            $sourceCopyStates = [Software::class => ['fields' => $read('glpi_softwares', $software)], SoftwareVersion::class => ['fields' => $read('glpi_softwareversions', $version)]];
            foreach ([Software::class, SoftwareVersion::class, SoftwareLicense::class] as $copyClass) {
                $PLUGIN_HOOKS['item_add']['software_assignment_atomicity_fixture'][$copyClass] = static function (CommonDBTM $item) use (&$heldCopies, &$copiedVersionScopes, $read): void {
                    $heldCopies[] = $item;
                    if ($item instanceof SoftwareVersion) {
                        $parent = $read('glpi_softwares', (int)$item->fields['softwares_id']);
                        $copiedVersionScopes[] = [$item->fields['entities_id'], $item->fields['is_recursive'], $parent['entities_id'], $parent['is_recursive']];
                    }
                };
            }
            $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'][Computer::class] = static function (Computer $item) use ($earlier, &$heldEarlier): void {
                if ((int)$item->getID() === $earlier) {
                    $heldEarlier = $item;
                }
            };
            $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'][Infocom::class] = static function (Infocom $item) use ($earlierFinance, &$heldFinance): void {
                if ((int)$item->getID() === $earlierFinance) {
                    $heldFinance = $item;
                }
            };
            $hook = match ($case) {
                'Software add', 'Version add', 'licence add' => 'pre_item_add', 'source trash' => 'pre_item_delete', 'discard purge' => 'pre_item_purge', default => 'pre_item_update'
            };
            $class = match ($case) {
                'Software add', 'Software aggregate' => Software::class, 'Version add' => SoftwareVersion::class, 'installation update' => Item_SoftwareVersion::class, 'assignment update', 'fresh copies then assignment update', 'discard purge' => Item_SoftwareLicense::class, default => SoftwareLicense::class
            };
            $PLUGIN_HOOKS[$hook]['software_assignment_atomicity_fixture'][$class] = static function (CommonDBTM $item) use ($case, $name, $software, $licence, $newLicence, $assignment, $installation, &$vetoes): void {
                $required = match ($case) {
                    'Software add', 'Version add', 'licence add' => ($item->input['name'] ?? null) === $name,
                    'installation update' => (int)$item->getID() === $installation && array_key_exists('softwareversions_id', $item->input),
                    'assignment update', 'fresh copies then assignment update' => (int)$item->getID() === (int)$assignment && array_key_exists('softwarelicenses_id', $item->input),
                    'destination quantity' => (int)$item->getID() === $newLicence && array_key_exists('number', $item->input),
                    'source quantity' => (int)$item->getID() === $licence && array_key_exists('number', $item->input),
                    'source trash' => (int)$item->getID() === $licence,
                    'licence aggregate' => (int)$item->getID() === $licence && array_key_exists('is_valid', $item->input),
                    'Software aggregate' => (int)$item->getID() === $software && array_key_exists('is_valid', $item->input),
                    'discard purge' => (int)$item->getID() === (int)$assignment,
                };
                if ($required) {
                    ++$vetoes;
                    $item->input = false;
                }
            };
            if ($context === 'caller') {
                $connection->beginTransaction();
            }
            try {
                $level = $connection->getTransactionNestingLevel();
                $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' transfer caller marker']) : null;
                $transfer = new Transfer();
                $state = [$transfer->already_transfer, $transfer->needtobe_transfer, $transfer->noneedtobe_transfer, $transfer->options, $transfer->to, LifecycleModelJournal::state($transfer)];
                verify(
                    $transfer->moveItems(['Computer' => [$earlier, $asset]], $destination, ['keep_software' => $case !== 'discard purge', 'keep_infocom' => 1, 'keep_history' => 1]) === false && $vetoes === 1,
                    'Actual no-cache ' . $context . ' moveItems propagates required ' . $case . ' veto'
                );
                if ($case === 'fresh copies then assignment update') {
                    verify(count($copiedVersionScopes) === 1 && $copiedVersionScopes[0] === [$destination, 0, $destination, 0], 'Actual copied Version add hook observes the current destination Software scope before the later allocation veto');
                }
                verify($takeSnapshot() === $before, 'Late refusal restores earlier asset/Infocom, actual software copies, assignments, quantity, audit and queue');
                verify($heldEarlier instanceof Computer && $heldEarlier->fields === $before[0], 'Operation journal restores the earlier successful asset retained by the real completion hook');
                verify($heldFinance instanceof Infocom && $heldFinance->fields === $before[3], 'Operation journal restores actual earlier financial model work');
                foreach ($heldCopies as $copy) {
                    if ($copy instanceof SoftwareLicense) {
                        verify(!isset($copy->fields['id']), 'Earlier created destination licence retained by actual add hook loses its phantom identity');
                    } else {
                        verify(LifecycleModelJournal::state($copy) === $sourceCopyStates[$copy->getType()], 'Earlier copied Software/Version hook instance restores its original source checkpoint');
                    }
                }
                verify(
                    [$transfer->already_transfer, $transfer->needtobe_transfer, $transfer->noneedtobe_transfer, $transfer->options, $transfer->to, LifecycleModelJournal::state($transfer)] === $state,
                    'Actual refusal restores all pre-operation Transfer bookkeeping'
                );
                verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1 && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Software refusal preserves the usable outer caller frame');
            } finally {
                unset($PLUGIN_HOOKS[$hook]['software_assignment_atomicity_fixture'], $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'], $PLUGIN_HOOKS['item_add']['software_assignment_atomicity_fixture']);
                if ($context === 'caller') {
                    $connection->rollBack();
                }
            }
        }
    }
    // Merge requires changed aggregates and accepted source trash after the bulk move.
    foreach (['standalone', 'caller'] as $context) {
        foreach (['old Software', 'new Software', 'source trash'] as $target) {
            $g = $graph('merge ' . $context . ' ' . $target, $entity);
            $sourceVersion = $record('glpi_softwareversions', ['softwares_id' => $g['software'], 'entities_id' => $entity, 'name' => $g['name']]);
            $targetVersion = $record('glpi_softwareversions', ['softwares_id' => $g['otherSoftware'], 'entities_id' => $entity, 'name' => $g['name']]);
            verify((new SoftwareLicense())->update(['id' => $g['licence'], 'softwareversions_id_buy' => $sourceVersion, 'softwareversions_id_use' => $sourceVersion]) === true, 'Merge fixture has actual owning buy/use versions');
            $movingSubject = $record('glpi_monitors', ['name' => $prefix . ' merge second subject', 'entities_id' => $entity]);
            $installationIds = [];
            foreach ([[$g['asset'], $targetVersion], [$g['asset'], $sourceVersion], [$movingSubject, $sourceVersion]] as [$subject, $version]) {
                $id = (new Item_SoftwareVersion())->add(['itemtype' => 'Monitor', 'items_id' => $subject, 'softwareversions_id' => $version]);
                verify(is_numeric($id) && $id > 0, 'Create actual merge collision or moved installation');
                $created[] = ['glpi_items_softwareversions', (int)$id];
                $installationIds[] = (int)$id;
            }
            $takeMergeSnapshot = static fn (): array => [$snapshot($g), $read('glpi_softwareversions', $sourceVersion), $read('glpi_softwareversions', $targetVersion), array_map(static fn (int $id): ?array => $read('glpi_items_softwareversions', $id), $installationIds)];
            $before = $takeMergeSnapshot();
            verify(!$before[0]['software']['is_valid'] && $before[0]['otherSoftware']['is_valid'], 'Actual finite-zero allocation starts with invalid source and valid destination Software');
            $targetId = $target === 'old Software' ? $g['software'] : $g['otherSoftware'];
            $vetoes = 0;
            $trashCalls = 0;
            $heldSource = null;
            $heldAggregates = [];
            $acceptedIndicators = [];
            PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries = [];
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Prior merge feedback']];
            $_SESSION['software_assignment_merge_probe'] = 'Original caller state';
            $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($target, $targetId, &$vetoes): void {
                if ($target !== 'source trash' && (int)$item->getID() === $targetId && array_key_exists('is_valid', $item->input)) {
                    ++$vetoes;
                    $item->input = false;
                }
            };
            $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($g, $target, &$heldSource, &$heldAggregates, &$acceptedIndicators, &$created): void {
                if ((int)$item->getID() === $g['software']) {
                    $heldSource = $item;
                }
                if ($target === 'source trash' && in_array((int)$item->getID(), [$g['software'], $g['otherSoftware']], true) && array_key_exists('is_valid', $item->input)) {
                    $heldAggregates[] = $item;
                    $acceptedIndicators[] = (int)$item->getID();
                    $_SESSION['software_assignment_merge_probe'] = 'Earlier aggregate callback state';
                    Session::addMessageAfterRedirect('Earlier merge aggregate accepted', false, INFO);
                    $queue = (int)(new QueuedNotification())->add(['itemtype' => Software::class, 'items_id' => $item->getID(), 'mode' => 'assignmentprobe', 'send_time' => '2026-01-01 00:00:00', 'name' => 'Merge aggregate before source trash']);
                    verify($queue > 0, 'Actual earlier merge aggregate queues completion work before source trash');
                    $created[] = ['glpi_queuednotifications', $queue];
                    QueuedNotification::forceSendFor(Software::class, $item->getID());
                    verify(PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === [], 'Merge aggregate delivery waits for the owning physical commit');
                }
            };
            $PLUGIN_HOOKS['pre_item_delete']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($g, $target, $read, $sourceVersion, $targetVersion, $installationIds, &$trashCalls, &$vetoes, &$acceptedIndicators): void {
                if ((int)$item->getID() === $g['software']) {
                    ++$trashCalls;
                    if ($target === 'source trash') {
                        $expected = [$g['software'], $g['otherSoftware']];
                        $observed = $acceptedIndicators;
                        sort($expected);
                        sort($observed);
                        verify($observed === $expected && $read('glpi_softwares', $g['software'])['is_valid']
                            && !$read('glpi_softwares', $g['otherSoftware'])['is_valid'], 'Both real Software validity writes and completed model callbacks occur before the source trash veto');
                        verify($read('glpi_softwareversions', $sourceVersion) === null && $read('glpi_items_softwareversions', $installationIds[1]) === null
                            && $read('glpi_items_softwareversions', $installationIds[2])['softwareversions_id'] === $targetVersion
                            && $read('glpi_softwarelicenses', $g['licence'])['softwares_id'] === $g['otherSoftware'], 'Real collision removal, nonduplicate installation move and licence rehome precede the actual source delete callback');
                        ++$vetoes;
                        Session::addMessageAfterRedirect('Required merge source trash refused', false, WARNING);
                        $item->input = false;
                    }
                }
            };
            if ($context === 'caller') {
                $connection->beginTransaction();
            }
            try {
                $level = $connection->getTransactionNestingLevel();
                $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' merge caller marker']) : null;
                $model = new Software();
                verify($model->getFromDB($g['otherSoftware']), 'Load actual merge destination');
                verify($model->merge([$g['software'] => 1], false) === false && $vetoes === 1, 'Actual public ' . $context . ' merge propagates required ' . $target . ' veto');
                verify($takeMergeSnapshot() === $before && $trashCalls === ($target === 'source trash' ? 1 : 0), 'Required refusal restores version collision, moved installations, owning licence/buy/use IDs, aggregates, source state, history and queue');
                verify($model->fields === $before[0]['otherSoftware'], 'Refused public merge retains its loaded destination state');
                if ($heldSource !== null) {
                    verify(LifecycleModelJournal::state($heldSource) === ['fields' => $before[0]['software']], 'Later destination veto rewinds the earlier accepted source Software aggregate model');
                }
                if ($target === 'source trash') {
                    verify(count($heldAggregates) === 2, 'Late source trash refusal follows exactly two actual completed Software indicator models');
                    foreach ($heldAggregates as $held) {
                        $key = (int)$held->getID() === $g['software'] ? 'software' : 'otherSoftware';
                        verify(LifecycleModelJournal::state($held) === ['fields' => $before[0][$key]], 'Late source trash refusal rewinds all public state on each earlier accepted aggregate model');
                    }
                    verify(PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === []
                        && ($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []) === ['Prior merge feedback']
                        && $_SESSION['software_assignment_merge_probe'] === 'Original caller state', 'Late merge refusal discards actual queued delivery, successful feedback and callback session changes');
                    verify(in_array('Required merge source trash refused', $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? [], true), 'Actual source trash refusal warning remains visible');
                }
                verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1 && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Merge refusal preserves caller ownership and its independent marker');
            } finally {
                unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'], $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'], $PLUGIN_HOOKS['pre_item_delete']['software_assignment_atomicity_fixture']);
                if ($context === 'caller') {
                    $connection->rollBack();
                }
            }
            $accepted = new Software();
            verify($accepted->getFromDB($g['otherSoftware']) && $accepted->merge([$g['software'] => 1], false) === true, 'Same actual merge succeeds after removing the required lifecycle veto');
            verify(
                $read('glpi_softwareversions', $sourceVersion) === null && $read('glpi_items_softwareversions', $installationIds[1]) === null
                && $read('glpi_items_softwareversions', $installationIds[0]) !== null && $read('glpi_items_softwareversions', $installationIds[2])['softwareversions_id'] === $targetVersion,
                'Accepted merge retains established collision removal and nonduplicate move behavior'
            );
            verify(
                $read('glpi_softwares', $g['software'])['is_deleted'] && $read('glpi_softwares', $g['software'])['is_valid']
                && !$read('glpi_softwares', $g['otherSoftware'])['is_valid'] && $read('glpi_softwarelicenses', $g['licence'])['softwares_id'] === $g['otherSoftware'],
                'Accepted merge reconciles old/new Software before successful source trash'
            );
        }
    }
    // The bounded dictionary command preserves its deliberate bulk hook policy.
    foreach (['standalone', 'caller'] as $context) {
        foreach (['old Software', 'new Software'] as $target) {
            $g = $graph('dictionary ' . $context . ' ' . $target, $entity);
            $before = $snapshot($g);
            $repository = new \itsmng\Database\Repository\SoftwareDictionaryRepository(Orm::create($DB));
            verify($repository->moveLicenses($g['software'], $g['software']) === true && $snapshot($g) === $before, 'Actual dictionary self move is a no-op');
            verify(
                $repository->moveLicenses(PHP_INT_MAX, $g['otherSoftware']) === false
                && $repository->moveLicenses($g['software'], PHP_INT_MAX) === false && $snapshot($g) === $before,
                'Actual missing dictionary source/target returns false without row, aggregate, history or queue changes'
            );
            $targetId = $target === 'old Software' ? $g['software'] : $g['otherSoftware'];
            $vetoes = 0;
            $licenceHooks = 0;
            $heldSource = null;
            PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries = [];
            $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($targetId, &$vetoes): void {
                if ((int)$item->getID() === $targetId && array_key_exists('is_valid', $item->input)) {
                    ++$vetoes;
                    $item->input = false;
                }
            };
            $PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'][SoftwareLicense::class] = static function () use (&$licenceHooks): void {
                ++$licenceHooks;
            };
            $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture'][Software::class] = static function (Software $item) use ($g, &$heldSource, &$created): void {
                if ((int)$item->getID() === $g['software']) {
                    $heldSource = $item;
                    $id = (int)(new QueuedNotification())->add(['itemtype' => Software::class, 'items_id' => $item->getID(), 'mode' => 'assignmentprobe', 'send_time' => '2026-01-01 00:00:00', 'name' => 'Dictionary aggregate probe']);
                    verify($id > 0, 'Earlier dictionary aggregate queues actual completed lifecycle work');
                    $created[] = ['glpi_queuednotifications', $id];
                    QueuedNotification::forceSendFor(Software::class, $item->getID());
                    verify(PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === [], 'Dictionary command defers actual delivery until physical commit');
                }
            };
            if ($context === 'caller') {
                $connection->beginTransaction();
            }
            try {
                $level = $connection->getTransactionNestingLevel();
                $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' dictionary caller marker']) : null;
                verify($repository->moveLicenses($g['software'], $g['otherSoftware']) === false && $vetoes === 1, 'Actual bounded ' . $context . ' dictionary move requires accepted ' . $target . ' aggregate');
                verify($snapshot($g) === $before && $licenceHooks === 0 && PluginSoftware_assignment_atomicity_fixtureNotificationEventAssignmentprobe::$deliveries === [], 'Refused dictionary command restores bulk licence ownership, aggregates, history and queue without inventing per-licence hooks');
                if ($heldSource !== null) {
                    verify(LifecycleModelJournal::state($heldSource) === ['fields' => $before['software']], 'Dictionary refusal restores actual earlier successful aggregate model');
                }
                verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1 && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Dictionary refusal preserves the outer caller frame and marker');
            } finally {
                unset($PLUGIN_HOOKS['pre_item_update']['software_assignment_atomicity_fixture'], $PLUGIN_HOOKS['item_update']['software_assignment_atomicity_fixture']);
                if ($context === 'caller') {
                    $connection->rollBack();
                }
            }
            verify($repository->moveLicenses($g['software'], $g['otherSoftware']) === true, 'Same actual dictionary ownership move succeeds without veto');
            $licence = $read('glpi_softwarelicenses', $g['licence']);
            verify(
                $licence['softwares_id'] === $g['otherSoftware'] && $licence['number'] === $before['licence']['number']
                && $licence['is_valid'] === $before['licence']['is_valid'] && $read('glpi_items_softwarelicenses', $g['assignment']) === $before['assignment'],
                'Accepted bulk command preserves quantity, licence validity and independent assignment identity'
            );
            verify($read('glpi_softwares', $g['software'])['is_valid'] && !$read('glpi_softwares', $g['otherSoftware'])['is_valid']
                && !$read('glpi_softwares', $g['software'])['is_deleted'], 'Bounded dictionary move reconciles old/new Software and leaves later replay trash outside its command');
        }
    }
    if ($DB->getProvider() === 'pgsql') {
        foreach (['standalone', 'caller'] as $context) {
            $g = $graph('native aborted ' . $context, $entity);
            $before = $snapshot($g);
            if ($context === 'caller') {
                $connection->beginTransaction();
            }
            try {
                $level = $connection->getTransactionNestingLevel();
                $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' native caller marker']) : null;
                $model = new SoftwareAssignmentAbortedCommit();
                $caught = SoftwareAssignmentAbortedCommit::$caughtFailures;
                try {
                    $result = $model->update(['id' => $g['assignment'], 'softwarelicenses_id' => $g['otherLicence']]);
                } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                    verify($error->getSQLState() === '25P02', 'Native aborted assignment commit reports the actual database failure');
                    $result = false;
                }
                verify($result === false && SoftwareAssignmentAbortedCommit::$caughtFailures === $caught + 1, 'Swallowed final PostgreSQL hook failure cannot become a successful assignment');
                verify($snapshot($g) === $before && $model->fields === $before['assignment'], 'Native refusal restores allocation, both aggregates, history, queue and retained public model');
                verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1 && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Native refusal preserves the usable caller savepoint owner');
            } finally {
                if ($context === 'caller') {
                    $connection->rollBack();
                }
            }
        }
    }
} finally {
    $PLUGIN_HOOKS = $savedHooks;
    while ($connection->getTransactionNestingLevel() > 0) {
        $connection->rollBack();
    }
    $CFG_GLPI['use_notifications'] = false;
    foreach (array_reverse($created) as [$table, $id]) {
        $class = getItemTypeForTable($table);
        $model = new $class();
        if ($model->getFromDB($id)) {
            verify((bool)$model->delete(['id' => $id, '_no_history' => true, '_disablenotif' => true], true), 'Public fixture cleanup: ' . $table);
        }
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after fixtures');
echo $DB->getProvider() . ": $assertions actual software assignment and aggregate atomicity assertions passed.\n";
