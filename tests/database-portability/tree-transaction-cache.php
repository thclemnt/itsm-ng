<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\TreeRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tree-transaction-cache.php /path/to/test-config\n");
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
verify($DB instanceof DBAdapter && !$DB->isSlave() && str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated configured writer');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Real administrator login');
verify(Toolbox::useCache(), 'Normal application cache is available');
$connection = $DB->getDoctrineConnection();
verify(!$connection->isTransactionActive(), 'Contract owns an initially idle supplied connection');
$savedSession = $_SESSION;
$savedSessionId = session_id();
$savedSessionStatus = session_status();
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$savedTranslation = $TRANSLATE;
$savedLocale = class_exists('Locale') ? Locale::getDefault() : null;
$cache = $GLPI_CACHE;
$remembered = [];
$remember = static function (string $key) use (&$remembered, $cache): void {
    if (!array_key_exists($key, $remembered)) {
        $present = $cache->has($key);
        $remembered[$key] = ['present' => $present, 'value' => $present ? $cache->get($key) : null];
    }
};
$ancestorKey = static fn (int|array $ids): string => 'ancestors_cache_glpi_entities_' . (is_array($ids) ? md5(implode('|', $ids)) : $ids);
$sonsKey = static fn (int $id): string => 'sons_cache_glpi_entities_' . $id;
$tables = ['glpi_entities', 'glpi_users', 'glpi_profiles', 'glpi_profilerights', 'glpi_profiles_users', 'glpi_logs'];
$rows = static function () use ($connection, $tables): array {
    $result = [];
    foreach ($tables as $table) {
        $result[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
    }
    return $result;
};
$baseline = $rows();
$root = $connection->fetchAssociative('SELECT ancestors_cache, sons_cache FROM glpi_entities WHERE id = 0');
verify($root !== false, 'The committed root cache belongs to an existing entity');
$primary = null;
$cleanupErrors = [];
$committed = [];
try {
    $CFG_GLPI['use_notifications'] = false;
    // Idle reads still admit the normal cache; root ancestry is a real committed empty path.
    $remember($ancestorKey(0));
    $cache->set($ancestorKey(0), []);
    verify(getAncestorsOf('glpi_entities', 0) === [] && $cache->has($ancestorKey(0)), 'Idle committed root ancestry remains cacheable');
    $connection->beginTransaction();
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Tree transaction ' . bin2hex(random_bytes(5));
    $parent = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1000 FROM glpi_entities');
    $child = $parent + 1;
    $grandchild = $parent + 2;
    $foreign = $parent + 3;
    foreach ([$parent, $child, $grandchild, $foreign] as $id) {
        verify(!$connection->fetchOne('SELECT COUNT(*) FROM glpi_entities WHERE id = ?', [$id]), 'Owned graph identity is unused');
        foreach ([$ancestorKey($id), $ancestorKey([$id]), $sonsKey($id)] as $key) {
            $remember($key);
        }
    }
    $remember($sonsKey(0));
    $fixtures->create('glpi_entities', ['id' => $parent, 'name' => $prefix . ' parent', 'entities_id' => 0]);
    $childModel = new Entity();
    verify($childModel->add(['name' => $prefix . ' child', 'entities_id' => $parent]) === $child, 'Public add owns the child edge on the caller connection');
    $grandchildModel = new Entity();
    verify($grandchildModel->add(['name' => $prefix . ' grandchild', 'entities_id' => $child]) === $grandchild, 'Public add owns the descendant edge');
    $foreignModel = new Entity();
    verify($foreignModel->add(['name' => $prefix . ' foreign', 'entities_id' => 0]) === $foreign, 'Public add owns an unrelated root sibling');
    $profile = $fixtures->create('glpi_profiles', ['name' => $prefix, 'interface' => 'central']);
    $token = bin2hex(random_bytes(24));
    $user = $fixtures->create('glpi_users', ['name' => $prefix, 'personal_token' => $token, 'entities_id' => $parent,
        'profiles_id' => $profile, 'is_active' => true, 'is_deleted' => false, 'begin_date' => null, 'end_date' => null]);
    $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $parent, 'is_recursive' => true]);
    // This is the observed previous root-sibling path, not an invalid token or missing grant.
    $cache->set($ancestorKey($child), [0 => 0]);
    $accepted = Session::authWithToken($token, 'personal_token', $child, false);
    verify($accepted instanceof User && $accepted->getID() === $user && $_SESSION['glpiactive_entity'] === $child
        && $_SESSION['glpiactiveentities'] === [$child => $child], 'Current owning edges admit the actual recursive-grant token despite stale shared sibling ancestry');
    verify(!$cache->has($ancestorKey($child)), 'Private authentication invalidates shared ancestry rather than publishing its uncommitted path');
    $_SESSION = $savedSession;
    $tree = new TreeRepository(Orm::create($DB));
    $raw = static fn (int $id): array => $connection->fetchAssociative('SELECT * FROM glpi_entities WHERE id = ?', [$id]);
    $assertPrivate = static function (int $depth) use ($connection, $parent, $child, $grandchild, $foreign, $tree, $cache, $ancestorKey, $sonsKey, $raw): void {
        verify($connection->getTransactionNestingLevel() === $depth, 'Tree queries preserve caller transaction depth');
        foreach ([$child, $grandchild] as $id) {
            $tree->updateDerived('glpi_entities', [$id], ['ancestors_cache' => (new DbUtils())->exportArrayToDB([$foreign => $foreign])]);
        }
        $tree->updateDerived('glpi_entities', [$parent], ['sons_cache' => (new DbUtils())->exportArrayToDB([$parent => $parent, $foreign => $foreign])]);
        $before = [$raw($parent), $raw($child), $raw($grandchild)];
        $arrayKey = $ancestorKey([$child, $grandchild]);
        $cache->set($ancestorKey($child), [$foreign => $foreign]);
        $cache->set($ancestorKey($grandchild), [$foreign => $foreign]);
        $cache->set($arrayKey, [$foreign => $foreign]);
        $cache->set($sonsKey($parent), [$parent => $parent, $foreign => $foreign]);
        verify(getAncestorsOf('glpi_entities', $child) === [0 => 0, $parent => $parent], 'Scalar ancestry follows current edges and root-first order inside the private frame');
        verify(getAncestorsOf('glpi_entities', $grandchild) === [0 => 0, $parent => $parent, $child => $child], 'The seeded descendant scalar cache is exercised through its actual authoritative path');
        verify(getAncestorsOf('glpi_entities', [$child, $grandchild]) === [0 => 0, $parent => $parent, $child => $child], 'Array ancestry ignores shared and persistent derived paths inside the private frame');
        verify(getSonsOf('glpi_entities', $parent) === [$parent => $parent, $child => $child, $grandchild => $grandchild], 'Descendants follow current owning edges instead of cached foreign siblings');
        verify(getAncestorsOf('glpi_entities', 0) === [], 'Private authoritative root ancestry remains empty');
        verify([$raw($parent), $raw($child), $raw($grandchild)] === $before, 'Private tree reads do not persist replacement derived cache columns');
        foreach ([$ancestorKey($child), $ancestorKey($grandchild), $arrayKey, $sonsKey($parent)] as $key) {
            verify(!$cache->has($key), 'No private scalar/array ancestry or sons is published to the shared backend');
        }
    };
    $combined = $ancestorKey([$child, $grandchild]);
    $remember($combined);
    $assertPrivate(1);
    $connection->beginTransaction();
    try {
        $assertPrivate(2);
        // An owning edge can change before a repair has regenerated derived columns.
        $tree->reparent('glpi_entities', 'entities_id', [$grandchild], $foreign);
        verify(getAncestorsOf('glpi_entities', $grandchild) === [0 => 0, $foreign => $foreign], 'Nested raw ownership change is visible despite persistent stale ancestry');
    } catch (Throwable $error) {
        try {
            $connection->rollBack();
        } catch (Throwable $cleanupError) {
            $cleanupErrors[] = $cleanupError;
        }
        throw $error;
    }
    $connection->rollBack();
    verify(getAncestorsOf('glpi_entities', $grandchild) === [0 => 0, $parent => $parent, $child => $child], 'Savepoint rollback restores the outer hierarchy without shared cached private state');
    verify($childModel->update(['id' => $child, 'entities_id' => $foreign]), 'Public move accepts the new real root sibling');
    verify(getAncestorsOf('glpi_entities', $grandchild) === [0 => 0, $foreign => $foreign, $child => $child], 'Public move updates descendant owning ancestry');
    verify(!isset(getSonsOf('glpi_entities', $parent)[$child]), 'Public move removes descendants from their old parent');
    verify($grandchildModel->delete(['id' => $grandchild], true), 'Public leaf purge runs its owning deletion frame inside the caller transaction');
    verify(!$connection->fetchOne('SELECT COUNT(*) FROM glpi_entities WHERE id = ?', [$grandchild])
        && !isset(getSonsOf('glpi_entities', $child)[$grandchild]) && !isset(getSonsOf('glpi_entities', $foreign)[$grandchild]), 'Public leaf purge removes its actual row and both ancestor descendant projections');
    verify($connection->getTransactionNestingLevel() === 1, 'Move and purge retain the ordinary caller-owned transaction');
    $connection->rollBack();
    verify($rows() === $baseline, 'Outer rollback preserves complete application rows including original persistent tree caches and audit history');
    verify(!$connection->isTransactionActive(), 'Outer rollback returns the supplied writer to idle');
    $cache->set($ancestorKey(0), []);
    verify(getAncestorsOf('glpi_entities', 0) === [] && $cache->has($ancestorKey(0)), 'Idle cache admission resumes after caller rollback');
    $_SESSION = $savedSession;
    $addCommitted = static function (string $name, int $owner) use ($connection, &$committed, $remember, $ancestorKey, $sonsKey): int {
        $id = 1 + (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_entities');
        foreach ([$ancestorKey($id), $ancestorKey([$id]), $sonsKey($id)] as $key) {
            $remember($key);
        }
        verify(!$connection->fetchOne('SELECT COUNT(*) FROM glpi_entities WHERE id = ?', [$id]), 'Committed creation owns an unused identity');
        $committed[$id] = $name; // A partial add is cleaned only when its actual marker matches.
        verify((new Entity())->add(['name' => $name, 'entities_id' => $owner]) === $id, 'Idle public creation owns its unused assigned identity');
        return $id;
    };
    $idleParent = $addCommitted($prefix . ' committed parent', 0);
    $idleForeign = $addCommitted($prefix . ' committed foreign', 0);
    $idleChild = $addCommitted($prefix . ' committed child', $idleParent);
    $idlePath = [0 => 0, $idleParent => $idleParent];
    verify(!$connection->isTransactionActive() && getAncestorsOf('glpi_entities', $idleChild) === $idlePath, 'Committed nonroot scalar ancestry preserves root-first order');
    verify($cache->has($ancestorKey($idleChild)) && $cache->get($ancestorKey($idleChild)) === $idlePath, 'Committed scalar ancestry still uses the normal shared backend');
    $stored = $connection->fetchOne('SELECT ancestors_cache FROM glpi_entities WHERE id = ?', [$idleChild]);
    verify((new DbUtils())->importArrayFromDB($stored, true) === $idlePath, 'Committed scalar ancestry still generates its owning persistent derived cache');
    verify(Session::changeActiveEntities($idleChild, false), 'Actual administrator selects the committed child scope');
    $cache->set($ancestorKey([$idleChild]), $idlePath);
    $idleModel = new Entity();
    verify($idleModel->getFromDB($idleChild) && $idleModel->update(['id' => $idleChild, 'entities_id' => $idleForeign]), 'Idle public reparent commits the actual new owner');
    verify(!$connection->isTransactionActive() && (int)$raw($idleChild)['entities_id'] === $idleForeign, 'Public reparent has a committed native edge');
    verify(!Session::haveAccessToEntity($idleParent, true) && Session::haveAccessToEntity($idleForeign, true), 'Recursive entity authorization follows the committed move despite the old aggregate cache');
    verify(getAncestorsOf('glpi_entities', [$idleChild]) === [0 => 0, $idleForeign => $idleForeign]
        && !$cache->has($ancestorKey([$idleChild])), 'Array requests neither admit nor publish aggregate ancestry keys after a committed move');
    $remember($ancestorKey([]));
    $remember($ancestorKey([0]));
    $cache->set($ancestorKey([]), [0 => 0]);
    $cache->set($ancestorKey([0]), [$idleParent => $idleParent]);
    verify(getAncestorsOf('glpi_entities', []) === [] && getAncestorsOf('glpi_entities', [0]) === [], 'Empty and root-only array ancestry retain their exact empty result');
    $_SESSION['glpiactiveentities'] = [];
    verify(!Session::haveAccessToEntity(0, true), 'An actual empty active entity scope cannot gain recursive root access from a poisoned aggregate cache');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        while ($connection->getTransactionNestingLevel() > 0) {
            $connection->rollBack();
        }
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    $_SESSION = $savedSession;
    foreach (array_reverse($committed, true) as $id => $name) {
        try {
            $owned = $connection->fetchAssociative('SELECT name FROM glpi_entities WHERE id = ?', [$id]);
            if ($owned !== false) {
                verify($owned['name'] === $name, 'Cleanup only purges the actual owned marker at the predicted identity');
                verify((new Entity())->delete(['id' => $id], true), 'Owned committed graph cleanup uses public purge');
            }
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    try {
        if ($committed !== []) {
            $afterCleanup = $rows();
            foreach ($afterCleanup['glpi_entities'] as &$entity) {
                if ((int)$entity['id'] === 0) {
                    $entity = array_replace($entity, $root);
                }
            }
            unset($entity);
            verify($afterCleanup === $baseline, 'All owned rows are gone and only the captured root derived cache may differ');
            $connection->update('glpi_entities', $root, ['id' => 0]);
        }
        verify($rows() === $baseline, 'Cleanup preserves native application rows after success or refusal');
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    foreach ($remembered as $key => $previous) {
        try {
            if ($previous['present']) {
                $cache->set($key, $previous['value']);
            } else {
                $cache->delete($key);
            }
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    try {
        if (session_id() !== $savedSessionId || session_status() !== $savedSessionStatus) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            session_id($savedSessionId);
            if ($savedSessionStatus === PHP_SESSION_ACTIVE) {
                Session::start();
            }
        }
        $_SESSION = $savedSession;
        verify(session_id() === $savedSessionId && session_status() === $savedSessionStatus && $_SESSION === $savedSession, 'Cleanup restores the actual session status, identity and values');
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    try {
        $plugins->setValue(null, $savedPlugins);
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    $TRANSLATE = $savedTranslation;
    try {
        if ($savedLocale !== null) {
            Locale::setDefault($savedLocale);
        }
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
}
if ($primary !== null) {
    if ($cleanupErrors !== []) {
        fwrite(STDERR, 'Additional owned cleanup failures: ' . count($cleanupErrors) . "\n");
    }
    throw $primary;
}
if ($cleanupErrors !== []) {
    throw new RuntimeException('Owned tree transaction cleanup failed.', previous: $cleanupErrors[0]);
}
echo $DB->getProvider() . ": caller-owned tree cache isolation: $assertions assertions passed.\n";
