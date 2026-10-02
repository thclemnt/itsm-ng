<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\ProfileRepository;
use itsmng\Database\Repository\ProfileRightRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\UnsupportedCriteria;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/profile-rights.php /path/to/test-config\n");
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
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$connection = $DB->getDoctrineConnection();
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $stamp = 'Rights ' . bin2hex(random_bytes(5));
    $source = $stamp . " source O'Reilly \\ %_ 日本語";
    $target = $stamp . ' target';
    $zero = $stamp . ' zero';
    $registered = $stamp . ' registered';
    $failed = $stamp . ' atomic failure';
    $repo = static fn () => new ProfileRightRepository(Orm::create($DB));
    $records = static fn () => new RecordRepository(Orm::create($DB));
    $profiles = [];
    foreach (['lower', 'higher', 'missing', 'helpdesk'] as $label) {
        $profiles[$label] = $fixtures->create('glpi_profiles', ['name' => $stamp . ' ' . $label, 'interface' => $label === 'helpdesk' ? 'helpdesk' : 'central']);
    }
    foreach (['lower' => READ, 'higher' => READ | CREATE, 'missing' => READ, 'helpdesk' => READ | CREATE | DELETE] as $label => $mask) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profiles[$label], 'name' => $source, 'rights' => $mask]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profiles[$label], 'name' => $target, 'rights' => UPDATE]);
        if ($label !== 'missing') {
            $fixtures->create('glpi_profilerights', ['profiles_id' => $profiles[$label], 'name' => $zero, 'rights' => 0]);
        }
    }
    $possible = ProfileRight::getAllPossibleRights();
    verify(isset($possible[$source], $possible[$target], $possible[$zero]), 'Distinct definition discovery retains literal names');
    verify(ProfileRight::getProfileRights($profiles['higher'], [$source, 'unknown']) === [$source => READ | CREATE], 'Profile projection retains named filters and numeric masks');
    verify(ProfileRight::getProfileRights(2147483647) === [], 'Unknown profile has no rights');
    $management = new ProfileRepository(Orm::create($DB));
    $ids = $management->manageableIds([$source => READ, $zero => 0], 'central');
    verify(in_array($profiles['lower'], $ids, true) && in_array($profiles['helpdesk'], $ids, true)
        && !in_array($profiles['higher'], $ids, true) && !in_array($profiles['missing'], $ids, true), 'Containment requires every right, explicit zeros and subset masks, with central helpdesk access');
    verify($management->canManage([$profiles['lower']], [$source => READ, $zero => 0], 'central', false)
        && !$management->canManage([$profiles['lower'], $profiles['higher']], [$source => READ, $zero => 0], 'central', false)
        && !$management->canManage([2147483647], [], 'central', true), 'Management checks all requested existing profiles');
    verify($management->manageableIds([$source => READ, $zero => 0], 'helpdesk') === [], 'Helpdesk containment cannot grant a stronger profile');
    verify(ProfileRight::addProfileRights([$registered]), 'Register a definition on every profile');
    $profileCount = $records()->countMatching('glpi_profiles', []);
    verify($records()->countMatching('glpi_profilerights', ['name' => $registered, 'rights' => 0]) === $profileCount, 'New definitions start without permissions on every profile');
    verify(isset(ProfileRight::getAllPossibleRights()[$registered]), 'Registration invalidates the definition cache');
    verify(!ProfileRight::addProfileRights([$failed, $registered]), 'Duplicate definitions retain a false mutation result');
    verify($records()->countMatching('glpi_profilerights', ['name' => $failed]) === 0
        && $records()->countMatching('glpi_profilerights', ['name' => $registered]) === $profileCount, 'Failed definition installation is atomic');
    verify(ProfileRight::updateProfileRightAsOtherRight($target, CREATE | DELETE | PURGE, [
        'name' => $source, ['rights' => ['&', READ]], ['rights' => ['&', CREATE]],
    ]), 'Structured migration grant');
    verify(ProfileRight::getProfileRights($profiles['higher'], [$target])[$target] === (UPDATE | CREATE | DELETE | PURGE)
        && ProfileRight::getProfileRights($profiles['lower'], [$target])[$target] === UPDATE, 'Every mask bit is combined only when both source predicates match: ' . json_encode(ProfileRight::getProfileRights($profiles['higher'], [$source, $target])));
    verify(ProfileRight::updateProfileRightAsOtherRight($target, READ, ['name' => 'missing source']), 'Empty source succeeds without changing permissions');
    verify(ProfileRight::updateProfileRightsAsOtherRights($target, $source, ['rights' => ['&', CREATE]]), 'Copy a selected source mask');
    verify(ProfileRight::getProfileRights($profiles['higher'], [$target])[$target] === (READ | CREATE)
        && ProfileRight::getProfileRights($profiles['lower'], [$target])[$target] === UPDATE, 'Copy replaces target masks and leaves unmatched profiles alone');
    try {
        $repo()->grantFrom($target, DELETE, [new QueryExpression('1 = 1')]);
        throw new LogicException('Raw SQL predicate was accepted');
    } catch (UnsupportedCriteria) {
    }
    ProfileRight::fillProfileRights($profiles['lower']);
    $before = ProfileRight::getProfileRights($profiles['lower']);
    ProfileRight::fillProfileRights($profiles['lower']);
    verify(ProfileRight::getProfileRights($profiles['lower']) === $before && $before[$source] === READ
        && $before[$target] === UPDATE && $before[$registered] === 0, 'Completion is idempotent and never resets existing permissions');
    // Scoped access retains explicit grants and recursive ancestor grants.
    $parent = $fixtures->create('glpi_entities', ['name' => $stamp . ' parent']);
    $child = $fixtures->create('glpi_entities', ['name' => $stamp . ' child', 'entities_id' => $parent]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $stamp . ' foreign']);
    $user = $fixtures->create('glpi_users', ['name' => $stamp]);
    $grant = $fixtures->create('glpi_profiles_users', ['profiles_id' => $profiles['lower'], 'users_id' => $user, 'entities_id' => $parent, 'is_recursive' => true]);
    verify(Profile::haveUserRight($user, $source, READ | CREATE, $child)
        && !Profile::haveUserRight($user, $source, CREATE, $child)
        && !Profile::haveUserRight($user, $source, READ, $foreign)
        && !Profile::haveUserRight($user, $source, 0, $child), 'Permission checks retain any-bit masks and recursive entity scope');
    (new \itsmng\Database\MappedStorage($DB))->update('glpi_profiles_users', $grant, ['is_recursive' => false]);
    verify(!Profile::haveUserRight($user, $source, READ, $child) && Profile::haveUserRight($user, $source, READ, $parent), 'Nonrecursive grants apply only to their own entity');
    verify(!Profile::haveUserRight(2147483647, $source, READ, $parent), 'Unregistered users cannot acquire a right');
    // Existing model callbacks remain responsible for active-session rights and history.
    $_SESSION['glpiactiveprofile'] = ['id' => $profiles['lower'], 'interface' => 'central'] + $before;
    $_SESSION['glpimenu'] = ['fixture'];
    ProfileRight::updateProfileRights($profiles['lower'], [$source => READ | CREATE]);
    verify($_SESSION['glpiactiveprofile'][$source] === (READ | CREATE) && !isset($_SESSION['glpimenu']), 'Model permission updates refresh the active session and menu');
    $history = $records()->countMatching('glpi_logs', ['itemtype' => 'Profile', 'items_id' => $profiles['lower']]);
    ProfileRight::updateProfileRights($profiles['lower'], ['computer' => READ | CREATE]);
    verify($records()->countMatching('glpi_logs', ['itemtype' => 'Profile', 'items_id' => $profiles['lower']]) > $history, 'Model callbacks retain parent profile history');
    unset($_SESSION['glpiactiveprofile']);
    verify($records()->matching('glpi_profiles', Profile::getUnderActiveProfileRestrictCriteria()) === [], 'Logged-out profile selection is closed');
    $_SESSION['glpiactiveprofile'] = ['id' => $profiles['lower'], 'interface' => 'central', 'profile' => CREATE];
    verify(Profile::getUnderActiveProfileRestrictCriteria() === [], 'A profile administrator can select all profiles');
    $_SESSION = $savedSession;
    $first = new Profile();
    $firstId = $first->add(['name' => $stamp . ' default one', 'is_default' => true, 'interface' => 'central']);
    verify($firstId > 0 && Profile::getDefault() === $firstId, 'New default profile replaces the previous default');
    $second = new Profile();
    $secondId = $second->add(['name' => $stamp . ' default two', 'is_default' => true, 'interface' => 'central']);
    verify($secondId > 0 && Profile::getDefault() === $secondId && $records()->countMatching('glpi_profiles', ['is_default' => true]) === 1, 'Default selection remains unique after model insertion');
    verify($first->update(['id' => $firstId, 'is_default' => true]) && Profile::getDefault() === $firstId
        && $records()->countMatching('glpi_profiles', ['is_default' => true]) === 1, 'Model update clears other defaults');
    $recordsType = $fixtures->create('glpi_domainrecordtypes', ['name' => $stamp . ' record type']);
    verify($first->getDomainRecordTypes()[$recordsType] === $stamp . ' record type', 'Domain-record type projection retains labels');
    verify(ProfileRight::deleteProfileRights([$registered]) && !isset(ProfileRight::getAllPossibleRights()[$registered]), 'Definition deletion invalidates the cache');
    verify($records()->countMatching('glpi_profilerights', ['name' => $registered]) === 0, 'Definition deletion covers every profile');
    verify(ProfileRight::addProfileRights([]) && ProfileRight::deleteProfileRights([]), 'Empty definition mutations are no-ops');
} finally {
    $connection->rollBack();
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
}
echo "Profile definitions, bitmask migration, scoped grants, containment, session hooks, defaults and atomic failure passed\n";
