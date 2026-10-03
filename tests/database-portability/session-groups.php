<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\GroupMembershipRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/session-groups.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$savedSession = $_SESSION;
$savedCache = $GLPI_CACHE;
$savedRequests = $SQL_TOTAL_REQUEST;
$savedDebug = $DEBUG_SQL;
$primary = $DB;
$connection = $primary->getDoctrineConnection();
$level = $connection->getTransactionNestingLevel();
$readDatabase = null;
$readConnection = null;
$readLevel = 0;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($primary);
    $stamp = 'Session groups ' . bin2hex(random_bytes(5));
    $parent = $fixtures->create('glpi_entities', ['name' => $stamp . ' parent']);
    $child = $fixtures->create('glpi_entities', ['name' => $stamp . ' child', 'entities_id' => $parent]);
    $sibling = $fixtures->create('glpi_entities', ['name' => $stamp . ' sibling', 'entities_id' => $parent]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $stamp . ' foreign']);
    $user = $fixtures->create('glpi_users', ['name' => $stamp . ' current']);
    $other = $fixtures->create('glpi_users', ['name' => $stamp . ' other']);
    $minimal = $fixtures->create('glpi_users', ['name' => $stamp . ' minimal']);
    $profile = $fixtures->create('glpi_profiles', ['name' => $stamp . ' profile']);
    $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $foreign, 'is_recursive' => false]);
    $fixtures->create('glpi_profiles_users', ['users_id' => $other, 'profiles_id' => $profile, 'entities_id' => $child, 'is_recursive' => true]);
    $groups = [];
    foreach (['root' => [0, false], 'parent_direct' => [$parent, false], 'parent_recursive' => [$parent, true],
        'child' => [$child, false], 'sibling' => [$sibling, true], 'foreign' => [$foreign, true],
        'unlinked' => [$child, true], 'other_user_only' => [$child, false]] as $kind => [$entity, $recursive]) {
        $groups[$kind] = $fixtures->create('glpi_groups', ['name' => $stamp . ' ' . $kind, 'entities_id' => $entity, 'is_recursive' => $recursive]);
    }
    // Membership order deliberately differs from Group ID, name and owning entity order.
    $memberships = [];
    foreach (['child', 'foreign', 'parent_recursive', 'root', 'sibling', 'parent_direct'] as $kind) {
        $memberships[$kind] = $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $groups[$kind]]);
    }
    $fixtures->create('glpi_groups_users', ['users_id' => $other, 'groups_id' => $groups['other_user_only']]);
    $fixtures->create('glpi_groups_users', ['users_id' => $minimal, 'groups_id' => $groups['unlinked']]);
    verify($groups['parent_recursive'] < $groups['child'] && $memberships['child'] < $memberships['parent_recursive'], 'Fixture distinguishes Group creation order from membership order');
    $em = Orm::create($primary);
    verify($em->getConnection() === $connection, 'Repository retains its supplied writer connection');
    $repository = new GroupMembershipRepository($em);
    $records = new RecordRepository($em);
    verify($records->countMatching('glpi_profiles_users', ['users_id' => $minimal]) === 0, 'Minimal user genuinely has no authorization grant');
    $_SESSION['glpiID'] = $user;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $_SESSION['glpishowallentities'] = true;
    $_SESSION['glpiactiveentities'] = [$child];
    $scope = getEntitiesRestrictCriteria(Group::getTable(), 'entities_id', [$child], true);
    $SQL_TOTAL_REQUEST = 0;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['child'], $groups['parent_recursive']], 'Actual session group scope includes the recursive ancestor, excludes its nonrecursive peer and unrelated groups, and preserves membership order');
    verify($repository->sessionGroupIds($user, $scope) === $_SESSION['glpigroups'] && $SQL_TOTAL_REQUEST === 0, 'Public membership selection uses the owning ORM projection without adapter SQL');
    $planningKey = implode(',', $_SESSION['glpigroups']);
    Session::loadGroups();
    verify(implode(',', $_SESSION['glpigroups']) === $planningKey, 'Unchanged memberships publish a stable planning group key');
    $_SESSION['glpiactiveentities'] = [$parent, $child];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['child'], $groups['parent_recursive'], $groups['parent_direct']], 'Explicit parent and child scope keeps membership order instead of Group or entity order');
    $_SESSION['glpiactiveentities'] = [$parent];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['parent_recursive'], $groups['parent_direct']], 'A flat parent scope does not invent descendant memberships');
    $_SESSION['glpiactiveentities'] = [$sibling];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['parent_recursive'], $groups['sibling']], 'Sibling selection includes only its recursive ancestor and own membership');
    $_SESSION['glpiactiveentities'] = [];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [], 'Explicit empty scope clears the prior snapshot even with stale show-all true');
    $_SESSION['glpiactiveentities'] = [0];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['root']], 'Root zero is a real owning entity and does not include descendant groups');
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpiID'] = $minimal;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['unlinked']], 'Minimal sessions retain direct Group membership without ProfileUser grants');
    $_SESSION['glpiID'] = $other;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['other_user_only']], 'Current identity selects only its own memberships');
    $_SESSION['glpiID'] = PHP_INT_MAX;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [], 'Unknown current user clears the previous membership snapshot');
    $_SESSION['glpiID'] = $user;
    Session::loadGroups();
    $beforeSavepoint = $_SESSION['glpigroups'];
    $connection->beginTransaction();
    try {
        $temporary = $fixtures->create('glpi_groups', ['name' => $stamp . ' temporary', 'entities_id' => $child]);
        $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $temporary]);
        (new RecordWriter(Orm::create($primary)))->delete('glpi_groups_users', $memberships['child']);
        Session::loadGroups();
        verify($_SESSION['glpigroups'] === [$groups['parent_recursive'], $temporary], 'Supplied writer sees uncommitted membership add/delete in its savepoint');
    } finally {
        $connection->rollBack();
    }
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === $beforeSavepoint && $connection->getTransactionNestingLevel() === $level + 1, 'Fresh projection observes savepoint rollback without ending the caller transaction');

    // A second configured handle on this same endpoint is a route probe, not a replica fixture.
    // The primary's new user/memberships are uncommitted, so only that writer can see them.
    // Root is committed on both handles, avoiding a route-dependent ancestry fixture/cache.
    $_SESSION['glpiactiveentities'] = [0];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['root']], 'Read-route control has an uncommitted membership under a real committed Root');
    $readDatabase = new class () extends DB {
        public function isSlave()
        {
            return true;
        }
    };
    $readConnection = $readDatabase->getDoctrineConnection();
    $readLevel = $readConnection->getTransactionNestingLevel();
    $readConnection->beginTransaction();
    verify($readConnection !== $connection && $readConnection->getNativeConnection() !== $connection->getNativeConnection(), 'Read-route probe uses a distinct physical handle');
    $readManager = Orm::create($readDatabase);
    verify($readDatabase->isSlave() && $readManager->getConnection() === $readConnection, 'Owning repository keeps the explicitly supplied read route');
    $DB = $readDatabase;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [], 'Actual public read-route query cannot see the other handle uncommitted membership graph');
    $DB = $primary;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['root']], 'Restored writer route retains its own uncommitted membership under the same Root scope');
    $_SESSION['glpiactiveentities'] = [$child];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === $beforeSavepoint, 'Read-route probe leaves the writer group scope and caller data usable');
    verify($connection->getTransactionNestingLevel() === $level + 1, 'Read selection does not commit or replace the supplied writer transaction');
} finally {
    $DB = $primary;
    if ($readConnection !== null) {
        while ($readConnection->getTransactionNestingLevel() > $readLevel) {
            $readConnection->rollBack();
        }
    }
    $readDatabase?->close();
    while ($connection->getTransactionNestingLevel() > $level) {
        $connection->rollBack();
    }
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
    $SQL_TOTAL_REQUEST = $savedRequests;
    $DEBUG_SQL = $savedDebug;
}
echo "Session group ownership, stable publication, explicit scope and supplied query routes passed\n";
