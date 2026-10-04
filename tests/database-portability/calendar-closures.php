<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use itsmng\Database\Entity\Holiday as HolidayPeriod;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\CalendarRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;
use itsmng\Database\TransactionOwnership;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/calendar-closures.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$writer = $DB;
$connection = $writer->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Owned contract starts outside caller transactions');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before Calendar lifecycle tests');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$reader = null;
$outerFrame = null;
$primary = null;
$secondary = [];
$recordFailure = static function (Throwable $error, string $label) use (&$primary, &$secondary): void {
    if ($primary === null) {
        $primary = $error;
    } elseif ($primary !== $error) {
        $secondary[] = ['label' => $label, 'class' => $error::class];
    }
};
$cleanup = static function (callable $operation, string $label) use ($recordFailure): bool {
    try {
        $operation();
        return true;
    } catch (Throwable $error) {
        $recordFailure($error, $label);
        return false;
    }
};
$rollbackScope = static function (callable $operation, string $label) use ($connection, $recordFailure): void {
    $frame = OwnedMutationFrame::begin($connection);
    $failure = null;
    try {
        $operation();
    } catch (Throwable $error) {
        $failure = $error;
        $recordFailure($error, $label);
    }
    try {
        $frame->rollBack();
    } catch (Throwable $error) {
        $failure ??= $error;
        $recordFailure($error, $label . '-rollback');
    }
    if ($failure !== null) {
        throw $failure;
    }
};
$rows = static fn (string $table, array $criteria = []): array => (new RecordRepository(Orm::create($writer)))->matching($table, $criteria, 'id ASC');
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($writer)))->find($table, 'id', $id);
$closures = static fn (int $id): array => (new CalendarRepository(Orm::create($writer)))->closures($id);
$originalLedger = $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version');
$originalLogs = $rows('glpi_logs');
$originalQueue = $rows('glpi_queuednotifications');
$prefix = 'Calendar closures ' . bin2hex(random_bytes(6));
$add = static function (CommonDBTM $model, array $input): int {
    $id = $model->add($input);
    verify(is_int($id) && $id > 0, 'Real public ' . $model->getType() . ' add');
    return $id;
};
$holiday = static fn (string $label, ?string $begin, ?string $end, bool $annual = false): int => $add(new Holiday(), [
    'name' => $prefix . ' ' . $label, 'entities_id' => 0, 'is_recursive' => true,
    'begin_date' => $begin, 'end_date' => $end, 'is_perpetual' => $annual,
]);
$link = static fn (int $calendar, int $holiday): int => $add(new Calendar_Holiday(), ['calendars_id' => $calendar, 'holidays_id' => $holiday]);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
try {
    $outerFrame = OwnedMutationFrame::begin($connection);
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
    $_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
    $CFG_GLPI['use_notifications'] = false;
    $plugins->setValue(null, [...$savedPlugins, 'calendar_closures_fixture']);
    $calendarId = $add(new Calendar(), ['name' => $prefix, 'entities_id' => 0, 'is_recursive' => true]);
    $calendar = new Calendar();
    verify($calendar->getFromDB($calendarId), 'Load the actual public Calendar once');
    foreach ([1, 2] as $day) {
        $add(new CalendarSegment(), ['calendars_id' => $calendarId, 'day' => $day, 'begin' => '09:00:00', 'end' => '17:00:00']);
    }
    verify(!$calendar->isHoliday('2030-05-06'), 'Warm the original open date before any membership exists');
    verify($calendar->computeEndDate('2030-05-06 09:00:00', 3600) === '2030-05-06 10:00:00', 'Public scheduling starts with the actual Monday segment');
    $may = $holiday('May', '2030-05-06', '2030-05-06');
    $relationId = $link($calendarId, $may);
    verify($calendar->isHoliday('2030-05-06 16:30:00'), 'Public add changes the already-warmed date without cache clear or Calendar reload');
    verify($calendar->computeEndDate('2030-05-06 09:00:00', 3600) === '2030-05-07 10:00:00', 'Public scheduling skips the newly closed day');
    verify($rows('glpi_logs', ['itemtype' => 'Calendar', 'items_id' => $calendarId, 'linked_action' => Log::HISTORY_ADD_RELATION]) !== [], 'Public membership add retains Calendar relation audit history');
    $mayModel = new Holiday();
    verify($mayModel->update(['id' => $may, 'begin_date' => '2030-05-07', 'end_date' => '2030-05-07']), 'Public Holiday update');
    verify(!$calendar->isHoliday('2030-05-06') && $calendar->isHoliday('2030-05-07'), 'Updated dates are effective on the same Calendar instance');
    verify((new Calendar_Holiday())->delete(['id' => $relationId], true), 'Public membership purge');
    verify(!$calendar->isHoliday('2030-05-07') && $read('glpi_holidays', $may) !== null, 'Removing membership reopens its warmed date and preserves the reusable Holiday');

    $rollbackScope(static function () use ($link, $calendarId, $may, $calendar, $rollbackScope, $mayModel): void {
        $link($calendarId, $may);
        verify($calendar->isHoliday('2030-05-07'), 'Caller savepoint sees its own membership');
        $rollbackScope(static function () use ($mayModel, $may, $calendar): void {
            verify($mayModel->update(['id' => $may, 'begin_date' => '2030-05-08', 'end_date' => '2030-05-08']), 'Nested caller changes closure dates');
            verify(!$calendar->isHoliday('2030-05-07') && $calendar->isHoliday('2030-05-08'), 'Nested date mutation is immediately visible');
        }, 'nested-date-mutation');
        verify($calendar->isHoliday('2030-05-07') && !$calendar->isHoliday('2030-05-08'), 'Nested rollback restores actual dates without resetting application caches');
    }, 'membership-savepoint');
    verify(!$calendar->isHoliday('2030-05-07') && $closures($calendarId) === [], 'Outer savepoint rollback restores the open date and membership list');

    $vetoes = 0;
    $vetoRows = $rows('glpi_calendars_holidays');
    $vetoLogs = $rows('glpi_logs');
    $vetoQueue = $rows('glpi_queuednotifications');
    $PLUGIN_HOOKS['pre_item_add']['calendar_closures_fixture'][Calendar_Holiday::class] = static function (Calendar_Holiday $item) use (&$vetoes): void {
        ++$vetoes;
        $item->input = false;
    };
    verify((new Calendar_Holiday())->add(['calendars_id' => $calendarId, 'holidays_id' => $may]) === false && $vetoes === 1, 'Actual public relation hook can refuse the otherwise valid owning membership');
    verify($rows('glpi_calendars_holidays') === $vetoRows && $rows('glpi_logs') === $vetoLogs && $rows('glpi_queuednotifications') === $vetoQueue && !$calendar->isHoliday('2030-05-07'), 'Hook refusal preserves owning rows, audit, queue and the warmed open date');
    unset($PLUGIN_HOOKS['pre_item_add']['calendar_closures_fixture']);

    $cases = [
        ['same name', '2030-05-01', '2030-05-02', false, ['2030-05-01', '2030-05-02 23:59:59'], ['2030-04-30', '2030-05-03', '2031-05-01']],
        ['same name', '2020-12-24', '2021-01-02', true, ['2030-12-24', '2031-01-01', '2031-01-02'], ['2030-12-23', '2031-01-03']],
        ['Leap', '2020-02-29', '2020-02-29', true, ['2032-02-29'], ['2031-02-28', '2031-03-01', '2032-02-28']],
        ['Single', '2020-07-14', '2020-07-14', true, ['2032-07-14'], ['2032-07-13', '2032-07-15']],
        ['No bounds', null, null, true, [], ['2030-06-01']],
    ];
    $links = [];
    foreach ($cases as [$label, $begin, $end, $annual, $inside, $outside]) {
        $id = $holiday($label, $begin, $end, $annual);
        $links[$id] = $link($calendarId, $id);
        $isolated = $add(new Calendar(), ['name' => $prefix . ' ' . $label, 'entities_id' => 0]);
        $link($isolated, $id);
        $model = new Calendar();
        verify($model->getFromDB($isolated), 'Load a public Calendar for each actual period');
        foreach ($inside as $date) {
            verify($model->isHoliday($date), 'Inclusive/annual Calendar date: ' . $date);
        }
        foreach ($outside as $date) {
            verify(!$model->isHoliday($date), 'Outside inclusive/annual Calendar date: ' . $date);
        }
    }
    $period = new HolidayPeriod();
    $period->begin_date = new DateTimeImmutable('2030-05-02');
    $period->end_date = new DateTimeImmutable('2030-05-01');
    verify(!$period->containsDay(new DateTimeImmutable('2030-05-01')), 'Ordinary reversed mapped interval is empty');
    $period->end_date = null;
    verify(!$period->containsDay(new DateTimeImmutable('2030-05-02')), 'A supplied nullable bound has no interval');
    $period->begin_date = null;
    $period->end_date = new DateTimeImmutable('2030-05-02');
    verify(!$period->containsDay(new DateTimeImmutable('2030-05-02')), 'A nullable start does not manufacture an annual or absolute closure');
    $selected = $closures($calendarId);
    verify(count($selected) === count($links), 'Repository preserves all distinct owning memberships');
    $selectedPairs = [];
    foreach ($selected as $entry) {
        $selectedPairs[$entry->holidays->id] = $entry->id;
    }
    ksort($selectedPairs);
    ksort($links);
    verify($selectedPairs === $links, 'Same-name Holidays retain their own link IDs and target identities');

    // Separate valid fields prevent a different integrity error masking the selected refusal.
    verify($read('glpi_calendars', PHP_INT_MAX) === null && $read('glpi_holidays', PHP_INT_MAX) === null, 'Both selected invalid native targets are actually absent');
    foreach ([
        [['calendars_id' => $calendarId, 'holidays_id' => array_key_first($links)], UniqueConstraintViolationException::class],
        [['calendars_id' => PHP_INT_MAX, 'holidays_id' => $may], ForeignKeyConstraintViolationException::class],
        [['calendars_id' => $calendarId, 'holidays_id' => PHP_INT_MAX], ForeignKeyConstraintViolationException::class],
    ] as [$values, $expected]) {
        $before = $rows('glpi_calendars_holidays');
        $rejected = false;
        $rollbackScope(static function () use ($connection, $values, $expected, &$rejected): void {
            try {
                $connection->insert('glpi_calendars_holidays', $values);
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                $rejected = $error instanceof $expected;
            }
        }, 'native-membership-refusal');
        verify($rejected && $rows('glpi_calendars_holidays') === $before, 'Native duplicate/invalid owning target is refused without changing memberships');
    }

    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    verify(count($closures($calendarId)) === count($links) && $calendar->isHoliday('2030-05-01'), 'Actual repository and public lookup read the owning closure graph');
    verify($SQL_TOTAL_REQUEST === 0, 'Closure selection and holiday policy do not call the legacy SQL adapter');
    ob_start();
    try {
        Calendar_Holiday::showForCalendar($calendar);
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    foreach ($links as $holidayId => $linkId) {
        verify(str_contains($html, 'id=' . $holidayId) && str_contains($html, 'item[Calendar_Holiday][' . $linkId . ']'), 'Actual tab retains the Holiday route and membership action identity');
    }
    $added = [];
    $PLUGIN_HOOKS['item_add']['calendar_closures_fixture'][Calendar_Holiday::class] = static function (Calendar_Holiday $item) use (&$added): void {
        $added[] = $item->input;
    };
    $cloneId = $calendar->clone(['name' => $prefix . ' modern clone']);
    verify(is_int($cloneId) && $cloneId > 0 && count($closures($cloneId)) === count($links), 'Modern public Calendar clone retains owning closure memberships');
    verify(count($added) === count($links), 'Modern clone invokes actual public membership add hooks');
    $legacyCloneId = $add(new Calendar(), ['name' => $prefix . ' deprecated clone', 'entities_id' => 0]);
    $added = [];
    $legacyLogs = $rows('glpi_logs', ['itemtype' => 'Calendar', 'items_id' => $legacyCloneId]);
    Calendar_Holiday::cloneCalendar($calendarId, $legacyCloneId);
    verify(count($closures($legacyCloneId)) === count($links) && count($added) === count($links), 'Deprecated extension entrypoint retains public add hooks and every closure');
    verify(array_reduce($added, static fn (bool $ok, array $input): bool => $ok && ($input['_no_history'] ?? false) === true, true), 'Deprecated clone preserves its explicit no-history input');
    verify($rows('glpi_logs', ['itemtype' => 'Calendar', 'items_id' => $legacyCloneId]) === $legacyLogs, 'Deprecated clone does not introduce relation audit entries');
    unset($PLUGIN_HOOKS['item_add']['calendar_closures_fixture']);
    $stock = $closures($cloneId);
    verify((new Calendar())->delete(['id' => $cloneId], true), 'Public Calendar purge');
    verify($closures($cloneId) === [] && $rows('glpi_calendarsegments', ['calendars_id' => $cloneId]) === [], 'Calendar purge removes its own closures and segments');
    foreach ($stock as $entry) {
        verify($read('glpi_holidays', $entry->holidays->id) !== null, 'Calendar purge leaves reusable Holiday owners intact');
    }
    $replaceCalendarId = $add(new Calendar(), ['name' => $prefix . ' replacement', 'entities_id' => 0]);
    $oldHoliday = $holiday('Before replacement', '2030-06-10', '2030-06-10');
    $replacement = $holiday('After replacement', '2030-06-11', '2030-06-11');
    $replacementLink = $link($replaceCalendarId, $oldHoliday);
    $replaceCalendar = new Calendar();
    verify($replaceCalendar->getFromDB($replaceCalendarId) && $replaceCalendar->isHoliday('2030-06-10'), 'Warm date before actual public Holiday replacement');
    verify((new Holiday())->delete(['id' => $oldHoliday, '_replace_by' => $replacement], true), 'Public Holiday replacement');
    verify($read('glpi_calendars_holidays', $replacementLink)['holidays_id'] === $replacement && !$replaceCalendar->isHoliday('2030-06-10') && $replaceCalendar->isHoliday('2030-06-11'), 'Replacement retains the link identity and immediately uses replacement dates');
    verify((new Holiday())->delete(['id' => $replacement], true), 'Public Holiday purge without replacement');
    verify($closures($replaceCalendarId) === [] && !$replaceCalendar->isHoliday('2030-06-11'), 'Holiday purge clears links and the already-warmed date');

    $scopeSession = $_SESSION;
    try {
        $entity = $add(new Entity(), ['name' => $prefix . ' entity', 'entities_id' => 0]);
        $scopedId = $add(new Calendar(), ['name' => $prefix . ' scoped', 'entities_id' => $entity]);
        $rootNonrecursive = $add(new Calendar(), ['name' => $prefix . ' root nonrecursive', 'entities_id' => 0, 'is_recursive' => false]);
        $scoped = new Calendar();
        verify($scoped->getFromDB($scopedId), 'Load entity-owned Calendar');
        $scope = static function (array $entities, int $rights): void {
            $_SESSION['glpiactiveentities'] = $entities;
            $_SESSION['glpiactiveentities_string'] = implode(',', $entities);
            $_SESSION['glpiactiveprofile']['calendar'] = $rights;
        };
        $scope([], READ | UPDATE);
        verify(!$scoped->can($scopedId, READ), 'Empty entity scope denies the actual Calendar tab');
        verify(Calendar_Holiday::showForCalendar($scoped) === false, 'UI refuses before selecting hidden owning memberships');
        $input = ['calendars_id' => $scopedId, 'holidays_id' => $may];
        verify(!(new Calendar_Holiday())->can(-1, CREATE, $input), 'Empty entity scope cannot create a membership');
        $scope([$entity], READ);
        verify($scoped->can($scopedId, READ) && !$scoped->can($scopedId, UPDATE), 'Read-only scope admits Calendar view, not edits');
        verify($calendar->can($calendarId, READ) && !(new Calendar())->can($rootNonrecursive, READ), 'Root recursive Calendar is visible to a descendant; root nonrecursive Calendar is not');
        $input = ['calendars_id' => $scopedId, 'holidays_id' => $may];
        verify(!(new Calendar_Holiday())->can(-1, CREATE, $input), 'Read-only Calendar does not grant relationship writes');
        $scope([$entity], UPDATE);
        $input = ['calendars_id' => $scopedId, 'holidays_id' => $may];
        verify(!$mayModel->can($may, READ) && (new Calendar_Holiday())->can(-1, CREATE, $input), 'Existing secondary DONT_CHECK role does not add a blanket Holiday READ requirement');
    } finally {
        $_SESSION = $scopeSession;
    }

    // The independent read session cannot see this writer's uncommitted fixture.
    // This proves endpoint selection, not a deployed asynchronous replica.
    $reader = (new ReflectionClass($writer))->newInstanceWithoutConstructor();
    verify($reader->connect() === true, 'Independent configured read transport opens');
    $reader->slave = true;
    $readConnection = $reader->getDoctrineConnection();
    verify($readConnection !== $connection && $readConnection->getParams() === $connection->getParams(), 'Separate read transport preserves all actual endpoint and TLS configuration');
    verify(Orm::create($reader)->getConnection() === $readConnection, 'ORM retains the supplied application read connection');
    $readMay = $link($calendarId, $may);
    verify($calendar->isHoliday('2030-05-07'), 'Writer sees its own uncommitted closure');
    $DB = $reader;
    try {
        verify((new CalendarRepository(Orm::create($reader)))->closures($calendarId) === [], 'Repository reads the independent supplied session, not the writer');
        verify(!$calendar->isHoliday('2030-05-07'), 'Public warmed lookup selects the supplied read connection');
        verify((new Calendar_Holiday())->add(['calendars_id' => $calendarId, 'holidays_id' => $may]) === false, 'Read adapter cannot write a membership');
    } finally {
        $DB = $writer;
    }
    verify($calendar->isHoliday('2030-05-07') && $read('glpi_calendars_holidays', $readMay) !== null, 'Returning to writer routing observes its original membership');
} catch (Throwable $error) {
    $recordFailure($error, 'calendar-scenario');
} finally {
    $DB = $writer;
    if ($outerFrame !== null) {
        // Refuse a replaced/unknown layer; never unwind arbitrary callback frames.
        $cleanup(static fn () => $outerFrame->rollBack(), 'owned-outer-rollback');
    }
    $cleanup(static fn () => $reader?->close(), 'independent-reader-close');
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $cleanup(static fn () => $plugins->setValue(null, $savedPlugins), 'plugin-context-restore');
    $cleanup(static fn () => restore_error_handler(), 'error-handler-restore');
}
$preservationSafe = $cleanup(static function () use ($connection): void {
    TransactionOwnership::assertManaged($connection);
    verify($connection->getTransactionNestingLevel() === 0, 'All owned ordinary and nested transactions are closed');
}, 'idle-managed-owner');
if ($preservationSafe) {
    // Preservation still runs after the first actual scenario failure.
    $cleanup(static fn () => verify($rows('glpi_logs') === $originalLogs && $rows('glpi_queuednotifications') === $originalQueue, 'Caller rollback restores audit and notification rows'), 'audit-queue-preservation');
    $cleanup(static fn () => verify($connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version') === $originalLedger, 'Calendar operations never rewrite historical receipts'), 'raw-ledger-preservation');
    $cleanup(static fn () => verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema remains unchanged'), 'schema-preservation');
}
if ($primary !== null) {
    throw new RuntimeException('Calendar closure contract failed; secondary diagnostic classes: ' . json_encode($secondary, JSON_THROW_ON_ERROR), previous: $primary);
}
echo "Calendar closure ownership, policy and lifecycle passed ($assertions assertions).\n";
