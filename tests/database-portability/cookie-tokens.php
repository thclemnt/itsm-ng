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

/** Exercise the real final User callback without replacing its preparation. */
class CookieBooleanUserFixture extends User
{
    public string $flagWrite = 'selected';

    public static function getTable($classname = null)
    {
        return User::getTable();
    }

    public static function getType()
    {
        return User::class;
    }

    public function pre_updateInDB()
    {
        parent::pre_updateInDB();
        $this->fields['is_ids_visible'] = 2;
        if ($this->flagWrite === 'selected') {
            if (!in_array('is_ids_visible', $this->updates, true)) {
                $this->updates[] = 'is_ids_visible';
            }
        } else {
            $this->updates = array_values(array_diff($this->updates, ['is_ids_visible']));
        }
    }
}

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

    // Compose token production with the current User's inherited Boolean
    // preference. Error messages are intentional refusal effects; compare the
    // authentication/preference/cookie state rather than all SESSION keys.
    $compositionSession = $_SESSION;
    $compositionConfig = $CFG_GLPI;
    $compositionHooks = $PLUGIN_HOOKS;
    $compositionCookies = $_COOKIE;
    try {
        verify(Session::getLoginUserID() === $userId, 'Boolean/token composition uses the actually authenticated current account');
        $CFG_GLPI['is_ids_visible'] = 1;
        $writer()->update('glpi_users', $userId, ['is_ids_visible' => null]);
        $_SESSION['glpiis_ids_visible'] = 1;
        $context = static fn (): array => array_intersect_key($_SESSION, array_flip([
            'glpiID', 'glpiextauth', 'glpiactiveprofile', 'glpiactive_entity', 'glpiactiveentities',
            'glpigroups', 'glpilanguage', 'glpiis_ids_visible', 'valid_id', '_glpi_csrf_token',
            'csrf_token_time', 'glpicsrftokens', 'glpiidortokens',
        ]));
        $compositionMode = 'early';
        $preCalls = 0;
        $pre = static function (User $item) use ($userId, &$compositionMode, &$preCalls): void {
            if ($item->getID() === $userId && array_key_exists('cookie_token', $item->input)) {
                ++$preCalls;
                if ($compositionMode !== 'unselected') {
                    $item->input['is_ids_visible'] = $compositionMode === 'early' ? 2 : 0;
                }
            }
        };
        $accepted = static function (User $item) use ($userId, &$updates): void {
            if ($item->getID() === $userId && in_array('cookie_token', $item->updates, true)) {
                $updates[] = $item->fields['cookie_token'];
            }
        };
        foreach ([User::class, CookieBooleanUserFixture::class] as $class) {
            // Plugin dispatch uses the concrete PHP class, not getType().
            $PLUGIN_HOOKS['pre_item_update']['cookie_token_fixture'][$class] = $pre;
            $PLUGIN_HOOKS['item_update']['cookie_token_fixture'][$class] = $accepted;
        }
        foreach (['early', 'selected'] as $compositionMode) {
            $subject = $compositionMode === 'early' ? new User() : new CookieBooleanUserFixture();
            verify($subject->getFromDB($userId), 'Load the actual account for composed Boolean refusal');
            $before = $read();
            $beforeHistory = $history();
            $beforeContext = $context();
            $updates = [];
            $preCalls = 0;
            verify($subject->getAuthToken('cookie_token', true) === false, 'Invalid ' . $compositionMode . ' Boolean refuses the actual token producer');
            verify($preCalls === 1 && $read() === $before && $history() === $beforeHistory && $updates === [], 'Composed refusal preserves full stored account/history and runs no accepted hook');
            verify($context() === $beforeContext && $_COOKIE === $compositionCookies, 'Composed refusal preserves actual authentication, inherited preference and cookie context');
        }
        foreach (['cancelled', 'unselected'] as $compositionMode) {
            $subject = new CookieBooleanUserFixture();
            $subject->flagWrite = $compositionMode;
            verify($subject->getFromDB($userId), 'Load the actual account for a nonpersisted Boolean callback control');
            $beforeHash = $read()['cookie_token'];
            $updates = [];
            $preCalls = 0;
            $rotated($subject->getAuthToken('cookie_token', true), $beforeHash, 'Token rotation with an invalid ' . $compositionMode . ' Boolean callback value');
            verify($preCalls === 1 && $read()['is_ids_visible'] === null && $subject->fields['is_ids_visible'] === null
                && ($compositionMode === 'unselected' || $subject->input['is_ids_visible'] === null)
                && (int)$_SESSION['glpiis_ids_visible'] === 1 && $_COOKIE === $compositionCookies, 'Only selected writes matter: inherited flag and effective preference survive accepted token rotation');
        }
        unset($PLUGIN_HOOKS['pre_item_update']['cookie_token_fixture']);

        // Acceptance is inside the supplied caller transaction, not a commit.
        // Verify storage with fresh scalar/public reads after owned rollback;
        // accepted plugin observations are deliberately not rolled back here.
        $priorSecret = 'savepoint previous cookie';
        $priorHash = Auth::getPasswordHash($priorSecret);
        $writer()->update('glpi_users', $userId, ['cookie_token' => $priorHash,
            'cookie_token_date' => new DateTimeImmutable($_SESSION['glpi_currenttime'])]);
        $before = $read();
        $beforeHistory = $history();
        $callerDepth = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $subject = new User();
            verify($subject->getFromDB($userId), 'Load an existing credential inside the owned caller savepoint');
            $updates = [];
            $issued = $subject->getAuthToken('cookie_token', true);
            $rotated($issued, $priorHash, 'Accepted rotation inside an owned caller savepoint');
            verify($connection->getTransactionNestingLevel() === $callerDepth + 1, 'Token producer does not commit or release the owned caller savepoint');
        } finally {
            while ($connection->getTransactionNestingLevel() > $callerDepth) {
                $connection->rollBack();
            }
        }
        $storedHash = (new UserRepository(Orm::create($DB)))->tokenValue($userId, 'cookie_token');
        $reloaded = new User();
        verify($connection->getTransactionNestingLevel() === $callerDepth && $read() === $before && $history() === $beforeHistory, 'Owned rollback restores the actual account and history without committing outer work');
        verify($reloaded->getFromDB($userId) && $reloaded->fields['cookie_token'] === $priorHash && $storedHash === $priorHash
            && Auth::checkPassword($priorSecret, $storedHash) && !Auth::checkPassword($issued, $storedHash), 'Fresh supplied-writer and public reads restore the prior credential and invalidate the rolled-back issued credential');
    } finally {
        $_SESSION = $compositionSession;
        $CFG_GLPI = $compositionConfig;
        $PLUGIN_HOOKS = $compositionHooks;
        $_COOKIE = $compositionCookies;
    }
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
