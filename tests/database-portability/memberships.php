<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\GroupMembershipRepository;
use itsmng\Database\Repository\ProfileUserRepository;
use itsmng\Database\UnsupportedCriteria;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/memberships.php /path/to/test-config\n");
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
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedCache = $GLPI_CACHE;
// Rolled-back entity IDs can be reused by earlier contracts; their cached trees
// must not leak into this fixture or survive its rollback.
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$connection = $DB->getDoctrineConnection();
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $stamp = 'Membership ' . bin2hex(random_bytes(5));
    $parent = $fixtures->create('glpi_entities', ['name' => $stamp . ' parent', 'completename' => $stamp . ' parent']);
    $child = $fixtures->create('glpi_entities', ['name' => $stamp . ' child', 'completename' => $stamp . ' child', 'entities_id' => $parent]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $stamp . ' foreign']);
    $firstProfile = $fixtures->create('glpi_profiles', ['name' => $stamp . ' first']);
    $secondProfile = $fixtures->create('glpi_profiles', ['name' => $stamp . ' second']);
    $right = $stamp . " O'Reilly \\ %_ 日本語";
    $fixtures->create('glpi_profilerights', ['profiles_id' => $firstProfile, 'name' => $right, 'rights' => READ]);
    $fixtures->create('glpi_profilerights', ['profiles_id' => $secondProfile, 'name' => $right, 'rights' => CREATE]);
    $users = [];
    foreach (['recursive', 'direct', 'foreign', 'ungranted', 'deleted', 'child', 'root'] as $name) {
        $users[$name] = $fixtures->create('glpi_users', [
            'name' => $stamp . ' ' . $name, 'realname' => $name === 'ungranted' ? null : 'Same',
            'firstname' => 'Same', 'entities_id' => $parent, 'is_deleted' => $name === 'deleted',
            'is_active' => $name !== 'deleted',
        ]);
    }
    $grant = static fn ($name, $profile, $entity, $recursive, $dynamic = false) => $fixtures->create('glpi_profiles_users', [
        'users_id' => $users[$name], 'profiles_id' => $profile, 'entities_id' => $entity,
        'is_recursive' => $recursive, 'is_dynamic' => $dynamic,
    ]);
    $recursiveGrant = $grant('recursive', $firstProfile, $parent, true);
    $grant('recursive', $secondProfile, $parent, true, true);
    $grant('direct', $firstProfile, $parent, false);
    $grant('foreign', $firstProfile, $foreign, true);
    $grant('deleted', $firstProfile, $parent, true);
    $grant('child', $firstProfile, $child, false);
    $grant('root', $firstProfile, 0, false);
    $profiles = new ProfileUserRepository(Orm::create($DB));
    verify(count($profiles->scopes($users['recursive'])) === 1, 'Repeated profile grants collapse identical entity/recursive scopes');
    verify(Profile_User::haveUniqueRight($users['recursive'], $firstProfile) === 1, 'Authorization count selects a user and profile');
    verify(Profile_User::getUserEntities($users['ungranted']) === [], 'An ungranted user has no authorized entities');
    verify(array_values(Profile_User::getUserEntities($users['root'])) === [0], 'Root is a real nonrecursive authorization');
    verify(in_array($child, Profile_User::getUserEntities($users['recursive']), true)
        && !in_array($child, Profile_User::getUserEntities($users['recursive'], false), true), 'Recursive expansion is optional');
    verify(array_values(Profile_User::getUserEntities($users['recursive'], true, true))[0] === $parent, 'An authorized default entity is returned first');
    verify(in_array($child, Profile_User::getUserEntitiesForRight($users['recursive'], $right, READ | CREATE), true)
        && Profile_User::getUserEntitiesForRight($users['recursive'], $right, DELETE) === []
        && Profile_User::getUserEntitiesForRight($users['recursive'], $right, 0) === [], 'Named permission scopes retain literal names and any-bit masks');
    verify(Profile_User::getEntitiesForProfileByUser($users['recursive'], $firstProfile) === [$parent => $parent]
        && isset(Profile_User::getEntitiesForProfileByUser($users['recursive'], $firstProfile, true)[$child])
        && isset(Profile_User::getEntitiesForUser($users['recursive'], true)[$child]), 'Profile and user projections retain entity-ID keyed results');
    $entityUsers = $profiles->usersInEntity($parent);
    verify(count($entityUsers) === 3 && $profiles->countUsersInEntity($parent) === 3, 'Entity listing/count retains separate grants and excludes deleted users');
    $rows = array_values(array_filter($entityUsers, static fn ($row) => $row['id'] === $users['recursive']));
    verify(count($rows) === 2 && $rows[0]['linkid'] === $recursiveGrant && $rows[0]['pid'] === $firstProfile
        && $rows[1]['pid'] === $secondProfile && $rows[1]['is_dynamic'] === 1, 'User fields, authorization identities and flags survive hydration without collapse');
    $scope = getEntitiesRestrictCriteria('glpi_profiles_users', '', $child, true);
    $profileUsers = $profiles->usersWithProfile($firstProfile, $scope);
    verify(array_column($profileUsers, 'id') === [$users['child'], $users['recursive']], 'Scoped profile users include direct child and recursive ancestor grants only');
    verify($profiles->usersWithProfile($firstProfile, ['entities_id' => ['<', 0]]) === [], 'Empty authorized scope stays closed');
    $group = $fixtures->create('glpi_groups', ['name' => $stamp . " O'Reilly \\ group", 'completename' => 'A parent', 'entities_id' => $parent, 'is_recursive' => true]);
    $subgroup = $fixtures->create('glpi_groups', ['name' => $stamp . ' subgroup', 'completename' => 'B child', 'entities_id' => $child, 'groups_id' => $group]);
    $links = [];
    foreach (['recursive', 'direct', 'foreign', 'ungranted', 'deleted'] as $name) {
        $links[$name] = $fixtures->create('glpi_groups_users', [
            'groups_id' => $group, 'users_id' => $users[$name], 'is_manager' => $name === 'recursive',
            'is_userdelegate' => $name === 'ungranted', 'is_dynamic' => $name === 'deleted',
        ]);
    }
    $childLink = $fixtures->create('glpi_groups_users', ['groups_id' => $subgroup, 'users_id' => $users['child']]);
    $groups = new GroupMembershipRepository(Orm::create($DB));
    verify(Group_User::isUserInGroup($users['recursive'], $group) && !Group_User::isUserInGroup($users['child'], $group), 'Direct membership existence');
    $userGroups = Group_User::getUserGroups($users['recursive'], ['glpi_groups.name' => $stamp . " O'Reilly \\ group"]);
    verify(count($userGroups) === 1 && $userGroups[0]['id'] === $group && $userGroups[0]['linkid'] === $links['recursive']
        && $userGroups[0]['is_manager'] === 1 && $userGroups[0]['IDD'] === $links['recursive'], 'Joined group criteria retain literal names and legacy membership aliases');
    verify(array_column(Group_User::getUserGroups($users['recursive'], ['entities_id' => $parent]), 'id') === [$group]
        && Group_User::getUserGroups($users['recursive'], ['entities_id' => $foreign]) === [], 'Unqualified joined entity criteria preserve ticket follow-up authorization scope');
    verify(count(Group_User::getGroupUsers($group, ['is_deleted' => 0])) === 4, 'Unqualified joined user flags retain their owning table');
    $joinedManager = Orm::create($DB);
    $joinedQuery = $joinedManager->createQueryBuilder()->select('r')->from(\itsmng\Database\Entity\GroupMembership::class, 'r')
        ->join('r.groups', 'g')->join('r.users', 'u');
    $joinedCriteria = (new \itsmng\Database\RecordCriteria($joinedQuery, $joinedManager->getClassMetadata(\itsmng\Database\Entity\GroupMembership::class), false))
        ->withJoinedMetadata($joinedManager->getClassMetadata(\itsmng\Database\Entity\Group::class), 'g')
        ->withJoinedMetadata($joinedManager->getClassMetadata(\itsmng\Database\Entity\User::class), 'u');
    try {
        $joinedCriteria->where(['entities_id' => $parent]);
        throw new LogicException('Ambiguous entity scope accepted');
    } catch (UnsupportedCriteria $error) {
        verify(str_contains($error->getMessage(), 'Ambiguous'), 'Ambiguous joined scopes require a table qualifier');
    }
    $groupUsers = Group_User::getGroupUsers($group, ['is_userdelegate' => 1, 'glpi_users.is_deleted' => 0]);
    verify(count($groupUsers) === 1 && $groupUsers[0]['id'] === $users['ungranted'] && $groupUsers[0]['is_manager'] === 0, 'Joined user filters and membership flags');
    verify(count(Group_User::getGroupUsers($group)) === 5, 'Unscoped membership API retains inactive/deleted users');
    $visible = $groups->members([$group, $subgroup], $scope, tree: true);
    verify($visible['total'] === 4 && count($visible['rows']) === 4
        && !in_array($users['direct'], array_column($visible['rows'], 'id'), true)
        && !in_array($users['foreign'], array_column($visible['rows'], 'id'), true), 'Grant existence prevents fan-out and excludes foreign/nonrecursive ancestor grants');
    $direct = $groups->directUserIds($group, $scope);
    sort($direct);
    $expected = [$users['recursive'], $users['ungranted'], $users['deleted']];
    sort($expected);
    verify($direct === $expected, 'Direct member exclusions include ungranted users and never child-group members');
    verify($groups->members([], $scope)['total'] === 0, 'Empty group selection stays closed');
    verify($groups->members([$group], $scope, 'is_manager')['total'] === 1
        && $groups->members([$group], $scope, 'is_userdelegate')['rows'][0]['id'] === $users['ungranted'], 'Manager/delegate filters are applied before counting');
    foreach (['group', 'parent', 'dynamic', 'manager', 'delegatee'] as $sort) {
        foreach (['ASC', 'DESC'] as $direction) {
            $all = $groups->members([$group, $subgroup], $scope, sort: $sort, direction: $direction, tree: true);
            $paged = [];
            for ($offset = 0; $offset < $all['total']; $offset += 2) {
                $page = $groups->members([$group, $subgroup], $scope, offset: $offset, limit: 2, sort: $sort, direction: $direction, tree: true);
                verify($page['total'] === 4 && count($page['rows']) <= 2, 'Page total is independent of offset and row limit');
                $paged = array_merge($paged, array_column($page['rows'], 'linkid'));
            }
            verify($paged === array_column($all['rows'], 'linkid') && count(array_unique($paged)) === 4, 'Stable page boundaries across sort fields/directions');
        }
    }
    verify($groups->members([$group], $scope)['rows'][0]['id'] === $users['ungranted']
        && $groups->members([$group], $scope, direction: 'DESC')['rows'][2]['id'] === $users['ungranted'], 'NULL names preserve MySQL ordering on PostgreSQL');
    try {
        Group_User::getGroupUsers($group, [new QueryExpression('1 = 1')]);
        throw new LogicException('Raw membership criterion accepted');
    } catch (UnsupportedCriteria) {
    }
    $model = new Group();
    verify($model->getFromDB($group), 'Load mapped group for application views');
    verify(Group_User::countForItem($model) === count(Group_User::getListForItem($model)), 'Ordered membership listing and unordered total preserve the same authorized relation scope');
    $_SESSION['glpiactive_entity'] = $child;
    $members = $ids = [];
    Group_User::getDataForGroup($model, $members, $ids, 'is_manager', true);
    verify(count($members) === 1 && $members[0]['linkid'] === $links['recursive'] && !in_array($users['child'], $ids, true), 'Legacy group projection filters display rows while retaining all direct exclusions');
    $rendered = Group_User::getPaginatedMembersForGroup($model, '', true, 0, 2, 'manager', 'DESC');
    verify($rendered['total'] === 4 && count($rendered['rows']) === 2 && $rendered['rows'][0]['manager'] !== '', 'Rendered member page uses bounded mapped rows and flags');
} finally {
    $connection->rollBack();
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
}
echo "Profile grants, recursive scopes, membership flags, grant deduplication, stable pagination and application views passed\n";
