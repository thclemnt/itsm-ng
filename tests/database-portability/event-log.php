<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Glpi\Event;
use itsmng\Database\Orm;
use itsmng\Database\Repository\EventRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/event-log.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
$connection = $DB->getDoctrineConnection();
verify(Orm::create(DBConnection::getReadConnection())->getConnection() === DBConnection::getReadConnection()->getDoctrineConnection(), 'Supplied read connection retained');
$repository = static fn () => new EventRepository(Orm::create($DB));
$records = static fn () => new RecordRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    // Isolate retention and ordering without changing committed fixture events.
    $connection->executeStatement('DELETE FROM glpi_events');
    $CFG_GLPI['event_loglevel'] = 5;
    $_SESSION['glpilist_limit'] = 2;
    // Native MySQL TIMESTAMP and MariaDB before 11.5 stop at January 2038.
    // Keep this ahead of the database clock within every supported provider's range.
    $_SESSION['glpi_currenttime'] = '2030-01-01 10:00:00';
    $message = "O'Reilly \\network\\tab 日本語\nSecond line";
    $first = Event::log(99999999, 'devices', 4, 'NULL', $message);
    verify($first > 0, 'Public log insert');
    $row = $records()->find('glpi_events', 'id', $first);
    verify($row['message'] === $message && $row['service'] === 'NULL' && (int)$row['items_id'] === 99999999, 'Raw strings and historical IDs survive');
    $literalNull = Event::log(0, 'system', 4, 'setup', 'NULL');
    verify($records()->find('glpi_events', 'id', $literalNull)['message'] === 'NULL', 'Literal NULL is text');
    verify(Event::log(0, 'system', 6, 'setup', 'Filtered event') === false && $repository()->count() === 2, 'Log level gate');
    $legacy = (new Event())->add(['type' => 'system', 'date' => '2030-01-01 10:00:00', 'level' => 4, 'message' => Toolbox::addslashes_deep($message)]);
    verify($records()->find('glpi_events', 'id', $legacy)['message'] === $message, 'Public legacy add decodes once');
    $savedFileLogging = $CFG_GLPI['use_log_in_files'];
    try {
        $CFG_GLPI['use_log_in_files'] = true;
        $fileMessage = 'Event file probe ' . bin2hex(random_bytes(6)) . ' \\literal\\path';
        verify(Event::log(0, 'system', 3, 'setup', $fileMessage) > 0, 'Important event accepted');
        verify(str_contains(file_get_contents(GLPI_LOG_DIR . '/event.log'), $fileMessage . "\n"), 'File log hook retains raw backslashes');
    } finally {
        $CFG_GLPI['use_log_in_files'] = $savedFileLogging;
    }
    $name = "fixture_%!\\user";
    $userEvent = Event::log(0, 'system', 4, 'login', $name . ' logged in');
    Event::log(0, 'system', 4, 'login', 'fixture_xy!\\user unrelated');
    $selected = $repository()->page(0, 10, user: $name);
    verify(array_column($selected, 'id') === [$userEvent], 'Literal username wildcard/backslash scope');
    verify(array_column($repository()->page(0, 10, user: strtoupper($name)), 'id') === [$userEvent], 'Existing case-insensitive user search');
    ob_start();
    Event::showForUser($name);
    $html = ob_get_clean();
    verify(str_contains($html, $name . ' logged in') && !str_contains($html, 'unrelated'), 'Recent user events render');
    ob_start();
    Event::showForUser('missing-event-user');
    $html = ob_get_clean();
    verify(str_contains($html, __('No Event')), 'Empty recent list');
    $null = $repository()->append(['type' => null, 'date' => null, 'service' => null, 'message' => null]);
    foreach (['type', 'items_id', 'date', 'service', 'level', 'message', 'invalid sort'] as $sort) {
        foreach (['ASC', 'DESC'] as $direction) {
            $all = $repository()->page(0, 100, $sort, $direction);
            $pages = [];
            for ($offset = 0; $offset < count($all); $offset += 2) {
                array_push($pages, ...array_column($repository()->page($offset, 2, $sort, $direction), 'id'));
            }
            verify($pages === array_column($all, 'id') && count(array_unique($pages)) === $repository()->count(), 'Stable event pages: ' . $sort . ' ' . $direction);
            if (in_array($sort, ['type', 'date', 'service', 'message'], true)) {
                verify(($direction === 'ASC' ? $all[0][$sort] : $all[count($all) - 1][$sort]) === null, 'Portable NULL ordering');
            }
        }
    }
    verify($repository()->page(0, 0) === [], 'Zero limit');
    // Warm metadata and UI helpers, then count adapter SQL across converted reads/writes.
    ob_start();
    Event::showList('/front/event.php');
    ob_end_clean();
    $DB->listFields('glpi_events');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Event::log(0, 'system', 4, 'setup', 'ORM request probe');
    ob_start();
    Event::showList('/front/event.php', 'ASC', 'date', 0);
    Event::showForUser($name);
    $html = ob_get_clean();
    verify(str_contains($html, 'sortable Table'), 'Paginated event table renders');
    verify($SQL_TOTAL_REQUEST === 0, 'Event reads/writes bypass adapter SQL');
    // DB clock controls retention, independent of the application's future session clock.
    $now = new DateTimeImmutable((string)$connection->fetchOne('SELECT CURRENT_TIMESTAMP'));
    $old = $repository()->append(['date' => $now->modify('-3 days'), 'message' => 'Expired']);
    $recent = $repository()->append(['date' => $now->modify('-1 day'), 'message' => 'Recent']);
    verify(Event::cleanOld(2) === 1 && $records()->find('glpi_events', 'id', $old) === null, 'Retention deletes and reports old row');
    verify($records()->find('glpi_events', 'id', $recent) !== null && $records()->find('glpi_events', 'id', $null) !== null, 'Recent and NULL date survive retention');
    verify($SQL_TOTAL_REQUEST === 0, 'Retention bypasses adapter SQL');
    // PostgreSQL CURRENT_TIMESTAMP is transaction-stable; MySQL timestamp is statement-stable.
    if ($DB->getProvider() === 'pgsql') {
        $boundary = $repository()->append(['date' => $now->modify('-2 days'), 'message' => 'Boundary']);
        verify(Event::cleanOld(2) === 0 && $records()->find('glpi_events', 'id', $boundary) !== null, 'Strict retention boundary');
    }
    $connection->beginTransaction();
    $repository()->deleteOlderThan(-100 * DAY_TIMESTAMP);
    $connection->rollBack();
    verify($records()->find('glpi_events', 'id', $recent) !== null, 'Retention follows supplied transaction');
    echo $DB->getProvider() . ": mapped event logging, literal user scope, stable pages and database-clock retention passed.\n";
} finally {
    $DB->rollback();
}
