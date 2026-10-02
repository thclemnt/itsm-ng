<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\OidcReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/oidc.php /path/to/test-config\n");
    exit(2);
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$repo = static fn () => new \itsmng\Database\Repository\OidcRepository(Orm::create($DB));
$records = static fn () => new RecordRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    foreach (['glpi_oidc_config', 'glpi_oidc_mapping'] as $table) {
        (new \itsmng\Database\Repository\RecordWriter(Orm::create($DB)))->delete($table, 0);
    }
    verify($repo()->configuration()['is_activate'] === 0 && $repo()->mapping() === [], 'Missing configuration has safe form defaults');
    $repo()->saveMapping(['name' => 'username', 'given_name' => 'first', 'family_name' => 'last', 'group' => 'groups', 'email' => 'mail', 'locale' => 'locale', 'phone_number' => 'phone']);
    $repo()->saveConfiguration(['Provider' => 'https://identity.example.test', 'ClientID' => "quoted'client", 'is_activate' => 1, 'is_forced' => 0, 'sso_link_users' => 0]);
    verify($repo()->configuration()['ClientID'] === "quoted'client" && $repo()->configuration()['sso_link_users'] === 0, 'Singleton config preserves strings and boolean boundary');
    verify($repo()->mapping()['name'] === 'username', 'Mapped singleton mapping saved');
    $repo()->saveConfiguration(['ClientSecret' => Toolbox::sodiumEncrypt('fixture-secret')]);
    ob_start();
    Auth::showAuthOIDCConfig();
    $configurationHtml = ob_get_clean();
    verify(str_contains($configurationHtml, 'identity.example.test'), 'Configuration form renders mapped values');
    $user = $fixtures->create('glpi_users', ['name' => 'oidc-original', 'authtype' => Auth::EXTERNAL]);
    $other = $fixtures->create('glpi_users', ['name' => 'oidc-other', 'authtype' => Auth::DB_GLPI]);
    verify($repo()->linkableUser('oidc-original', false) === $user, 'Existing external user eligible');
    verify($repo()->linkableUser('oidc-other', false) === null && $repo()->linkableUser('oidc-other', true) === $other, 'Local linking retains explicit setting');
    verify($repo()->linkableUser('missing-oidc-user', true) === null, 'Unknown login is absent');
    $group = $fixtures->create('glpi_groups', ['name' => 'Existing OIDC group']);
    $member = $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $group, 'is_manager' => true]);
    $claims = ['username' => "O'Connor", 'first' => 'Ada', 'last' => "O'Connor", 'mail' => ' ada@example.test ', 'locale' => 'en_GB', 'phone' => '+33 123', 'groups' => ['Existing OIDC group', "Team's group", "Team's group"]];
    if ($DB->getProvider() === 'mysql') {
        $claims['groups'][] = "TEAM'S GROUP";
    }
    $at = new DateTimeImmutable('2030-01-02 12:30:00');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    verify($repo()->synchronizeProfile($user, $claims, $at) === 'ada@example.test', 'Email handed to lifecycle without surrounding whitespace');
    // Put another user's row last to reproduce the legacy update-the-last-row bug.
    $otherState = $fixtures->create('glpi_oidc_users', ['user_id' => $other, 'update' => false]);
    verify($repo()->synchronizeProfile($user, $claims, $at) === 'ada@example.test', 'Repeated sync succeeds');
    verify($SQL_TOTAL_REQUEST === 0, 'Profile sync uses ORM without adapter SQL');
    $row = $read('glpi_users', $user);
    verify($row['name'] === "O'Connor" && $row['firstname'] === 'Ada' && $row['realname'] === "O'Connor" && rtrim($row['language']) === 'en_GB' && $row['date_mod'] === '2030-01-02 12:30:00', 'Typed claims saved without SQL escaping or changing unrelated fields');
    verify(count($records()->matching('glpi_oidc_users', ['user_id' => $user])) === 1, 'One state per user');
    verify($read('glpi_oidc_users', $otherState)['user_id'] === $other && $read('glpi_oidc_users', $otherState)['update'] === 0, 'Another user state cannot be overwritten');
    $newGroups = $records()->matching('glpi_groups', ['name' => "Team's group"]);
    verify(count($newGroups) === 1 && count($records()->matching('glpi_groups_users', ['users_id' => $user])) === 2, 'Group names and repeated memberships handled without INSERT IGNORE');
    verify($read('glpi_groups_users', $member)['is_manager'] === 1, 'Existing membership flags retained');
    verify(!$repo()->needsRefresh($user) && $repo()->needsRefresh($other) && !$repo()->needsRefresh(0), 'Refresh lookup is scoped and boolean');
    $repo()->requestRefresh();
    verify($repo()->needsRefresh($user), 'Refresh command resets state');
    // Calling the local persistence entry point never authenticates with a provider.
    Oidc::addUserData($claims, $user);
    Oidc::addUserData($claims, $user);
    $emails = $records()->matching('glpi_useremails', ['users_id' => $user]);
    verify(count($emails) === 1 && $emails[0]['email'] === 'ada@example.test' && $emails[0]['is_default'] === 1 && !$repo()->needsRefresh($user), 'Email lifecycle creates one default address and state is refreshed');
    $before = $read('glpi_users', $user);
    $invalid = false;
    try {
        $repo()->synchronizeProfile($user, ['first' => 'Must roll back', 'groups' => ['valid', ['invalid']]], $at);
    } catch (InvalidArgumentException $error) {
        $invalid = true;
    }
    verify($invalid && $read('glpi_users', $user) === $before, 'Malformed group claims roll back profile writes');
    $invalid = false;
    try {
        $repo()->synchronizeProfile(2147483647, [], $at);
    } catch (InvalidArgumentException $error) {
        $invalid = true;
    }
    verify($invalid, 'Unknown user cannot be implicitly created by profile sync');
    ob_start();
    Oidc::showFormUserConfig();
    $mappingHtml = ob_get_clean();
    verify(str_contains($mappingHtml, 'username'), 'Mapping form renders persisted mapping');
    verify((new User())->delete(['id' => $user], true), 'User lifecycle purge removes OIDC state before FK');
    verify($records()->matching('glpi_oidc_users', ['user_id' => $user]) === [] && $read('glpi_oidc_users', $otherState) !== null, 'Purge removes only target state');
    verify((new ForeignKeys())->audit($connection) === [], 'OIDC graph valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$postgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
$migration = new OidcReferences();
$user = null;
$legacyIds = [];
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_oidc_users', 'user_id'), 'glpi_oidc_users'));
    $connection->executeStatement($platform->getDropIndexSQL('oidc_users_user', 'glpi_oidc_users'));
    if ($postgres) {
        foreach (OidcReferences::FLAGS as $table => $flags) {
            foreach ($flags as $column => $default) {
                $field = $quote($column);
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' DROP DEFAULT');
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' TYPE SMALLINT USING (' . $field . '::int)');
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' SET DEFAULT ' . (int)$default);
            }
        }
    }
    $user = $fixtures->create('glpi_users', ['name' => 'oidc-migration-fixture']);
    $legacyIds = [(int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_oidc_users')];
    $connection->insert('glpi_oidc_users', ['id' => $legacyIds[0], 'user_id' => $user, $quote('update') => 0]);
    verify($migration->plan($connection) !== [], 'Legacy OIDC schema has migration plan');
    $connection->insert('glpi_oidc_mapping', ['id' => 99999]);
    try {
        $migration->apply($connection);
        throw new LogicException('Non-singleton configuration accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Non-singleton OIDC'), 'Unexpected singleton IDs rejected before DDL');
    } finally {
        $connection->delete('glpi_oidc_mapping', ['id' => 99999]);
    }
    $legacyIds[] = $legacyIds[0] + 1;
    $connection->insert('glpi_oidc_users', ['id' => $legacyIds[1], 'user_id' => $user, $quote('update') => 1]);
    try {
        $migration->apply($connection);
        throw new LogicException('Duplicate OIDC states accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Duplicate OIDC'), 'Duplicate state rejected before DDL');
    }
    $connection->delete('glpi_oidc_users', ['id' => $legacyIds[1]]);
    $connection->update('glpi_oidc_users', ['user_id' => 2147483647], ['id' => $legacyIds[0]]);
    try {
        $migration->apply($connection);
        throw new LogicException('Orphan OIDC state accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Orphaned OIDC'), 'Orphan rejected before DDL');
    }
    $connection->update('glpi_oidc_users', ['user_id' => $user, $quote('update') => 2], ['id' => $legacyIds[0]]);
    try {
        $migration->apply($connection);
        throw new LogicException('Invalid OIDC boolean accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Invalid OIDC boolean'), 'Invalid boolean rejected before DDL');
    }
    $connection->update('glpi_oidc_users', [$quote('update') => 0], ['id' => $legacyIds[0]]);
    $connection->beginTransaction();
    try {
        if ($postgres) {
            $migration->apply($connection);
            verify($migration->plan($connection) === [], 'Transactional PostgreSQL migration applied');
        } else {
            try {
                $migration->apply($connection);
                throw new LogicException('MySQL DDL accepted in transaction');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'outside an application transaction'), 'MySQL DDL in transaction refused');
            }
        }
    } finally {
        $connection->rollBack();
    }
    verify($migration->plan($connection) !== [], 'Unapplied or rolled-back schema remains retryable');
    $migration->apply($connection);
    verify($migration->apply($connection) === [], 'OIDC migration idempotent');
    verify($repo()->needsRefresh($user), 'Zero refresh flag remains false after migration');
    if ($postgres) {
        foreach (OidcReferences::FLAGS as $table => $flags) {
            foreach ($flags as $column => $default) {
                verify($connection->createSchemaManager()->listTableColumns($table)[$column]->getType() instanceof \Doctrine\DBAL\Types\BooleanType, 'Native PostgreSQL boolean installed');
            }
        }
    }
} finally {
    foreach ($legacyIds as $id) {
        $connection->delete('glpi_oidc_users', ['id' => $id]);
    }
    if ($user !== null) {
        $connection->delete('glpi_users', ['id' => $user]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": OIDC scoped persistence, profile and group sync, user purge and audited migration passed.\n";
