<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\V220\TreeParentReferences;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/tree-parents.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
// Database fixture rollbacks do not roll back filesystem caches. Earlier
// contracts can reuse an ID with stale ancestors after resetting sequences.
// Keep a real cache scoped to this fixture; transaction-private reads must not publish.
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    foreach (ReferenceHistory::get('optional', 'TREE_PARENTS') as $table => $relations) {
        $column = array_key_first($relations);
        $type = getItemTypeForTable($table);
        $model = getItemForItemtype($type);
        $root = $model->add(['name' => "Tree O'Reilly " . $type, 'entities_id' => 0]);
        $other = $model->add(['name' => 'Tree other ' . $type, 'entities_id' => 0]);
        $child = $model->add(['name' => 'Tree branch ' . $type, $column => $root, 'entities_id' => 0]);
        $leaf = $model->add(['name' => 'Tree leaf ' . $type, $column => $child, 'entities_id' => 0]);
        verify($root > 0 && $other > 0 && $child > 0 && $leaf > 0, 'Build tree ' . $table);
        verify($model->getFromDB($root) && $model->fields[$column] === null, 'Root uses NULL ' . $table);
        $input = ['name' => "Tree O'Reilly " . $type, 'entities_id' => 0];
        verify((int)$model->findID($input) === $root, 'Root lookup retains legacy empty-parent criteria');
        verify($model->getFromDB($leaf) && (int)$model->fields['level'] === 3, 'Tree depth');
        verify(str_contains($model->fields['completename'], "Tree O'Reilly " . $type . ' > Tree branch'), 'Quoted complete name');
        verify(array_values(array_map('intval', getAncestorsOf($table, $leaf))) === [$root, $child], 'Ancestor traversal ' . $table . ' expected ' . json_encode([$root, $child]) . ' actual ' . json_encode(getAncestorsOf($table, $leaf)));
        $sons = getSonsOf($table, $root);
        verify(isset($sons[$root], $sons[$child], $sons[$leaf]) && count($sons) === 3, 'Descendant traversal');
        verify($connection->isTransactionActive() && !$GLPI_CACHE->has('sons_cache_' . $table . '_' . $root)
            && getSonsOf($table, $root) === $sons && !$GLPI_CACHE->has('sons_cache_' . $table . '_' . $root), 'Private descendant reads repeat the authoritative tree without publishing');
        verify($model->update(['id' => $root, 'name' => "Renamed O'Reilly " . $type]), 'Rename root');
        verify($model->getFromDB($leaf) && str_starts_with($model->fields['completename'], "Renamed O'Reilly " . $type . ' > '), 'Rename propagates complete names');
        verify($model->update(['id' => $child, $column => $other]), 'Move subtree');
        verify(array_values(array_map('intval', getAncestorsOf($table, $leaf))) === [$other, $child], 'Move invalidates ancestor caches');
        verify(!isset(getSonsOf($table, $root)[$child]), 'Move invalidates old descendant cache');
        verify(!$model->update(['id' => $other, $column => $leaf]), 'Reject cycle through a descendant');
        verify($model->delete(['id' => $child], true), 'Purge internal node');
        verify($model->getFromDB($leaf) && (int)$model->fields[$column] === $other && (int)$model->fields['level'] === 2, 'Purge reparents child and fixes depth');
        verify($model->delete(['id' => $other], true), 'Purge root');
        verify($model->getFromDB($leaf) && $model->fields[$column] === null && (int)$model->fields['level'] === 1, 'Root purge promotes children');
        $child2 = $model->add(['name' => 'Replacement child ' . $type, $column => $root, 'entities_id' => 0]);
        verify($model->delete(['id' => $root, '_replace_by' => $leaf], true), 'Replace root');
        verify($model->getFromDB($child2) && (int)$model->fields[$column] === $leaf, 'Replacement moves descendants');
        if (isset(\itsmng\Database\Migration\V220\TreeUniqueness::TABLES[$table])) {
            $connection->beginTransaction();
            try {
                $rejected = false;
                try {
                    $fixtures->create($table, ['name' => 'Tree leaf ' . $type]);
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
                    $rejected = true;
                }
                verify($rejected, 'Root sibling uniqueness survives NULL: ' . $table);
            } finally {
                $connection->rollBack();
            }
            $different = $fixtures->create($table, ['name' => 'Tree leaf ' . $type, $column => $child2]);
            verify($different > 0, 'Equal names under different parents are permitted');
        }
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $SQL_TOTAL_REQUEST = 0;
        $repository = new \itsmng\Database\Repository\TreeRepository(\itsmng\Database\Orm::create($DB));
        $repository->updateDerived($table, [$leaf, $child2], ['sons_cache' => null, 'ancestors_cache' => null]);
        $GLPI_CACHE->delete('ancestors_cache_' . $table . '_' . $child2);
        $GLPI_CACHE->delete('sons_cache_' . $table . '_' . $leaf);
        getAncestorsOf($table, $child2);
        getSonsOf($table, $leaf);
        $model->regenerateTreeUnderID($leaf, true, true);
        verify($SQL_TOTAL_REQUEST === 0, 'Tree cache reads, writes and regeneration use ORM: ' . $table);
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Tree relationships remain valid');
} finally {
    $DB->rollBack();
    $GLPI_CACHE = $savedCache;
}

// Cache admission belongs to a genuinely idle supplied connection, even with an
// isolated backend. These committed NULL-root graphs have no native ancestors.
verify(!$connection->isTransactionActive() && Toolbox::useCache(), 'Committed cache probe starts on the idle supplied connection');
$treeTables = array_keys(ReferenceHistory::get('optional', 'TREE_PARENTS'));
$committedRows = static function () use ($connection, $treeTables): array {
    $rows = [];
    foreach ([...$treeTables, 'glpi_logs'] as $table) {
        $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
    }
    return $rows;
};
$beforeCommitted = $committedRows();
$savedSession = $_SESSION;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$owned = [];
$primary = null;
$cleanupErrors = [];
$prefix = 'Committed tree ' . bin2hex(random_bytes(8));
try {
    foreach (ReferenceHistory::get('optional', 'TREE_PARENTS') as $table => $relations) {
        $column = array_key_first($relations);
        $type = getItemTypeForTable($table);
        $model = getItemForItemtype($type);
        // Assigned public creation avoids reusing either native rows or orphaned
        // native item history. No existing tree cache or history needs restoration.
        $first = 1000 + max(
            (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ' . $connection->quoteIdentifier($table)),
            (int)$connection->fetchOne('SELECT COALESCE(MAX(items_id), 0) FROM glpi_logs WHERE itemtype = ?', [$type])
        );
        [$root, $other, $child, $leaf] = range($first, $first + 3);
        foreach ([$root => null, $other => null, $child => $root, $leaf => $child] as $id => $parent) {
            $name = $prefix . ' ' . $type . ' ' . $id;
            verify(!$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id])
                && !$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE itemtype = ? AND items_id = ?', [$type, $id]), 'Committed identity owns no native row or history: ' . $table);
            $owned[] = ['table' => $table, 'type' => $type, 'id' => $id, 'names' => [$name]];
            verify(
                $model->addWithAssignedIdentifier($id, ['name' => $name, $column => $parent, 'entities_id' => 0]) === $id,
                'Committed public tree creation: ' . $table
            );
        }
        verify(!$connection->isTransactionActive() && $model->getFromDB($root) && $model->fields[$column] === null, 'Committed public roots remain NULL on the idle connection: ' . $table);
        $sons = getSonsOf($table, $root);
        verify(isset($sons[$root], $sons[$child], $sons[$leaf]) && count($sons) === 3, 'Committed descendant traversal: ' . $table);
        verify($GLPI_CACHE->has('sons_cache_' . $table . '_' . $root) && getSonsOf($table, $root) === $sons, 'Warm descendant cache');
        $ancestors = getAncestorsOf($table, $leaf);
        verify(array_values(array_map('intval', $ancestors)) === [$root, $child]
            && $GLPI_CACHE->has('ancestors_cache_' . $table . '_' . $leaf) && getAncestorsOf($table, $leaf) === $ancestors, 'Warm committed scalar ancestor cache: ' . $table);
        $renamed = $prefix . ' renamed ' . $type;
        $owned[count($owned) - 4]['names'][] = $renamed;
        verify($model->update(['id' => $root, 'name' => $renamed]), 'Committed public rename: ' . $table);
        verify(!$connection->isTransactionActive() && $model->getFromDB($leaf)
            && str_starts_with($model->fields['completename'], $renamed . ' > ') && getSonsOf($table, $root) === $sons, 'Committed rename propagates names and retains descendant membership: ' . $table);
        verify($model->update(['id' => $child, $column => $other]), 'Committed public subtree move: ' . $table);
        verify(!$connection->isTransactionActive()
            && (int)$connection->fetchOne('SELECT ' . $connection->quoteIdentifier($column) . ' FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$child]) === $other
            && !$GLPI_CACHE->has('sons_cache_' . $table . '_' . $root)
            && !$GLPI_CACHE->has('ancestors_cache_' . $table . '_' . $leaf), 'Committed move invalidates warmed owning scalar keys: ' . $table);
        verify(array_values(array_map('intval', getAncestorsOf($table, $leaf))) === [$other, $child], 'Committed move refreshes scalar ancestor cache: ' . $table);
        verify(getSonsOf($table, $root) === [$root => $root]
            && isset(getSonsOf($table, $other)[$child], getSonsOf($table, $other)[$leaf]), 'Committed move refreshes old and new descendant caches: ' . $table);
        verify($GLPI_CACHE->has('sons_cache_' . $table . '_' . $root)
            && $GLPI_CACHE->has('sons_cache_' . $table . '_' . $other)
            && $GLPI_CACHE->has('ancestors_cache_' . $table . '_' . $leaf), 'Idle cache warming resumes after committed move: ' . $table);
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    foreach (array_reverse($owned) as $record) {
        try {
            $row = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($record['table']) . ' WHERE id = ?', [$record['id']]);
            if ($row !== false) {
                verify(in_array($row['name'], $record['names'], true), 'Cleanup owns the exact committed tree row');
                verify(getItemForItemtype($record['type'])->delete(['id' => $record['id']], true), 'Reverse leaf-order public committed cleanup');
            }
            // Some tree models disable item history but their public move hook
            // still logs parent changes. Remove only these owned item log IDs.
            foreach ($connection->fetchAllAssociative('SELECT * FROM glpi_logs WHERE itemtype = ? AND items_id = ?', [$record['type'], $record['id']]) as $log) {
                $connection->delete('glpi_logs', ['id' => $log['id'], 'itemtype' => $record['type'], 'items_id' => $record['id']]);
            }
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    try {
        verify(!$connection->isTransactionActive() && $committedRows() === $beforeCommitted, 'Committed probe preserves full native tree rows, derived fields and history');
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    $GLPI_CACHE = $savedCache;
    $_SESSION = $savedSession;
}
if ($primary !== null) {
    if ($cleanupErrors !== []) {
        fwrite(STDERR, 'Additional committed tree cleanup failures: ' . count($cleanupErrors) . "\n");
    }
    throw $primary;
}
if ($cleanupErrors !== []) {
    throw new RuntimeException('Committed tree cleanup failed: ' . implode('; ', array_map(static fn (Throwable $error): string => $error->getMessage(), $cleanupErrors)), previous: $cleanupErrors[0]);
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new TreeParentReferences();
$legacyId = null;
try {
    foreach (ReferenceHistory::get('optional', 'TREE_PARENTS') as $table => $relations) {
        $column = array_key_first($relations);
        $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
        $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        $after->getColumn($column)->setNotnull(true)->setDefault(0);
        if (isset(\itsmng\Database\Migration\V220\TreeUniqueness::TABLES[$table])) {
            $index = \itsmng\Database\Migration\V220\TreeUniqueness::indexName($table, $platform);
            $after->dropIndex($index);
            $after->dropColumn('parent_key');
            $after->addUniqueIndex(\itsmng\Database\Migration\V220\TreeUniqueness::TABLES[$table], $index);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_taskcategories');
    $connection->insert('glpi_taskcategories', ['id' => $legacyId, 'name' => 'Legacy tree root']);
    $connection->update('glpi_taskcategories', ['taskcategories_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned tree parent');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_businesscriticities')['businesscriticities_id']->getNotnull(), 'Orphan audit precedes all DDL');
    $connection->update('glpi_taskcategories', ['taskcategories_id' => $legacyId], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Cyclic tree parents');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_businesscriticities')['businesscriticities_id']->getNotnull(), 'Cycle audit precedes all DDL');
    $connection->update('glpi_taskcategories', ['taskcategories_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT taskcategories_id FROM glpi_taskcategories WHERE id = ?', [$legacyId]) === null, 'Legacy root becomes NULL');
    // Model an interrupted index replacement, then prove duplicate preflight and retry.
    $index = \itsmng\Database\Migration\V220\TreeUniqueness::indexName('glpi_states', $platform);
    $connection->executeStatement($platform->getDropIndexSQL($index, 'glpi_states'));
    try {
        $connection->insert('glpi_states', ['name' => 'Duplicate tree sibling']);
        $connection->insert('glpi_states', ['name' => 'Duplicate tree sibling']);
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Duplicate tree siblings');
        }
        verify($rejected, 'Interrupted migration refuses duplicate root names');
    } finally {
        $connection->delete('glpi_states', ['name' => 'Duplicate tree sibling']);
    }
    $migration->apply($connection);
    verify($connection->createSchemaManager()->introspectTable('glpi_states')->hasIndex($index), 'Retry restores missing uniqueness index');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Tree migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_taskcategories', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": tree parents, caches, reparenting, uniqueness and migration passed.\n";
