<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Csrf;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php personal-token-authorization.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$savedSession = $_SESSION;
$savedSessionId = session_id();
$savedSessionStatus = session_status();
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$savedTranslation = $TRANSLATE;
$savedLocale = class_exists('Locale') ? Locale::getDefault() : null;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'personal_token_fixture']);
$connection = $DB->getDoctrineConnection();
$nesting = $connection->getTransactionNestingLevel();
$connection->beginTransaction();
try {
    $CFG_GLPI['use_notifications'] = false;
    // Account admission uses the application/session wall clock. Keep both
    // relative boundaries inside the preserved portable native TIMESTAMP range.
    $admissionClock = new DateTimeImmutable($_SESSION['glpi_currenttime']);
    $pastAdmission = $admissionClock->modify('-1 day')->format('Y-m-d H:i:s');
    $futureAdmission = $admissionClock->modify('+1 day')->format('Y-m-d H:i:s');
    verify($pastAdmission > '1970-01-03 00:00:00' && $futureAdmission < '2038-01-17 00:00:00', 'Fixture session clock permits past/future admission dates within both native TIMESTAMP domains');
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Personal token ' . bin2hex(random_bytes(5));
    $parent = $fixtures->create('glpi_entities', ['name' => $prefix . ' parent']);
    $child = $fixtures->create('glpi_entities', ['name' => $prefix . ' child', 'entities_id' => $parent]);
    $grandchild = $fixtures->create('glpi_entities', ['name' => $prefix . ' grandchild', 'entities_id' => $child]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $prefix . ' foreign']);
    $profile = $fixtures->create('glpi_profiles', ['name' => $prefix, 'interface' => 'central']);
    $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'planning', 'rights' => Planning::READMY]);
    $group = $fixtures->create('glpi_groups', ['name' => $prefix . ' parent group', 'entities_id' => $parent, 'is_recursive' => true]);
    $childGroup = $fixtures->create('glpi_groups', ['name' => $prefix . ' child group', 'entities_id' => $child]);
    $rootGroup = $fixtures->create('glpi_groups', ['name' => $prefix . ' root group', 'entities_id' => 0]);
    $users = [];
    $tokens = [];
    foreach (['direct', 'recursive', 'root', 'no_grants', 'provisioned'] as $kind) {
        $tokens[$kind] = bin2hex(random_bytes(24));
        $users[$kind] = $fixtures->create('glpi_users', [
            'name' => $prefix . ' ' . $kind, 'personal_token' => $tokens[$kind],
            'entities_id' => $kind === 'root' ? 0 : $parent, 'profiles_id' => $profile,
            'is_active' => true, 'is_deleted' => false, 'begin_date' => null, 'end_date' => null,
            'language' => 'fr_FR',
        ]);
        if (!in_array($kind, ['no_grants', 'provisioned'], true)) {
            $fixtures->create('glpi_profiles_users', ['users_id' => $users[$kind], 'profiles_id' => $profile,
                'entities_id' => $kind === 'root' ? 0 : $parent, 'is_recursive' => $kind === 'recursive']);
        }
        foreach ([$group, $childGroup, $rootGroup] as $membership) {
            $fixtures->create('glpi_groups_users', ['users_id' => $users[$kind], 'groups_id' => $membership]);
        }
    }
    $events = [];
    foreach (['init_session', 'change_entity', 'change_profile'] as $hook) {
        $PLUGIN_HOOKS[$hook]['personal_token_fixture'] = static function () use ($hook, &$events): void {
            $events[] = ['hook' => $hook, 'user' => Session::getLoginUserID(),
                'entity' => $_SESSION['glpiactive_entity'] ?? null, 'groups' => $_SESSION['glpigroups'] ?? []];
        };
    }
    $priorContext = static function () use (&$events): array {
        verify((new Auth())->login('itsm', 'itsm', true), 'Real previous account context');
        Csrf::generate();
        Session::getNewIDORToken('Computer');
        Session::loadLanguage('en_GB', false);
        $events = [];
        return ['session' => $_SESSION, 'id' => session_id(), 'status' => session_status(), 'translation_defined' => true,
            'translation' => $GLOBALS['TRANSLATE'], 'locale' => class_exists('Locale') ? Locale::getDefault() : null];
    };
    $restored = static function (array $before, string $reason): void {
        verify($_SESSION === $before['session'] && session_id() === $before['id'] && session_status() === $before['status'], $reason . ': prior account, scope, groups and real page tokens restored with the same PHP session');
        verify(array_key_exists('TRANSLATE', $GLOBALS) === $before['translation_defined']
            && (!$before['translation_defined'] || $GLOBALS['TRANSLATE'] === $before['translation'])
            && (!class_exists('Locale') || Locale::getDefault() === $before['locale']), $reason . ': exact previous translation object and locale retained');
    };
    foreach (['', 'unknown_' . bin2hex(random_bytes(24))] as $invalidToken) {
        $before = $priorContext();
        verify(Session::authWithToken($invalidToken, 'personal_token', $foreign, true) === false, 'Empty or unknown personal token cannot authenticate or apply entity scope');
        $restored($before, 'Invalid token');
        verify($events === [], 'Invalid token invokes no initialization/change hooks');
    }
    foreach (['inactive' => ['is_active' => false], 'deleted' => ['is_deleted' => true],
        'future' => ['begin_date' => $futureAdmission], 'expired' => ['end_date' => $pastAdmission]] as $reason => $changes) {
        (new RecordWriter(Orm::create($DB)))->update('glpi_users', $users['direct'], $changes);
        $before = $priorContext();
        verify(Session::authWithToken($tokens['direct'], 'personal_token', $foreign, true) === false, $reason . ': matched token does not bypass the owning account/date admission policy');
        $restored($before, $reason);
        verify($events === [], $reason . ': refusal happens before session reset, scope/group publication or initialization hooks');
        (new RecordWriter(Orm::create($DB)))->update('glpi_users', $users['direct'], ['is_active' => true, 'is_deleted' => false, 'begin_date' => null, 'end_date' => null]);
    }
    // A deterministic timestamp tests the same owning predicate used by both
    // public authentication paths, without racing the wall clock at equality.
    $datedUser = new User();
    verify($datedUser->getFromDB($users['direct']), 'Load the real account for strict date-boundary controls');
    $admission = new ReflectionMethod(Session::class, 'accountIsAdmitted');
    $anchor = '2030-06-01 12:00:00';
    foreach ([['begin_date', $anchor, false], ['end_date', $anchor, false],
        ['begin_date', '2030-06-01 11:59:59', true], ['end_date', '2030-06-01 12:00:01', true]] as [$field, $date, $allowed]) {
        (new RecordWriter(Orm::create($DB)))->update('glpi_users', $users['direct'], ['begin_date' => null, 'end_date' => null, $field => $date]);
        verify($datedUser->getFromDB($users['direct']) && $admission->invoke(null, $datedUser, $anchor) === $allowed, $field . ': persisted equality is excluded and the valid one-second interior is admitted by the shared policy');
    }
    (new RecordWriter(Orm::create($DB)))->update('glpi_users', $users['direct'], ['begin_date' => null, 'end_date' => null]);
    foreach ([[$tokens['direct'], $foreign, false], [$tokens['direct'], $child, false],
        [$tokens['direct'], $parent, true], [$tokens['root'], $child, false], [$tokens['root'], 0, true]] as [$token, $entity, $recursive]) {
        $before = $priorContext();
        verify(Session::authWithToken($token, 'personal_token', $entity, $recursive) === false, 'Personal token cannot select foreign, inherited nonrecursive or ungranted recursive scope');
        $restored($before, 'Refused scope');
        verify(array_column($events, 'hook') === ['init_session', 'change_entity', 'change_profile'], 'Eligible token runs the original authentication hooks but rejected requested scope invokes no extra change hook');
        verify(!in_array($foreign, array_column($events, 'entity'), true)
            && !in_array($childGroup, array_merge(...array_column($events, 'groups')), true), 'Rejected requested entity/groups are never exposed by initialization/change hooks');
    }
    $before = $priorContext();
    verify(Session::authWithToken($tokens['no_grants'], 'personal_token', $foreign, true) === false, 'Final actual no-grant initialization refuses the personal token');
    $restored($before, 'No grants');
    verify(array_column($events, 'hook') === ['init_session'] && $events[0]['groups'] === [], 'No-grant attempt retains the actual provisioning hook but publishes no profile/entity/groups');
    $before = $priorContext();
    unset($GLOBALS['TRANSLATE']);
    $before['translation_defined'] = false;
    verify(Session::authWithToken($tokens['no_grants'], 'personal_token', null, null) === false, 'Eligible final refusal restores an originally absent translation global');
    $restored($before, 'Absent translation context');
    $before = $priorContext();
    session_write_close();
    $before['status'] = PHP_SESSION_NONE;
    verify(Session::authWithToken($tokens['no_grants'], 'personal_token', null, null) === false, 'Final rejection also respects a caller that closed its real PHP session');
    $restored($before, 'Closed caller session');
    Session::start();
    $reopened = $_SESSION;
    $expectedStored = $before['session'];
    unset($reopened['glpi_currenttime'], $expectedStored['glpi_currenttime']);
    verify($reopened === $expectedStored, 'Reopening the actual prior session proves restored user/grants/groups/page tokens were persisted, not only copied in memory');
    session_write_close();

    // A real initialization hook may provision the grant before Session reads it.
    $provisionedGrant = null;
    $PLUGIN_HOOKS['init_session']['personal_token_fixture'] = static function () use ($fixtures, $users, $profile, $parent, &$events, &$provisionedGrant): void {
        $events[] = ['hook' => 'init_session', 'user' => Session::getLoginUserID()];
        if (Session::getLoginUserID() === $users['provisioned']) {
            $provisionedGrant = $fixtures->create('glpi_profiles_users', ['users_id' => $users['provisioned'], 'profiles_id' => $profile, 'entities_id' => $parent]);
        }
    };
    $events = [];
    $accepted = Session::authWithToken($tokens['provisioned'], 'personal_token', $parent, false);
    verify($accepted instanceof User && $accepted->getID() === $users['provisioned']
        && $provisionedGrant > 0 && $_SESSION['glpiactiveprofile']['id'] === $profile
        && $_SESSION['glpiactiveentities'] === [$parent => $parent], 'Actual init_session grant provisioning remains accepted before current grant/profile selection');

    unset($PLUGIN_HOOKS['init_session']['personal_token_fixture']);
    $before = $priorContext();
    $pluginMarker = null;
    $PLUGIN_HOOKS['init_session']['personal_token_fixture'] = static function () use ($fixtures, $prefix, &$pluginMarker): void {
        $pluginMarker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' hook side effect']);
    };
    verify(Session::authWithToken($tokens['no_grants'], 'personal_token', null, null) === false, 'A provisioning hook without a grant still cannot authenticate the token');
    $restored($before, 'Hook with database side effect');
    verify($pluginMarker > 0 && (new RecordRepository(Orm::create($DB)))->find('glpi_suppliers', 'id', $pluginMarker)['name'] === $prefix . ' hook side effect', 'Restoring session context does not pretend to roll back legitimate plugin database effects');
    unset($PLUGIN_HOOKS['init_session']['personal_token_fixture']);
    $before = $priorContext();
    $PLUGIN_HOOKS['init_session']['personal_token_fixture'] = static function (): void {
        throw new RuntimeException('Personal token initialization hook failure');
    };
    try {
        Session::authWithToken($tokens['direct'], 'personal_token', $parent, false);
        throw new LogicException('Throwing initialization hook was hidden');
    } catch (RuntimeException $error) {
        verify(!$error instanceof LogicException && $error->getMessage() === 'Personal token initialization hook failure', 'Hook exception propagates to the caller');
    }
    $restored($before, 'Throwing hook');
    unset($PLUGIN_HOOKS['init_session']['personal_token_fixture']);

    (new RecordWriter(Orm::create($DB)))->update('glpi_users', $users['direct'], ['begin_date' => $pastAdmission, 'end_date' => $futureAdmission]);
    $accepted = Session::authWithToken($tokens['direct'], 'personal_token', $parent, false);
    verify($accepted instanceof User && $accepted->getID() === $users['direct']
        && $_SESSION['glpiactiveentities'] === [$parent => $parent] && $_SESSION['glpigroups'] === [$group]
        && Session::haveRight('planning', Planning::READMY) === Planning::READMY, 'Active account within explicit dates retains direct scope, eligible groups and real planning mask');
    $accepted = Session::authWithToken($tokens['recursive'], 'personal_token', $child, false);
    verify($accepted instanceof User && $accepted->getID() === $users['recursive']
        && $_SESSION['glpiactive_entity'] === $child && $_SESSION['glpiactiveentities'] === [$child => $child]
        && $_SESSION['glpigroups'] === [$group, $childGroup], 'Recursive ancestor grant accepts requested descendant-only scope instead of reverting to the account default entity');
    $accepted = Session::authWithToken($tokens['recursive'], 'personal_token', $child, true);
    verify($accepted instanceof User && $accepted->getID() === $users['recursive']
        && isset($_SESSION['glpiactiveentities'][$child], $_SESSION['glpiactiveentities'][$grandchild])
        && !isset($_SESSION['glpiactiveentities'][$parent], $_SESSION['glpiactiveentities'][$foreign]), 'Accepted descendant recursive scope preserves exactly its subtree');
    $accepted = Session::authWithToken($tokens['root'], 'personal_token', 0, false);
    verify($accepted instanceof User && $accepted->getID() === $users['root']
        && $_SESSION['glpiactive_entity'] === 0 && $_SESSION['glpigroups'] === [$rootGroup], 'Personal token retains a real direct root-zero grant and root membership');
    verify($connection->getTransactionNestingLevel() === $nesting + 1, 'Authentication leaves the supplied caller database transaction open');
} finally {
    while ($connection->getTransactionNestingLevel() > $nesting) {
        $connection->rollBack();
    }
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
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $TRANSLATE = $savedTranslation;
    if ($savedLocale !== null) {
        Locale::setDefault($savedLocale);
    }
    $plugins->setValue(null, $savedPlugins);
}
echo $DB->getProvider() . ": Actual personal-token admission, scope, publication and lifecycle restoration: $assertions assertions passed.\n";
