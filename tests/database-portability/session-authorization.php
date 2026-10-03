<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\GroupMembershipRepository;
use itsmng\Database\Repository\ProfileUserRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/session-authorization.php /path/to/test-config\n");
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
final class SessionAuthorizationResponse extends RuntimeException
{
}
/** Exercise actual API endpoint policy, including private app-token/session checks. */
final class SessionAuthorizationApi extends \Glpi\Api\APIRest
{
    public function request(int $client, string $endpoint, array $params = [], ?string $token = null, string $appToken = 'session-authorization-fixture'): mixed
    {
        $this->session_write = true;
        $this->app_tokens = [$client => 'session-authorization-fixture'];
        $this->parameters = ['app_token' => $appToken, 'session_token' => $token ?? session_id()];
        return match ($endpoint) {
            'profiles' => $this->getMyProfiles(), 'entities' => $this->getMyEntities($params),
            'profile' => $this->changeActiveProfile($params), 'entity' => $this->changeActiveEntities($params),
        };
    }
    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new SessionAuthorizationResponse(json_encode($response), $httpcode);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedCache = $GLPI_CACHE;
$savedHooks = $PLUGIN_HOOKS;
$savedRequests = $SQL_TOTAL_REQUEST;
$savedDebug = $DEBUG_SQL;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'session_authorization_fixture']);
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$connection = $DB->getDoctrineConnection();
$initialNesting = $connection->getTransactionNestingLevel();
$connection->beginTransaction();
try {
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $writer = static fn () => new RecordWriter(Orm::create($DB));
    $prefix = 'Session grants ' . bin2hex(random_bytes(5));
    $literal = "O'Reilly \\ 日本語";
    $parent = $fixtures->create('glpi_entities', ['name' => $literal, 'completename' => $prefix . ' parent']);
    $child = $fixtures->create('glpi_entities', ['name' => $prefix . ' child', 'entities_id' => $parent, 'completename' => $prefix . ' parent > child']);
    $sibling = $fixtures->create('glpi_entities', ['name' => $prefix . ' sibling', 'entities_id' => $parent, 'completename' => $prefix . ' parent > sibling']);
    $foreign = $fixtures->create('glpi_entities', ['name' => $prefix . ' foreign']);
    $unnamed = $fixtures->create('glpi_entities', ['name' => null, 'completename' => null]);
    $profiles = [];
    foreach (['null' => null, 'first' => $prefix . ' A', 'tie' => $prefix . ' A', 'last' => $literal, 'unavailable' => $prefix . ' unavailable'] as $kind => $name) {
        $profiles[$kind] = $fixtures->create('glpi_profiles', ['name' => $name, 'interface' => 'central']);
    }
    foreach (['null' => 0, 'first' => READ | CREATE, 'tie' => READ | UPDATE, 'last' => READ] as $kind => $mask) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profiles[$kind], 'name' => 'computer', 'rights' => $mask]);
    }
    $login = 'session_grants_' . bin2hex(random_bytes(5));
    $user = $fixtures->create('glpi_users', [
        'name' => $login, 'password' => Auth::getPasswordHash('session secret'), 'authtype' => Auth::DB_GLPI,
        'is_active' => true, 'is_deleted' => false, 'entities_id' => $child, 'profiles_id' => $profiles['first'],
        'password_last_update' => new DateTimeImmutable(), 'personal_token' => 'session_token_' . bin2hex(random_bytes(16)),
    ]);
    $ungranted = $fixtures->create('glpi_users', ['name' => $prefix . ' no grants']);
    $grant = static fn (string $profile, int $entity, bool $recursive, bool $dynamic = false): int => $fixtures->create('glpi_profiles_users', [
        'users_id' => $user, 'profiles_id' => $profiles[$profile], 'entities_id' => $entity,
        'is_recursive' => $recursive, 'is_dynamic' => $dynamic,
    ]);
    $grant('null', 0, false);
    $grant('null', $child, false);
    $grant('first', $parent, false);
    $recursiveGrant = $grant('first', $parent, true, true);
    $grant('tie', $parent, true);
    $grant('tie', $parent, false, true);
    $grant('tie', $unnamed, false);
    $grant('last', $parent, false);
    $grant('last', $parent, false, true);
    $em = Orm::create($DB);
    verify($em->getConnection() === $connection, 'Session repositories retain the supplied physical writer connection');
    // Use names whose relative lexical order is independent of the provider's case/accent collation.
    $writer()->update('glpi_profiles', $profiles['last'], ['name' => $prefix . ' Z ' . $literal]);
    $expectedOrder = [$profiles['null'], $profiles['first'], $profiles['tie'], $profiles['last']];
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    Session::initEntityProfiles($user);
    verify($SQL_TOTAL_REQUEST === 0, 'Public profile snapshot bypasses the legacy SQL adapter');
    verify(array_keys($_SESSION['glpiprofiles']) === $expectedOrder, 'NULL-first profile name ordering and stable tied profile IDs');
    verify($_SESSION['glpiprofiles'][$profiles['null']]['name'] === null
        && $_SESSION['glpiprofiles'][$profiles['last']]['name'] === $prefix . ' Z ' . $literal, 'Nullable and literal profile labels');
    verify($_SESSION['glpiprofiles'][$profiles['first']]['entities'][$parent] === ['id' => $parent, 'name' => $literal, 'is_recursive' => 1]
        && $_SESSION['glpiprofiles'][$profiles['tie']]['entities'][$parent]['is_recursive'] === 1
        && $_SESSION['glpiprofiles'][$profiles['last']]['entities'][$parent]['is_recursive'] === 0, 'Duplicate recursive grant wins in either order without crossing profiles');
    verify($_SESSION['glpiprofiles'][$profiles['null']]['entities'][0]['id'] === 0, 'Root is a real session grant');
    verify(array_key_first($_SESSION['glpiprofiles'][$profiles['tie']]['entities']) === $unnamed
        && $_SESSION['glpiprofiles'][$profiles['tie']]['entities'][$unnamed]['name'] === null, 'Entity ordering is NULL-first and preserves nullable labels');
    verify((new ProfileUserRepository($em))->sessionProfiles($user) === $_SESSION['glpiprofiles'], 'Public snapshot retains the complete owned repository projection');
    Session::initEntityProfiles($ungranted);
    verify($_SESSION['glpiprofiles'] === [], 'Ungrantable user clears the old snapshot');
    Session::initEntityProfiles(PHP_INT_MAX);
    verify($_SESSION['glpiprofiles'] === [], 'Unknown user has no profiles');
    Session::initEntityProfiles($user);

    $groups = [];
    foreach (['parent_direct' => [$parent, false], 'parent_recursive' => [$parent, true], 'child' => [$child, false], 'sibling' => [$sibling, true], 'foreign' => [$foreign, true], 'unlinked' => [$child, true], 'root' => [0, false]] as $kind => [$entity, $recursive]) {
        $groups[$kind] = $fixtures->create('glpi_groups', ['name' => $prefix . ' ' . $kind, 'entities_id' => $entity, 'is_recursive' => $recursive]);
        if ($kind !== 'unlinked') {
            $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $groups[$kind], 'is_dynamic' => true, 'is_manager' => false]);
        }
    }
    $_SESSION['glpiID'] = $user;
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpishowallentities'] = true;
    $SQL_TOTAL_REQUEST = 0;
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['parent_recursive'], $groups['child']] && $SQL_TOTAL_REQUEST === 0, 'Explicit child scope includes its recursive ancestor but excludes unrelated groups even with stale show-all');
    verify((new GroupMembershipRepository($em))->sessionGroupIds($user, getEntitiesRestrictCriteria('glpi_groups', 'entities_id', [$child], true)) === $_SESSION['glpigroups'], 'Session group scope targets actual Group ownership');
    $_SESSION['glpiactiveentities'] = [];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [], 'Empty entity scope stays closed with show-all true');
    $_SESSION['glpiactiveentities'] = [$parent, $child];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['parent_direct'], $groups['parent_recursive'], $groups['child']], 'Explicit parent and child scope preserves membership order');
    $_SESSION['glpiactiveentities'] = [0];
    Session::loadGroups();
    verify($_SESSION['glpigroups'] === [$groups['root']], 'Root scope includes its actual membership while excluding child groups');

    // Later writes must be visible through fresh managers on this same uncommitted connection.
    $connection->beginTransaction();
    try {
        $temporaryGroup = $fixtures->create('glpi_groups', ['name' => $prefix . ' uncommitted', 'entities_id' => $child]);
        $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $temporaryGroup]);
        $_SESSION['glpiactiveentities'] = [$child];
        Session::loadGroups();
        verify(in_array($temporaryGroup, $_SESSION['glpigroups'], true), 'Uncommitted membership is visible on the supplied writer');
        $writer()->delete('glpi_profiles_users', $recursiveGrant);
        Session::initEntityProfiles($user);
        verify($_SESSION['glpiprofiles'][$profiles['first']]['entities'][$parent]['is_recursive'] === 0, 'Uncommitted grant revocation is visible on the supplied writer');
        $grant('last', $foreign, true);
        Session::initEntityProfiles($user);
        verify(isset($_SESSION['glpiprofiles'][$profiles['last']]['entities'][$foreign]), 'Uncommitted new grant is visible');
    } finally {
        $connection->rollBack();
    }
    Session::loadGroups();
    verify(!in_array($temporaryGroup, $_SESSION['glpigroups'], true), 'Rollback removes membership from the refreshed session');
    Session::initEntityProfiles($user);
    verify($_SESSION['glpiprofiles'][$profiles['first']]['entities'][$parent]['is_recursive'] === 1
        && !isset($_SESSION['glpiprofiles'][$profiles['last']]['entities'][$foreign]), 'Rollback restores grant snapshots without a stale identity map');

    $events = [];
    foreach (['init_session', 'change_entity', 'change_profile'] as $hook) {
        $PLUGIN_HOOKS[$hook]['session_authorization_fixture'] = static function () use ($hook, &$events): void {
            $events[] = ['hook' => $hook, 'profiles' => array_keys($_SESSION['glpiprofiles'] ?? []), 'groups' => $_SESSION['glpigroups'] ?? []];
        };
    }
    verify((new Auth())->login($login, 'session secret', true), 'Actual password login with owned grants');
    verify($_SESSION['glpiactiveprofile']['id'] === $profiles['first'] && $_SESSION['glpiactiveentities'] === [$child => $child], 'Granted preferred profile and recursive default entity retain selection');
    verify(Session::haveRight('computer', READ | CREATE) === (READ | CREATE)
        && Session::haveRight('computer', CREATE | UPDATE) === CREATE
        && !Session::haveRight('computer', UPDATE | DELETE)
        && !Session::haveRight('computer', 0) && !Session::haveRight('session-fixture-unknown-right', READ), 'Actual public permission lookup preserves stored masks, any-bit semantics and absent/zero denial after login');
    verify(Session::haveRightsAnd('computer', [READ, CREATE])
        && !Session::haveRightsAnd('computer', [READ, UPDATE])
        && Session::haveRightsOr('computer', [UPDATE, CREATE])
        && !Session::haveRightsOr('computer', [UPDATE, DELETE]), 'Public composite permission checks consume the selected profile mask');
    verify(array_column($events, 'hook') === ['init_session', 'change_entity', 'change_profile']
        && $events[0]['profiles'] === [] && $events[1]['groups'] === [$groups['parent_recursive'], $groups['child']], 'Hooks run before grant loading and after group publication in their original order');
    $client = $fixtures->create('glpi_apiclients', ['name' => $prefix, 'dolog_method' => 0]);
    $api = new SessionAuthorizationApi();
    $payload = $api->request($client, 'profiles')['myprofiles'];
    verify(array_column($payload, 'id') === $expectedOrder && $payload[0]['entities'] === array_values($_SESSION['glpiprofiles'][$profiles['null']]['entities']), 'API preserves profile order and unkeyed entity payloads');
    verify($api->request($client, 'entity', ['entities_id' => $child, 'is_recursive' => false]) === true, 'Actual API entity switch uses granted scope');
    \itsmng\Csrf::generate();
    Session::getNewIDORToken('Computer');
    $beforeRefusal = $_SESSION;
    $beforeRefusalEvents = $events;
    $beforeSessionId = session_id();
    $before = $_SESSION['glpiactiveprofile']['id'];
    $beforeRights = $_SESSION['glpiactiveprofile']['computer'];
    try {
        $api->request($client, 'profile', ['profiles_id' => $profiles['unavailable']]);
        throw new LogicException('Unavailable profile accepted');
    } catch (SessionAuthorizationResponse $error) {
        verify($error->getCode() === 404 && $_SESSION['glpiactiveprofile']['id'] === $before
            && $_SESSION['glpiactiveprofile']['computer'] === $beforeRights
            && Session::haveRight('computer', CREATE) === CREATE, 'API rejects ungranted profile without changing actual active permission decisions');
        verify($_SESSION === $beforeRefusal && $events === $beforeRefusalEvents && session_id() === $beforeSessionId, 'Rejected profile retains same-session CSRF/IDOR tokens, scope, groups and hook trace');
    }
    try {
        $api->request($client, 'entity', ['entities_id' => $foreign, 'is_recursive' => false]);
        throw new LogicException('Foreign entity accepted');
    } catch (SessionAuthorizationResponse $error) {
        verify($error->getCode() === 400 && $_SESSION['glpiactive_entity'] === $child, 'API rejects foreign entity without changing active scope');
        verify($_SESSION === $beforeRefusal && $events === $beforeRefusalEvents && session_id() === $beforeSessionId, 'Rejected entity preserves complete same-session authorization and token state');
    }
    try {
        $api->request($client, 'profiles', token: 'forged-session-token');
        throw new LogicException('Forged session accepted');
    } catch (SessionAuthorizationResponse $error) {
        verify($error->getCode() === 401, 'Actual API endpoint still checks the session token');
        verify($_SESSION === $beforeRefusal && $events === $beforeRefusalEvents && session_id() === $beforeSessionId, 'Rejected session token does not replace authenticated state or consume valid page tokens');
    }
    try {
        $api->request($client, 'profiles', appToken: 'forged-app-token');
        throw new LogicException('Forged app token accepted');
    } catch (SessionAuthorizationResponse $error) {
        verify($error->getCode() === 400 && str_contains($error->getMessage(), 'ERROR_WRONG_APP_TOKEN_PARAMETER')
            && $_SESSION['glpiactiveprofile']['id'] === $before, 'Actual private app-token check rejects a forgery before changing authorization');
        verify($_SESSION === $beforeRefusal && $events === $beforeRefusalEvents && session_id() === $beforeSessionId, 'Rejected app token preserves complete session and hooks');
    }
    $api->request($client, 'profile', ['profiles_id' => $profiles['null']]);
    verify(!Session::haveRight('computer', READ | CREATE | UPDATE | DELETE | PURGE), 'Switching to an explicit zero-mask profile drops every prior Computer permission');
    verify($api->request($client, 'entity', ['entities_id' => 0, 'is_recursive' => false]) === true
        && $_SESSION['glpiactive_entity'] === 0 && $_SESSION['glpigroups'] === [$groups['root']], 'Granted root API switch retains root identity and actual group membership');
    verify(in_array(0, array_column($api->request($client, 'entities')['myentities'], 'id'), true), 'API root grant is represented as real ID zero');
    $api->request($client, 'profile', ['profiles_id' => $profiles['tie']]);
    verify(Session::haveRight('computer', READ | UPDATE) === (READ | UPDATE)
        && !Session::haveRight('computer', CREATE), 'A second nonzero profile publishes its own update mask without borrowing create rights');
    $api->request($client, 'profile', ['profiles_id' => $profiles['last']]);
    verify(Session::haveRight('computer', READ | CREATE) === READ
        && !Session::haveRight('computer', CREATE | UPDATE), 'Public profile switch loads its own read mask without inheriting prior create/update grants');
    verify($_SESSION['glpiactiveprofile']['id'] === $profiles['last']
        && array_column($api->request($client, 'entities')['myentities'], 'id') === [$parent], 'API switches only to the selected profile ownership');
    verify(Session::changeActiveEntities($parent, false)
        && $_SESSION['glpiactiveentities'] === [$parent => $parent]
        && $_SESSION['glpigroups'] === [$groups['parent_direct'], $groups['parent_recursive']], 'A nonrecursive grant retains its direct public scope and eligible groups');
    $refusedSession = $_SESSION;
    $refusedEvents = $events;
    foreach ([[$child, false], [$child, true], [$parent, true]] as [$entity, $recursive]) {
        verify(Session::changeActiveEntities($entity, $recursive) === false
            && $_SESSION === $refusedSession && $events === $refusedEvents, 'A nonrecursive grant cannot select a descendant or recursive view, and refusal preserves session/hooks');
    }
    try {
        $api->request($client, 'entity', ['entities_id' => $child, 'is_recursive' => false]);
        throw new LogicException('API accepted descendant of a nonrecursive grant');
    } catch (SessionAuthorizationResponse $error) {
        verify($error->getCode() === 400 && $_SESSION === $refusedSession && $events === $refusedEvents, 'Actual API refuses inherited nonrecursive descendant access without publishing a new scope');
    }
    $api->request($client, 'profile', ['profiles_id' => $profiles['null']]);
    verify($api->request($client, 'entity', ['entities_id' => $child, 'is_recursive' => false]) === true
        && $_SESSION['glpiactiveentities'] === [$child => $child], 'An explicit child grant remains usable beside a nonrecursive root grant');
    $refusedSession = $_SESSION;
    $refusedEvents = $events;
    verify(Session::changeActiveEntities($sibling, false) === false
        && Session::changeActiveEntities($child, true) === false
        && $_SESSION === $refusedSession && $events === $refusedEvents, 'Nonrecursive root and direct child grants do not grant sibling or child-tree access');
    $api->request($client, 'profile', ['profiles_id' => $profiles['first']]);
    verify(Session::haveRight('computer', READ | CREATE) === (READ | CREATE)
        && !Session::haveRight('computer', UPDATE), 'Switching back restores the original owned permission mask');
    verify($api->request($client, 'entity', ['entities_id' => $child, 'is_recursive' => true]) === true
        && $_SESSION['glpiactiveentities'] === [$child => $child], 'A recursive ancestor grant permits a descendant recursive view');
    verify(Session::changeActiveEntities($parent, true)
        && isset($_SESSION['glpiactiveentities'][$parent], $_SESSION['glpiactiveentities'][$child], $_SESSION['glpiactiveentities'][$sibling])
        && !isset($_SESSION['glpiactiveentities'][$foreign])
        && end($events)['hook'] === 'change_entity', 'A recursive direct grant retains its complete subtree and accepted change hook');
    $writer()->update('glpi_users', $user, ['profiles_id' => $profiles['unavailable']]);
    verify((new Auth())->login($login, 'session secret', true) && $_SESSION['glpiactiveprofile']['id'] === $profiles['null'], 'Ungrantable default profile falls back to the NULL-first granted profile');

    $tokenUser = new User();
    verify($tokenUser->getFromDB($user), 'Token user reload');
    verify(Session::authWithToken($tokenUser->fields['personal_token'], 'personal_token', null, null)?->getID() === $user
        && array_keys($_SESSION['glpiprofiles']) === $expectedOrder, 'Personal-token authentication uses the same current grant snapshot');
    $expired = new Auth();
    $expired->auth_succeded = true;
    $expired->user = $tokenUser;
    $expired->password_expired = true;
    $events = [];
    Session::init($expired);
    verify(!isset($_SESSION['glpiprofiles']) && !isset($_SESSION['glpiactiveprofile']) && $events === [], 'Expired-password session cannot publish authorization or invoke initialization hooks');
    $denied = new Auth();
    $denied->auth_succeded = true;
    $denied->user = new User();
    verify($denied->user->getFromDB($ungranted), 'No-grant account reload');
    Session::init($denied);
    verify(!$denied->auth_succeded && $_SESSION['glpiprofiles'] === [] && !isset($_SESSION['glpiactiveprofile']), 'Existing login policy denies an active account with no grants');
    $writer()->update('glpi_users', $user, ['is_deleted' => true]);
    $deleted = new Auth();
    $deleted->auth_succeded = true;
    $deleted->user = $tokenUser;
    $events = [];
    Session::init($deleted);
    verify(!isset($_SESSION['glpiID']) && !isset($_SESSION['glpiprofiles']) && $events === [], 'Existing session initialization refuses a deleted account before exposing grants');
    $writer()->update('glpi_users', $user, ['is_deleted' => false]);
    $minimal = new User();
    verify($minimal->getFromDB($ungranted), 'Minimal-session account reload');
    $fixtures->create('glpi_groups_users', ['users_id' => $ungranted, 'groups_id' => $groups['child']]);
    unset($_SESSION['glpiID']);
    $minimal->loadMinimalSession($child, false);
    verify($_SESSION['glpigroups'] === [$groups['child']] && !isset($_SESSION['glpiprofiles']), 'Minimal session retains membership eligibility without requiring a profile grant');
} finally {
    while ($connection->getTransactionNestingLevel() > $initialNesting) {
        $connection->rollBack();
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $GLPI_CACHE = $savedCache;
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
    $SQL_TOTAL_REQUEST = $savedRequests;
    $DEBUG_SQL = $savedDebug;
}
echo "Session grant snapshots, recursive group ownership, supplied connection, login hooks and API policy passed\n";
