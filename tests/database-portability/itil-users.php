<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ITILUserReferences;
use itsmng\Database\OptionalReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\ITILUserRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-users.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $owner = $fixtures->create('glpi_users', ['name' => 'ITIL original author']);
    $replacement = $fixtures->create('glpi_users', ['name' => 'ITIL replacement author']);
    $other = $fixtures->create('glpi_users', ['name' => 'ITIL unrelated author']);
    foreach ([Ticket::class, Problem::class, Change::class] as $type) {
        $source = new $type();
        foreach ([null, $other] as $updater) {
            $id = $source->add(['name' => 'Clone updater', 'content' => 'Valid ITIL clone source', 'entities_id' => 0]);
            verify((int)$id > 0, 'Create ITIL clone source');
            $DB->update($source->getTable(), ['users_id_lastupdater' => $updater], ['id' => $id]);
            verify($source->getFromDB($id), 'Load ITIL clone source');
            $clone = $source->clone();
            verify((int)$clone > 0, 'Clone ' . $type);
            verify($read($source->getTable(), (int)$clone)['users_id_lastupdater'] === $updater, 'Clone preserves nullable and explicit updater for ' . $type);
        }
    }
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'ITIL history host']);
    $snapshots = $records = $others = [];
    foreach (OptionalReferences::ITIL_USERS as $table => $columns) {
        $extra = [];
        if (in_array($table, ['glpi_itilfollowups', 'glpi_itilsolutions'], true)) {
            $extra = ['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => 'Historical content'];
        }
        $records[$table] = $fixtures->create($table, array_fill_keys(array_keys($columns), $owner) + $extra);
        $others[$table] = $fixtures->create($table, array_fill_keys(array_keys($columns), $other) + $extra);
        $snapshots[$table] = array_diff_key($read($table, $records[$table]), $columns);
    }
    verify((new User())->delete(['id' => $owner, '_replace_by' => $replacement], true), 'Replace historical ITIL user');
    foreach (OptionalReferences::ITIL_USERS as $table => $columns) {
        $row = $read($table, $records[$table]);
        foreach ($columns as $column => $target) {
            verify((int)$row[$column] === $replacement, 'Replacement updates ' . $table . '.' . $column);
        }
        verify(array_diff_key($row, $columns) === $snapshots[$table], 'Reference replacement does not replay workflow: ' . $table);
    }
    verify((new User())->delete(['id' => $replacement], true), 'Purge historical ITIL user');
    foreach (OptionalReferences::ITIL_USERS as $table => $columns) {
        $row = $read($table, $records[$table]);
        foreach ($columns as $column => $target) {
            verify($row[$column] === null, 'Historical reference becomes NULL ' . $table . '.' . $column);
            verify((int)$read($table, $others[$table])[$column] === $other, 'Other user reference retained');
            verify((new RecordRepository(Orm::create($DB)))->countMatching($table, ['id' => $records[$table], $column => 0]) === 1, 'Legacy absent-user criteria');
        }
        verify(array_diff_key($row, $columns) === $snapshots[$table], 'Purge preserves nonreference fields ' . $table);
    }
    $repo = new ITILUserRepository(Orm::create($DB));
    $public = $fixtures->create('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => 'Public followup', 'is_private' => false, 'users_id' => $other, 'date' => '2030-01-01 12:00:00']);
    $mine = $fixtures->create('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => 'My private followup', 'is_private' => true, 'users_id' => $other, 'date' => '2030-01-01 12:00:00']);
    $hidden = $fixtures->create('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => 'Missing author private', 'is_private' => true, 'date' => '2030-01-01 12:00:00']);
    $foreign = $fixtures->create('glpi_itilfollowups', ['itemtype' => 'Problem', 'items_id' => $ticket, 'content' => 'Different itemtype']);
    $rows = array_column($repo->followups('Ticket', $ticket, $other, false), 'id');
    verify(in_array($public, $rows, true) && in_array($mine, $rows, true) && !in_array($hidden, $rows, true) && !in_array($foreign, $rows, true), 'Followups scope by parent type, public visibility and positive owner');
    verify(array_search($mine, $rows, true) < array_search($public, $rows, true), 'Same-date followups use stable descending IDs');
    $anonymous = array_column($repo->followups('Ticket', $ticket, 0, false), 'id');
    verify(in_array($public, $anonymous, true) && !in_array($mine, $anonymous, true) && !in_array($hidden, $anonymous, true), 'Anonymous viewer cannot own authorless private followup');
    verify(in_array($hidden, array_column($repo->followups('Ticket', $ticket, $other, true), 'id'), true), 'Private-reading right includes authorless followups');
    verify(!$repo->hasCentralProfile($other) && !$repo->hasCentralProfile(0), 'Missing central profile and anonymous user are excluded');
    $helpdesk = $fixtures->create('glpi_profiles', ['name' => 'ITIL Helpdesk', 'interface' => 'helpdesk']);
    $central = $fixtures->create('glpi_profiles', ['name' => 'ITIL Central', 'interface' => 'central']);
    $fixtures->create('glpi_profiles_users', ['users_id' => $other, 'profiles_id' => $helpdesk, 'entities_id' => 0]);
    verify(!$repo->hasCentralProfile($other), 'Helpdesk membership is not central');
    $fixtures->create('glpi_profiles_users', ['users_id' => $other, 'profiles_id' => $central, 'entities_id' => 0]);
    verify($repo->hasCentralProfile($other), 'Central membership detected');
    $actor = $fixtures->create('glpi_tickets_users', ['users_id' => $other, 'tickets_id' => $ticket, 'type' => CommonITILActor::OBSERVER]);
    $followup = new ITILFollowup();
    verify($followup->getFromDB($mine) && $followup->isFromSupportAgent(), 'Observer with central profile is support agent');
    $writer = new \itsmng\Database\Repository\RecordWriter(Orm::create($DB));
    $writer->update('glpi_tickets_users', $actor, ['type' => CommonITILActor::REQUESTER]);
    verify(!$followup->isFromSupportAgent(), 'Requester remains requester despite central profile');
    $writer->update('glpi_tickets_users', $actor, ['type' => CommonITILActor::ASSIGN]);
    verify($followup->isFromSupportAgent(), 'Assigned author is support agent');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->followups('Ticket', $ticket, $other, false);
    $repo->hasCentralProfile($other);
    verify($SQL_TOTAL_REQUEST === 0, 'ITIL followup reads use ORM');
    $savedRights = $_SESSION['glpiactiveprofile'][ITILFollowup::$rightname];
    $_SESSION['glpiactiveprofile'][ITILFollowup::$rightname] = 0;
    $html = ITILFollowup::showShortForITILObject($ticket, 'Ticket');
    verify(str_contains($html, 'Public followup') && !str_contains($html, 'Missing author private'), 'Public view respects private visibility');
    $_SESSION['glpiactiveprofile'][ITILFollowup::$rightname] = $savedRights;
    $deletedAuthorFollowup = new ITILFollowup();
    verify($deletedAuthorFollowup->update(['id' => $records['glpi_itilfollowups'], 'content' => 'Edited historical followup']), 'Ordinary content update still runs');
    verify((int)$read('glpi_itilfollowups', $records['glpi_itilfollowups'])['users_id_editor'] === (int)Session::getLoginUserID(), 'Ordinary content edit records its editor');
    verify((new ForeignKeys())->audit($connection) === [], 'ITIL user graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new ITILUserReferences();
$legacy = null;
try {
    foreach (OptionalReferences::ITIL_USERS as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_tickets');
    $connection->insert('glpi_tickets', ['id' => $legacy, 'name' => 'Legacy ITIL user']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy ITIL user migration has a plan');
    verify((int)$connection->fetchOne('SELECT users_id_recipient FROM glpi_tickets WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_tickets', ['users_id_recipient' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned ITIL user');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_tickets')['users_id_recipient']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_tickets', ['users_id_recipient' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id_recipient FROM glpi_tickets WHERE id = ?', [$legacy]) === null, 'Legacy ITIL recipient becomes NULL');
    verify($migration->apply($connection) === [], 'ITIL user migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_tickets', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": ITIL user relationships, historical cleanup, followup visibility and migration passed.\n";
