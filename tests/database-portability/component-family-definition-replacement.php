<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DeletionCancelled;
use itsmng\Database\EntityRegistry;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Repository\ComponentDefinitionRepository;
use itsmng\Database\SchemaCheck;
use itsmng\Database\TransactionOwnershipMismatch;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/component-family-definition-replacement.php /path/to/test-config\n");
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
$plugins->setValue(null, [...$savedPlugins, 'family_definition_replacement_fixture']);
$CFG_GLPI['use_notifications'] = false;
$families = [
    [Item_DeviceMotherboard::class, []],
    [Item_DeviceMemory::class, ['size' => 8192]],
    [Item_DeviceHardDrive::class, ['capacity' => 1048576]],
];
$tables = ['glpi_entities', 'glpi_logs', 'glpi_queuednotifications', 'itsmng_migrations'];
foreach ($families as [$linkClass]) {
    $tables[] = $linkClass::getTable();
    $deviceClass = $linkClass::getDeviceType();
    $tables[] = $deviceClass::getTable();
    foreach (EntityRegistry::discriminatedReferences($linkClass::getTable())['items_id']['selections'] as $selection) {
        $tables[] = $selection['target'];
    }
}
$tables = array_values(array_unique($tables));
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
    $prefix = 'Component replacement ' . bin2hex(random_bytes(6));
    foreach ($families as [$linkClass, $payload]) {
        $table = $linkClass::getTable();
        $deviceClass = $linkClass::getDeviceType();
        $deviceTable = $deviceClass::getTable();
        $deviceColumn = $linkClass::getDeviceForeignKey();
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'];
        verify(ComponentDefinitionRepository::supportsFamily(new $linkClass(), $deviceTable, $deviceColumn), 'Every converted family enters its metadata-declared definition command');
        verify(!ComponentDefinitionRepository::supportsFamily(new $linkClass(), $deviceTable, 'items_id'), 'An attached subject projection cannot mint a definition role');
        foreach ($reference['selections'] as $kind => $selection) {
            $subjectTable = $selection['target'];
            $subjectColumn = $selection['column'];
            $foreignEntity = $fixtures->create('glpi_entities', ['name' => $prefix . ' foreign ' . $kind, 'entities_id' => 0]);
            $rootAsset = $fixtures->create($subjectTable, ['name' => $prefix . ' root ' . $kind]);
            $foreignAsset = $fixtures->create($subjectTable, ['name' => $prefix . ' hidden ' . $kind, 'entities_id' => $foreignEntity]);
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = '0';
            $_SESSION['glpiactive_entity'] = 0;
            $_SESSION['glpishowallentities'] = false;
            verify(!Session::haveAccessToEntity($foreignEntity), 'Actual actor cannot view the foreign asset entity');
            verify(
                EntityRegistry::entityScopeOwner($table) === ['column' => $deviceColumn, 'target' => $deviceTable],
                'Component cached scope is declared by its actual owning definition property'
            );

            $graph = static function (bool $recursive = false, ?bool $targetRecursive = null) use ($fixtures, $rootAsset, $foreignAsset, $table, $deviceTable, $deviceColumn, $deviceClass, $kind, $payload): array {
                $source = $fixtures->create($deviceTable, ['designation' => 'Replacement source', 'entities_id' => 0, 'is_recursive' => $recursive]);
                $target = $fixtures->create($deviceTable, ['designation' => 'Replacement target', 'entities_id' => 0, 'is_recursive' => $targetRecursive ?? $recursive]);
                $root = $fixtures->create($table, [$deviceColumn => $source, 'itemtype' => $kind, 'items_id' => $rootAsset, 'serial' => 'Root original', 'otherserial' => null] + $payload);
                $foreign = $fixtures->create($table, [$deviceColumn => $source, 'itemtype' => $kind, 'items_id' => $foreignAsset, 'serial' => 'Foreign original', 'otherserial' => null] + $payload);
                $duplicate = $fixtures->create($table, [$deviceColumn => $source, 'itemtype' => $kind, 'items_id' => $foreignAsset, 'serial' => 'Duplicate original', 'otherserial' => null] + $payload);
                $stock = $fixtures->create($table, [$deviceColumn => $source, 'itemtype' => null, 'items_id' => 0, 'serial' => null, 'otherserial' => null] + $payload);
                $neighbor = $fixtures->create($table, [$deviceColumn => $target, 'itemtype' => null, 'items_id' => 0, 'serial' => null, 'otherserial' => null] + $payload);
                $device = new $deviceClass();
                verify($device->getFromDB($source), 'Load actual definition owner');
                return compact('source', 'target', 'root', 'foreign', 'duplicate', 'stock', 'neighbor', 'device');
            };
            $read = static function (string $table, int $id) use ($connection): ?array {
                return $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]) ?: null;
            };

            $g = $graph();
            $missing = $g['target'] + 1000000000;
            verify($read($deviceTable, $missing) === null, 'Owned absent definition identity is proved absent');
            foreach ([$g['source'], $missing, -1, 'invalid-definition'] as $invalidTarget) {
                $invalidOwner = new $deviceClass();
                verify($invalidOwner->getFromDB($g['source']), 'Load the actual source for each invalid destination');
                $before = $snapshot();
                verify($invalidOwner->delete(['id' => $g['source'], '_replace_by' => $invalidTarget], true) === false
                    && $snapshot() === $before, 'Self/missing/negative/malformed destination refuses with full native graph preservation');
            }
            $before = $snapshot();
            verify((new $deviceClass())->delete(['id' => $missing, '_replace_by' => $g['target']], true) === false
                && $snapshot() === $before, 'An absent source cannot delegate ownership or purge a neighbor');
            $input = ['id' => $g['foreign'], $deviceColumn => $g['target']];
            verify(!(new $linkClass())->can($g['foreign'], UPDATE, $input), 'Ordinary asset assignment authority remains denied');
            $before = $snapshot();
            $naked = new $deviceClass();
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
            foreach (['root', 'foreign', 'duplicate', 'stock', 'neighbor'] as $key) {
                $oldRows[$key] = $read($table, $g[$key]);
            }
            $level = $connection->getTransactionNestingLevel();
            $reloads = 0;
            $permissionRights = [];
            $PLUGIN_HOOKS['item_can']['family_definition_replacement_fixture'][$linkClass] = static function (Item_Devices $item) use ($g, $deviceColumn, &$permissionRights): void {
                if ((int)$item->getID() !== $g['foreign']) {
                    return;
                }
                $permissionRights[] = $item->right;
                verify(
                    (int)$item->fields[$deviceColumn] === $g['source'],
                    'Restrictive role hooks receive the actual stored definition row'
                );
                verify(
                    $item->update(['id' => $g['foreign'], 'serial' => 'Unowned probe mutation']) === false,
                    'Pure permission probes cannot enter a delegated mutation'
                );
            };
            $PLUGIN_HOOKS['item_update']['family_definition_replacement_fixture'][$linkClass] = static function (Item_Devices $item) use ($g, $foreignAsset, $deviceColumn, $subjectColumn, &$reloads): void {
                if ((int)$item->getID() !== $g['foreign']) {
                    return;
                }
                verify(
                    $item->getFromDB($item->getID()) && (int)$item->fields[$deviceColumn] === $g['target']
                    && (int)$item->fields[$subjectColumn] === $foreignAsset && $item->fields['serial'] === 'Foreign original',
                    'Real late public reload returns the exact admitted current child and retains subject/specificities'
                );
                ++$reloads;
            };
            // The trusted public delete seam does not invent a source PURGE requirement.
            $purgeRight = $_SESSION['glpiactiveprofile']['device'] ?? null;
            $_SESSION['glpiactiveprofile']['device'] = READ | UPDATE;
            verify($g['device']->delete(['id' => $g['source'], '_replace_by' => $g['target']], true), 'Actual trusted owner replacement preserves foreign links without asset editing grants');
            verify($permissionRights === [DELETE, PURGE], 'Actual retarget retains each original restrictive relation role once in order');
            verify($reloads === 1, 'A legitimate public late reload remains available once after the writer producer');
            $PLUGIN_HOOKS = $savedHooks;
            if ($purgeRight === null) {
                unset($_SESSION['glpiactiveprofile']['device']);
            } else {
                $_SESSION['glpiactiveprofile']['device'] = $purgeRight;
            }
            foreach (['root', 'foreign', 'duplicate', 'stock'] as $key) {
                $expected = $oldRows[$key];
                $expected[$deviceColumn] = $g['target'];
                verify($read($table, $g[$key]) === $expected, 'Replacement changes only owning definition and preserves full asset tuple/specificities/definition cache: ' . $key);
            }
            verify($read($table, $g['neighbor']) === $oldRows['neighbor'], 'Neighbor target binding remains byte/type exact');
            verify(
                $read($deviceTable, $g['source']) === null && $connection->getTransactionNestingLevel() === $level,
                'Actual source purge retains caller frame and required replacement links'
            );

            $completed = 0;
            foreach (['item_can_delete', 'item_can_purge', 'probe_fields', 'cancel', 'subject', 'scalar', 'late_scalar', 'late_input', 'actor', 'queue_throw', 'writer', 'source_scope', 'target_scope', 'destructive_child'] as $mode) {
                $g = $graph();
                $before = $snapshot();
                $checkpoint = LifecycleModelJournal::state($g['device']);
                $actor = Session::getLoginUserID();
                $called = 0;
                $callbackError = new RuntimeException('Actual definition replacement queue callback failure');
                $PLUGIN_HOOKS['item_update']['family_definition_replacement_fixture'][$linkClass] = static function () use (&$completed): void {
                    ++$completed;
                };
                $event = (str_starts_with($mode, 'item_can_') || $mode === 'probe_fields') ? 'item_can' : (in_array($mode, ['late_scalar', 'late_input', 'actor', 'queue_throw', 'writer', 'source_scope', 'target_scope', 'destructive_child'], true) ? 'item_update' : 'pre_item_update');
                $PLUGIN_HOOKS[$event]['family_definition_replacement_fixture'][$linkClass] = static function (Item_Devices $item) use ($mode, $g, $rootAsset, $subjectColumn, $deviceClass, $connection, $deviceTable, $table, &$called, $callbackError, &$completed): void {
                    if ((int)$item->getID() !== $g['foreign']) {
                        return;
                    }
                    if (str_starts_with($mode, 'item_can_')) {
                        $right = match ($mode) {
                            'item_can_delete' => DELETE, 'item_can_purge' => PURGE
                        };
                        if ($item->right !== $right) {
                            return;
                        }
                    }
                    ++$called;
                    if (str_starts_with($mode, 'item_can_')) {
                        $item->right = 0;
                    } elseif ($mode === 'probe_fields') {
                        $item->fields['serial'] = 'Hidden probe-only scalar amendment';
                    } elseif ($mode === 'cancel') {
                        $item->input = [];
                    } elseif ($mode === 'subject') {
                        $item->input[$subjectColumn] = $rootAsset;
                        $item->input['items_id'] = $rootAsset;
                    } elseif ($mode === 'scalar') {
                        $item->input['serial'] = 'Unowned scalar amendment';
                    } elseif ($mode === 'late_scalar') {
                        $item->fields['serial'] = 'Unowned late scalar amendment';
                    } elseif ($mode === 'late_input') {
                        $item->input[$subjectColumn] = $rootAsset;
                        $item->input['items_id'] = $rootAsset;
                    } elseif ($mode === 'actor') {
                        $_SESSION['glpiID'] = 0;
                    } elseif ($mode === 'source_scope' || $mode === 'target_scope') {
                        verify($connection->update($deviceTable, ['is_recursive' => true], ['id' => $g[$mode === 'source_scope' ? 'source' : 'target']], ['is_recursive' => \Doctrine\DBAL\Types\Types::BOOLEAN]) === 1, 'Actual late callback changes its selected persisted definition scope');
                    } elseif ($mode === 'destructive_child') {
                        verify($connection->delete($table, ['id' => $g['foreign']]) === 1, 'Actual late callback removes its selected persisted binding');
                    } elseif ($mode === 'writer') {
                        $GLOBALS['DB'] = clone $GLOBALS['DB'];
                    } else {
                        verify(
                            (new QueuedNotification())->add(['itemtype' => $deviceClass, 'items_id' => $g['source'],
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
            $g = $graph(false, true);
            $oldRows = [];
            foreach (['root', 'foreign', 'duplicate', 'stock', 'neighbor'] as $key) {
                $oldRows[$key] = $read($table, $g[$key]);
            }
            $targetRecord = $read($deviceTable, $g['target']);
            verify($g['device']->delete(['id' => $g['source'], '_replace_by' => $g['target']], true), 'A genuinely widened owning definition scope remains available to every unchanged asset');
            foreach (['root', 'foreign', 'duplicate', 'stock'] as $key) {
                $expected = array_replace($oldRows[$key], [$deviceColumn => $g['target'],
                    'entities_id' => $targetRecord['entities_id'], 'is_recursive' => $targetRecord['is_recursive']]);
                verify($read($table, $g[$key]) === $expected, 'Attached/duplicate/stock cached scope follows the new definition while subject and payload remain exact');
            }
            verify(
                $read($table, $g['neighbor']) === $oldRows['neighbor'] && $read($deviceTable, $g['source']) === null,
                'Widened replacement removes only the owned source and preserves the actual target neighbor'
            );
            verify($connection->getTransactionNestingLevel() === $level, 'Valid scope forwarding preserves the caller-owned frame');
            $g = $graph(true, false);
            $before = $snapshot();
            verify(
                !$g['device']->delete(['id' => $g['source'], '_replace_by' => $g['target']], true) && $snapshot() === $before,
                'A genuinely narrowed definition scope cannot invalidate an existing foreign asset link'
            );
        }
    }
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
    try {
        $plugins->setValue(null, $savedPlugins);
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Additional owned component cleanup failure: ' . $error::class . "\n");
        } catch (Throwable) {
            // Reporting a secondary failure cannot replace the actual primary.
        }
    }
    throw $primary;
}
if ($cleanup) {
    throw $cleanup[0];
}
verify($snapshot() === $baseline, 'Outer owned fixture rollback restores original native database rows and ledger');
verify((new SchemaCheck())->differences($connection) === [], 'Definition commands leave the complete composed schema unchanged');
echo $DB->getProvider() . ": three component families and every declared subject: public definition replacement ownership, restrictive hooks, native rollback and routing passed.\n";
