<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\UserAuthenticationSources;
use itsmng\Database\Orm;
use itsmng\Database\Repository\UserRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/user-authentication-sources.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
require __DIR__ . '/FixtureRecords.php';
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
$migration = new UserAuthenticationSources();
$plan = $migration->plan($connection);
verify(!$plan['sql'] && !$plan['key_sql'] && !$plan['constraint_sql'] && !$plan['copy_legacy'], 'Fresh install already has typed authentication sources and constraints');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Local login');
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $ldap = $fixtures->create('glpi_authldaps', ['name' => 'Authentication source LDAP']);
    $mail = $fixtures->create('glpi_authmails', ['name' => 'Authentication source mail']);
    $storage = new MappedStorage($DB);
    $read = fn (int $id): array => (new RecordRepository(Orm::create($DB), false))->find('glpi_users', 'id', $id);
    $connection->insert('glpi_users', ['name' => 'default-pending-authentication']);
    $pending = $connection->fetchAssociative('SELECT authtype, auths_id, auth_source_code, authldaps_id, authmails_id FROM glpi_users WHERE name = ?', ['default-pending-authentication']);
    verify((int)$pending['authtype'] === Auth::NOT_YET_AUTHENTIFIED && (int)$pending['auths_id'] === 0
        && $pending['auth_source_code'] === null && $pending['authldaps_id'] === null && $pending['authmails_id'] === null, 'Raw inserts with omitted authentication fields retain a valid pending account');
    foreach ([Auth::NOT_YET_AUTHENTIFIED, Auth::MAIL, Auth::LDAP, Auth::EXTERNAL, Auth::CAS, Auth::X509] as $kind) {
        $source = $kind === Auth::MAIL ? $mail : $ldap;
        $id = $fixtures->create('glpi_users', ['name' => 'typed-source-' . $kind, 'authtype' => $kind, 'auths_id' => $source]);
        $row = $read($id);
        verify($row['auths_id'] === $source && $row['auth_source_code'] === null, 'Legacy selection reads its typed source');
        verify($kind === Auth::MAIL ? $row['authmails_id'] === $mail && $row['authldaps_id'] === null
            : $row['authldaps_id'] === $ldap && $row['authmails_id'] === null, 'Only the matching source association is stored');
        foreach ([0, -1, '', false, null] as $empty) {
            $emptyId = $fixtures->create('glpi_users', ['name' => 'empty-source-' . $kind . '-' . bin2hex(random_bytes(4)), 'authtype' => $kind, 'auths_id' => $empty]);
            $row = $read($emptyId);
            verify($row['auths_id'] === 0 && $row['authmails_id'] === null && $row['authldaps_id'] === null && $row['auth_source_code'] === null, 'No selected server stays available for fallback authentication');
        }
    }
    foreach ([Auth::DB_GLPI, Auth::API, Auth::COOKIE, 999] as $kind) {
        $id = $fixtures->create('glpi_users', ['name' => 'opaque-source-' . $kind, 'authtype' => $kind, 'auths_id' => 12345]);
        verify($read($id)['auth_source_code'] === 12345 && $read($id)['auths_id'] === 12345, 'Non-server kinds preserve opaque source codes');
    }
    $em = Orm::create($DB);
    $native = new Entity\User();
    $native->name = 'native-authentication-user';
    $native->authtype = Auth::LDAP;
    $native->entities = $em->find(Entity\Entity::class, 0);
    $newLdap = new Entity\AuthLDAP();
    $newLdap->name = 'Native new authentication source';
    $native->authldap = $newLdap;
    $em->persist($newLdap);
    $em->persist($native);
    $em->flush();
    verify($native->auths_id === $newLdap->id && $native->auth_source_code === null, 'Native source and user persist in one flush with refreshed identity');
    $native->authldap = null;
    $em->flush();
    verify($native->auths_id === 0, 'Native empty source regenerates zero');
    $native->authtype = Auth::MAIL;
    $native->authmail = $em->getReference(Entity\AuthMail::class, $mail);
    $em->flush();
    verify($native->auths_id === $mail, 'Native kind transition selects the new source');
    $native->authtype = Auth::DB_GLPI;
    $native->authmail = null;
    $native->auth_source_code = -1;
    $em->flush();
    verify($native->auths_id === -1, 'Native non-server payload remains signed and opaque');
    foreach ([Auth::LDAP, Auth::DB_GLPI] as $kind) {
        $invalid = new Entity\User();
        $invalid->authtype = $kind;
        $invalid->authmail = $em->getReference(Entity\AuthMail::class, $mail);
        try {
            $em->persist($invalid);
            throw new LogicException('Native inconsistent authentication source accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $id = $fixtures->create('glpi_users', ['name' => 'legacy-source-transition', 'authtype' => Auth::LDAP, 'auths_id' => $ldap, 'password' => 'old password', 'is_deleted_ldap' => true]);
    $changes = $storage->update('glpi_users', $id, ['authtype' => Auth::MAIL, 'authmails_id' => $mail]);
    verify(in_array('auths_id', $changes, true) && $read($id)['authldaps_id'] === null && $read($id)['auths_id'] === $mail, 'Canonical changes clear prior branch and track the legacy logical field');
    $repo = new UserRepository(Orm::create($DB));
    $repo->changeAuthentication([$id], Auth::EXTERNAL, $ldap);
    verify($read($id)['auths_id'] === $ldap && $read($id)['authmails_id'] === null && $read($id)['password'] === '' && $read($id)['is_deleted_ldap'] === 0, 'Bulk authentication update writes associations and preserves maintenance behavior');
    $repo->changeAuthentication([$id], Auth::LDAP, -1);
    verify($read($id)['auths_id'] === 0 && $read($id)['authtype'] === Auth::LDAP, 'Bulk no-server selection preserves directory fallback');
    foreach ([['authtype' => Auth::LDAP, 'authldaps_id' => $ldap, 'auths_id' => $ldap + 1],
        ['authtype' => Auth::MAIL, 'authldaps_id' => $ldap], ['authtype' => Auth::LDAP, 'authldaps_id' => -1],
        ['authtype' => Auth::LDAP, 'auth_source_code' => 4], ['authtype' => Auth::DB_GLPI, 'auth_source_code' => null],
        ['authtype' => Auth::LDAP, 'auths_id' => 'invalid', 'authldaps_id' => 0]] as $invalid) {
        try {
            $storage->update('glpi_users', $id, $invalid);
            throw new LogicException('Inconsistent legacy/canonical source accepted');
        } catch (InvalidArgumentException) {
        }
    }
    foreach ([[null, ['authtype' => Auth::LDAP, 'authldaps_id' => 2147483647, 'authmails_id' => null, 'auth_source_code' => null]],
        [null, ['authtype' => Auth::MAIL, 'authmails_id' => 2147483647, 'authldaps_id' => null, 'auth_source_code' => null]],
        [UserAuthenticationSources::CHECK, ['authtype' => Auth::MAIL, 'authmails_id' => null, 'authldaps_id' => $ldap, 'auth_source_code' => null]],
        [UserAuthenticationSources::CHECK, ['authtype' => Auth::LDAP, 'authldaps_id' => 0, 'authmails_id' => null, 'auth_source_code' => null]],
        [UserAuthenticationSources::CHECK, ['authtype' => Auth::DB_GLPI, 'authldaps_id' => null, 'authmails_id' => null, 'auth_source_code' => null]]] as [$expectedCheck, $invalid]) {
        try {
            $connection->transactional(fn () => $connection->update('glpi_users', $invalid, ['id' => $id]));
            throw new LogicException('Database accepted invalid authentication branch');
        } catch (DriverException $error) {
            verify(in_array($error->getSQLState(), ['23503', '23514', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck), 'FK/CHECK constraint rejects invalid branch');
        }
    }
    $fixtures->create('glpi_users', ['name' => 'unique-empty-source', 'authtype' => Auth::LDAP, 'auths_id' => 0]);
    try {
        $connection->transactional(fn () => $connection->insert('glpi_users', ['name' => 'unique-empty-source', 'authtype' => Auth::LDAP, 'auth_source_code' => null, 'entities_id' => 0]));
        throw new LogicException('Duplicate no-server login accepted');
    } catch (DriverException $error) {
        verify(in_array($error->getSQLState(), ['23505', '23000'], true), 'Generated zero preserves login uniqueness for NULL associations');
    }
    $replacement = $fixtures->create('glpi_authmails');
    $mailUser = $fixtures->create('glpi_users', ['name' => 'mail-server-purge', 'authtype' => Auth::MAIL, 'auths_id' => $mail]);
    $opaqueUser = $fixtures->create('glpi_users', ['name' => 'opaque-colliding-server', 'authtype' => Auth::DB_GLPI, 'auths_id' => $mail]);
    verify((new AuthMail())->delete(['id' => $mail, '_replace_by' => $replacement], true), 'Mail server lifecycle supports replacement');
    verify($read($mailUser)['auths_id'] === $replacement && $read($opaqueUser)['auths_id'] === $mail, 'Mail replacement affects only the typed association');
    verify((new AuthMail())->delete(['id' => $replacement], true) && $read($mailUser)['auths_id'] === 0, 'Mail purge clears selection without changing authentication kind');
} finally {
    $connection->rollBack();
}

// Exercise the frozen upgrade in an isolated reconstructed legacy schema.
$postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
$name = 'itsm_port_authentication_sources_' . getmypid() . '_' . bin2hex(random_bytes(4));
$quote = $connection->quoteIdentifier($name);
$fixture = null;
$connection->executeStatement(($postgres ? 'CREATE SCHEMA ' : 'CREATE DATABASE ') . $quote);
try {
    $params = $connection->getParams();
    $params['driver'] = $postgres ? 'pdo_pgsql' : 'pdo_mysql';
    if ($postgres) {
        $host = $DB->dbhost;
        $port = $DB->dbport;
        if (preg_match('/^(.+):(\d+)$/', $host, $endpoint)) {
            $host = trim($endpoint[1], '[]');
            $port = (int)$endpoint[2];
        }
        $params += ['host' => $host, 'port' => $port, 'user' => $DB->dbuser, 'password' => rawurldecode($DB->dbpassword)];
    } else {
        $params['dbname'] = $name;
    }
    $fixture = DriverManager::getConnection($params);
    if ($postgres) {
        $fixture->executeStatement('SET search_path TO ' . $quote);
    }
    // The master widens referenced IDs before the typed authentication stage.
    $fixture->executeStatement('CREATE TABLE glpi_authldaps (id BIGINT PRIMARY KEY)');
    $fixture->executeStatement('CREATE TABLE glpi_authmails (id BIGINT PRIMARY KEY)');
    $fixture->executeStatement('INSERT INTO glpi_authldaps VALUES (11)');
    $fixture->executeStatement('INSERT INTO glpi_authmails VALUES (12)');
    $fixture->executeStatement('CREATE TABLE glpi_users (id INTEGER PRIMARY KEY, name VARCHAR(255), authtype INTEGER NOT NULL, auths_id INTEGER NOT NULL DEFAULT 0)');
    $fixture->executeStatement('CREATE UNIQUE INDEX users_unicityloginauth ON glpi_users (name, authtype, auths_id)');
    $fixture->executeStatement('CREATE INDEX custom_source ON glpi_users (auths_id, id)');
    $fixture->executeStatement("INSERT INTO glpi_users VALUES (1, 'ldap', 3, 11), (2, 'external', 4, 11), (3, 'cas', 5, 11), (4, 'x509', 6, 11), (5, 'mail', 2, 12), (6, 'empty-ldap', 3, 0), (7, 'negative-mail', 2, -1), (8, 'local', 1, -1), (9, 'custom', 999, 99), (12, 'pending-ldap', 0, 11), (13, 'pending-empty', 0, 0)");
    $fixture->executeStatement("INSERT INTO glpi_users VALUES (10, 'collision', 3, 0), (11, 'collision', 3, -1)");
    try {
        $migration->apply($fixture);
        throw new LogicException('Normalizing source sentinels merged distinct login keys');
    } catch (RuntimeException $error) {
        verify(!$error instanceof LogicException && str_contains($error->getMessage(), 'merge distinct login keys'), 'Collision preflight refuses before changing schema');
    }
    $fixture->executeStatement('DELETE FROM glpi_users WHERE id IN (10, 11)');
    $fixture->update('glpi_users', ['auths_id' => 99], ['id' => 1]);
    try {
        $migration->apply($fixture);
        throw new LogicException('Orphan migration accepted');
    } catch (RuntimeException $error) {
        verify(!$error instanceof LogicException && !$fixture->createSchemaManager()->introspectTable('glpi_users')->hasColumn('authldaps_id'), 'Orphan preflight rejects before schema changes');
    }
    $fixture->update('glpi_users', ['auths_id' => 11], ['id' => 1]);
    $plan = $migration->plan($fixture);
    verify($plan['ldap_rows'] === 7 && $plan['mail_rows'] === 2 && $plan['copy_legacy'], 'Dry run counts authentication sources without changing data');
    if (!$postgres) {
        $fixture->beginTransaction();
        try {
            $migration->apply($fixture);
            throw new LogicException('MySQL DDL executed in transaction');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'outside an application transaction'), 'MySQL DDL transaction guard');
        } finally {
            $fixture->rollBack();
        }
    }
    foreach ($plan['sql'] as $sql) {
        $fixture->executeStatement($sql);
    }
    foreach ([['authldaps_id' => 12], ['auth_source_code' => 99]] as $inconsistent) {
        $fixture->update('glpi_users', $inconsistent, ['id' => 1]);
        try {
            $migration->apply($fixture);
            throw new LogicException('Conflicting canonical authentication source was overwritten');
        } catch (RuntimeException $error) {
            verify(!$error instanceof LogicException && str_contains($error->getMessage(), 'disagree'), 'Partial upgrades reject conflicting canonical data before mutation');
        } finally {
            $fixture->update('glpi_users', array_fill_keys(array_keys($inconsistent), null), ['id' => 1]);
        }
    }
    $migration->apply($fixture);
    verify(array_map('intval', $fixture->fetchFirstColumn('SELECT auths_id FROM glpi_users ORDER BY id')) === [11, 11, 11, 11, 12, 0, 0, -1, 99, 11, 0], 'Upgrade preserves all legacy selections and opaque payloads');
    $plan = $migration->plan($fixture);
    verify(!$plan['sql'] && !$plan['key_sql'] && !$plan['constraint_sql'] && !$plan['copy_legacy'], 'Partial-column retry and repeated upgrade are idempotent');
    verify($fixture->createSchemaManager()->introspectTable('glpi_users')->hasIndex('users_unicityloginauth'), 'Login uniqueness index is preserved');
    verify($fixture->createSchemaManager()->introspectTable('glpi_users')->hasIndex('custom_source'), 'Custom legacy indexes survive the generated-column conversion');
} finally {
    $fixture?->close();
    $connection->executeStatement(($postgres ? 'DROP SCHEMA ' : 'DROP DATABASE ') . $quote . ($postgres ? ' CASCADE' : ''));
}
echo "Typed user authentication sources, generated identity, native ORM, FK/CHECK enforcement, purge/replacement and upgrade retry passed\n";
