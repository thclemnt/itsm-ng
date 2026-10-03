<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ServiceLevelCalendars;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/service-level-calendars.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeBooleanFixture.php';
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
$DB->beginTransaction();
try {
    $calendar = $fixtures->create('glpi_calendars', ['name' => 'Fixed calendar']);
    foreach ([
        ['begin_date' => '2020-12-24', 'end_date' => '2021-01-02', 'is_perpetual' => true],
        ['begin_date' => '2030-05-01', 'end_date' => '2030-05-02', 'is_perpetual' => false],
        ['begin_date' => '2020-07-14', 'end_date' => '2020-07-14', 'is_perpetual' => true],
    ] as $dates) {
        $holiday = $fixtures->create('glpi_holidays', $dates);
        $fixtures->create('glpi_calendars_holidays', ['calendars_id' => $calendar, 'holidays_id' => $holiday]);
    }
    $holidayCalendar = new Calendar();
    verify($holidayCalendar->getFromDB($calendar), 'Load mapped holiday calendar');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    foreach (['2030-12-24', '2030-12-31', '2031-01-01', '2031-01-02', '2030-05-02 16:00:00', '2032-07-14'] as $date) {
        verify($holidayCalendar->isHoliday($date), 'Inclusive holiday: ' . $date);
    }
    foreach (['2030-12-23', '2031-01-03', '2031-05-01', '2032-07-15'] as $date) {
        verify(!$holidayCalendar->isHoliday($date), 'Outside holiday: ' . $date);
    }
    verify($SQL_TOTAL_REQUEST === 0, 'Holiday selection uses ORM');
    $replacement = $fixtures->create('glpi_calendars', ['name' => 'Replacement calendar']);
    $ticketCalendar = $fixtures->create('glpi_calendars', ['name' => 'Entity calendar']);
    $entity = $fixtures->create('glpi_entities', ['id' => (int)$connection->fetchOne('SELECT MAX(id) + 100 FROM glpi_entities'), 'name' => 'Calendar entity', 'calendars_id' => $ticketCalendar]);
    $slm = new SLM();
    $id = $slm->add(['name' => 'Explicit calendar policy', 'calendar_selection' => -1]);
    verify($id > 0 && $slm->getFromDB($id), 'Create ticket-calendar policy through form input');
    verify($slm->usesTicketCalendar() && $read('glpi_slms', $id)['calendars_id'] === null, 'Ticket calendar uses a real boolean and NULL FK');
    foreach ([1 => 1, 0 => 0] as $selected => $expected) {
        $search = Search::getDatas('SLM', [
            'criteria' => [
                ['field' => 2, 'searchtype' => 'equals', 'value' => $id, 'link' => 'AND'],
                ['field' => 5, 'searchtype' => 'equals', 'value' => $selected, 'link' => 'AND'],
            ],
            'sort' => [2], 'order' => ['ASC'], 'start' => 0, 'list_limit' => 10, 'reset' => 'reset',
        ]);
        verify((int)$search['data']['totalcount'] === $expected, 'Native boolean calendar-policy search');
    }
    $agreements = [];
    foreach (['SLA', 'OLA'] as $type) {
        $model = new $type();
        $aid = $model->add(['slms_id' => $id, 'name' => 'Inherited ' . $type, 'type' => SLM::TTR, 'number_time' => 2, 'definition_time' => 'hour']);
        verify($aid > 0 && $model->getFromDB($aid) && $model->usesTicketCalendar(), 'Agreement inherits policy');
        verify(!$connection->createSchemaManager()->introspectTable($type::getTable())->hasColumn('calendars_id'), 'No redundant agreement calendar column');
        $model->setTicketCalendar($ticketCalendar);
        verify($model->fields['calendars_id'] === $ticketCalendar, 'Resolve ticket calendar at execution boundary');
        $model->setTicketCalendar(0);
        verify($model->fields['calendars_id'] === null && $model->computeDate('2030-01-01 10:00:00') === '2030-01-01 12:00:00', 'Repeated resolution can select always-open calendar');
        $agreements[$type] = $aid;
    }
    $tid = $fixtures->create('glpi_tickets', ['name' => 'Calendar selection ticket', 'entities_id' => $entity, 'slas_id_ttr' => $agreements['SLA']]);
    $ticket = new Ticket();
    verify($ticket->getFromDB($tid) && (int)$ticket->getCalendar() === $ticketCalendar, 'Ticket inherits entity calendar when selected');
    verify($slm->update(['id' => $id, 'calendar_selection' => $calendar]), 'Switch to named calendar');
    verify($read('glpi_slms', $id)['calendars_id'] === $calendar && $read('glpi_slms', $id)['use_ticket_calendar'] === 0, 'Named calendar has FK and disabled inheritance');
    verify((int)$ticket->getCalendar() === $calendar, 'Fixed calendar overrides entity calendar');
    verify($slm->update(['id' => $id, 'calendar_selection' => 0]), 'Switch to always-open');
    verify($ticket->getCalendar() === 0, 'Always-open does not accidentally inherit entity calendar');
    verify($slm->update(['id' => $id, 'calendars_id' => -1]), 'Legacy API selection translated only at model input');
    verify($read('glpi_slms', $id)['calendars_id'] === null && $read('glpi_slms', $id)['use_ticket_calendar'] === 1, 'Legacy -1 never reaches stored FK');
    verify($slm->update(['id' => $id, 'use_ticket_calendar' => false, 'calendars_id' => $calendar]), 'Canonical API calendar policy');
    verify($slm->update(['id' => $id, 'use_ticket_calendar' => true]), 'Canonical API inheritance clears fixed reference');
    verify($read('glpi_slms', $id)['calendars_id'] === null, 'Canonical policy cannot retain conflicting reference');
    verify($slm->prepareInputForUpdate(['calendars_id' => -2]) === false, 'Unsupported sentinel rejected');
    verify($slm->prepareInputForUpdate(['calendars_id' => $calendar, 'use_ticket_calendar' => true]) === false, 'Conflicting input rejected');
    $slm->update(['id' => $id, 'calendar_selection' => $calendar]);
    verify((new Calendar())->delete(['id' => $calendar, '_replace_by' => $replacement], true), 'Calendar replacement respects SLM FK');
    verify($read('glpi_slms', $id)['calendars_id'] === $replacement && (int)$ticket->getCalendar() === $replacement, 'Agreements resolve replacement through SLM');
    verify((new Calendar())->delete(['id' => $replacement], true), 'Calendar purge clears SLM reference');
    verify($read('glpi_slms', $id)['calendars_id'] === null && $ticket->getCalendar() === 0, 'Purged fixed calendar falls back to always-open');
    $slm->update(['id' => $id, 'calendar_selection' => -1]);
    $slm->getFromDB($id);
    ob_start();
    $slm->showForm($id);
    $html = ob_get_clean();
    verify(str_contains($html, 'calendar_selection') && preg_match('/value="-1"[^>]*selected|selected[^>]*value="-1"/', $html) === 1, 'Form retains ticket-calendar choice');
    foreach (['SLA', 'OLA'] as $type) {
        ob_start();
        $type::showForSLM($slm);
        $html = ob_get_clean();
        verify(str_contains($html, __('Calendar of the ticket')), 'Agreement view displays inherited policy');
    }
    // Constraint failures need savepoints on PostgreSQL; no raw writer may store sentinel/conflicting values.
    foreach ([['calendars_id' => -1], ['calendars_id' => $ticketCalendar, 'use_ticket_calendar' => true], ['calendars_id' => 2147483647, 'use_ticket_calendar' => false]] as $values) {
        $rejected = false;
        $connection->beginTransaction();
        // The PgSQL driver can warn while freeing a failed statement before rollback.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            $connection->update('glpi_slms', $values, ['id' => $id], ['use_ticket_calendar' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
        } catch (\Doctrine\DBAL\Exception $error) {
            $rejected = true;
        } finally {
            $connection->rollBack();
            restore_error_handler();
        }
        verify($rejected, 'Database rejects invalid calendar policy');
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Calendar ownership graph valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$migration = new ServiceLevelCalendars();
$nativeBooleans = new NativeBooleanFixture($connection, 'glpi_slms');
$legacyIds = [];
$agreementIds = [];
$calendar = null;
try {
    $connection->executeStatement('ALTER TABLE glpi_slms DROP ' . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform && !$platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform ? 'CHECK' : 'CONSTRAINT') . ' ' . ServiceLevelCalendars::CHECK);
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_slms', 'calendars_id'), 'glpi_slms'));
    $connection->executeStatement('UPDATE glpi_slms SET calendars_id = CASE WHEN use_ticket_calendar THEN -1 ELSE 0 END WHERE calendars_id IS NULL');
    foreach (['glpi_slms', 'glpi_slas', 'glpi_olas'] as $table) {
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        if ($table === 'glpi_slms') {
            $after->dropColumn('use_ticket_calendar');
            $after->getColumn('calendars_id')->setNotnull(true)->setDefault(0);
        } else {
            $after->addColumn('calendars_id', 'integer', ['default' => 0]);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $calendar = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_calendars');
    $connection->insert('glpi_calendars', ['id' => $calendar, 'name' => 'Migration calendar']);
    $next = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_slms');
    foreach ([-1, 0, $calendar] as $selection) {
        $legacyIds[] = $next;
        $connection->insert('glpi_slms', ['id' => $next++, 'name' => 'Legacy policy', 'calendars_id' => $selection]);
    }
    foreach (['glpi_slas', 'glpi_olas'] as $table) {
        $aid = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM ' . $table);
        $agreementIds[$table] = $aid;
        $connection->insert($table, ['id' => $aid, 'slms_id' => $legacyIds[2], 'name' => 'Legacy inherited calendar',
            'number_time' => 2, 'definition_time' => 'hour', 'calendars_id' => -1]);
    }
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && $plan['ticket_calendars'] >= 1 && $plan['always_open'] >= 1, 'Read-only plan includes policy and schema changes');
    $connection->update('glpi_slms', ['calendars_id' => 2147483647], ['id' => $legacyIds[2]]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Invalid service-level calendar');
    }
    verify($rejected && !$connection->createSchemaManager()->introspectTable('glpi_slms')->hasColumn('use_ticket_calendar'), 'Orphan rejected before any schema mutation');
    $connection->update('glpi_slms', ['calendars_id' => $calendar], ['id' => $legacyIds[2]]);
    $connection->beginTransaction();
    try {
        if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $migration->apply($connection);
        } else {
            $refused = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $refused = str_contains($error->getMessage(), 'outside an application transaction');
            }
            verify($refused, 'MySQL DDL refuses an active application transaction');
        }
    } finally {
        $connection->rollBack();
    }
    verify(!$connection->createSchemaManager()->introspectTable('glpi_slms')->hasColumn('use_ticket_calendar'), 'Transactional check leaves legacy schema intact');
    // Simulate interruption after the first DDL statement; retries must finish the data conversion.
    $connection->executeStatement($migration->plan($connection)['sql'][0]);
    $migration->apply($connection);
    foreach ($agreementIds as $table => $aid) {
        $type = getItemTypeForTable($table);
        $agreement = new $type();
        verify($agreement->getFromDB($aid) && $agreement->fields['calendars_id'] === $calendar && !$agreement->usesTicketCalendar(), 'Removing stale agreement calendar preserves effective parent policy');
    }
    verify($read('glpi_slms', $legacyIds[0])['use_ticket_calendar'] === 1 && $read('glpi_slms', $legacyIds[0])['calendars_id'] === null, 'Ticket policy migrated');
    verify($read('glpi_slms', $legacyIds[1])['use_ticket_calendar'] === 0 && $read('glpi_slms', $legacyIds[1])['calendars_id'] === null, 'Always-open policy migrated');
    verify($read('glpi_slms', $legacyIds[2])['use_ticket_calendar'] === 0 && $read('glpi_slms', $legacyIds[2])['calendars_id'] === $calendar, 'Fixed calendar preserved');
    $plan = $migration->apply($connection);
    verify($plan['sql'] === [] && $plan['check_sql'] === [] && $plan['ticket_calendars'] === 0 && $plan['always_open'] === 0, 'Migration is idempotent');
} finally {
    foreach ($agreementIds as $table => $aid) {
        $connection->delete($table, ['id' => $aid]);
    }
    foreach ($legacyIds as $id) {
        $connection->delete('glpi_slms', ['id' => $id]);
    }
    if ($calendar !== null) {
        $connection->delete('glpi_calendars', ['id' => $calendar]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    // The historical phase intentionally removed this column and its native
    // domain. Restore the captured current domain only after canonical repair.
    $nativeBooleans->restore();
}
echo $DB->getProvider() . ": normalized service-level calendars, inherited policies, constrained lifecycle and migration passed.\n";
