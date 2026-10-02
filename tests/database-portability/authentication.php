<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\LdapRepository;
use itsmng\Database\Repository\MailAuthenticationRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\UserPasswordRepository;
use itsmng\Database\Repository\UserRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/authentication.php /path/to/test-config\n");
    exit(1);
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
verify((new Auth())->login('itsm', 'itsm', true), 'Local login');
$savedConfiguration = $CFG_GLPI;
$savedSession = $_SESSION;
$savedTimezone = date_default_timezone_get();
$fixtures = new FixtureRecords($DB);
$writer = static fn () => new RecordWriter(Orm::create($DB));
$passwords = static fn () => new UserPasswordRepository(Orm::create($DB));
$users = static fn () => new UserRepository(Orm::create($DB));
$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_users', 'id', $id);
$DB->beginTransaction();
try {
    $CFG_GLPI['use_notifications'] = false;
    $ldap = $fixtures->create('glpi_authldaps', ['name' => "Active O'Reilly\\LDAP", 'is_active' => true, 'is_default' => true]);
    $inactiveLdap = $fixtures->create('glpi_authldaps', ['name' => 'Inactive LDAP', 'is_active' => false]);
    $mail = $fixtures->create('glpi_authmails', ['name' => "Active O'Reilly\\mail", 'is_active' => true, 'connect_string' => '{example.invalid/imap}']);
    $inactiveMail = $fixtures->create('glpi_authmails', ['name' => 'Inactive mail', 'is_active' => false]);
    $login = "Login O'Reilly\\account " . bin2hex(random_bytes(4));
    $hash = Auth::getPasswordHash('local secret');
    $id = $fixtures->create('glpi_users', ['name' => $login, 'authtype' => Auth::DB_GLPI, 'password' => $hash, 'password_last_update' => '2030-03-20 12:30:00']);
    $directoryUser = $fixtures->create('glpi_users', ['name' => $login, 'authtype' => Auth::LDAP, 'auths_id' => $ldap, 'password' => 'external hash']);
    $opaque = $fixtures->create('glpi_users', ['name' => $login, 'authtype' => Auth::DB_GLPI, 'auths_id' => 17, 'password' => 'opaque hash']);
    $credentials = $passwords()->localCredentials($login, 15, 10);
    verify((int)$credentials['id'] === $id && $credentials['password'] === $hash, 'Only the unselected local authentication source supplies credentials');
    verify($credentials['password_expiration_date'] === '2030-04-04 12:30:00' && $credentials['lock_date'] === '2030-04-14 12:30:00', 'Expiry and lock dates use database-side interval arithmetic and the legacy string contract');
    verify($passwords()->localCredentials($login . "\0suffix", 15, 10) === null, 'NUL cannot truncate a credential lookup');
    verify($passwords()->localCredentials('missing-authentication-user', 15, 10) === null, 'Missing credentials fail');
    $writer()->update('glpi_users', $id, ['password_last_update' => null]);
    $undated = $passwords()->localCredentials($login, 15, 10);
    verify($undated['password_expiration_date'] === null && $undated['lock_date'] === null, 'Undated password retains NULL policy dates');
    $DB->setTimezone('Europe/Paris');
    $writer()->update('glpi_users', $id, ['password_last_update' => '2030-03-30 12:30:00']);
    $dates = $passwords()->localCredentials($login, 1, 0);
    verify($dates['password_expiration_date'] === '2030-03-31 12:30:00', 'Day arithmetic preserves local wall-clock time across DST');

    $email = "O'Reilly\\account@example.invalid";
    $fixtures->create('glpi_useremails', ['users_id' => $id, 'email' => $email]);
    $fixtures->create('glpi_useremails', ['users_id' => $id, 'email' => 'second@example.invalid']);
    verify((int)$users()->authenticationMatch(['email' => addslashes($email)])['id'] === $id, 'Email lookup selects its account');
    verify((int)$users()->authenticationMatch(['name' => addslashes($login)])['id'] === $id, 'Email fan-out does not change the selected account');
    verify((int)$users()->authenticationMatch(['glpi_users.name' => addslashes($login), 'glpi_useremails.email' => addslashes($email)])['id'] === $id, 'Qualified login and email predicates use mapped joins');
    verify((int)$users()->authenticationMatch(['OR' => ['name' => 'not the login', 'email' => addslashes($email)]])['id'] === $id, 'Nested email criteria retain their junction');
    verify($users()->authenticationMatch(['name' => addslashes($login), 'email' => 'absent@example.invalid']) === null, 'Combined criteria stay conjunctive');
    verify((new Auth())->userExists(['name' => addslashes($login)]) === Auth::USER_EXISTS_WITH_PWD, 'Account without email remains discoverable by login');
    $noPassword = $fixtures->create('glpi_users', ['name' => 'ldap-no-password', 'authtype' => Auth::LDAP, 'auths_id' => $ldap, 'user_dn' => "cn=O'Reilly,dc=example"]);
    $authentication = new Auth();
    verify($authentication->userExists(['name' => 'ldap-no-password']) === Auth::USER_EXISTS_WITHOUT_PWD
        && $authentication->user_dn === "cn=O'Reilly,dc=example", 'Passwordless directory users retain their DN');
    verify((new Auth())->userExists(['name' => 'missing-authentication-user']) === Auth::USER_DOESNT_EXIST, 'Public missing-account status');

    $CFG_GLPI['password_expiration_delay'] = -1;
    $CFG_GLPI['password_expiration_lock_delay'] = -1;
    verify((new Auth())->connection_db(addslashes($login), 'local secret'), 'Public local authentication binds the decoded login');
    verify(!(new Auth())->connection_db(addslashes($login), 'wrong password'), 'Incorrect password remains rejected');
    $literal = $fixtures->create('glpi_users', ['name' => 'NULL', 'authtype' => Auth::DB_GLPI, 'password' => $hash]);
    verify((int)$passwords()->localCredentials('NULL', -1, -1)['id'] === $literal
        && (new Auth())->connection_db('NULL', 'local secret'), 'Typed login NULL remains a literal credential');
    verify($passwords()->localCredentials("' OR 1=1 --", -1, -1) === null, 'SQL-shaped login cannot select another account');
    $writer()->update('glpi_users', $id, ['auths_id' => 23]);
    verify(!(new Auth())->connection_db(addslashes($login), 'local secret'), 'Local login never falls back to another source code');
    $writer()->update('glpi_users', $id, ['auths_id' => 0]);
    $CFG_GLPI['password_expiration_delay'] = 15;
    $CFG_GLPI['password_expiration_lock_delay'] = 10;
    $_SESSION['glpi_currenttime'] = '2030-04-24 12:30:00';
    $authentication = new Auth();
    verify($authentication->connection_db(addslashes($login), 'local secret') && $authentication->password_expired, 'Expired password flag is set before the lock boundary');
    verify($read($id)['is_active'] === 1, 'Exact lock boundary does not disable the account');
    $_SESSION['glpi_currenttime'] = '2030-04-24 12:30:01';
    verify((new Auth())->connection_db(addslashes($login), 'local secret') && $read($id)['is_active'] === 0, 'Password-policy locking still uses the User lifecycle');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $authentication = new Auth();
    $authentication->getAuthMethods();
    verify(isset($authentication->authtypes['ldap'][$inactiveLdap], $authentication->authtypes['mail'][$inactiveMail]), 'Authentication configuration retains inactive servers for existing-source handling');
    verify($authentication->authtypes['mail'][$mail]['connect_string'] === '{example.invalid/imap}', 'Mail connection configuration is preserved');
    $methods = Auth::getLoginAuthMethods();
    verify(($methods['mail-' . $mail] ?? null) === "Active O'Reilly\\mail" && !isset($methods['mail-' . $inactiveMail]), 'Login menu includes only active mail sources');
    if (Toolbox::canUseLdap()) {
        verify($methods['_default'] === 'ldap-' . $ldap && isset($methods['ldap-' . $ldap]) && !isset($methods['ldap-' . $inactiveLdap]), 'Active default directory remains the login default');
    } else {
        verify($methods['_default'] === 'local' && !isset($methods['ldap-' . $ldap]), 'LDAP availability still gates the login menu');
    }
    verify((new LdapRepository(Orm::create($DB)))->isActive($ldap) && !(new LdapRepository(Orm::create($DB)))->isActive($inactiveLdap), 'Synchronization eligibility follows directory state');
    verify((new MailAuthenticationRepository(Orm::create($DB)))->activeCount() > 0 && AuthMail::useAuthMail(), 'Mail availability uses the mapped configuration');
    $dropdown = Auth::dropdown(['display' => false]);
    verify(is_string($dropdown) && str_contains($dropdown, 'value="' . Auth::MAIL . '"'), 'Authentication method dropdown retains active mail option');
    $users()->authenticationMatch(['email' => addslashes($email)]);
    $passwords()->localCredentials($login, 15, 10);
    verify($SQL_TOTAL_REQUEST === 0, 'Authentication lookup and source discovery bypass the SQL adapter');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $savedConfiguration;
    $_SESSION = $savedSession;
    $DB->setTimezone($savedTimezone);
}
echo $DB->getProvider() . ": mapped credentials, email lookup, expiry boundaries, source menus and adapter-free authentication queries passed.\n";
