<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\QueueTemplateReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/notification-queues.php /path/to/test-config\n");
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
$repo = static fn () => new \itsmng\Database\Repository\NotificationQueueRepository(Orm::create($DB));
$oldConfiguration = $CFG_GLPI;
$DB->beginTransaction();
try {
    $template = $fixtures->create('glpi_notificationtemplates', ['name' => 'Mapped queue template', 'itemtype' => 'Ticket']);
    $otherTemplate = $fixtures->create('glpi_notificationtemplates', ['name' => 'Other queue template', 'itemtype' => 'Ticket']);
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Queued ticket']);
    $at = new DateTimeImmutable('2030-01-02 12:00:00');
    $retained = [];
    foreach (['notification' => ['glpi_queuednotifications', 'QueuedNotification', 'mailing'], 'chat' => ['glpi_queuedchats', 'QueuedChat', 'chat']] as $kind => [$table, $model, $mode]) {
        $base = ['itemtype' => 'Ticket', 'items_id' => $ticket, 'entities_id' => 0, 'notificationtemplates_id' => $template, 'mode' => $mode];
        if ($kind === 'notification') {
            $base['recipient'] = "o'connor@example.test";
            $base['body_text'] = 'Rendered notification history';
        } else {
            $base['ticketTitle'] = 'Rendered chat history <title>';
        }
        $ids = [];
        foreach (['early' => '2030-01-02 10:00:00', 'tie' => '2030-01-02 10:00:00', 'boundary' => '2030-01-02 12:00:00', 'future' => '2030-01-02 13:00:00', 'missing' => null, 'deleted' => '2030-01-02 09:00:00'] as $name => $date) {
            $ids[$name] = $fixtures->create($table, $base + ['send_time' => $date, 'is_deleted' => $name === 'deleted']);
        }
        $foreignMode = $fixtures->create($table, array_replace($base, ['mode' => 'ajax', 'send_time' => '2030-01-02 10:00:00']));
        $other = $fixtures->create($table, array_replace($base, ['notificationtemplates_id' => $otherTemplate, 'send_time' => '2030-01-02 10:00:00']));
        $scope = ['notificationtemplates_id' => $template];
        $SQL_TOTAL_REQUEST = 0;
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        verify(array_column($repo()->pending($kind, $mode, $at, 1, $scope), 'id') === [$ids['early']], 'Pending limit and stable timestamp tie order for ' . $kind);
        verify(array_column($repo()->pending($kind, $mode, $at, 0, $scope), 'id') === [$ids['early'], $ids['tie']], 'Strict due date, NULL exclusion, mode and deletion predicates for ' . $kind);
        verify(array_column($repo()->pending($kind, $mode, $at, 20, $scope + ['is_deleted' => true, 'mode' => 'ajax', 'send_time' => ['>', '2031-01-01']]), 'id') === [$ids['early'], $ids['tie']], 'Extra filters cannot override queue eligibility');
        $CFG_GLPI['notifications_modes'] = [];
        $CFG_GLPI['notifications_mailing'] = true;
        $CFG_GLPI['notifications_chat'] = true;
        $CFG_GLPI['notifications_ajax'] = true;
        $pending = $model::getPendings($at->format('Y-m-d H:i:s'), 1, [$mode], $scope);
        verify(array_column($pending[$mode] ?? [], 'id') === [$ids['early']], 'Public pending API honors channel selection');
        verify($model::getPendings($at->format('Y-m-d H:i:s'), 20, ['ajax'], $scope) === [], 'Non-cron channel remains excluded');
        $CFG_GLPI['notifications_' . $mode] = false;
        verify($model::getPendings($at->format('Y-m-d H:i:s'), 20, [$mode], $scope) === [], 'Disabled channel remains excluded');
        verify($SQL_TOTAL_REQUEST === 0, 'Queue selection bypasses adapter SQL');
        // Cleanup only deleted entries strictly older than the cutoff; rollback must restore them.
        $deletedBoundary = $fixtures->create($table, array_replace($base, ['send_time' => $at->format('Y-m-d H:i:s'), 'is_deleted' => true]));
        $deletedMissing = $fixtures->create($table, array_replace($base, ['send_time' => null, 'is_deleted' => true]));
        $connection->beginTransaction();
        verify($repo()->purgeExpired($kind, $at) >= 1 && $read($table, $ids['deleted']) === null, 'Mapped expiry purges old deleted rows');
        verify($read($table, $ids['early']) !== null && $read($table, $deletedBoundary) !== null && $read($table, $deletedMissing) !== null, 'Expiry preserves pending, boundary and NULL dates');
        $connection->rollBack();
        verify($read($table, $ids['deleted']) !== null, 'Queue expiry shares caller transaction');
        $criteria = ['itemtype' => 'Ticket', 'items_id' => $ticket, 'entities_id' => 0, 'notificationtemplates_id' => $template];
        if ($kind === 'notification') {
            $criteria['recipient'] = $DB->escape($base['recipient']);
            $differentRecipient = $fixtures->create($table, array_replace($base, ['recipient' => 'other@example.test', 'send_time' => '2030-01-02 10:00:00']));
        }
        $duplicates = $repo()->duplicateIds($kind, $criteria);
        verify(in_array($ids['early'], $duplicates, true) && !in_array($ids['deleted'], $duplicates, true) && !in_array($other, $duplicates, true), 'Duplicate selection respects pending state and template');
        if ($kind === 'notification') {
            verify(!in_array($differentRecipient, $duplicates, true), 'Email deduplication preserves other recipients');
        }
        $input = $base + ['send_time' => '2030-01-02 10:00:00'];
        if (isset($input['recipient'])) {
            $input['recipient'] = $DB->escape($input['recipient']);
        }
        $replacement = (new $model())->add($input);
        verify($replacement > 0 && $read($table, $ids['early']) === null && $read($table, $replacement) !== null, 'Queue lifecycle replaces pending duplicates without sending');
        verify($read($table, $ids['deleted']) !== null && $read($table, $other) !== null, 'Deduplication preserves history and other templates');
        $retained[$table] = [$replacement, $ids['deleted'], $other];
    }
    verify((new NotificationTemplate())->delete(['id' => $template], true), 'Template purge cancels and releases both queues');
    foreach ($retained as $table => [$pending, $deleted, $other]) {
        foreach ([$pending, $deleted] as $id) {
            $row = $read($table, $id);
            verify($row !== null && $row['is_deleted'] === 1 && $row['notificationtemplates_id'] === null, 'Template purge retains cancelled queue history with no dangling reference');
        }
        verify($read($table, $other)['notificationtemplates_id'] === $otherTemplate, 'Template purge preserves unrelated queued delivery');
        $model = $table === 'glpi_queuednotifications' ? 'QueuedNotification' : 'QueuedChat';
        $right = strtolower($model);
        $oldRights = $_SESSION['glpiactiveprofile'][$right] ?? 0;
        try {
            $_SESSION['glpiactiveprofile'][$right] = ALLSTANDARDRIGHT;
            ob_start();
            (new $model())->showForm($pending);
            $html = ob_get_clean();
            verify(str_contains($html, '<table') && str_contains($html, $table === 'glpi_queuednotifications' ? 'Rendered notification history' : 'Rendered chat history &lt;title&gt;'), 'Retained queue form renders payload with a NULL template');
        } finally {
            $_SESSION['glpiactiveprofile'][$right] = $oldRights;
        }
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Queue template graph remains valid');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $oldConfiguration;
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new QueueTemplateReferences();
$legacy = [];
try {
    foreach (ReferenceHistory::get('optional', 'QUEUE_TEMPLATES') as $table => $relations) {
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
    foreach (array_keys(ReferenceHistory::get('optional', 'QUEUE_TEMPLATES')) as $table) {
        $legacy[$table] = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM ' . $quote($table));
        $connection->insert($table, ['id' => $legacy[$table], 'entities_id' => 0, 'mode' => 'mailing']);
    }
    verify($migration->plan($connection)['sql'] !== [], 'Legacy queue template migration has a plan');
    foreach ($legacy as $table => $id) {
        verify((int)$connection->fetchOne('SELECT notificationtemplates_id FROM ' . $quote($table) . ' WHERE id = ?', [$id]) === 0, 'Plan preserves data');
        $connection->update($table, ['notificationtemplates_id' => 2147483647], ['id' => $id]);
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Nonzero orphaned queue template');
        }
        foreach (array_keys($legacy) as $auditedTable) {
            verify($rejected && $connection->createSchemaManager()->listTableColumns($auditedTable)['notificationtemplates_id']->getNotnull(), 'Either orphan stops all queue template DDL');
        }
        $connection->update($table, ['notificationtemplates_id' => 0], ['id' => $id]);
    }
    $migration->apply($connection);
    foreach ($legacy as $table => $id) {
        verify($connection->fetchOne('SELECT notificationtemplates_id FROM ' . $quote($table) . ' WHERE id = ?', [$id]) === null, 'Legacy queue template defaults become NULL');
    }
    verify($migration->apply($connection) === [], 'Queue template migration is idempotent');
} finally {
    foreach ($legacy as $table => $id) {
        $connection->delete($table, ['id' => $id]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Notification queue selection, cleanup, template ownership and migration passed.\n";
