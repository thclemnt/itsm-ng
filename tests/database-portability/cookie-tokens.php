<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Entity\User as MappedUser;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\UserRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php cookie-tokens.php /path/to/test-config\n");
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
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$savedCookies = $_COOKIE;
$savedTranslation = $TRANSLATE;
$savedLocale = class_exists('Locale') ? Locale::getDefault() : null;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'cookie_token_fixture']);
$connection = $DB->getDoctrineConnection();
$depth = $connection->getTransactionNestingLevel();
$connection->beginTransaction();
$savedErrorReporting = error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
try {
    $CFG_GLPI['use_notifications'] = false;
    $CFG_GLPI['login_remember_time'] = DAY_TIMESTAMP;
    verify((new User())->getAuthToken('cookie_token', true) === false, 'An unloaded account cannot create a credential or parse an absent date');
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Cookie token ' . bin2hex(random_bytes(5));
    $profile = $fixtures->create('glpi_profiles', ['name' => $prefix, 'interface' => 'central']);
    $userId = $fixtures->create('glpi_users', [
        'name' => $prefix, 'password' => Auth::getPasswordHash('cookie secret'), 'authtype' => Auth::DB_GLPI,
        'profiles_id' => $profile, 'entities_id' => 0, 'is_active' => true, 'is_deleted' => false,
        'begin_date' => null, 'end_date' => null, 'cookie_token' => null, 'cookie_token_date' => null,
        'password_last_update' => new DateTimeImmutable(),
    ]);
    $fixtures->create('glpi_profiles_users', ['users_id' => $userId, 'profiles_id' => $profile, 'entities_id' => 0]);
    $read = static fn (): array => (new RecordRepository(Orm::create($DB)))->find('glpi_users', 'id', $userId);
    $history = static fn (): int => (new RecordRepository(Orm::create($DB)))->countMatching('glpi_logs', ['itemtype' => 'User', 'items_id' => $userId]);
    $writer = static fn () => new RecordWriter(Orm::create($DB));
    $model = new User();
    $updates = [];
    $PLUGIN_HOOKS['item_update']['cookie_token_fixture'][User::class] = static function (User $item) use ($userId, &$updates): void {
        if ($item->getID() === $userId && in_array('cookie_token', $item->updates, true)) {
            $updates[] = $item->fields['cookie_token'];
        }
    };
    $rotated = static function ($token, ?string $previous, string $reason) use ($read, &$updates): void {
        $row = $read();
        verify(is_string($token) && strlen($token) === 40, $reason . ': return a generated token after the public lifecycle');
        verify($row['cookie_token'] !== $token && Auth::checkPassword($token, $row['cookie_token'])
            && $row['cookie_token'] !== $previous, $reason . ': persist a new matching hash, never plaintext or the previous cookie hash');
        verify($row['cookie_token_date'] === $_SESSION['glpi_currenttime'], $reason . ': persist the actual session timestamp');
        verify(count($updates) === 1 && $updates[0] === $row['cookie_token'], $reason . ': accepted public update hook observes the persisted hash exactly once');
    };
    foreach ([false, true] as $forced) {
        $writer()->update('glpi_users', $userId, ['cookie_token' => null, 'cookie_token_date' => null]);
        verify($model->getFromDB($userId), 'Load an actual account with absent cookie token and NULL date');
        $updates = [];
        $rotated($model->getAuthToken('cookie_token', $forced), null, $forced ? 'Forced rotation from NULL' : 'Absent token creation from NULL');
    }
    $previousHash = Auth::getPasswordHash('old cookie secret');
    foreach ([false, true] as $forced) {
        $writer()->update('glpi_users', $userId, ['cookie_token' => $previousHash, 'cookie_token_date' => null]);
        verify($model->getFromDB($userId), 'Load an existing cookie hash with NULL date');
        $updates = [];
        $rotated($model->getAuthToken('cookie_token', $forced), $previousHash, $forced ? 'Forced rotation ignores NULL expiry' : 'Undated existing cookie is outdated');
        verify(!Auth::checkPassword('old cookie secret', $read()['cookie_token']), 'Missing timestamp cannot make the old credential current');
    }
    $writer()->update('glpi_users', $userId, ['cookie_token' => $previousHash, 'cookie_token_date' => new DateTimeImmutable('-2 days')]);
    verify($model->getFromDB($userId), 'Load an actually expired cookie');
    $updates = [];
    $rotated($model->getAuthToken('cookie_token'), $previousHash, 'Expired cookie rotation');
    $writer()->update('glpi_users', $userId, ['cookie_token' => $previousHash, 'cookie_token_date' => new DateTimeImmutable()]);
    verify($model->getFromDB($userId), 'Load a currently valid cookie');
    $before = $read();
    $beforeHistory = $history();
    $updates = [];
    verify($model->getAuthToken('cookie_token') === $previousHash, 'Valid unforced cookie retains the stored hash shape expected by alternate authentication');
    verify($read() === $before && $history() === $beforeHistory && $updates === [], 'Reuse changes no row/history and invokes no public update hook');
    $manager = Orm::create($DB);
    try {
        $managed = $manager->find(MappedUser::class, $userId);
        $managed->cookie_token = 'unflushed model value';
        verify(
            $manager->getConnection() === $connection
            && (new UserRepository($manager))->tokenValue($userId, 'cookie_token') === $previousHash,
            'Scalar credential read uses the supplied writer and ignores an actually loaded but unflushed ORM identity'
        );
    } finally {
        $manager->close();
    }
    $rotated($model->getAuthToken('cookie_token', true), $previousHash, 'Forced rotation replaces a still-valid cookie');

    $before = $read();
    $beforeHistory = $history();
    $updates = [];
    $vetoCalls = 0;
    $PLUGIN_HOOKS['pre_item_update']['cookie_token_fixture'][User::class] = static function (User $item) use ($userId, &$vetoCalls): void {
        if ($item->getID() === $userId) {
            ++$vetoCalls;
            $item->input = [];
        }
    };
    verify($model->getAuthToken('cookie_token', true) === false, 'An actual public preparation-hook refusal returns no unpersisted cookie token');
    verify($vetoCalls === 1 && $read() === $before && $history() === $beforeHistory && $updates === [], 'Refusal retains the previous token/date/full row/history and emits no accepted update hook');
    verify($model->getAuthToken('personal_token', true) === false && $read() === $before, 'The same required persistence outcome protects personal-token generation');
    unset($PLUGIN_HOOKS['pre_item_update']['cookie_token_fixture']);
    verify($model->getFromDB($userId), 'Reload after the real refusal');
    $personal = $model->getAuthToken();
    verify(is_string($personal) && $read()['personal_token'] === $personal, 'Accepted personal token still persists plaintext as its existing contract requires');

    $beforeHash = $read()['cookie_token'];
    $changedAfterWrite = false;
    $PLUGIN_HOOKS['item_update']['cookie_token_fixture'][User::class] = static function (User $item) use ($userId, $writer, $beforeHash, &$changedAfterWrite): void {
        if ($item->getID() === $userId) {
            $writer()->update('glpi_users', $userId, ['cookie_token' => $beforeHash]);
            $changedAfterWrite = $item->fields['cookie_token'] !== $beforeHash;
        }
    };
    verify($model->getAuthToken('cookie_token', true) === false && $changedAfterWrite
        && $read()['cookie_token'] === $beforeHash, 'The actual stored-value postcondition rejects a public hook that restores the old credential while leaving the model at its attempted hash');
    $changedHash = null;
    $attemptedHash = null;
    $PLUGIN_HOOKS['item_update']['cookie_token_fixture'][User::class] = static function (User $item) use ($userId, $writer, &$attemptedHash, &$changedHash): void {
        if ($item->getID() === $userId) {
            $attemptedHash = $item->fields['cookie_token'];
            // Case-insensitive MySQL equality must not admit a different hash.
            // Change the bcrypt payload, leaving its algorithm prefix intact.
            $changedHash = preg_replace_callback('/[a-zA-Z]/', static fn (array $match): string => ctype_upper($match[0]) ? strtolower($match[0]) : strtoupper($match[0]), substr($attemptedHash, 7), 1);
            $changedHash = substr($attemptedHash, 0, 7) . $changedHash;
            $writer()->update('glpi_users', $userId, ['cookie_token' => $changedHash]);
        }
    };
    verify($model->getAuthToken('cookie_token', true) === false && is_string($changedHash)
        && $changedHash !== $attemptedHash && strtolower($changedHash) === strtolower($attemptedHash)
        && $read()['cookie_token'] === $changedHash, 'A real case-only post-hook credential mutation is rejected byte-exactly even on case-insensitive storage');
    $PLUGIN_HOOKS['item_update']['cookie_token_fixture'][User::class] = static function (User $item) use ($userId, &$updates): void {
        if ($item->getID() === $userId && in_array('cookie_token', $item->updates, true)) {
            $updates[] = $item->fields['cookie_token'];
        }
    };

    $lowerProfile = $fixtures->create('glpi_profiles', ['name' => $prefix . ' lower actor', 'interface' => 'central']);
    $fixtures->create('glpi_profilerights', ['profiles_id' => $lowerProfile, 'name' => 'user', 'rights' => READ]);
    $lowerToken = bin2hex(random_bytes(24));
    $lower = $fixtures->create('glpi_users', [
        'name' => $prefix . ' lower actor', 'personal_token' => $lowerToken,
        'profiles_id' => $lowerProfile, 'is_active' => true, 'is_deleted' => false,
        'begin_date' => null, 'end_date' => null,
    ]);
    $fixtures->create('glpi_profiles_users', ['users_id' => $lower, 'profiles_id' => $lowerProfile, 'entities_id' => 0]);
    // The target has a real administrator grant, making the current User-owned
    // management policy demonstrably stricter than the read-only actor.
    $fixtures->create('glpi_profiles_users', ['users_id' => $userId,
        'profiles_id' => $savedSession['glpiactiveprofile']['id'], 'entities_id' => 0]);
    verify(Session::authWithToken($lowerToken, 'personal_token', 0, false) instanceof User, 'Actual granted lower-right actor authentication');
    verify(!$model->currentUserHaveMoreRightThan($userId), 'Current owning User policy denies management of the higher-right target');
    $beforeHash = $read()['cookie_token'];
    verify($model->getAuthToken('cookie_token', true) === false && $read()['cookie_token'] === $beforeHash, 'A stripped protected-token field cannot produce an unpersisted credential despite a successful public update of other fields');

    // Exercise the actual login producer of forced cookie rotation. This is a
    // public-method header-warning regression, not a real HTTP cookie assertion.
    $writer()->update('glpi_users', $userId, ['cookie_token' => null, 'cookie_token_date' => null]);
    $updates = [];
    $auth = new Auth();
    verify($auth->login($prefix, 'cookie secret', true, true, 'local'), 'Actual password login with remember-me accepts the active granted account under strict warnings');
    verify(Session::getLoginUserID() === $userId && $auth->auth_succeded
        && is_string($read()['cookie_token']) && $read()['cookie_token_date'] !== null
        && count($updates) === 1, 'Remember-me login rotates and persists the cookie through the real User lifecycle');
    $cookie = json_decode($_COOKIE[session_name() . '_rememberme'] ?? '', true);
    verify(is_array($cookie) && count($cookie) === 2 && (int)$cookie[0] === $userId
        && is_string($cookie[1]) && Auth::checkPassword($cookie[1], $read()['cookie_token']), 'Actual remember-me payload contains the authenticated user and a plaintext credential matching its persisted hash');
    $before = $read();
    $beforeCookies = $_COOKIE;
    $updates = [];
    $vetoCalls = 0;
    $PLUGIN_HOOKS['pre_item_update']['cookie_token_fixture'][User::class] = static function (User $item) use ($userId, &$vetoCalls): void {
        if ($item->getID() === $userId && array_key_exists('cookie_token', $item->input)
            && $item->input['cookie_token'] !== $item->fields['cookie_token']) {
            ++$vetoCalls;
            $item->input = [];
        }
    };
    $auth = new Auth();
    verify($auth->login($prefix, 'cookie secret', true, true, 'local') && $auth->auth_succeded
        && Session::getLoginUserID() === $userId, 'Actual accepted password login remains valid when remembered-token rotation is refused');
    verify($vetoCalls === 1 && $_COOKIE === $beforeCookies && $updates === []
        && $read()['cookie_token'] === $before['cookie_token']
        && $read()['cookie_token_date'] === $before['cookie_token_date'], 'Actual remembered-login producer emits no replacement cookie or unpersisted token after the public veto');
    verify($connection->getTransactionNestingLevel() === $depth + 1, 'Token persistence retains the provided caller transaction');
} finally {
    restore_error_handler();
    error_reporting($savedErrorReporting);
    while ($connection->getTransactionNestingLevel() > $depth) {
        $connection->rollBack();
    }
    if (session_id() !== $savedSessionId) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id($savedSessionId);
        Session::start();
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $_COOKIE = $savedCookies;
    $TRANSLATE = $savedTranslation;
    if ($savedLocale !== null) {
        Locale::setDefault($savedLocale);
    }
    $plugins->setValue(null, $savedPlugins);
}
echo $DB->getProvider() . ": Cookie-token nullable-date, rotation, lifecycle and actual login controls: $assertions assertions passed.\n";
