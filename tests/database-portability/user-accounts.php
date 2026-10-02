<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\LdapRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\UserRepository;
use itsmng\Database\Repository\UserPasswordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/user-accounts.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$users = static fn () => new UserRepository(Orm::create($DB));
$passwords = static fn () => new UserPasswordRepository(Orm::create($DB));
$writer = static fn () => new RecordWriter(Orm::create($DB));
$DB->beginTransaction();
try {
    $now = new DateTimeImmutable($connection->fetchOne('SELECT CURRENT_TIMESTAMP'));
    $prefix = 'Account ' . bin2hex(random_bytes(5));
    $login = $prefix . " O'Reilly\\directory";
    $user = $fixtures->create('glpi_users', ['name' => $login, 'authtype' => Auth::DB_GLPI, 'password' => 'local hash']);
    $other = $fixtures->create('glpi_users', ['name' => $prefix . ' other', 'password' => 'other hash']);
    verify($users()->exists(['name' => $login]), 'Raw login is bound');
    verify($users()->exists(['name' => addslashes($login)], true), 'Legacy input is decoded once');
    verify(!$users()->exists(['name' => $login, 'id' => ['<>', $user]]), 'Rename excludes the current account');
    verify(!$users()->exists(['name' => $login, 'authtype' => Auth::LDAP]), 'Duplicate account lookup includes authentication type');
    $model = new User();
    verify($model->getFromDB($user), 'Load quoted login');
    $model->blankPassword();
    verify($read('glpi_users', $user)['password'] === '' && $read('glpi_users', $other)['password'] === 'other hash', 'Password maintenance binds the login without affecting other users');
    verify((new User())->add(['name' => addslashes($login), 'authtype' => Auth::DB_GLPI]) === false, 'Public add rejects duplicate account');
    $writer()->update('glpi_users', $user, ['is_deleted_ldap' => true, 'password' => 'local hash']);
    $source = $fixtures->create('glpi_authldaps');
    verify(User::changeAuthMethod([$user], Auth::EXTERNAL, $source), 'Public authentication change succeeds');
    $row = $read('glpi_users', $user);
    verify($row['authtype'] === Auth::EXTERNAL && $row['auths_id'] === $source && $row['password'] === '' && $row['is_deleted_ldap'] === 0, 'Authentication maintenance updates source, password and directory deletion flag');
    verify($read('glpi_users', $other)['password'] === 'other hash', 'Authentication maintenance stays scoped');
    verify(!User::changeAuthMethod([$user], 999, 17), 'Unsupported authentication change rejected');
    $duplicateLocal = $fixtures->create('glpi_users', ['name' => $prefix . ' source collision', 'authtype' => Auth::DB_GLPI]);
    $duplicateExternal = $fixtures->create('glpi_users', ['name' => $prefix . ' source collision', 'authtype' => Auth::EXTERNAL]);
    $DB->beginTransaction();
    verify(!User::changeAuthMethod([$duplicateLocal, $duplicateExternal], Auth::EXTERNAL, $source), 'Conflicting authentication sources return failure rather than logging successful changes');
    $DB->rollBack();
    verify($read('glpi_users', $duplicateLocal)['authtype'] === Auth::DB_GLPI && $read('glpi_users', $duplicateExternal)['auths_id'] === 0, 'Rejected authentication change leaves both accounts intact after rollback');
    $writer()->update('glpi_users', $user, ['personal_token' => 'occupied-token']);
    verify($users()->exists(['personal_token' => 'occupied-token']) && !$users()->exists(['personal_token' => 'free-token']), 'Token collision lookup');
    verify(strlen(User::getUniqueToken()) === 40, 'Generated token contract');

    $reset = $fixtures->create('glpi_users', ['name' => $prefix . ' reset', 'password_forget_token' => 'reset-token', 'password_forget_token_date' => $now->modify('-12 hours')]);
    verify(User::getUserByForgottenPasswordToken('reset-token')?->getID() === $reset, 'Public reset resolves one unexpired account');
    verify(User::getUserByForgottenPasswordToken("reset-token\0suffix") === null, 'NUL cannot truncate a bound reset token into a valid credential');
    verify(User::getUserByForgottenPasswordToken('') === null && $passwords()->forgottenTokenUser('absent-token') === null, 'Empty and absent tokens fail');
    $writer()->update('glpi_users', $other, ['password_forget_token' => 'reset-token', 'password_forget_token_date' => $now->modify('-1 hour')]);
    verify(User::getUserByForgottenPasswordToken('reset-token') === null, 'Ambiguous reset token fails');
    $writer()->update('glpi_users', $other, ['password_forget_token_date' => $now->modify('-2 days')]);
    verify($passwords()->forgottenTokenUser('reset-token') === $reset, 'Expired duplicate does not mask current token');
    $writer()->update('glpi_users', $reset, ['password_forget_token_date' => $now->modify('-2 days')]);
    verify(User::getUserByForgottenPasswordToken('reset-token') === null, 'Expired reset fails');
    $writer()->update('glpi_users', $reset, ['password_forget_token_date' => null]);
    verify($passwords()->forgottenTokenUser('reset-token') === null, 'Undated reset fails');

    $baseline = $passwords()->noticeCount(30);
    $expired = $fixtures->create('glpi_users', ['name' => $prefix . ' expired', 'authtype' => Auth::DB_GLPI, 'password_last_update' => $now->modify('-40 days'), 'cookie_token' => 'remember', 'cookie_token_date' => $now]);
    $oldNotice = $fixtures->create('glpi_users', ['name' => $prefix . ' old notice', 'authtype' => Auth::DB_GLPI, 'password_last_update' => $now->modify('-40 days')]);
    $recentNotice = $fixtures->create('glpi_users', ['name' => $prefix . ' recent notice', 'authtype' => Auth::DB_GLPI, 'password_last_update' => $now->modify('-40 days')]);
    $fresh = $fixtures->create('glpi_users', ['name' => $prefix . ' fresh', 'authtype' => Auth::DB_GLPI, 'password_last_update' => $now->modify('-10 days'), 'cookie_token' => 'keep']);
    $external = $fixtures->create('glpi_users', ['name' => $prefix . ' external', 'authtype' => Auth::LDAP, 'password_last_update' => $now->modify('-40 days'), 'cookie_token' => 'keep']);
    $deleted = $fixtures->create('glpi_users', ['name' => $prefix . ' deleted', 'authtype' => Auth::DB_GLPI, 'password_last_update' => $now->modify('-40 days'), 'is_deleted' => true]);
    $disabled = $fixtures->create('glpi_users', ['name' => $prefix . ' disabled', 'authtype' => Auth::DB_GLPI, 'password_last_update' => $now->modify('-40 days'), 'is_active' => false]);
    $undated = $fixtures->create('glpi_users', ['name' => $prefix . ' undated', 'authtype' => Auth::DB_GLPI]);
    $oldAlert = $fixtures->create('glpi_alerts', ['itemtype' => 'User', 'items_id' => $oldNotice, 'type' => Alert::NOTICE, 'date' => $now->modify('-2 days')]);
    $fixtures->create('glpi_alerts', ['itemtype' => 'User', 'items_id' => $recentNotice, 'type' => Alert::NOTICE, 'date' => $now->modify('-1 hour')]);
    $fixtures->create('glpi_contracts', ['id' => $expired]);
    $fixtures->create('glpi_alerts', ['itemtype' => 'Contract', 'items_id' => $expired, 'type' => Alert::NOTICE, 'date' => $now]);
    verify($passwords()->noticeCount(30) === $baseline + 2, 'Notice selection respects activity, auth type, null dates and the one-day alert delay');
    $notices = array_column($passwords()->notices(30, 0), 'alert_id', 'user_id');
    verify(array_key_exists($expired, $notices) && $notices[$expired] === null && $notices[$oldNotice] === $oldAlert && !array_key_exists($recentNotice, $notices), 'Notice rows preserve selected alert and ignore other item types');
    verify(count($passwords()->notices(30, 1)) === 1, 'Notice batches are limited in SQL');
    $DB->beginTransaction();
    $passwords()->disableExpired(30);
    verify($read('glpi_users', $expired)['is_active'] === 0 && $read('glpi_users', $expired)['cookie_token'] === null && $read('glpi_users', $expired)['cookie_token_date'] === null, 'Expiry atomically disables local users and clears persistent login');
    verify($read('glpi_users', $recentNotice)['is_active'] === 0, 'Recent notification does not postpone account expiry');
    foreach ([$fresh, $external, $deleted, $undated] as $id) {
        verify($read('glpi_users', $id)['is_active'] === 1, 'Expiry preserves ineligible account ' . $id);
    }
    $DB->rollBack();
    verify($read('glpi_users', $expired)['is_active'] === 1 && $read('glpi_users', $expired)['cookie_token'] === 'remember', 'Bulk expiry shares the caller savepoint');

    $directory = new LdapRepository(Orm::create($DB));
    $dn = "ou=O'Reilly\\team,dc=example";
    $group = $fixtures->create('glpi_groups', ['name' => $prefix . ' LDAP group', 'ldap_group_dn' => $dn, 'ldap_field' => 'memberOf', 'ldap_value' => 'ou=Team%,dc=example']);
    $wrongField = $fixtures->create('glpi_groups', ['name' => $prefix . ' other attribute', 'ldap_field' => 'other', 'ldap_value' => 'ou=Team%,dc=example']);
    verify(in_array('memberOf', $directory->membershipFields(), true), 'Directory membership fields are read from mapped groups');
    verify($directory->groupsForDns([$dn]) === [$group] && $directory->groupsForDns([]) === [], 'DN lookup binds quotes and backslashes');
    verify($directory->groupsForAttribute('memberOf', ['ou=Team A,dc=example']) === [$group], 'Stored directory wildcard is the pattern');
    verify($directory->groupsForAttribute('memberof', ['ou=Team A,dc=example']) === [$group], 'Group field lookup accepts the normalized attribute name used by LDAP synchronization');
    verify($directory->groupsForAttribute('memberOf', ['ou=Unmatched%,dc=example']) === [] && $directory->groupsForAttribute('memberOf', []) === [], 'LDAP input is a subject, not a SQL or wildcard expression');
    $fixtures->create('glpi_groups', ['name' => $prefix . ' literal directory group', 'ldap_field' => 'literalAttribute', 'ldap_value' => 'cn=Actual,dc=example']);
    verify($directory->groupsForAttribute('literalAttribute', ['cn=%,dc=example']) === [], 'A wildcard in a directory value cannot become the matching pattern');
    $membership = $fixtures->create('glpi_groups_users', ['users_id' => $user, 'groups_id' => $group, 'is_dynamic' => true]);
    verify(array_column($users()->memberships($user), 'id') === [$membership] && $users()->memberships($other) === [], 'Membership snapshots stay scoped');
    $email = $fixtures->create('glpi_useremails', ['users_id' => $user, 'email' => 'dynamic@example.invalid', 'is_dynamic' => true]);
    $row = $users()->emails($user)[0];
    verify($row['id'] === $email && (bool)$row['is_dynamic'] && $row['users_id'] === $user, 'Email synchronization keeps its dynamic ownership fields');
    $manual = $fixtures->create('glpi_useremails', ['users_id' => $user, 'email' => 'manual@example.invalid', 'is_dynamic' => false]);
    verify($model->getFromDB($user), 'Reload external account');
    $model->input = ['_emails' => ['DYNAMIC@example.invalid', 'new@example.invalid', 'NEW@example.invalid']];
    $model->syncDynamicEmails();
    verify(count($users()->emails($user)) === 3 && $read('glpi_useremails', $email) !== null, 'Public synchronization deduplicates directory case variants and keeps matching addresses');
    $model->input = ['_emails' => ['NEW@example.invalid']];
    $model->syncDynamicEmails();
    verify(count($users()->emails($user)) === 2 && $read('glpi_useremails', $email) === null && $read('glpi_useremails', $manual) !== null, 'Public synchronization removes stale dynamic addresses and preserves manual addresses');
    $entity = $fixtures->create('glpi_entities', ['name' => $prefix . ' grant entity', 'entities_id' => 0]);
    $profile = $fixtures->create('glpi_profiles', ['name' => $prefix . ' grant profile']);
    $grant = $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $entity]);
    $rootGrant = $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => 0]);
    $otherGrant = $fixtures->create('glpi_profiles_users', ['users_id' => $other, 'profiles_id' => $profile, 'entities_id' => $entity]);
    $users()->removeEntityGrants($user, $entity);
    verify($read('glpi_profiles_users', $grant) === null && $read('glpi_profiles_users', $rootGrant) !== null && $read('glpi_profiles_users', $otherGrant) !== null, 'Partial deletion removes only that account and entity grants');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $users()->exists(['personal_token' => 'occupied-token']);
    $users()->memberships($user);
    $users()->emails($user);
    $users()->defaultPasswordCandidates(['itsm']);
    $passwords()->noticeCount(30);
    $passwords()->notices(30, 1);
    $passwords()->forgottenTokenUser('reset-token');
    $directory->membershipFields();
    $directory->groupsForDns([$dn]);
    $directory->groupsForAttribute('memberOf', ['ou=Team A,dc=example']);
    verify($SQL_TOTAL_REQUEST === 0, 'Account and password-policy queries bypass the SQL adapter');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": Account maintenance, password expiry/reset and local directory membership queries passed.\n";
