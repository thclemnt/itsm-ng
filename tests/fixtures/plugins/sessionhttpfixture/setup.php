<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DeletionUnit;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

function plugin_version_sessionhttpfixture(): array
{
    return [
        'name' => 'Session HTTP fixture',
        'version' => '1.0.0',
        'author' => 'ITSM-NG test suite',
        'license' => 'GPL v2+',
        'requirements' => ['glpi' => ['min' => '9.5.13']],
    ];
}

/** The private manifest is the only authority for fixture hook effects. */
function plugin_sessionhttpfixture_manifest(callable $operation): void
{
    global $DB;

    if (PHP_SAPI !== 'cli-server'
        || !in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost'], true)
        || !isset($DB) || !str_starts_with($DB->dbdefault, 'itsm_port_')) {
        return;
    }
    $path = GLPI_VAR_DIR . '/_tmp/session-http-fixture.json';
    if (!is_file($path) || is_link($path)) {
        return;
    }
    $stream = fopen($path, 'r+b');
    if ($stream === false) {
        throw new RuntimeException('Cannot open private Session HTTP fixture manifest');
    }
    try {
        if (!flock($stream, LOCK_EX)) {
            throw new RuntimeException('Cannot lock private Session HTTP fixture manifest');
        }
        $state = json_decode(stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['database'] ?? null) !== $DB->dbdefault
            || !is_string($state['guard'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $state['guard'])
            || ($state['prefix'] ?? null) !== 'SessionHTTP-' . $state['guard'] . '-') {
            return;
        }
        foreach (['direct', 'recursive', 'inactive', 'deleted', 'no_grants', 'provisioned', 'remember'] as $role) {
            if (!is_int($state['users'][$role] ?? null) || $state['users'][$role] <= 0
                || !is_string($state['personalTokens'][$role] ?? null) || $state['personalTokens'][$role] === '') {
                return;
            }
        }
        foreach (['profile', 'parent', 'child'] as $identity) {
            if (!is_int($state[$identity] ?? null) || $state[$identity] <= 0) {
                return;
            }
        }
        foreach (['provision', 'closeBeforeToken', 'vetoCookie', 'extendDeletionScope'] as $mode) {
            if (!is_bool($state['mode'][$mode] ?? null)) {
                return;
            }
        }
        foreach (['provisioned', 'closedBeforeToken', 'cookieVeto', 'deletionGrants'] as $observation) {
            if (!is_int($state['observed'][$observation] ?? null) || $state['observed'][$observation] < 0) {
                return;
            }
        }
        $records = new RecordRepository(Orm::create($DB));
        $owned = static fn (string $table, int $id): bool => $records->countMatching(
            $table,
            ['id' => $id, 'name' => ['LIKE', $state['prefix'] . '%']],
            false
        ) === 1;
        if (!$operation($state, $owned)) {
            return;
        }
        $contents = json_encode($state, JSON_THROW_ON_ERROR);
        if (!chmod($path, 0600) || !rewind($stream) || !ftruncate($stream, 0)
            || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
            throw new RuntimeException('Cannot save private Session HTTP fixture manifest');
        }
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function plugin_init_sessionhttpfixture(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['sessionhttpfixture'] = true;
    $PLUGIN_HOOKS['pre_item_delete']['sessionhttpfixture'][User::class] = static function (User $item): void {
        plugin_sessionhttpfixture_manifest(static function (array &$state, callable $owned) use ($item): bool {
            global $DB, $CFG_GLPI;

            $target = $state['users']['delete_target'] ?? 0;
            if (!$state['mode']['extendDeletionScope'] || $state['observed']['deletionGrants'] !== 0
                || $item->getID() !== $target || Session::getLoginUserID() !== ($state['users']['delete_actor'] ?? 0)
                || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE'
                || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== rtrim($CFG_GLPI['root_doc'], '/') . '/apirest.php/User/' . $target
                || !$owned('glpi_users', $target) || !$owned('glpi_profiles', $state['deletion']['profile'])
                || !$owned('glpi_entities', $state['foreign'])) {
                return false;
            }
            if (Session::canViewAllEntities() || array_values(array_map('intval', $_SESSION['glpiactiveentities'])) !== [$state['parent']]
                || !Session::haveRight('user', DELETE) || !DeletionUnit::isActive($DB->getDoctrineConnection())) {
                throw new RuntimeException('Deletion fixture requires the real restricted actor and active writer frame');
            }
            $manager = Orm::create($DB);
            $records = new RecordRepository($manager);
            $grant = ['users_id' => $target, 'profiles_id' => $state['deletion']['profile']];
            if ($records->countMatching('glpi_profiles_users', ['users_id' => $target], false) !== 1
                || $records->countMatching('glpi_profiles_users', $grant + ['entities_id' => $state['parent']], false) !== 1) {
                throw new RuntimeException('Deletion fixture target must initially have only its owned parent grant');
            }
            // Real can(DELETE) already passed. An ordinary lifecycle hook now
            // changes grants in this writer frame without altering authorization.
            $id = (new RecordWriter($manager))->insert('glpi_profiles_users', $grant + [
                'entities_id' => $state['foreign'], 'is_recursive' => false, 'is_dynamic' => false, 'is_default_profile' => false,
            ]);
            $state['deletion']['foreignGrant'] = $id;
            $state['owned'][Profile_User::class][] = $id;
            ++$state['observed']['deletionGrants'];
            return true;
        });
    };
    $PLUGIN_HOOKS['init_session']['sessionhttpfixture'] = static function (): void {
        plugin_sessionhttpfixture_manifest(static function (array &$state, callable $owned): bool {
            global $DB;

            $user = $state['users']['provisioned'];
            if (!$state['mode']['provision'] || Session::getLoginUserID() !== $user
                || !$owned('glpi_users', $user) || !$owned('glpi_profiles', $state['profile'])
                || !$owned('glpi_entities', $state['parent'])) {
                return false;
            }
            $manager = Orm::create($DB);
            $grant = ['users_id' => $user, 'profiles_id' => $state['profile'], 'entities_id' => $state['parent']];
            if ((new RecordRepository($manager))->countMatching('glpi_profiles_users', $grant, false) !== 0) {
                return false;
            }
            (new RecordWriter($manager))->insert('glpi_profiles_users', $grant + [
                'is_recursive' => false, 'is_dynamic' => false, 'is_default_profile' => true,
            ]);
            ++$state['observed']['provisioned'];
            return true;
        });
    };
    $PLUGIN_HOOKS['pre_item_update']['sessionhttpfixture'][User::class] = static function (User $item): void {
        plugin_sessionhttpfixture_manifest(static function (array &$state, callable $owned) use ($item): bool {
            if (!$state['mode']['vetoCookie'] || $item->getID() !== $state['users']['remember']
                || !$owned('glpi_users', $state['users']['remember']) || !is_array($item->input)
                || !array_key_exists('cookie_token', $item->input)
                || $item->input['cookie_token'] === ($item->fields['cookie_token'] ?? null)) {
                return false;
            }
            $item->input = [];
            ++$state['observed']['cookieVeto'];
            return true;
        });
    };
    $PLUGIN_HOOKS['post_init']['sessionhttpfixture'] = static function (): void {
        plugin_sessionhttpfixture_manifest(static function (array &$state, callable $owned): bool {
            global $CFG_GLPI;

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            if (!$state['mode']['closeBeforeToken'] || !isset($_GET['genical'])
                || $path !== rtrim($CFG_GLPI['root_doc'], '/') . '/front/planning.php'
                || !is_string($_GET['token'] ?? null) || session_status() !== PHP_SESSION_ACTIVE) {
                return false;
            }
            foreach ($state['personalTokens'] as $role => $token) {
                if (hash_equals($token, $_GET['token']) && $owned('glpi_users', $state['users'][$role])) {
                    session_write_close();
                    ++$state['observed']['closedBeforeToken'];
                    return true;
                }
            }
            return false;
        });
    };
}
