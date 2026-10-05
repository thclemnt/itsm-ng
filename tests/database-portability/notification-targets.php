<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\V220\NotificationRecipients;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationRecipientRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/notification-targets.php /path/to/test-config\n");
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
$migration = new NotificationRecipients();
$migration->apply($connection);
$plan = $migration->plan($connection);
verify(!$plan['sql'] && !$plan['key_sql'] && !$plan['constraint_sql'] && !$plan['copy_legacy'], 'Idempotent recipient upgrade');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $notification = $fixtures->create('glpi_notifications', ['itemtype' => 'Ticket', 'event' => 'new']);
    // Opaque recipient codes stay INTEGER; collision controls must fit that range.
    $group = $fixtures->create('glpi_groups', ['id' => 950000144]);
    $profile = $fixtures->create('glpi_profiles');
    $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'profile', 'rights' => 0]);
    $storage = new MappedStorage($DB);
    foreach ([3, 5, 6, 2, 1, 4, 999] as $kind) {
        $value = $kind === 2 ? $profile : $group;
        $id = $storage->insert('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => $kind, 'items_id' => $value]);
        $row = $connection->fetchAssociative('SELECT * FROM glpi_notificationtargets WHERE id = ?', [$id]);
        verify((int)$row['items_id'] === $value, 'Generated compatibility key for each recipient kind');
        verify($kind === 2 ? (int)$row['profiles_id'] === $profile && $row['groups_id'] === null && $row['recipient_code'] === null
            : (in_array($kind, [3, 5, 6], true) ? (int)$row['groups_id'] === $group && $row['profiles_id'] === null && $row['recipient_code'] === null
                : $row['groups_id'] === null && $row['profiles_id'] === null && (int)$row['recipient_code'] === $value), 'Only the selected recipient branch is stored');
    }
    foreach (['glpi_groups' => [3, 'groups_id', 4294967991], 'glpi_profiles' => [2, 'profiles_id', 4294967992]] as $table => [$kind, $column, $wideId]) {
        $fixtures->create($table, ['id' => $wideId]);
        $id = $storage->insert('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => $kind, 'items_id' => $wideId]);
        $row = $connection->fetchAssociative('SELECT * FROM glpi_notificationtargets WHERE id = ?', [$id]);
        verify((int)$row['items_id'] === $wideId && (int)$row[$column] === $wideId, 'Typed recipients preserve identifiers above 32 bits');
    }
    $em = Orm::create($DB);
    $native = new Entity\NotificationTarget();
    $newGroup = new Entity\Group();
    $newGroup->entities = $em->find(Entity\Entity::class, 0);
    $native->notifications = $em->getReference(Entity\Notification::class, $notification);
    $native->type = 3;
    $native->group = $newGroup;
    $em->persist($newGroup);
    $em->persist($native);
    $em->flush();
    verify($native->items_id === $newGroup->id && $native->recipient_code === null, 'Native Doctrine persists a new parent and refreshes the generated key in one flush');
    $native->group = $em->getReference(Entity\Group::class, $group);
    $em->flush();
    verify($native->items_id === $group, 'Native association update refreshes the generated key');
    foreach ([3, 1] as $invalidKind) {
        $invalid = new Entity\NotificationTarget();
        $invalid->notifications = $native->notifications;
        $invalid->type = $invalidKind;
        if ($invalidKind === 1) {
            $invalid->group = $native->group;
        }
        try {
            $em->persist($invalid);
            throw new LogicException('Native Doctrine accepted an inconsistent recipient');
        } catch (InvalidArgumentException) {
        }
    }
    $changes = $storage->update('glpi_notificationtargets', $native->id, ['type' => 2, 'profiles_id' => $profile]);
    verify(in_array('items_id', $changes, true), 'Canonical association changes include the legacy logical field');
    $legacy = new NotificationTarget();
    verify($legacy->getFromDB($native->id) && (int)$legacy->fields['items_id'] === $profile, 'Legacy model reads canonical profile selection');
    verify($legacy->update(['id' => $native->id, 'type' => 1, 'items_id' => Notification::AUTHOR]), 'Legacy update changes recipient kind');
    $row = (new RecordRepository(Orm::create($DB)))->find('glpi_notificationtargets', 'id', $native->id);
    verify($row['groups_id'] === null && $row['profiles_id'] === null && $row['recipient_code'] === Notification::AUTHOR, 'Kind transition clears previous associations');
    $code = $storage->insert('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => 999, 'items_id' => '8', 'recipient_code' => 8]);
    verify($connection->fetchOne('SELECT items_id FROM glpi_notificationtargets WHERE id = ?', [$code]) == 8, 'Numeric legacy/canonical payload agreement');
    foreach ([[null, ['type' => 3, 'groups_id' => 2147483647, 'profiles_id' => null, 'recipient_code' => null]],
        [null, ['type' => 2, 'groups_id' => null, 'profiles_id' => 2147483647, 'recipient_code' => null]],
        [NotificationRecipients::CHECK, ['type' => 3, 'groups_id' => null, 'profiles_id' => null, 'recipient_code' => null]],
        [NotificationRecipients::CHECK, ['type' => 3, 'groups_id' => $group, 'profiles_id' => $profile, 'recipient_code' => null]],
        [NotificationRecipients::CHECK, ['type' => 1, 'groups_id' => $group, 'profiles_id' => null, 'recipient_code' => 8]],
        [NotificationRecipients::CHECK, ['type' => 1, 'groups_id' => null, 'profiles_id' => null, 'recipient_code' => null]]] as [$expectedCheck, $invalid]) {
        try {
            $connection->transactional(fn () => $connection->update('glpi_notificationtargets', $invalid, ['id' => $native->id]));
            throw new LogicException('Invalid recipient was stored: ' . json_encode($invalid));
        } catch (DriverException $error) {
            verify(in_array($error->getSQLState(), ['23503', '23514', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck), 'Database constraint rejection: ' . $error->getMessage());
        }
    }
    try {
        $connection->transactional(fn () => $connection->update('glpi_notificationtargets', ['items_id' => 12], ['id' => $native->id]));
    } catch (DriverException $error) {
        verify(in_array($error->getSQLState(), ['428C9', 'HY000'], true), 'Generated column rejects assignment');
    }
    // MariaDB may warn and ignore the assignment; PostgreSQL rejects it.
    verify((int)$connection->fetchOne('SELECT items_id FROM glpi_notificationtargets WHERE id = ?', [$native->id]) === Notification::AUTHOR, 'Direct writes cannot change the generated recipient identity');
    // Replacement and purge must preserve opaque constants even when identifiers collide.
    $replacement = $fixtures->create('glpi_groups');
    $repo = new NotificationRecipientRepository(Orm::create($DB));
    $groupModel = new Group();
    verify($groupModel->delete(['id' => $group, '_replace_by' => $replacement], true), 'Group lifecycle replaces typed notification recipients');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE groups_id = ?', [$replacement]) === 3, 'Group replacement retains all three group recipient kinds');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE recipient_code = ?', [$group]) >= 3, 'Group replacement leaves colliding opaque payloads unchanged');
    verify($groupModel->delete(['id' => $replacement], true), 'Group lifecycle deletes its notification targets');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE groups_id = ?', [$replacement]) === 0, 'Group purge removes typed targets');
    $newProfile = $fixtures->create('glpi_profiles');
    $fixtures->create('glpi_profilerights', ['profiles_id' => $newProfile, 'name' => 'profile', 'rights' => 0]);
    $profileModel = new Profile();
    verify($profileModel->delete(['id' => $profile, '_replace_by' => $newProfile], true), 'Profile lifecycle replaces typed notification recipients');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE profiles_id = ?', [$newProfile]) === 1, 'Profile replacement retains the selected recipient');
    verify($profileModel->delete(['id' => $newProfile], true), 'Profile lifecycle deletes its notification targets');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE profiles_id = ?', [$newProfile]) === 0, 'Profile purge removes typed targets');
    // The existence query requires a Ticket author target and a mailing template binding.
    $repo = new NotificationRecipientRepository(Orm::create($DB));
    $connection->transactional(function () use ($connection, $repo): void {
        $connection->executeStatement("UPDATE glpi_notifications_notificationtemplates SET mode = 'fixture-disabled'");
        verify(!$repo->hasAuthorMailing(), 'Non-mailing bindings do not enable author mailing');
    });
    $template = $fixtures->create('glpi_notificationtemplates');
    $fixtures->create('glpi_notifications_notificationtemplates', ['notifications_id' => $notification, 'notificationtemplates_id' => $template, 'mode' => 'mailing']);
    verify($repo->hasAuthorMailing(), 'Mapped author-mailing query recognizes the selected recipient and binding');
} finally {
    $connection->rollBack();
}

// Exercise the frozen upgrade in an isolated reconstructed legacy schema.
$postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
$name = 'itsm_port_notification_targets_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
    $fixture = $postgres ? DriverManager::getConnection($params)
        : \itsmng\Database\MySQLConnection::create($params, $connection->getConfiguration());
    if (!$postgres) {
        verify($fixture !== $connection && $fixture instanceof \itsmng\Database\MySQLManagedConnection
            && $fixture->getNativeConnection() !== $connection->getNativeConnection()
            && $fixture->fetchOne('SELECT DATABASE()') === $name, 'Separate historical fixture retains its actual canonical MySQL writer and exclusively owned database');
    }
    if ($postgres) {
        $fixture->executeStatement('SET search_path TO ' . $quote);
    }
    // Referenced IDs are widened before the master runs this typed stage.
    $fixture->executeStatement('CREATE TABLE glpi_groups (id BIGINT PRIMARY KEY)');
    $fixture->executeStatement('CREATE TABLE glpi_profiles (id BIGINT PRIMARY KEY)');
    $fixture->executeStatement('INSERT INTO glpi_groups VALUES (11)');
    $fixture->executeStatement('INSERT INTO glpi_profiles VALUES (12)');
    $fixture->executeStatement('CREATE TABLE glpi_notificationtargets (id INTEGER PRIMARY KEY, type INTEGER NOT NULL, items_id INTEGER NOT NULL DEFAULT 0)');
    $fixture->executeStatement('CREATE INDEX items ON glpi_notificationtargets (type, items_id)');
    $fixture->executeStatement('CREATE INDEX custom_recipient ON glpi_notificationtargets (items_id, id)');
    $fixture->executeStatement('INSERT INTO glpi_notificationtargets VALUES (1, 3, 11), (2, 5, 11), (3, 6, 11), (4, 2, 12), (5, 1, 8), (6, 4, 42), (7, 999, 99)');
    $fixture->update('glpi_notificationtargets', ['items_id' => 99], ['id' => 1]);
    try {
        $migration->apply($fixture);
        throw new LogicException('Orphan migration accepted');
    } catch (RuntimeException $error) {
        verify(!$error instanceof LogicException && !$fixture->createSchemaManager()->introspectTable('glpi_notificationtargets')->hasColumn('groups_id'), 'Orphan preflight rejects before schema changes');
    }
    $fixture->update('glpi_notificationtargets', ['items_id' => 11], ['id' => 1]);
    $plan = $migration->plan($fixture);
    verify($plan['group_rows'] === 3 && $plan['profile_rows'] === 1 && $plan['copy_legacy'], 'Dry run counts recipients without changing data');
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
    foreach ([['groups_id' => 12], ['recipient_code' => 99]] as $inconsistent) {
        $fixture->update('glpi_notificationtargets', $inconsistent, ['id' => 1]);
        try {
            $migration->apply($fixture);
            throw new LogicException('Conflicting canonical recipient was overwritten');
        } catch (RuntimeException $error) {
            verify(!$error instanceof LogicException && str_contains($error->getMessage(), 'disagree'), 'Partial upgrades reject conflicting canonical data before mutation');
        } finally {
            $fixture->update('glpi_notificationtargets', array_fill_keys(array_keys($inconsistent), null), ['id' => 1]);
        }
    }
    $migration->apply($fixture);
    verify(array_map('intval', $fixture->fetchFirstColumn('SELECT items_id FROM glpi_notificationtargets ORDER BY id')) === [11, 11, 11, 12, 8, 42, 99], 'Upgrade preserves all legacy selections and opaque payloads');
    $plan = $migration->plan($fixture);
    verify(!$plan['sql'] && !$plan['key_sql'] && !$plan['constraint_sql'] && !$plan['copy_legacy'], 'Partial-column retry and repeated upgrade are idempotent');
    verify($fixture->createSchemaManager()->introspectTable('glpi_notificationtargets')->hasIndex('items'), 'Compatibility selection index is preserved');
    verify($fixture->createSchemaManager()->introspectTable('glpi_notificationtargets')->hasIndex('custom_recipient'), 'Custom legacy indexes survive the generated-column conversion');
} finally {
    $fixture?->close();
    $connection->executeStatement(($postgres ? 'DROP SCHEMA ' : 'DROP DATABASE ') . $quote . ($postgres ? ' CASCADE' : ''));
}
echo "Typed notification recipients, generated identity, native ORM, FK/CHECK enforcement, purge/replacement and upgrade retry passed\n";
