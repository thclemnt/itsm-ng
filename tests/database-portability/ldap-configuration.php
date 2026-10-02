<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/ldap-configuration.php /path/to/test-config\n");
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
$repo = static fn () => new \itsmng\Database\Repository\LdapRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $before = AuthLDAP::getNumberOfServers();
    $first = (new AuthLDAP())->add(['name' => 'Z mapped directory', 'is_active' => true, 'is_default' => true, 'email1_field' => 'mail']);
    $second = (new AuthLDAP())->add(['name' => 'A mapped directory', 'is_active' => true, 'is_default' => false, 'email1_field' => '', 'email2_field' => 'secondaryMail']);
    $inactive = (new AuthLDAP())->add(['name' => 'Inactive mapped directory', 'is_active' => false, 'email1_field' => 'mail']);
    $noEmail = (new AuthLDAP())->add(['name' => 'No email mapped directory', 'is_active' => true, 'email1_field' => null, 'email2_field' => '', 'email3_field' => null, 'email4_field' => '']);
    verify($first > 0 && $second > 0 && $inactive > 0 && $noEmail > 0, 'Directory lifecycle creates local configuration');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    verify(AuthLDAP::useAuthLdap() && AuthLDAP::getNumberOfServers() === $before + 3, 'Active directory count uses typed boolean');
    verify(AuthLDAP::getDefault() === $first, 'Only active default is selected');
    $ids = [$first, $second, $inactive, $noEmail];
    verify(array_values(array_intersect(array_column($repo()->directories(true), 'id'), $ids)) === [$second, $noEmail, $first], 'Active chooser sorts by name');
    $servers = AuthLDAP::getLdapServers();
    verify(isset($servers[$first], $servers[$inactive]) && array_key_first($servers) === $first, 'Server list retains ID keys, inactive configurations and default ordering');
    verify(array_values(array_intersect(AuthLDAP::getServersWithImportByEmailActive(), $ids)) === [$first, $second], 'Email import excludes inactive and empty configurations');
    verify($SQL_TOTAL_REQUEST === 0, 'Directory selection bypasses adapter SQL');
    verify((new AuthLDAP())->update(['id' => $second, 'is_default' => true]), 'Switch directory default through lifecycle');
    verify(AuthLDAP::getDefault() === $second && $read('glpi_authldaps', $first)['is_default'] === 0, 'Default switch clears prior default');
    $connection->beginTransaction();
    (new AuthLDAP())->update(['id' => $first, 'is_default' => true]);
    $connection->rollBack();
    verify(AuthLDAP::getDefault() === $second, 'Default maintenance shares caller transaction');
    $replica = (new AuthLdapReplicate())->add(['authldaps_id' => $first, 'name' => 'Z replica', 'host' => 'replica-z.example.test', 'port' => 0]);
    $replicaA = (new AuthLdapReplicate())->add(['authldaps_id' => $first, 'name' => 'A replica', 'host' => 'replica-a.example.test', 'port' => 636]);
    $otherReplica = (new AuthLdapReplicate())->add(['authldaps_id' => $second, 'name' => 'Other replica', 'host' => 'other.example.test']);
    verify($replica > 0 && $replicaA > 0 && $otherReplica > 0, 'Replica lifecycle persists required master');
    verify(AuthLDAP::getAllReplicateForAMaster($first) === [['id' => $replica, 'host' => 'replica-z.example.test', 'port' => 389], ['id' => $replicaA, 'host' => 'replica-a.example.test', 'port' => 636]], 'Failover contract returns scoped endpoints and defaults port');
    verify(array_column($repo()->replicas($first, true), 'id') === [$replicaA, $replica] && AuthLDAP::getAllReplicateForAMaster(0) === [], 'Replica form name order and invalid master selection');
    $directory = new AuthLDAP();
    $directory->getFromDB($first);
    ob_start();
    $directory->showFormReplicatesConfig();
    $html = ob_get_clean();
    verify(str_contains($html, 'replica-a.example.test') && !str_contains($html, 'other.example.test'), 'Replica configuration rendering respects master');
    $login = "o'connor\\ldap";
    $ldapUser = $fixtures->create('glpi_users', ['name' => $login, 'auths_id' => $first, 'authtype' => Auth::LDAP, 'sync_field' => 'mapped-sync']);
    $alternateUser = $fixtures->create('glpi_users', ['name' => 'alternate-directory-source', 'auths_id' => $first, 'authtype' => Auth::X509]);
    $fixtures->create('glpi_authmails', ['id' => $first]);
    $mailUser = $fixtures->create('glpi_users', ['name' => 'mail-same-source-id', 'auths_id' => $first, 'authtype' => Auth::MAIL]);
    $localUser = $fixtures->create('glpi_users', ['name' => 'local-same-source-id', 'auths_id' => $first, 'authtype' => Auth::DB_GLPI]);
    $otherUser = $fixtures->create('glpi_users', ['name' => 'other-directory-user', 'auths_id' => $second, 'authtype' => Auth::LDAP]);
    $SQL_TOTAL_REQUEST = 0;
    verify($repo()->knownServerIds($login) === [$first] && $repo()->knownServerIds('unknown-directory-login') === [], 'Known-server lookup binds raw quoted login');
    $candidates = iterator_to_array($repo()->userCandidates($first, 'DESC'), false);
    verify(array_column($candidates, 'id') === [$ldapUser], 'Synchronization filters source and authentication types');
    $imports = array_column(iterator_to_array($repo()->userCandidates(null, 'ASC'), false), 'id');
    verify(in_array($ldapUser, $imports, true) && in_array($localUser, $imports, true) && in_array($otherUser, $imports, true), 'Import compares all existing logins');
    verify($directory->isSyncFieldUsed() && !$repo()->usesSyncField($second), 'Sync-field detection stays scoped');
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'LDAP group scope', 'entities_id' => 0]);
    $dn = "ou=o'connor\\team,dc=example,dc=test";
    $fixtures->create('glpi_groups', ['entities_id' => $entity, 'name' => 'LDAP scoped group', 'ldap_group_dn' => $dn, 'ldap_value' => 'mapped-team']);
    $fixtures->create('glpi_groups', ['entities_id' => 0, 'name' => 'LDAP other group', 'ldap_group_dn' => 'ou=outside,dc=test', 'ldap_value' => 'outside-team']);
    verify($repo()->groupIdentifiers(['glpi_groups.entities_id' => $entity]) === [['ldap_group_dn' => $dn, 'ldap_value' => 'mapped-team']], 'Local group identifiers honor entity scope');
    verify($repo()->groupValuesForDns([$dn]) === [['ldap_value' => 'mapped-team']] && $repo()->groupValuesForDns([]) === [], 'Ancestor DN values bind raw strings and empty sets');
    verify($SQL_TOTAL_REQUEST === 0, 'Synchronization candidate and group lookups bypass adapter');
    verify((new AuthLDAP())->delete(['id' => $first], true), 'Directory purge removes owned replicas');
    verify($read('glpi_users', $ldapUser)['auths_id'] === 0 && $read('glpi_users', $alternateUser)['auths_id'] === 0 && $read('glpi_users', $localUser)['auths_id'] === $first && $read('glpi_users', $mailUser)['auths_id'] === $first, 'Directory purge clears LDAP and alternate sources without touching local or mail authentication');
    verify($read('glpi_authldapreplicates', $replica) === null && $read('glpi_authldapreplicates', $replicaA) === null && $read('glpi_authldapreplicates', $otherReplica) !== null, 'Purge deletes only this directory replica graph');
    verify((new AuthLDAP())->delete(['id' => $second, '_replace_by' => $noEmail], true), 'Directory replacement succeeds');
    verify($read('glpi_authldapreplicates', $otherReplica)['authldaps_id'] === $noEmail, 'Replacement preserves and reassigns replicas');
    verify($read('glpi_users', $otherUser)['auths_id'] === $noEmail, 'Directory replacement updates LDAP users without photo synchronization');
    verify((new ForeignKeys())->audit($connection) === [], 'LDAP graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
echo $DB->getProvider() . ": LDAP configuration, replica ownership, defaults, candidates and purge passed.\n";
