<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DeletionCancelled;
use itsmng\Database\EntityRegistry;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\TransactionOwnershipMismatch;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/component-definition-replacement.php /path/to/test-config\n");
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
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$writer = $DB;
$connection = $DB->getDoctrineConnection();
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$savedConfig = $CFG_GLPI;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'definition_replacement_fixture']);
$CFG_GLPI['use_notifications'] = false;
$tables = ['glpi_entities', 'glpi_computers', 'glpi_deviceprocessors', 'glpi_items_deviceprocessors',
    'glpi_logs', 'glpi_queuednotifications', 'itsmng_migrations'];
$snapshot = static function () use ($connection, $tables): array {
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table)
            . ' ORDER BY ' . $connection->quoteIdentifier($table === 'itsmng_migrations' ? 'version' : 'id'));
    }
    return $rows;
};
$baseline = $snapshot();
$connection->beginTransaction();
$primary = null;
$cleanup = [];
try {
    $fixtures = new FixtureRecords($DB);
    $foreignEntity = $fixtures->create('glpi_entities', ['name' => 'Definition replacement foreign entity', 'entities_id' => 0]);
    $rootAsset = $fixtures->create('glpi_computers', ['name' => 'Definition replacement root asset']);
    $foreignAsset = $fixtures->create('glpi_computers', ['name' => 'Definition replacement hidden asset', 'entities_id' => $foreignEntity]);
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    verify(!Session::haveAccessToEntity($foreignEntity), 'Actual actor cannot view the foreign asset entity');
    verify(
        EntityRegistry::entityScopeOwner('glpi_items_deviceprocessors') === ['column' => 'deviceprocessors_id', 'target' => 'glpi_deviceprocessors'],
        'Processor cached scope is declared by its actual owning definition property'
    );

    $graph = static function (bool $recursive = false, ?bool $targetRecursive = null) use ($fixtures, $rootAsset, $foreignAsset): array {
        $source = $fixtures->create('glpi_deviceprocessors', ['designation' => 'Replacement source', 'entities_id' => 0, 'is_recursive' => $recursive]);
        $target = $fixtures->create('glpi_deviceprocessors', ['designation' => 'Replacement target', 'entities_id' => 0, 'is_recursive' => $targetRecursive ?? $recursive]);
        $root = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $source, 'itemtype' => Computer::class, 'items_id' => $rootAsset, 'frequency' => 3100, 'serial' => 'Root original']);
        $foreign = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $source, 'itemtype' => Computer::class, 'items_id' => $foreignAsset, 'frequency' => 3200, 'serial' => 'Foreign original']);
        $stock = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $source, 'itemtype' => null, 'items_id' => 0, 'frequency' => 3300]);
        $neighbor = $fixtures->create('glpi_items_deviceprocessors', ['deviceprocessors_id' => $target, 'itemtype' => null, 'items_id' => 0, 'frequency' => 3400]);
        $device = new DeviceProcessor();
        verify($device->getFromDB($source), 'Load actual definition owner');
        return compact('source', 'target', 'root', 'foreign', 'stock', 'neighbor', 'device');
    };
    $read = static function (string $table, int $id) use ($connection): ?array {
        return $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]) ?: null;
    };

    $g = $graph();
    $input = ['id' => $g['foreign'], 'deviceprocessors_id' => $g['target']];
    verify(!(new Item_DeviceProcessor())->can($g['foreign'], UPDATE, $input), 'Ordinary asset assignment authority remains denied');
    $before = $snapshot();
    $naked = new DeviceProcessor();
    verify($naked->getFromDB($g['source']), 'Load direct-call refusal owner');
    $naked->input = ['id' => $g['source'], '_replace_by' => $g['target']];
    $refused = false;
    try {
        $naked->deleteFromDB(true);
    } catch (DeletionCancelled) {
        $refused = true;
    }
    verify($refused && $snapshot() === $before, 'Public low-level delete cannot mint a delegated replacement outside actual owner deletion');
    $oldRows = [];
    foreach (['root', 'foreign', 'stock', 'neighbor'] as $key) {
        $oldRows[$key] = $read('glpi_items_deviceprocessors', $g[$key]);
    }
    $level = $connection->getTransactionNestingLevel();
    $reloads = 0;
    $PLUGIN_HOOKS['item_update']['definition_replacement_fixture'][Item_DeviceProcessor::class] = static function (Item_DeviceProcessor $item) use ($g, $foreignAsset, &$reloads): void {
        if ((int)$item->getID() !== $g['foreign']) {
            return;
        }
        verify(
            $item->getFromDB($item->getID()) && (int)$item->fields['deviceprocessors_id'] === $g['target']
            && (int)$item->fields['computers_id'] === $foreignAsset && $item->fields['serial'] === 'Foreign original',
            'Real late public reload returns the exact admitted current child and retains subject/specificities'
        );
        ++$reloads;
    };
    // The trusted public delete seam does not invent a source PURGE requirement.
    $purgeRight = $_SESSION['glpiactiveprofile']['device'] ?? null;
    $_SESSION['glpiactiveprofile']['device'] = READ | UPDATE;
    verify($g['device']->delete(['id' => $g['source'], '_replace_by' => $g['target']], true), 'Actual trusted owner replacement preserves foreign links without asset editing grants');
    verify($reloads === 1, 'A legitimate public late reload remains available once after the writer producer');
    $PLUGIN_HOOKS = $savedHooks;
    if ($purgeRight === null) {
        unset($_SESSION['glpiactiveprofile']['device']);
    } else {
        $_SESSION['glpiactiveprofile']['device'] = $purgeRight;
    }
    foreach (['root', 'foreign', 'stock'] as $key) {
        $expected = $oldRows[$key];
        $expected['deviceprocessors_id'] = $g['target'];
        verify($read('glpi_items_deviceprocessors', $g[$key]) === $expected, 'Replacement changes only owning definition and preserves full asset tuple/specificities/definition cache: ' . $key);
    }
    verify($read('glpi_items_deviceprocessors', $g['neighbor']) === $oldRows['neighbor'], 'Neighbor target binding remains byte/type exact');
    verify(
        $read('glpi_deviceprocessors', $g['source']) === null && $connection->getTransactionNestingLevel() === $level,
        'Actual source purge retains caller frame and required replacement links'
    );

    $completed = 0;
    foreach (['item_can', 'cancel', 'subject', 'scalar', 'late_scalar', 'late_input', 'actor', 'queue_throw', 'writer'] as $mode) {
        $g = $graph();
        $before = $snapshot();
        $checkpoint = LifecycleModelJournal::state($g['device']);
        $actor = Session::getLoginUserID();
        $called = 0;
        $callbackError = new RuntimeException('Actual definition replacement queue callback failure');
        $PLUGIN_HOOKS['item_update']['definition_replacement_fixture'][Item_DeviceProcessor::class] = static function () use (&$completed): void {
            ++$completed;
        };
        $event = $mode === 'item_can' ? 'item_can' : (in_array($mode, ['late_scalar', 'late_input', 'actor', 'queue_throw', 'writer'], true) ? 'item_update' : 'pre_item_update');
        $PLUGIN_HOOKS[$event]['definition_replacement_fixture'][Item_DeviceProcessor::class] = static function (Item_DeviceProcessor $item) use ($mode, $g, $rootAsset, &$called, $callbackError, &$completed): void {
            if ((int)$item->getID() !== $g['foreign']) {
                return;
            }
            ++$called;
            if ($mode === 'item_can') {
                $item->right = 0;
            } elseif ($mode === 'cancel') {
                $item->input = [];
            } elseif ($mode === 'subject') {
                $item->input['computers_id'] = $rootAsset;
                $item->input['items_id'] = $rootAsset;
            } elseif ($mode === 'scalar') {
                $item->input['serial'] = 'Unowned scalar amendment';
            } elseif ($mode === 'late_scalar') {
                $item->fields['serial'] = 'Unowned late scalar amendment';
            } elseif ($mode === 'late_input') {
                $item->input['computers_id'] = $rootAsset;
                $item->input['items_id'] = $rootAsset;
            } elseif ($mode === 'actor') {
                $_SESSION['glpiID'] = 0;
            } elseif ($mode === 'writer') {
                $GLOBALS['DB'] = clone $GLOBALS['DB'];
            } else {
                verify(
                    (new QueuedNotification())->add(['itemtype' => DeviceProcessor::class, 'items_id' => $g['source'],
                    'name' => 'Actual definition replacement callback queue', 'send_time' => '2026-01-01 00:00:00']) > 0,
                    'Real late callback appends a genuine queue row'
                );
                throw $callbackError;
            }
            ++$completed;
        };
        $result = null;
        $caught = null;
        try {
            $result = $g['device']->delete(['id' => $g['source'], '_replace_by' => $g['target']], true);
        } catch (Throwable $error) {
            $caught = $error;
        } finally {
            $GLOBALS['DB'] = $writer;
            $PLUGIN_HOOKS = $savedHooks;
        }
        verify($called === 1, 'Real restrictive/amending callback executes once: ' . $mode);
        if ($mode === 'queue_throw') {
            verify($caught === $callbackError, 'Actual callback primary survives owning rollback');
        } elseif ($mode === 'writer') {
            verify(($caught === null && $result === false) || $caught instanceof DeletionCancelled || $caught instanceof TransactionOwnershipMismatch, 'Actual facade substitution is refused');
        } else {
            verify($caught === null && $result === false, 'Unowned child change cancels required owner replacement: ' . $mode);
        }
        verify($snapshot() === $before, 'Actual owner rollback restores all native rows/history/queue/ledger: ' . $mode);
        verify(
            LifecycleModelJournal::state($g['device']) === $checkpoint && Session::getLoginUserID() === $actor,
            'Proven original rollback restores owner model and actor checkpoint: ' . $mode
        );
        verify($connection->getTransactionNestingLevel() === $level, 'Refused child preserves supplied caller frame: ' . $mode);
    }
    $g = $graph(true, false);
    $before = $snapshot();
    verify(
        !$g['device']->delete(['id' => $g['source'], '_replace_by' => $g['target']], true) && $snapshot() === $before,
        'A genuinely narrowed definition scope cannot invalidate an existing foreign asset link'
    );
} catch (Throwable $error) {
    $primary = $error;
} finally {
    $GLOBALS['DB'] = $writer;
    try {
        $connection->rollBack();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    $_SESSION = $savedSession;
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI = $savedConfig;
    $plugins->setValue(null, $savedPlugins);
}
if ($primary !== null) {
    throw $primary;
}
if ($cleanup) {
    throw $cleanup[0];
}
verify($snapshot() === $baseline, 'Outer owned fixture rollback restores original native database rows and ledger');
echo $DB->getProvider() . ": component definition replacement ownership, restrictive hooks, native rollback and routing passed.\n";
