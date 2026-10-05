<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/browser-notification-inbox.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';

use itsmng\Database\Entity\QueuedNotification as Message;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationQueueRepository;
use itsmng\Domain\BrowserNotificationInbox;

$assertions = 0;
$currentReadCases = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_USER_WARNING);
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'A disposable database is required');
$writer = $DB;
$connection = $writer->getDoctrineConnection();
$configuration = $CFG_GLPI;
$session = $_SESSION;
$level = $connection->getTransactionNestingLevel();
$before = $connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id');
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($writer);
    $user = $fixtures->create('glpi_users', ['name' => 'Browser inbox owner']);
    $other = $fixtures->create('glpi_users', ['name' => 'Other browser inbox owner']);
    $entity = $fixtures->create('glpi_entities', ['name' => 'Inbox non-active entity']);
    $_SESSION['glpiID'] = $user;
    $_SESSION['glpiactiveentities'] = [0];
    $CFG_GLPI['notifications_ajax'] = true;
    $queue = static fn (array $extra): int => $fixtures->create('glpi_queuednotifications', $extra + [
        'mode' => 'ajax', 'recipient' => (string)$user, 'entities_id' => $entity,
        'itemtype' => 'NotificationAjax', 'name' => "A browser 'title' 日本語", 'body_text' => "Body \\ literal",
        // Frozen MySQL queue DDL is native TIMESTAMP, whose older-server range ends in 2038.
        'send_time' => '2037-01-01 00:00:00', 'sent_try' => 4,
    ]);
    $mine = $queue([]);
    $duplicate = $queue([]);
    $theirs = $queue(['recipient' => (string)$other]);
    $mail = $queue(['mode' => 'mailing']);
    $nonCanonicalMode = $queue(['mode' => 'AJAX']);
    $paddedMode = $queue(['mode' => 'ajax ']);
    $paddedRecipient = $queue(['recipient' => $user . ' ']);
    $deleted = $queue(['is_deleted' => true, 'sent_time' => '2010-01-01 00:00:00']);
    $nullRecipient = $queue(['recipient' => null]);
    $plugin = $queue(['itemtype' => 'PluginInboxSubject', 'items_id' => 73]);
    $unknown = $queue(['itemtype' => 'UnknownInboxSubject', 'items_id' => 91]);
    $nullKind = $queue(['itemtype' => null]);
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Browser link ticket', 'entities_id' => $entity]);
    $linked = $queue(['itemtype' => 'Ticket', 'items_id' => $ticket]);
    $expected = [$mine, $duplicate, $plugin, $unknown, $nullKind, $linked];
    $inbox = new BrowserNotificationInbox($writer);
    verify(array_map(static fn (Message $message): int => $message->id, $inbox->pending($user)) === $expected,
        'Recipient and channel isolate physical duplicates, independent of entity or scheduled mail time');
    verify($inbox->pending(0) === [] && $inbox->pending(-1) === [], 'Anonymous/invalid recipients fail closed');
    $rows = NotificationAjax::getMyNotifications();
    verify(array_column($rows, 'id') === $expected && $rows[0]['title'] === "A browser 'title' 日本語"
        && $rows[0]['body'] === "Body \\ literal", 'Public inbox preserves rendered bytes and each physical message');
    verify($rows[0]['url'] === null && $rows[3]['url'] === null && $rows[4]['url'] === null,
        'Test, unknown and null subjects have no fabricated link');
    verify($rows[2]['url'] === '/plugin/inbox?id=73' && str_ends_with($rows[5]['url'], '/ticket.form.php?id=' . $ticket),
        'Existing plugin and core public form links retain their rendered subject');
    $CFG_GLPI['notifications_ajax'] = false;
    verify(NotificationAjax::getMyNotifications() === false, 'Disabled polling retains the public false result');
    $CFG_GLPI['notifications_ajax'] = true;

    $native = static fn (int $id): array => $connection->fetchAssociative('SELECT * FROM glpi_queuednotifications WHERE id = ?', [$id]);
    foreach ([$theirs, $mail, $nonCanonicalMode, $paddedMode, $paddedRecipient, $deleted, $nullRecipient] as $denied) {
        $snapshot = $native($denied);
        verify(!$inbox->acknowledge($denied, $user) && $native($denied) === $snapshot,
            'Other/noncanonical recipient or channel, already deleted and NULL recipient remain byte-for-byte unchanged');
    }
    verify(!$inbox->acknowledge(0, $user) && !$inbox->acknowledge($mine, 0), 'Invalid acknowledgement fails closed');
    $snapshot = $native($mine);
    foreach ([true, [], $mine . 'suffix', '9999999999999999999999999999999999'] as $invalid) {
        NotificationAjax::raisedNotification($invalid);
        verify($native($mine) === $snapshot, 'Public acknowledgement rejects lossy identifier coercion');
    }
    // A replica-designated clone shares this test connection; this is routing-policy evidence, not a live replica test.
    $read = clone $writer;
    $read->slave = true;
    $DB = $read;
    verify(array_column(NotificationAjax::getMyNotifications(), 'id') === $expected, 'Polling accepts its supplied read adapter');
    try {
        NotificationAjax::raisedNotification($mine);
        verify(false, 'Replica-designated acknowledgement must refuse');
    } catch (LogicException $error) {
        verify(str_contains($error->getMessage(), 'supplied writer') && $native($mine) === $snapshot, 'Replica refusal precedes writes');
    }
    verify($inbox->acknowledge($mine, $user), 'An already captured writer remains owned when the global adapter changes');
    $DB = $writer;
    $acknowledged = $native($mine);
    $unchanged = $acknowledged;
    $unchanged['is_deleted'] = $snapshot['is_deleted'];
    $unchanged['sent_time'] = $snapshot['sent_time'];
    verify($unchanged === $snapshot && $acknowledged['sent_time'] !== null && (bool)$acknowledged['is_deleted'],
        'Acknowledgement changes only presentation time and deletion flag; scheduling, body, scope and retries survive');
    verify(!$inbox->acknowledge($mine, $user) && $native($mine) === $acknowledged, 'Repeated delivery retains the original presentation record');
    verify(in_array($duplicate, array_column(NotificationAjax::getMyNotifications(), 'id'), true), 'One acknowledgement does not consume its duplicate');

    if ($writer->getProvider() === 'mysql' && MySQLConnection::snapshotIsolation($connection) !== null) {
        $originalCapability = MySQLConnection::snapshotIsolation($connection);
        $globalCapability = $connection->fetchAllNumeric("SHOW GLOBAL VARIABLES WHERE Variable_name = 'innodb_snapshot_isolation'");
        $scope = $connection->captureManagedTransactionScope();
        $depth = $connection->getTransactionNestingLevel();
        $snapshot = $native($duplicate);
        $capturedInbox = new BrowserNotificationInbox($writer);
        // Model a caller callback changing its own physical session after admission.
        // No GLOBAL change, provider-name/version guess or hidden repair is permitted.
        $callback = static fn () => $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
        $callback();
        try {
            foreach ([
                static fn () => (new NotificationQueueRepository(Orm::create($writer)))->browserMessageForAcknowledgement($duplicate, $user),
                static fn () => $capturedInbox->acknowledge($duplicate, $user),
            ] as $attempt) {
                try {
                    $attempt();
                    verify(false, 'An incompatible caller-selected snapshot mode must refuse before locking or acknowledgement');
                } catch (CurrentReadUnavailable $error) {
                    $scope->assertActive();
                    verify($connection->getTransactionNestingLevel() === $depth && $connection->getNativeConnection()->inTransaction()
                        && MySQLConnection::snapshotIsolation($connection) === true && $native($duplicate) === $snapshot,
                        'Direct locking read and nested command refuse without changing caller depth, physical frame, session mode or payload');
                    ++$currentReadCases;
                }
            }
        } finally {
            $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ?', [(int)$originalCapability], [\Doctrine\DBAL\ParameterType::INTEGER]);
        }
        $scope->assertActive();
        $connection->beginTransaction();
        verify($capturedInbox->acknowledge($duplicate, $user) && (bool)$native($duplicate)['is_deleted'],
            'The same command retries through the captured writer after the caller explicitly restores its session');
        $connection->rollBack();
        $scope->assertActive();
        verify($native($duplicate) === $snapshot && MySQLConnection::snapshotIsolation($connection) === $originalCapability,
            'Retry rollback restores every payload cell and preserves the original caller-selected capability');
        verify($connection->fetchAllNumeric("SHOW GLOBAL VARIABLES WHERE Variable_name = 'innodb_snapshot_isolation'") === $globalCapability,
            'The shared server capability/default remains unchanged');
        ++$currentReadCases;
    }

    $connection->beginTransaction();
    NotificationAjax::raisedNotification((string)$duplicate);
    verify((bool)$native($duplicate)['is_deleted'], 'Public acknowledgement participates in the caller savepoint');
    $connection->rollBack();
    verify(!(bool)$native($duplicate)['is_deleted'], 'Caller rollback restores the pending message');
    $record = new Message();
    $record->recipient = (string)$user;
    $record->mode = 'ajax';
    $first = new DateTimeImmutable('2001-01-01 00:00:00');
    verify($record->acknowledgeBrowserMessage($user, $first)
        && $record->sent_time instanceof DateTime
        && $record->sent_time->format('Y-m-d H:i:s.uP') === $first->format('Y-m-d H:i:s.uP'),
        'Entity copies its immutable clock into the native mapped mutable timestamp without changing the instant');
    $presentation = $record->sent_time;
    verify(!$record->acknowledgeBrowserMessage($user, new DateTimeImmutable('2002-01-01 00:00:00'))
        && $record->sent_time === $presentation && $record->sent_try === 0,
        'Entity transition is independently idempotent across different clock instants');
} finally {
    while ($connection->getTransactionNestingLevel() > $level) {
        $connection->rollBack();
    }
    $DB = $writer;
    $CFG_GLPI = $configuration;
    $_SESSION = $session;
    restore_error_handler();
}
verify($connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id') === $before,
    'All pre-existing queue rows and payloads survive the contract rollback');
printf("PASS: %d browser inbox assertions; %d available current-read capability cases\n", $assertions, $currentReadCases);

class PluginInboxSubject
{
    public static function getFormURL($full = true): string
    {
        return '/plugin/inbox';
    }
}
