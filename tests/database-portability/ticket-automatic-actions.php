<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity as Record;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\TicketAutomaticActionRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/ticket-automatic-actions.php /path/to/test-config\n");
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
class TicketCronProbe extends CronTask
{
    public int $processed = 0;
    public array $messages = [];

    public function addVolume($volume)
    {
        $this->processed += $volume;
    }

    public function log($content)
    {
        $this->messages[] = $content;
        return true;
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$originalConfiguration = $CFG_GLPI;
$originalTimezone = date_default_timezone_get();
$DB->setTimezone('Europe/Paris');
$CFG_GLPI['use_notifications'] = false;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $write = static fn (string $table, int $id, array $values) => (new RecordWriter(Orm::create($DB)))->update($table, $id, $values);
    $read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $repo = new TicketAutomaticActionRepository(Orm::create($DB));
    $entity = $fixtures->create('glpi_entities', ['id' => 4294968201, 'name' => 'Ticket boundaries', 'max_closedate' => '2030-03-31 00:30:00']);
    $foreign = $fixtures->create('glpi_entities', ['id' => 4294968202, 'name' => 'Other entity']);
    $new = static fn (array $values = []): int => $fixtures->create('glpi_tickets', $values + [
        'entities_id' => $entity, 'name' => "Automatic O'Reilly", 'status' => $_SESSION['SOLVED'],
        'date' => '2030-03-31 00:29:59', 'solvedate' => '2030-03-31 00:29:59', 'closedate' => null,
    ]);
    $now = new DateTimeImmutable('2030-04-01 00:30:00');
    $sorted = static function (array $ids): array {
        sort($ids, SORT_NUMERIC);
        return $ids;
    };
    $early = $new(['id' => 4294968210]);
    $exact = $new(['solvedate' => '2030-03-31 00:30:00']);
    $late = $new(['solvedate' => '2030-03-31 00:30:01']);
    $noDate = $new(['solvedate' => null]);
    $deleted = $new(['is_deleted' => true]);
    $other = $new(['entities_id' => $foreign]);
    $closed = $new(['status' => $_SESSION['CLOSED'], 'closedate' => '2030-03-31 00:29:59']);
    $closedExact = $new(['status' => $_SESSION['CLOSED'], 'closedate' => '2030-03-31 00:30:00']);
    $closedDeleted = $new(['status' => $_SESSION['CLOSED'], 'closedate' => '2030-03-31 00:29:59', 'is_deleted' => true]);
    $closedNull = $new(['status' => $_SESSION['CLOSED']]);
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    verify($repo->closeCandidates($entity, $_SESSION['SOLVED'], 1, now: $now) === [$early], 'Close uses strict calendar-day delay across the spring clock change');
    verify($repo->closeCandidates($entity, $_SESSION['SOLVED'], 1, new DateTimeImmutable('2030-03-31 00:30:00'), $now) === $sorted([$early, $exact]), 'Working-calendar cutoff is inclusive');
    verify($repo->closeCandidates($entity, $_SESSION['SOLVED'], 0, now: $now) === $sorted([$early, $exact, $late, $noDate]), 'Zero close delay keeps undated active solved tickets and entity isolation');
    verify($repo->purgeCandidates($entity, [$_SESSION['CLOSED']], 1, $now) === [$closed, $closedDeleted], 'Purge has a strict delay and includes soft-deleted tickets');
    verify($repo->purgeCandidates($entity, [$_SESSION['CLOSED']], 0, $now) === [$closed, $closedExact, $closedDeleted, $closedNull], 'Zero purge delay includes null closure dates');
    verify($repo->purgeCandidates($entity, [], 0, $now) === [], 'Empty closed-status list selects nothing');
    $incoming = $new(['status' => $_SESSION['INCOMING']]);
    $waiting = $new(['status' => $_SESSION['WAITING']]);
    $new(['status' => $_SESSION['INCOMING'], 'date' => '2030-03-31 00:30:00']);
    $new(['status' => $_SESSION['INCOMING'], 'date' => null]);
    $new(['status' => $_SESSION['INCOMING'], 'is_deleted' => true]);
    $new(['status' => $_SESSION['INCOMING'], 'closedate' => '2030-03-31 00:29:59']);
    $new(['status' => $_SESSION['INCOMING'], 'entities_id' => $foreign]);
    $rows = $repo->overdue($entity, [$_SESSION['INCOMING'], $_SESSION['WAITING']], 1, $now);
    verify(array_column($rows, 'id') === [$incoming, $waiting], 'Overdue excludes closed, deleted, undated, boundary and foreign-entity rows');
    verify($rows[0]['name'] === "Automatic O'Reilly" && $rows[0]['entities_id'] === $entity && $rows[0]['date'] === '2030-03-31 00:29:59' && $rows[0]['closedate'] === null, 'Notification rows retain full scalar data, local dates and wide entity identifiers');
    verify($repo->overdue($entity, [], 1, $now) === [], 'Empty overdue statuses select nothing');
    $after = new DateTimeImmutable('2030-03-30 00:30:00');
    $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $closed]);
    $surveyEarly = $new(['status' => $_SESSION['CLOSED'], 'closedate' => '2030-03-30 23:00:00']);
    $new(['status' => $_SESSION['CLOSED'], 'closedate' => '2030-03-30 00:30:00']);
    $new(['status' => $_SESSION['CLOSED'], 'closedate' => '2030-03-31 00:30:01']);
    $rows = $repo->surveyCandidates($entity, $_SESSION['CLOSED'], $after, 1, 1, $now);
    verify(array_column($rows, 'id') === [$surveyEarly, $closedExact], 'Survey closure delay and own-entity duration gate are inclusive; watermark is strict and existing surveys excluded');
    verify($rows[1]['closedate'] === '2030-03-31 00:30:00' && $rows[1]['entities_id'] === $entity, 'Survey scalar dates and identifiers preserve the public contract');
    verify($repo->surveyCandidates($entity, $_SESSION['CLOSED'], null, 0, 0, $now) === [], 'Null inherited watermark selects nothing');
    $write('glpi_entities', $entity, ['max_closedate' => '2030-03-31 00:30:01']);
    verify($repo->surveyCandidates($entity, $_SESSION['CLOSED'], $after, 1, 1, $now) === [], 'Own entity duration gate remains separate from inherited selection watermark');
    $write('glpi_entities', $entity, ['max_closedate' => null]);
    verify($repo->surveyCandidates($entity, $_SESSION['CLOSED'], $after, 0, 0, $now) === [], 'Null own entity duration gate selects nothing');
    verify($SQL_TOTAL_REQUEST === 0, 'All candidate reads and fixture updates use ORM without adapter SQL');

    // Isolate public callbacks from seeded entities and the future boundary fixtures.
    Orm::create($DB)->createQueryBuilder()->update(Record\Entity::class, 'e')
        ->set('e.autoclose_delay', ':never')->set('e.autopurge_delay', ':never')->set('e.notclosed_delay', ':never')
        ->set('e.inquest_config', ':explicit')->set('e.inquest_rate', ':zero')
        ->setParameter('never', Entity::CONFIG_NEVER)->setParameter('explicit', 1)->setParameter('zero', 0)->getQuery()->execute();
    $parent = $fixtures->create('glpi_entities', ['name' => 'Action parent', 'level' => 1, 'autoclose_delay' => 0, 'autopurge_delay' => Entity::CONFIG_NEVER, 'notclosed_delay' => Entity::CONFIG_NEVER, 'inquest_config' => 1]);
    $child = $fixtures->create('glpi_entities', ['name' => 'Action child', 'entities_id' => $parent, 'level' => 2, 'autopurge_delay' => Entity::CONFIG_PARENT]);
    $publicDate = (new DateTimeImmutable())->modify('-3 days')->format('Y-m-d H:i:s');
    $publicSolution = (new DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s');
    $publicTicket = static fn (int $owner, array $values = []): int => $new($values + ['entities_id' => $owner, 'date' => $publicDate, 'solvedate' => $publicSolution]);
    $parentSolved = $publicTicket($parent);
    $childSolved = $publicTicket($child);
    $childDeleted = $publicTicket($child, ['is_deleted' => true]);
    $task = new TicketCronProbe();
    verify(Ticket::cronCloseTicket($task) === 1 && $task->processed === 2 && count($task->messages) === 2, 'Public close resolves inherited zero delay and accounts by entity');
    foreach ([$parentSolved, $childSolved] as $id) {
        $row = $read('glpi_tickets', $id);
        verify($row['status'] === $_SESSION['CLOSED'] && $row['closedate'] !== null, 'Public close retains model status/date hooks');
    }
    verify($read('glpi_tickets', $childDeleted)['status'] === $_SESSION['SOLVED'], 'Public close leaves deleted solved tickets untouched');
    $calendar = $fixtures->create('glpi_calendars', ['name' => 'Every working day']);
    foreach (range(0, 6) as $day) {
        $fixtures->create('glpi_calendarsegments', ['calendars_id' => $calendar, 'day' => $day, 'begin' => '00:00:00', 'end' => '24:00:00']);
    }
    $write('glpi_entities', $parent, ['autoclose_delay' => 1, 'calendars_id' => $calendar, 'calendar_mode' => 'explicit']);
    $calendarSolved = $publicTicket($child);
    // Keep future native TIMESTAMP fixtures below MySQL's January 2038 limit.
    $futureSolved = $publicTicket($child, ['solvedate' => '2037-01-01 00:00:00']);
    $working = new Calendar();
    verify($working->getFromDB($calendar) && $working->hasAWorkingDay(), 'Real working calendar fixture');
    $task = new TicketCronProbe();
    verify(Ticket::cronCloseTicket($task) === 1 && $task->processed === 1 && $read('glpi_tickets', $calendarSolved)['status'] === $_SESSION['CLOSED'], 'Public close uses inherited working calendar');
    verify($read('glpi_tickets', $futureSolved)['status'] === $_SESSION['SOLVED'], 'Future solution stays open');
    $emptyCalendar = $fixtures->create('glpi_calendars', ['name' => 'No working days']);
    $write('glpi_entities', $parent, ['calendars_id' => $emptyCalendar, 'calendar_mode' => 'explicit']);
    $fallbackSolved = $publicTicket($child);
    $task = new TicketCronProbe();
    verify(Ticket::cronCloseTicket($task) === 1 && $task->processed === 1 && $read('glpi_tickets', $fallbackSolved)['status'] === $_SESSION['CLOSED'], 'Empty working calendar falls back to elapsed days');

    $write('glpi_entities', $parent, ['autopurge_delay' => 0, 'autoclose_delay' => Entity::CONFIG_NEVER]);
    $purgeDeleted = $publicTicket($child, ['status' => $_SESSION['CLOSED'], 'is_deleted' => true, 'closedate' => '2000-01-03 00:00:00']);
    $satisfaction = $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $parentSolved]);
    $lock = $fixtures->create('glpi_objectlocks', ['itemtype' => 'Ticket', 'items_id' => $parentSolved, 'users_id' => Session::getLoginUserID()]);
    $task = new TicketCronProbe();
    verify(Ticket::cronPurgeTicket($task) === 1 && $task->processed === 5, 'Public purge includes inherited policy and soft-deleted closed tickets');
    foreach ([$parentSolved, $childSolved, $calendarSolved, $fallbackSolved, $purgeDeleted] as $id) {
        verify($read('glpi_tickets', $id) === null, 'Public purge removed selected ticket');
    }
    verify($read('glpi_ticketsatisfactions', $satisfaction) === null && $read('glpi_objectlocks', $lock) === null, 'Public purge retains survey and lock cleanup before constrained ticket deletion');
    verify($read('glpi_tickets', $futureSolved) !== null, 'Public purge retains solved tickets');
    verify(Ticket::cronPurgeTicket(new TicketCronProbe()) === 0, 'Repeated purge has no candidates');

    $write('glpi_entities', $parent, ['notclosed_delay' => 1]);
    $publicTicket($parent, ['status' => $_SESSION['INCOMING']]);
    $publicTicket($child, ['status' => $_SESSION['WAITING']]);
    $publicTicket($child, ['status' => $_SESSION['INCOMING'], 'date' => '2037-01-01 00:00:00']);
    verify(Ticket::cronAlertNotClosed(new TicketCronProbe()) === 0, 'Notifications-disabled action returns without delivery');
    $CFG_GLPI['use_notifications'] = true;
    foreach (array_keys(Notification_NotificationTemplate::getModes()) as $mode) {
        $CFG_GLPI['notifications_' . $mode] = false;
    }
    $task = new TicketCronProbe();
    verify(Ticket::cronAlertNotClosed($task) === 1 && $task->processed === 2 && count($task->messages) === 2, 'Public overdue action uses inherited delay and preserves event/accounting without active delivery modes');
    $CFG_GLPI['use_notifications'] = false;

    $write('glpi_entities', $parent, ['inquest_config' => 1, 'inquest_rate' => 100, 'inquest_delay' => 0, 'inquest_duration' => 0, 'max_closedate' => '2000-01-01 00:00:00']);
    $write('glpi_entities', $child, ['max_closedate' => '2000-01-01 00:00:00']);
    $surveyParent = $publicTicket($parent, ['status' => $_SESSION['CLOSED'], 'closedate' => '2000-01-04 00:00:00']);
    $surveyChild = $publicTicket($child, ['status' => $_SESSION['CLOSED'], 'closedate' => '2000-01-05 00:00:00']);
    $surveyExisting = $publicTicket($child, ['status' => $_SESSION['CLOSED'], 'closedate' => '2000-01-06 00:00:00']);
    $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $surveyExisting]);
    $task = new TicketCronProbe();
    verify(Ticket::cronCreateInquest($task) === 1 && $task->processed === 2 && count($task->messages) === 2, 'Public survey action resolves inherited settings and excludes an existing survey');
    foreach ([$surveyParent, $surveyChild] as $id) {
        $rows = (new RecordRepository(Orm::create($DB)))->matching('glpi_ticketsatisfactions', ['tickets_id' => $id]);
        verify(count($rows) === 1 && $rows[0]['type'] === 1 && $rows[0]['date_begin'] !== null, 'Public survey insertion retains model initialization');
    }
    verify($read('glpi_entities', 0)['max_closedate'] === '2000-01-05 00:00:00', 'Survey preserves existing inherited-parent watermark routing');
    verify(Ticket::cronCreateInquest(new TicketCronProbe()) === 0, 'Repeated survey action does not duplicate existing surveys');
    // A nonselected sample still advances the examined-candidate watermark.
    $write('glpi_entities', $parent, ['inquest_rate' => 1]);
    $sample = $publicTicket($parent, ['status' => $_SESSION['CLOSED'], 'closedate' => '2000-01-07 00:00:00']);
    mt_srand(1234);
    verify(mt_rand(1, 100) > 1, 'Deterministic unsampled fixture');
    mt_srand(1234);
    verify(Ticket::cronCreateInquest(new TicketCronProbe()) === 0 && $read('glpi_entities', 0)['max_closedate'] === '2000-01-07 00:00:00', 'Unsampled candidates advance the watermark without reporting created surveys');
    verify((new RecordRepository(Orm::create($DB)))->matching('glpi_ticketsatisfactions', ['tickets_id' => $sample]) === [], 'Unsampled ticket has no survey');
    $write('glpi_entities', $parent, ['inquest_rate' => 0]);
    verify(Ticket::cronCreateInquest(new TicketCronProbe()) === 0, 'Zero survey rate disables inherited survey generation');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $originalConfiguration;
    $DB->setTimezone($originalTimezone);
}
echo "PASS: ticket automatic action boundaries, inheritance, model hooks, purge cleanup and survey sampling\n";
