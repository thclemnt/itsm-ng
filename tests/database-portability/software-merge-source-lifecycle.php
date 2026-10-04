<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-merge-source-lifecycle.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
/** A real overridable preload, not a registered Plugin hook. */
class SoftwareMergeOwnerRebind extends Software
{
    public int $loads = 0;
    public int $rebindAt = 0;
    public ?DBAdapter $replacement = null;

    public static function getTable($classname = null)
    {
        return Software::getTable();
    }

    public function post_getFromDB()
    {
        ++$this->loads;
        if ($this->loads === $this->rebindAt) {
            $GLOBALS['DB'] = $this->replacement;
        }
    }
}

$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
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
$plugins->setValue(null, [...$savedPlugins, 'software_merge_source_fixture']);
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Fixture starts outside caller frames');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$created = [];
$fixtures = new FixtureRecords($DB, static function (string $table, int $id) use (&$created): void {
    $created[] = [$table, $id];
});
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$allRows = static fn (string $table): array => $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
$snapshot = static fn (): array => [$allRows('glpi_softwares'), $allRows('glpi_softwareversions'), $allRows('glpi_softwarelicenses'),
    $allRows('glpi_items_softwareversions'), $allRows('glpi_items_softwarelicenses'), $allRows('glpi_logs'), $allRows('glpi_queuednotifications')];
$baseline = $snapshot();
$prefix = 'Merge source ' . bin2hex(random_bytes(6));
$primary = null;
$cleanup = [];

try {
    $category = $fixtures->create('glpi_softwarecategories', ['name' => $prefix . ' deletion category']);
    $CFG_GLPI['softwarecategories_id_ondelete'] = $category;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;

    foreach (['standalone', 'caller'] as $context) {
        foreach (['delete veto', 'update veto', 'legacy update veto', 'delete identity', 'update identity', 'late restore', 'late template', 'delete exception', 'accept'] as $case) {
            $source = $fixtures->create('glpi_softwares', ['name' => $prefix . ' ' . $context . ' ' . $case, 'comment' => 'Original source comment']);
            $other = $fixtures->create('glpi_softwares', ['name' => $prefix . ' unrelated ' . $context . ' ' . $case]);
            $model = new Software();
            verify($model->getFromDB($source), 'Load selected source before the public command');
            $checkpoint = LifecycleModelJournal::state($model);
            $otherBefore = $read('glpi_softwares', $other);
            $before = $snapshot();
            $events = [];
            $PLUGIN_HOOKS['pre_item_delete']['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($source, $case, &$events): void {
                if ((int)$item->getID() === $source) {
                    $events[] = 'pre_item_delete';
                    if ($case === 'delete veto') {
                        $item->input = false;
                    }
                }
            };
            $PLUGIN_HOOKS['item_delete']['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($source, $other, $case, &$events): void {
                if ((int)$item->getID() === $source) {
                    $events[] = 'item_delete';
                    if ($case === 'delete identity') {
                        $item->fields['id'] = $other;
                    } elseif ($case === 'delete exception') {
                        throw new RuntimeException('Actual merge source delete callback failure');
                    }
                }
            };
            $PLUGIN_HOOKS['pre_item_update']['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($source, $case, &$events): void {
                if ((int)$item->getID() === $source && array_key_exists('comment', $item->input)) {
                    $events[] = 'pre_item_update';
                    verify(($item->input['is_deleted'] ?? null) === 1 && (bool)$item->fields['is_deleted'], 'Follow-up hook retains the original raw trash flag and runs after real deletion');
                    if ($case === 'update veto' || ($case === 'legacy update veto' && ($item->input['is_deleted'] ?? null) === 1)) {
                        $item->input = false;
                    }
                }
            };
            $PLUGIN_HOOKS['item_update']['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($source, $other, $case, &$events): void {
                if ((int)$item->getID() === $source && array_key_exists('comment', $item->input)) {
                    $events[] = 'item_update';
                    if ($case === 'late restore') {
                        verify((new Software())->update(['id' => $source, 'is_deleted' => 0]) === true, 'Actual completion callback restores the selected source on the same writer');
                    } elseif ($case === 'late template') {
                        verify((new Software())->update(['id' => $source, 'is_template' => 1]) === true, 'Actual completion callback changes the selected source into a template on the same writer');
                    } elseif ($case === 'update identity') {
                        $item->fields['id'] = $other;
                    }
                }
            };
            $callerScope = null;
            $caseFailure = null;
            if ($context === 'caller') {
                $connection->beginTransaction();
                $callerScope = $connection->captureManagedTransactionScope();
            }
            try {
                $level = $connection->getTransactionNestingLevel();
                $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' caller marker']) : null;
                $caught = null;
                try {
                    $result = $model->removeMergedSource($source, 'Merged source comment');
                } catch (RuntimeException $error) {
                    $caught = $error;
                    $result = false;
                }
                if ($case === 'delete exception') {
                    verify($caught !== null && $caught->getMessage() === 'Actual merge source delete callback failure', 'Actual callback primary is retained');
                } else {
                    verify($caught === null, 'Ordinary refusal and acceptance do not hide another exception');
                }
                verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1
                    && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Command preserves the usable caller frame and marker');
                if ($case === 'accept') {
                    verify($result === true && $events === ['pre_item_delete', 'item_delete', 'pre_item_update', 'item_update'], 'Delete completes before the ordinary follow-up update');
                    $actual = $read('glpi_softwares', $source);
                    verify((bool)$actual['is_deleted'] && (int)$actual['softwarecategories_id'] === $category
                        && $actual['comment'] === "\nMerged source comment", 'Persisted deletion retains the existing merge category and conditional-newline comment behavior');
                    $history = $records()->matching('glpi_logs', ['itemtype' => 'Software', 'items_id' => $source], 'id ASC');
                    verify(count(array_filter($history, static fn (array $row): bool => (int)$row['linked_action'] === Log::HISTORY_DELETE_ITEM)) === 1, 'Actual deletion lifecycle writes its source removal history once');
                    verify($read('glpi_softwares', $other) === $otherBefore, 'Accepted source removal leaves the unrelated source byte-exact');
                } else {
                    verify($result === false && $snapshot() === $before, 'Refusal rolls back source deletion, follow-up intents, all audit and queue rows');
                    verify(LifecycleModelJournal::state($model) === $checkpoint, 'Proven owned rollback restores the retained selected-source model');
                    verify($events[0] === 'pre_item_delete', 'Refusal traverses the actual delete lifecycle');
                    if ($case === 'delete veto') {
                        verify($events === ['pre_item_delete'], 'A delete veto prevents subsequent removal or follow-up work');
                    }
                }
            } catch (Throwable $error) {
                $caseFailure = $error;
                throw $error;
            } finally {
                foreach (['pre_item_delete', 'item_delete', 'pre_item_update', 'item_update'] as $event) {
                    unset($PLUGIN_HOOKS[$event]['software_merge_source_fixture']);
                }
                if ($callerScope !== null) {
                    try {
                        $callerScope->assertActive();
                        $connection->rollBack();
                    } catch (Throwable $error) {
                        if ($caseFailure === null) {
                            throw $error;
                        }
                        $cleanup[] = $error;
                    }
                }
            }
        }
    }

    // Same database, genuinely distinct canonical writer. The fixture restores
    // its own callback-global afterwards; the command never adopts that writer.
    $originalDatabase = $DB;
    $secondary = new DB();
    $secondaryFailure = null;
    try {
        verify($secondary !== $originalDatabase && !$secondary->isSlave()
            && $secondary->dbdefault === $originalDatabase->dbdefault
            && $secondary->getDoctrineConnection() !== $connection, 'Rebind control owns a separate canonical writer for the same disposable database');
        foreach ([1, 2] as $loadNumber) {
            $source = $fixtures->create('glpi_softwares', ['name' => $prefix . ' writer preload ' . $loadNumber]);
            $before = $snapshot();
            $beforeSource = $connection->fetchAssociative('SELECT * FROM glpi_softwares WHERE id = ?', [$source]);
            $model = new SoftwareMergeOwnerRebind();
            $model->rebindAt = $loadNumber;
            $model->replacement = $secondary;
            $refused = false;
            try {
                $model->removeMergedSource($source, 'Must not reach foreign writer');
            } catch (\itsmng\Database\TransactionOwnershipMismatch $error) {
                $refused = true;
            } finally {
                $DB = $originalDatabase;
            }
            verify($refused && $model->loads === $loadNumber && $snapshot() === $before,
                'Initial and nested own preload refuse writer rebinding before adopting a foreign mutation owner');
            verify($secondary->getDoctrineConnection()->getTransactionNestingLevel() === 0
                && $secondary->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_softwares WHERE id = ?', [$source]) === $beforeSource,
                'Actual secondary writer has no adopted frame or source mutation');
        }
        $source = $fixtures->create('glpi_softwares', ['name' => $prefix . ' writer after deletion']);
        $before = $snapshot();
        $PLUGIN_HOOKS['item_delete']['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($source, $secondary): void {
            if ((int)$item->getID() === $source) {
                $GLOBALS['DB'] = $secondary;
            }
        };
        $refused = false;
        try {
            (new Software())->removeMergedSource($source, 'Must not adopt follow-up writer');
        } catch (\itsmng\Database\TransactionOwnershipMismatch $error) {
            $refused = true;
        } finally {
            $DB = $originalDatabase;
            unset($PLUGIN_HOOKS['item_delete']['software_merge_source_fixture']);
        }
        verify($refused && $snapshot() === $before && $secondary->getDoctrineConnection()->getTransactionNestingLevel() === 0,
            'Actual delete completion rebind is refused before follow-up update and original-owner rollback is proven');
    } catch (Throwable $error) {
        $secondaryFailure = $error;
        throw $error;
    } finally {
        $DB = $originalDatabase;
        try {
            $secondary->close();
        } catch (Throwable $error) {
            if ($secondaryFailure === null) {
                throw $error;
            }
            $cleanup[] = $error;
        }
    }

    // Dictionary's existing update policy stays distinct from merge removal.
    $dictionary = $fixtures->create('glpi_softwares', ['name' => $prefix . ' dictionary', 'comment' => 'Original dictionary comment']);
    $deleteCalls = 0;
    $PLUGIN_HOOKS['pre_item_delete']['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($dictionary, &$deleteCalls): void {
        if ((int)$item->getID() === $dictionary) {
            ++$deleteCalls;
            $item->input = false;
        }
    };
    verify((new Software())->putInTrash($dictionary, 'Dictionary comment') === true && $deleteCalls === 0, 'Existing dictionary update does not acquire the new merge delete policy');
    verify($read('glpi_softwares', $dictionary)['comment'] === "\nDictionary comment", 'Dictionary comment behavior is unchanged');
    unset($PLUGIN_HOOKS['pre_item_delete']['software_merge_source_fixture']);

    $template = $fixtures->create('glpi_softwares', ['name' => $prefix . ' template', 'is_template' => 1]);
    $templateVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $template, 'name' => $prefix . ' template version']);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $template, 'softwareversions_id_buy' => $templateVersion, 'softwareversions_id_use' => $templateVersion]);
    $target = $fixtures->create('glpi_softwares', ['name' => $prefix . ' template merge destination']);
    $templateHooks = 0;
    foreach (['pre_item_update', 'item_update', 'pre_item_delete', 'item_delete'] as $event) {
        $PLUGIN_HOOKS[$event]['software_merge_source_fixture'][Software::class] = static function (Software $item) use ($template, $target, &$templateHooks): void {
            if (in_array((int)$item->getID(), [$template, $target], true)) {
                ++$templateHooks;
            }
        };
    }
    $before = $snapshot();
    verify((new Software())->removeMergedSource($template, 'Do not purge') === false && $snapshot() === $before, 'Merge source removal refuses templates before public delete can force a physical purge');
    $templateMerge = new Software();
    verify($templateMerge->getFromDB($target) && $templateMerge->merge([$template => 1], false) === false
        && $templateHooks === 0 && $snapshot() === $before, 'Public merge refuses selected template before child/version moves, aggregate callbacks, deletion or audit');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
    $CFG_GLPI = $savedConfig;
    $_SESSION = $savedSession;
    foreach (array_reverse($created) as [$table, $id]) {
        try {
            (new \itsmng\Database\MappedStorage($DB))->delete($table, $id);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    try {
        // All new source audit rows belong to the exclusively created IDs.
        $softwareIds = array_column(array_filter($created, static fn (array $row): bool => $row[0] === 'glpi_softwares'), 1);
        foreach ($softwareIds as $id) {
            (new \itsmng\Database\Repository\HistoryRepository(Orm::create($DB)))->deleteForItem('Software', $id);
        }
        verify($snapshot() === $baseline, 'Fixture cleanup restores all original source, audit and queue rows');
        verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after tests');
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try { fwrite(STDERR, 'Additional fixture cleanup failure: ' . $error::class . "\n"); } catch (Throwable) { }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
fwrite(STDOUT, 'software merge source lifecycle passed: ' . $assertions . " assertions\n");
