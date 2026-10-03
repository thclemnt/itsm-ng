<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Private CLI companion. All browser/API calls use existing application routes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$directory = $argv[1] ?? '';
$action = $argv[2] ?? '';
if (!is_file($directory . '/config_db.php')) {
    throw new RuntimeException('Disposable application configuration required');
}
define('GLPI_ROOT', dirname(__DIR__, 3));
define('GLPI_CONFIG_DIR', realpath($directory));
$privateVar = getenv('GLPI_VAR_DIR');
if (!$privateVar || !is_dir($privateVar) || !($privateVar = realpath($privateVar))
    || $privateVar === GLPI_ROOT || str_starts_with($privateVar, GLPI_ROOT . '/')) {
    throw new RuntimeException('Existing private GLPI_VAR_DIR outside the server document root required');
}
define('GLPI_VAR_DIR', $privateVar);
define('PLUGINS_DIRECTORIES', [GLPI_ROOT . '/plugins', GLPI_ROOT . '/tests/fixtures/plugins']);
require GLPI_ROOT . '/inc/includes.php';
require dirname(__DIR__) . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error) use ($action): void {
    // Credentials are accepted through stdin, never printed in diagnostic SQL.
    fwrite(STDERR, get_class($error) . ' in Session HTTP fixture ' . $action . "\n");
    exit(1);
});
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable test database required');
}
$_SESSION['glpiextauth'] = 0;
if (!(new Auth())->login('itsm', 'itsm', true)) {
    throw new RuntimeException('Fixture administrator login failed');
}
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$records = new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$path = GLPI_VAR_DIR . '/_tmp/session-http-fixture.json';
if (is_link($path)) {
    throw new RuntimeException('Refuse a linked fixture manifest');
}
if ($action === 'seed') {
    if (is_file($path) || $records->countMatching('glpi_plugins', ['directory' => 'sessionhttpfixture'])) {
        throw new RuntimeException('Refuse existing fixture state; use a private test installation');
    }
    $guard = bin2hex(random_bytes(20));
    $state = ['guard' => $guard, 'database' => $DB->dbdefault, 'prefix' => 'SessionHTTP-' . $guard . '-',
        'owned' => [], 'mode' => ['provision' => false, 'closeBeforeToken' => false, 'vetoCookie' => false],
        'observed' => ['provisioned' => 0, 'closedBeforeToken' => 0, 'cookieVeto' => 0],
        'savedConfig' => Config::getConfigurationValues('core', ['login_remember_time', 'login_remember_default'])];
} else {
    if (!is_string($input['guard'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $input['guard']) || !is_file($path)) {
        throw new RuntimeException('Private fixture capability required');
    }
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!hash_equals($state['guard'], $input['guard']) || $state['database'] !== $DB->dbdefault
        || $state['prefix'] !== 'SessionHTTP-' . $state['guard'] . '-') {
        throw new RuntimeException('Refuse fixture/database ownership mismatch');
    }
}
$save = static function () use ($path, &$state): void {
    if (file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('Cannot save private fixture state');
    }
};
if ($action === 'seed') {
    $save();
    $connection->transactional(static function () use ($DB, &$state): void {
        $fixtures = new FixtureRecords($DB);
        $create = static function (string $type, array $values) use ($fixtures, &$state): int {
            $id = $fixtures->create($type::getTable(), $values);
            $state['owned'][$type][] = $id;
            return $id;
        };
        $tree = static function (array $values) use (&$state): int {
            // Public Entity hooks own ancestry/descendant cache invalidation.
            // A direct ORM seed would leave the administrator's warmed root
            // tree stale and would not represent a real entity lifecycle.
            $model = new Entity();
            $id = $model->add($values);
            if (!$id) {
                throw new RuntimeException('Public fixture Entity creation failed');
            }
            $state['owned'][Entity::class][] = $id;
            return $id;
        };
        $prefix = $state['prefix'];
        $state['parent'] = $tree(['name' => $prefix . 'parent', 'entities_id' => 0]);
        $state['child'] = $tree(['name' => $prefix . 'child', 'entities_id' => $state['parent']]);
        $state['grandchild'] = $tree(['name' => $prefix . 'grandchild', 'entities_id' => $state['child']]);
        $state['foreign'] = $tree(['name' => $prefix . 'foreign', 'entities_id' => 0]);
        if (!Session::changeActiveEntities(0, true) || !Session::haveAccessToEntity($state['grandchild'])) {
            throw new RuntimeException('Real fixture root grant must cover the new public tree');
        }
        $state['profile'] = $create(Profile::class, ['name' => $prefix . 'profile', 'interface' => 'central']);
        foreach (['planning' => Planning::READMY | Planning::READGROUP, 'ticket' => Ticket::READALL,
            'task' => CommonITILTask::SEEPUBLIC, 'computer' => READ | UPDATE, 'location' => READ] as $name => $rights) {
            $create(ProfileRight::class, ['profiles_id' => $state['profile'], 'name' => $name, 'rights' => $rights]);
        }
        $state['groups'] = [];
        foreach (['parent' => [$state['parent'], true], 'child' => [$state['child'], false], 'foreign' => [$state['foreign'], false]] as $kind => [$entity, $recursive]) {
            $state['groups'][$kind] = $create(Group::class, ['name' => $prefix . 'group-' . $kind, 'entities_id' => $entity, 'is_recursive' => $recursive]);
        }
        $state['users'] = $state['names'] = $state['personalTokens'] = [];
        foreach (['direct', 'recursive', 'inactive', 'deleted', 'future', 'expired', 'no_grants', 'provisioned', 'remember'] as $kind) {
            $state['personalTokens'][$kind] = bin2hex(random_bytes(24));
            $state['names'][$kind] = $prefix . $kind;
            $entity = $kind === 'remember' ? 0 : $state['parent'];
            $state['users'][$kind] = $create(User::class, [
                'name' => $state['names'][$kind], 'password' => Auth::getPasswordHash('SessionHTTP1!'),
                'authtype' => Auth::DB_GLPI, 'profiles_id' => $state['profile'], 'entities_id' => $entity,
                'personal_token' => $state['personalTokens'][$kind], 'cookie_token' => null, 'cookie_token_date' => null,
                'is_active' => $kind !== 'inactive', 'is_deleted' => $kind === 'deleted',
                'begin_date' => $kind === 'future' ? new DateTimeImmutable('+10 years') : null,
                'end_date' => $kind === 'expired' ? new DateTimeImmutable('-10 years') : null,
                'language' => 'en_GB', 'use_mode' => Session::NORMAL_MODE,
                'password_last_update' => new DateTimeImmutable(),
            ]);
            if (!in_array($kind, ['no_grants', 'provisioned'], true)) {
                $create(Profile_User::class, ['users_id' => $state['users'][$kind], 'profiles_id' => $state['profile'],
                    'entities_id' => $entity, 'is_recursive' => $kind === 'recursive', 'is_default_profile' => true]);
            }
            foreach ($state['groups'] as $group) {
                $create(Group_User::class, ['users_id' => $state['users'][$kind], 'groups_id' => $group]);
            }
        }
        $state['computerName'] = $prefix . 'computer';
        $state['location'] = $create(Location::class, ['name' => $prefix . 'location', 'completename' => $prefix . 'location', 'entities_id' => $state['parent']]);
        $state['computer'] = $create(Computer::class, ['name' => $state['computerName'], 'entities_id' => $state['parent'], 'locations_id' => $state['location']]);
        $state['tasks'] = [];
        $event = static function (string $key, int $entity, ?int $user, ?int $group) use ($create, &$state, $prefix): void {
            $ticket = $create(Ticket::class, ['name' => $prefix . $key, 'entities_id' => $entity, 'status' => CommonITILObject::INCOMING]);
            $state['tasks'][$key] = $create(TicketTask::class, ['tickets_id' => $ticket, 'content' => $prefix . $key,
                'users_id_tech' => $user, 'groups_id_tech' => $group, 'is_private' => false,
                'begin' => new DateTimeImmutable('+1 day 10:00'), 'end' => new DateTimeImmutable('+1 day 11:00'), 'state' => Planning::TODO]);
        };
        foreach (['direct', 'recursive'] as $kind) {
            foreach (['parent', 'child', 'grandchild', 'foreign'] as $entity) {
                $event($kind . '-' . $entity, $state[$entity], $state['users'][$kind], null);
            }
        }
        foreach (['parent', 'child', 'foreign'] as $kind) {
            $event('group-' . $kind, $state[$kind], null, $state['groups'][$kind]);
        }
        $event('provisioned-parent', $state['parent'], $state['users']['provisioned'], null);
        $event('remember-root', 0, $state['users']['remember'], null);
        $state['plugin'] = $fixtures->create('glpi_plugins', ['directory' => 'sessionhttpfixture', 'name' => 'Session HTTP fixture',
            'version' => '1.0.0', 'state' => Plugin::ACTIVATED]);
        \itsmng\Database\SequenceSynchronizer::synchronize($DB->getDoctrineConnection());
    });
    Config::setConfigurationValues('core', ['login_remember_time' => DAY_TIMESTAMP, 'login_remember_default' => false]);
    $save();
    $result = array_intersect_key($state, array_flip(['guard', 'prefix', 'users', 'names', 'personalTokens', 'parent', 'child', 'grandchild', 'foreign', 'profile', 'groups', 'computer', 'computerName', 'location', 'tasks', 'owned']));
    $result['cookieName'] = session_name();
    $result['rememberName'] = session_name() . '_rememberme';
} elseif ($action === 'mode') {
    foreach ($state['mode'] as $key => $value) {
        if (array_key_exists($key, $input)) {
            if (!is_bool($input[$key])) {
                throw new RuntimeException('Fixture mode must be a Boolean');
            }
            $state['mode'][$key] = $input[$key];
        }
    }
    $save();
    $result = ['configured' => true];
} elseif ($action === 'observe') {
    $result = $state['observed'];
} elseif ($action === 'cookie-snapshot') {
    $row = $records->find('glpi_users', 'id', $state['users']['remember']);
    if (!$row || $row['name'] !== $state['names']['remember']) {
        throw new RuntimeException('Owned remembered account required for credential snapshot');
    }
    // Keep credential bytes solely in the existing 0600 private manifest.
    // Browser assertions receive comparison booleans, never hash/plaintext.
    $state['cookieSnapshot'] = [
        'hash' => $row['cookie_token'], 'date' => $row['cookie_token_date'],
        'history' => $records->countMatching('glpi_logs', ['itemtype' => User::class, 'items_id' => $row['id']]),
    ];
    $save();
    $result = ['captured' => true];
} elseif ($action === 'cookie-check') {
    $value = $input['cookie'] ?? '';
    // Browser storage holds setcookie()'s URL-encoded wire value; PHP decodes
    // incoming $_COOKIE before the real authentication route sees its JSON.
    $cookie = is_string($value) ? json_decode(rawurldecode($value), true) : null;
    $row = $records->find('glpi_users', 'id', $state['users']['remember']);
    $result = ['matches' => is_array($cookie) && count($cookie) === 2 && (int)$cookie[0] === $row['id']
        && is_string($cookie[1]) && is_string($row['cookie_token']) && Auth::checkPassword($cookie[1], $row['cookie_token']),
        'dated' => $row['cookie_token_date'] !== null,
        'dateMatches' => array_key_exists('expectedDate', $input) && $row['cookie_token_date'] === $input['expectedDate'],
        'unchanged' => isset($state['cookieSnapshot']) && $row['cookie_token'] === $state['cookieSnapshot']['hash']
            && $row['cookie_token_date'] === $state['cookieSnapshot']['date'],
        'historyUnchanged' => isset($state['cookieSnapshot']) && $records->countMatching('glpi_logs', [
            'itemtype' => User::class, 'items_id' => $row['id'],
        ]) === $state['cookieSnapshot']['history']];
} elseif ($action === 'clean') {
    // Domain records must already have been purged through the real admin API.
    foreach ($state['owned'] as $type => $ids) {
        foreach ($ids as $id) {
            if ($records->find($type::getTable(), 'id', $id) !== null) {
                throw new RuntimeException('Refuse final cleanup before authorized API purge completes');
            }
        }
    }
    // The provisioning hook creates one additional owned grant, purged with User.
    Config::setConfigurationValues('core', $state['savedConfig']);
    $plugin = new Plugin();
    if (!$plugin->getFromDB($state['plugin']) || $plugin->fields['directory'] !== 'sessionhttpfixture'
        || !$plugin->delete(['id' => $state['plugin']], true)) {
        throw new RuntimeException('Owned fixture plugin cleanup failed');
    }
    unlink($path);
    $result = ['cleaned' => true];
} else {
    throw new RuntimeException('Unknown fixture action');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
