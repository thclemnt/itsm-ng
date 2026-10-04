<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php authentication-completion.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
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
$savedRequest = $_REQUEST;
$savedCookies = $_COOKIE;
$savedGet = $_GET;
$savedPost = $_POST;
$savedTranslation = $TRANSLATE;
$savedLocale = class_exists('Locale') ? Locale::getDefault() : null;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'authentication_completion_fixture']);
$rules = SingletonRuleList::getInstance(RuleRight::class, 0);
$savedRuleList = $rules->list;
$savedRuleLoad = $rules->load;
$connection = $DB->getDoctrineConnection();
$nesting = $connection->getTransactionNestingLevel();
$connection->beginTransaction();
$primary = null;
try {
    $CFG_GLPI['use_notifications'] = false;
    $CFG_GLPI['password_expiration_delay'] = -1;
    $CFG_GLPI['password_expiration_lock_delay'] = -1;
    $CFG_GLPI['enable_api_login_external_token'] = true;
    $CFG_GLPI['login_remember_time'] = 7 * DAY_TIMESTAMP;
    $CFG_GLPI['cas_host'] = '';
    $CFG_GLPI['ssovariables_id'] = 0;
    $CFG_GLPI['x509_email_field'] = '';
    $CFG_GLPI['highcontrast_css'] = false;
    $_GET = $_POST = $_REQUEST = $_COOKIE = [];
    $records = static fn () => new RecordRepository(Orm::create($DB));
    $writer = static fn () => new RecordWriter(Orm::create($DB));
    verify($records()->matching('glpi_authldaps', ['is_active' => true]) === [], 'Fixture has no external synchronization producer');
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Authentication completion ' . bin2hex(random_bytes(5));
    $profile = $fixtures->create('glpi_profiles', ['name' => $prefix, 'interface' => 'central']);
    $group = $fixtures->create('glpi_groups', ['name' => $prefix, 'entities_id' => 0]);
    $password = bin2hex(random_bytes(20));
    $token = bin2hex(random_bytes(24));
    $cookie = bin2hex(random_bytes(24));
    $user = $fixtures->create('glpi_users', [
        'name' => $prefix, 'password' => Auth::getPasswordHash($password), 'authtype' => Auth::DB_GLPI,
        'is_active' => true, 'is_deleted' => false, 'is_deleted_ldap' => true,
        'highcontrast_css' => false, 'language' => 'en_GB', 'timezone' => 'UTC',
        'api_token' => $token, 'cookie_token' => Auth::getPasswordHash($cookie),
        'cookie_token_date' => $_SESSION['glpi_currenttime'], 'profiles_id' => $profile,
    ]);
    $manual = $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => 0, 'is_dynamic' => false]);
    $manualGroup = $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $group, 'is_dynamic' => false]);
    $read = static fn () => $records()->find('glpi_users', 'id', $user);
    $preferences = static fn () => array_intersect_key($read(), array_flip($CFG_GLPI['user_pref_field']));
    $baseline = $preferences();
    $nativePreferences = static fn () => array_intersect_key(
        $connection->fetchAssociative('SELECT * FROM glpi_users WHERE id = ?', [$user]),
        array_flip($CFG_GLPI['user_pref_field'])
    );
    $nativeBaseline = $nativePreferences();
    $observedInputs = [];
    $observedProviders = [];
    $PLUGIN_HOOKS['pre_item_update']['authentication_completion_fixture'][User::class] = static function ($item) use ($user, &$observedInputs, &$observedProviders): void {
        if ($item instanceof User && (int)$item->getID() === $user) {
            verify(array_key_exists('highcontrast_css', $item->fields), 'Public hook retains complete stored account context');
            $observedInputs[] = array_keys($item->input);
            $observedProviders[] = $item->input['_extauth'] ?? null;
        }
    };
    foreach (['local', 'api', 'cookie'] as $provider) {
        $_REQUEST = $_COOKIE = [];
        if ($provider === 'api') {
            $_REQUEST['user_token'] = $token;
        } elseif ($provider === 'cookie') {
            $_COOKIE[session_name() . '_rememberme'] = json_encode([$user, $cookie], JSON_THROW_ON_ERROR);
        }
        verify((new Auth())->login($provider === 'local' ? $prefix : '', $provider === 'local' ? $password : '', $provider === 'local'), 'Actual verified login through ' . $provider);
        verify($preferences() === $baseline && $nativePreferences() === $nativeBaseline, 'Exact stored preference tuple remains unchanged through ' . $provider);
        verify($read()['is_deleted_ldap'] === 0 && $read()['last_login'] !== null, 'Completion persists owned account state');
        verify($DB->getDoctrineConnection() === $connection && $connection->getTransactionNestingLevel() === $nesting + 1, 'Login retains supplied writer and caller-owned frame');
    }
    verify($observedProviders === [null, 1, 1], 'Actual local/API/cookie hooks retain verified provider transients');
    foreach ($observedInputs as $keys) {
        verify(!in_array('highcontrast_css', $keys, true) && !in_array('language', $keys, true), 'Read-context preferences are not writable completion input');
    }
    // Nullable inheritance remains a deliberate preference command, not a login effect.
    foreach ([null, true, false] as $flag) {
        $writer()->update('glpi_users', $user, ['highcontrast_css' => $flag]);
        $expected = $preferences();
        $_REQUEST = $_COOKIE = [];
        verify((new Auth())->login($prefix, $password, true), 'Real local login for nullable preference state');
        verify($preferences() === $expected, 'False, true and inherited NULL are distinct and preserved');
    }
    $model = new User();
    verify($model->getFromDB($user) && $model->update(['id' => $user, 'highcontrast_css' => false]), 'Deliberate public default preference update');
    verify($read()['highcontrast_css'] === null && $_SESSION['glpihighcontrast_css'] === $CFG_GLPI['highcontrast_css'], 'Deliberate preference update retains inheritance and effective session refresh');
    $writer()->update('glpi_users', $user, ['highcontrast_css' => false]);
    $baseline = $preferences();

    // Actual stored rules, criterias and actions; no fabricated action/SQL engine.
    $rule = $fixtures->create('glpi_rules', ['sub_type' => RuleRight::class, 'name' => $prefix, 'match' => Rule::AND_MATCHING, 'is_active' => true]);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $rule, 'criteria' => 'LOGIN', 'condition' => Rule::PATTERN_IS, 'pattern' => $prefix]);
    $actionIds = [];
    foreach (['timezone' => 'UTC', 'is_active' => '1', '_entities_id_default' => '0', '_profiles_id_default' => (string)$profile, 'groups_id' => (string)$group, 'entities_id' => '0', 'profiles_id' => (string)$profile] as $field => $value) {
        $actionIds[$field] = $fixtures->create('glpi_ruleactions', ['rules_id' => $rule, 'action_type' => 'assign', 'field' => $field, 'value' => $value]);
    }
    $rules->load = 0;
    $collection = new RuleRightCollection();
    $context = $read();
    $evaluation = $collection->evaluateAuthentication([$group], $context, ['type' => Auth::DB_GLPI, 'login' => $prefix]);
    verify($evaluation->outcome->assignments['timezone'] === 'UTC' && $evaluation->outcome->assignments['is_active'] === '1', 'Equal-valued actual actions retain explicit mutation intent');
    verify(!array_key_exists('highcontrast_css', $evaluation->outcome->assignments) && $evaluation->context['highcontrast_css'] === 0, 'Rule read context and mutation ownership stay distinct');
    verify($evaluation->outcome->grants['rules_entities_rights'] === [['0', (string)$profile, 0]], 'Actual entity/profile actions produce complete dynamic grant outcome');
    $observedInputs = [];
    verify((new Auth())->login($prefix, $password, true), 'Real login applies explicit matched rule outcome');
    verify(in_array('timezone', end($observedInputs), true) && in_array('is_active', end($observedInputs), true), 'Equal-valued rule fields reach the real public preparation hook');
    verify($preferences() === $baseline, 'Matched rule preserves unrelated exact preference tuple');
    verify($records()->find('glpi_profiles_users', 'id', $manual) !== null && $records()->find('glpi_groups_users', 'id', $manualGroup) !== null, 'Manual grants and group ownership survive dynamic rule processing');
    $dynamic = $records()->matching('glpi_profiles_users', ['users_id' => $user, 'is_dynamic' => true]);
    verify(count($dynamic) === 1 && $dynamic[0]['profiles_id'] === $profile && $dynamic[0]['entities_id'] === 0, 'Rule outcome reconciles actual dynamic grants on supplied writer');

    // Reentrant matching hook uses the same owning collection and cached rule.
    $reentered = false;
    $PLUGIN_HOOKS['rule_matched']['authentication_completion_fixture'] = static function () use ($collection, $context, $prefix, &$reentered): void {
        if (!$reentered) {
            $reentered = true;
            $inner = $collection->evaluateAuthentication([], $context, ['type' => Auth::DB_GLPI, 'login' => $prefix . ' absent']);
            verify($inner->outcome->assignments === [] && $inner->outcome->grants === [], 'Nested no-match evaluation cannot inherit outer mutations');
        }
    };
    $outer = $collection->evaluateAuthentication([$group], $context, ['type' => Auth::DB_GLPI, 'login' => $prefix]);
    verify($reentered && $outer->outcome->grants === $evaluation->outcome->grants, 'Balanced nested evaluation preserves exact outer outcome');
    unset($PLUGIN_HOOKS['rule_matched']['authentication_completion_fixture']);
    $failure = new RuntimeException('Actual authentication rule hook refusal');
    $PLUGIN_HOOKS['rule_matched']['authentication_completion_fixture'] = static function () use ($failure): never { throw $failure; };
    try {
        $collection->evaluateAuthentication([$group], $context, ['type' => Auth::DB_GLPI, 'login' => $prefix]);
        throw new LogicException('Throwing real rule hook accepted');
    } catch (RuntimeException $error) {
        verify($error === $failure, 'Actual rule hook retains primary exception identity');
    }
    unset($PLUGIN_HOOKS['rule_matched']['authentication_completion_fixture']);
    $after = $collection->evaluateAuthentication([], $context, ['type' => Auth::DB_GLPI, 'login' => $prefix . ' absent']);
    verify($after->outcome->assignments === [] && $after->outcome->grants === [], 'Exceptional evaluation releases actual mutation context');
    $legacy = $collection->processAllRules([$group], $context, ['type' => Auth::DB_GLPI, 'login' => $prefix]);
    verify($legacy['timezone'] === 'UTC' && isset($legacy['_ldap_rules']), 'Ordinary legacy rule evaluation still uses its original output contract');

    // Genuine same-model public reentrancy owns a separate lifecycle/context.
    $model = new User();
    verify($model->getFromDB($user), 'Reentrant completion uses the actual stored User');
    $reentered = false;
    $PLUGIN_HOOKS['pre_item_update']['authentication_completion_fixture'][User::class] = static function ($item) use ($user, $evaluation, &$reentered): void {
        if ($item instanceof User && (int)$item->getID() === $user && !$reentered) {
            $reentered = true;
            verify($item->update(['id' => $user, 'highcontrast_css' => false]), 'Nested ordinary preference edit has no inherited completion requirement');
            verify($item->completeAuthentication(new \itsmng\Domain\Authentication\AuthenticationCompletion($user, $_SESSION['glpi_currenttime'], $evaluation->outcome)), 'Nested typed completion owns and restores its own outcome');
        }
    };
    verify($model->completeAuthentication(new \itsmng\Domain\Authentication\AuthenticationCompletion($user, $_SESSION['glpi_currenttime'], $evaluation->outcome)), 'Outer completion resumes after genuine nested public lifecycles');
    verify($reentered && $read()['highcontrast_css'] === null, 'Nested deliberate preference edit persists its legitimate inheritance effect');
    unset($PLUGIN_HOOKS['pre_item_update']['authentication_completion_fixture'][User::class]);
    $writer()->update('glpi_users', $user, ['highcontrast_css' => false]);

    // A late callback already has outer pending fields; nested reloads must not replace them.
    $lateName = $prefix . ' late outcome';
    $fixtures->create('glpi_ruleactions', ['rules_id' => $rule, 'action_type' => 'assign', 'field' => 'realname', 'value' => $lateName]);
    $rules->load = 0;
    $lateOutcome = $collection->evaluateAuthentication([$group], $read(), ['type' => Auth::DB_GLPI, 'login' => $prefix]);
    verify($lateOutcome->outcome->assignments['realname'] === $lateName, 'Late control uses an actual executed non-boolean rule action');
    $lateNested = new class extends User {
        private bool $reentered = false;
        public static function getTable($classname = null) { return User::getTable(); }
        public function pre_updateInDB() {
            parent::pre_updateInDB();
            if (!$this->reentered) {
                $this->reentered = true;
                $pendingName = $this->fields['realname'];
                verify($this->completeAuthentication(new \itsmng\Domain\Authentication\AuthenticationCompletion((int)$this->getID(), $_SESSION['glpi_currenttime'])), 'Genuine late callback completes a nested login');
                verify($this->fields['realname'] === $pendingName && in_array('realname', $this->updates, true), 'Whole nested completion retains the outer prepared non-boolean write');
            }
        }
    };
    verify($lateNested->getFromDB($user), 'Late nested lifecycle uses the stored account');
    verify($lateNested->completeAuthentication(new \itsmng\Domain\Authentication\AuthenticationCompletion($user, $_SESSION['glpi_currenttime'], $lateOutcome->outcome)), 'Outer completion survives a late same-model nested completion');
    verify($read()['realname'] === $lateName && $preferences() === $baseline, 'Exact outer rule outcome persists after nested initial and final reloads');

    // A real deactivation action is mandatory admission intent, not optional input.
    $writer()->update('glpi_ruleactions', $actionIds['is_active'], ['value' => '0']);
    $rules->load = 0;
    $_SESSION = $savedSession;
    $_REQUEST = $_COOKIE = [];
    verify((new Auth())->login($prefix, $password, true) === false, 'Actual rule deactivation refuses Session admission');
    verify($read()['is_active'] === 0 && Session::getLoginUserID() !== $user, 'Real deactivation is persisted and no successful user session is published');
    verify($preferences() === $baseline && $records()->find('glpi_profiles_users', 'id', $manual) !== null, 'Deactivation retains unrelated preference and manual grant ownership');
    $writer()->update('glpi_users', $user, ['is_active' => true]);

    foreach (['drop', 'replace'] as $mutation) {
        $_SESSION = $savedSession;
        $PLUGIN_HOOKS['pre_item_update']['authentication_completion_fixture'][User::class] = static function ($item) use ($user, $mutation): bool {
            if ($item instanceof User && (int)$item->getID() === $user) {
                if ($mutation === 'drop') { unset($item->input['is_active']); }
                else { $item->input['is_active'] = true; }
            }
            return true;
        };
        $before = $read();
        $beforeGrants = $records()->matching('glpi_profiles_users', ['users_id' => $user], order: ['id']);
        verify((new Auth())->login($prefix, $password, true) === false, 'True-return hook cannot ' . $mutation . ' required rule deactivation');
        verify($read() === $before && $records()->matching('glpi_profiles_users', ['users_id' => $user], order: ['id']) === $beforeGrants, 'Rejected admission hook performs no account or grant update');
    }
    unset($PLUGIN_HOOKS['pre_item_update']['authentication_completion_fixture'][User::class]);
    $_SESSION = $savedSession;
    $deactivation = $collection->evaluateAuthentication([$group], $read(), ['type' => Auth::DB_GLPI, 'login' => $prefix]);
    $late = new class extends User {
        public static function getTable($classname = null) { return User::getTable(); }
        public function pre_updateInDB() {
            parent::pre_updateInDB();
            $this->updates = array_values(array_diff($this->updates, ['is_active']));
        }
    };
    verify($late->getFromDB($user), 'Actual model callback has the persisted local account');
    $before = $read();
    verify($late->completeAuthentication(new \itsmng\Domain\Authentication\AuthenticationCompletion($user, $_SESSION['glpi_currenttime'], $deactivation->outcome)) === false, 'Late public model callback cannot cancel mandatory rule deactivation');
    verify($read() === $before, 'Final write guard refuses before any account write');
    verify($late->update(['id' => $user, 'highcontrast_css' => false]), 'Refused completion releases typed context for normal preference edits');
    verify($read()['highcontrast_css'] === null, 'Normal preference inheritance remains valid after completion refusal');
    $writer()->update('glpi_users', $user, ['highcontrast_css' => false]);
    $writer()->update('glpi_ruleactions', $actionIds['is_active'], ['value' => '1']);
    $rules->load = 0;

    // Public login honors a cancelled lifecycle and emits no invented completion.
    $PLUGIN_HOOKS['pre_item_update']['authentication_completion_fixture'][User::class] = static function ($item) use ($user): void {
        if ($item instanceof User && (int)$item->getID() === $user) {
            $item->input = false;
        }
    };
    $before = $read();
    verify((new Auth())->login($prefix, $password, true) === false, 'Real cancelled account completion refuses login');
    verify($read() === $before, 'Cancelled public completion leaves actual stored account unchanged');
    verify($records()->find('glpi_profiles_users', 'id', $manual) !== null, 'Cancellation preserves legitimate manual grant');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    // Report each independent cleanup failure without replacing actual primary.
    $cleanup = static function (callable $operation) use (&$primary): void {
        try {
            $operation();
        } catch (Throwable $error) {
            if ($primary === null) {
                $primary = $error;
            } else {
                try { error_log('Authentication fixture cleanup: ' . $error->getMessage()); } catch (Throwable) { }
            }
        }
    };
    $cleanup(static function () use ($connection, $nesting): void {
        while ($connection->getTransactionNestingLevel() > $nesting) { $connection->rollBack(); }
    });
    $cleanup(static function () use ($savedSessionId, $savedSessionStatus): void {
        if (session_id() !== $savedSessionId || session_status() !== $savedSessionStatus) {
            if (session_status() === PHP_SESSION_ACTIVE) { session_abort(); }
            session_id($savedSessionId);
            if ($savedSessionStatus === PHP_SESSION_ACTIVE) { Session::start(); }
        }
    });
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $_REQUEST = $savedRequest;
    $_COOKIE = $savedCookies;
    $_GET = $savedGet;
    $_POST = $savedPost;
    $TRANSLATE = $savedTranslation;
    $rules->list = $savedRuleList;
    $rules->load = $savedRuleLoad;
    $cleanup(static fn () => $plugins->setValue(null, $savedPlugins));
    if ($savedLocale !== null) { $cleanup(static fn () => Locale::setDefault($savedLocale)); }
}
if ($primary !== null) { throw $primary; }
echo $DB->getProvider() . ": Actual authentication completion, preference ownership, rule intentions and lifecycle controls passed.\n";
