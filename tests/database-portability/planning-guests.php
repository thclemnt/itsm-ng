<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\PlanningEventGuests;
use itsmng\Database\Orm;
use itsmng\Database\Repository\PlanningGuestRepository;
use itsmng\Database\Repository\PlanningRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php planning-guests.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
$migration = new PlanningEventGuests();
$migration->apply($connection);
$DB->clearSchemaCache();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$repo = fn (): PlanningGuestRepository => new PlanningGuestRepository(Orm::create($DB));
verify(!$connection->createSchemaManager()->introspectTable('glpi_planningexternalevents')->hasColumn('users_id_guests'), 'Serialized guest storage is removed');
$DB->beginTransaction();
try {
    $owner = (int)Session::getLoginUserID();
    $first = $fixtures->create('glpi_users', ['name' => "Guest O'Reilly"]);
    $second = $fixtures->create('glpi_users', ['name' => 'Second guest']);
    $third = $fixtures->create('glpi_users', ['name' => 'Uninvited guest']);
    $input = ['name' => 'Guest membership event', 'text' => 'Body', 'users_id' => $owner, 'entities_id' => 0,
        'plan' => ['begin' => '2026-10-01 10:00:00', 'end' => '2026-10-01 12:00:00'], '_no_check_plan' => true];
    $event = new PlanningExternalEvent();
    $id = $event->add($input + ['users_id_guests' => [$second, (string)$first, $second]]);
    verify(is_int($id) && $id > 0 && $repo()->userIds($id) === [$second, $first], 'Public add persists ordered unique guests');
    verify($event->getFromDB($id) && $event->fields['users_id_guests'] === [$second, $first], 'Public guest projection is an array');
    foreach (['planningexternalevents_id', 'users_id'] as $column) {
        try {
            $connection->transactional(static function () use ($connection, $id, $third, $column): void {
                $values = ['planningexternalevents_id' => $id, 'users_id' => $third, 'position' => 99];
                $values[$column] = 2147483647;
                $connection->insert(PlanningEventGuests::TABLE, $values);
            });
            throw new LogicException('Raw guest orphan accepted');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
        }
    }
    foreach (['glpi_planningexternalevents' => $id, 'glpi_users' => $first] as $table => $parent) {
        try {
            $connection->transactional(static fn () => $connection->delete($table, ['id' => $parent]));
            throw new LogicException('Referenced guest parent deletion accepted');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            if (!$error instanceof \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException && $error->getSQLState() !== '23001') {
                throw $error;
            }
        }
    }
    verify($event->update(['id' => $id, 'text' => 'Changed body', '_no_check_plan' => true]), 'Partial event update');
    verify($repo()->userIds($id) === [$second, $first], 'Omitted guests are preserved');
    verify($event->update(['id' => $id, 'users_id_guests' => [$first, $third], '_no_check_plan' => true]), 'Guest-only update');
    verify($repo()->userIds($id) === [$first, $third], 'Guest-only update persists order');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_logs', ['items_id' => $id, 'itemtype' => 'PlanningExternalEvent', 'id_search_option' => 12]) === 1, 'Guest-only history is retained');
    try {
        $event->update(['id' => $id, 'name' => 'Invalid replacement', 'users_id_guests' => [2147483647], '_no_check_plan' => true]);
        throw new LogicException('Unknown guest accepted');
    } catch (InvalidArgumentException) {
    }
    verify($event->getFromDB($id) && $event->fields['name'] === $input['name'] && $repo()->userIds($id) === [$first, $third], 'Invalid guests roll back scalar update and memberships');
    $count = (new RecordRepository(Orm::create($DB)))->countMatching('glpi_planningexternalevents', []);
    try {
        (new PlanningExternalEvent())->add($input + ['users_id_guests' => [2147483647]]);
        throw new LogicException('Unknown add guest accepted');
    } catch (InvalidArgumentException) {
    }
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_planningexternalevents', []) === $count, 'Invalid guests roll back event creation');
    $planning = new PlanningRepository(Orm::create($DB));
    verify(array_column($planning->externalEvents(['glpi_planningexternaleventguests.users_id' => $first]), 'id') === [$id], 'Exact guest selection');
    verify($planning->externalEvents(['glpi_planningexternaleventguests.users_id' => $second]) === [], 'Removed guest cannot select event');
    verify(count($planning->externalEvents(['id' => $id])) === 1, 'Guest fan-out does not duplicate owned events');
    $options = ['who' => $first, 'whogroup' => 0, 'begin' => '2026-10-01 09:00:00', 'end' => '2026-10-01 13:00:00'];
    verify(array_column(PlanningExternalEvent::populatePlanning($options), 'id') === [$id], 'Calendar includes invited users');
    verify(PlanningExternalEvent::populatePlanning(array_replace($options, ['who' => $second])) === [], 'Calendar excludes removed guest');
    verify(count(PlanningExternalEvent::getUserItemsAsVCalendars($first)) === 1 && PlanningExternalEvent::getUserItemsAsVCalendars($second) === [], 'iCalendar uses canonical memberships');
    $recall = new PlanningRecall();
    $recall->fields = ['itemtype' => 'PlanningExternalEvent', 'items_id' => $id];
    $target = new class (0, 'planningrecall', $recall) extends NotificationTargetPlanningRecall {
        public array $selected = [];
        public function addToRecipientsList(array $data)
        {
            $this->selected[] = (int)$data['users_id'];
        }
    };
    $target->addGuests();
    verify($target->selected === [$first, $third], 'Recall recipient selection reads canonical guests without dispatch');
    $search = Search::getDatas('PlanningExternalEvent', ['criteria' => [['field' => 12, 'value' => (string)$first, 'searchtype' => 'equals']], 'reset' => 'reset', 'list_limit' => 10], [1, 12]);
    verify(array_map('intval', array_column($search['data']['rows'], 'id')) === [$id], 'Guest search joins users through membership');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $planning->externalEvents(['id' => $id]);
    $repo()->userIds($id);
    PlanningExternalEvent::populatePlanning($options);
    PlanningExternalEvent::getUserItemsAsVCalendars($first);
    verify($SQL_TOTAL_REQUEST === 0, 'Guest and calendar reads execute no adapter SQL');
    verify($event->update(['id' => $id, 'rrule' => ['freq' => 'daily', 'interval' => 1, 'count' => 3], '_no_check_plan' => true]), 'Recurring event update');
    $clone = $event->createInstanceClone($id, '2026-10-02 10:00:00');
    verify($clone instanceof PlanningExternalEvent && $repo()->userIds((int)$clone->getID()) === [$first, $third], 'Recurrence clone copies canonical guests through the public input');
    verify($clone->delete(['id' => $clone->getID()], true), 'Purge recurrence clone');
    verify($event->update(['id' => $id, 'users_id_guests' => [], '_no_check_plan' => true]) && $repo()->userIds($id) === [], 'Explicit empty guests clear membership');
    $repo()->replaceGuests($id, [$first, $third]);
    verify((new User())->delete(['id' => $first, '_replace_by' => $third], true), 'Public guest replacement');
    verify($repo()->userIds($id) === [$third], 'Replacement deduplicates an existing guest');
    verify((new User())->delete(['id' => $third], true) && $repo()->userIds($id) === [], 'User purge removes guest memberships');
    $repo()->replaceGuests($id, [$second]);
    $failing = new class () extends PlanningExternalEvent {
        public static function getType()
        {
            return 'PlanningExternalEvent';
        }
        public static function getTable($classname = null)
        {
            return 'glpi_planningexternalevents';
        }
        public function cleanDBonPurge()
        {
            parent::cleanDBonPurge();
            throw new RuntimeException('Injected failure after guest cleanup');
        }
    };
    try {
        $failing->delete(['id' => $id], true);
        throw new LogicException('Injected purge failure ignored');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Injected failure after guest cleanup', 'Domain cleanup failed as expected');
    }
    verify($event->getFromDB($id) && $repo()->userIds($id) === [$second], 'Failed event purge restores its guests and parent');
    verify($event->delete(['id' => $id], true), 'Event purge');
    verify($repo()->userIds($id) === [] && (new ForeignKeys())->audit($connection) === [], 'Event purge removes guest memberships before parent');
} finally {
    $DB->rollBack();
}
// Audit the old storage and retry boundary independently of runtime mappings.
$manager = $connection->createSchemaManager();
$before = $manager->introspectTable('glpi_planningexternalevents');
$legacy = clone $before;
$legacy->addColumn('users_id_guests', 'text', ['notnull' => false]);
foreach ($connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
    $connection->executeStatement($sql);
}
$manager->dropTable(PlanningEventGuests::TABLE);
$DB->clearSchemaCache();
$guest = $fixtures->create('glpi_users', ['name' => 'Legacy guest']);
$id = $fixtures->create('glpi_planningexternalevents');
try {
    foreach (['[2147483647]' => 'Orphaned legacy event guest', '[true]' => 'Invalid legacy event guest ID', 'broken' => 'Malformed legacy event guest list'] as $value => $message) {
        $connection->update('glpi_planningexternalevents', ['users_id_guests' => $value], ['id' => $id]);
        try {
            $migration->apply($connection);
            throw new LogicException('Invalid legacy guest accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), $message), 'Guest preflight refusal: ' . $value);
        }
        verify(!$manager->tablesExist([PlanningEventGuests::TABLE]), 'Refusal creates no canonical table');
    }
    $connection->update('glpi_planningexternalevents', ['users_id_guests' => json_encode([(string)$guest, $guest])], ['id' => $id]);
    $migration->apply($connection);
    verify($repo()->userIds($id) === [$guest], 'Legacy JSON guests are copied and deduplicated');
    $migration->apply($connection);
    verify($repo()->userIds($id) === [$guest], 'Upgrade retry preserves canonical guests');
    verify((new ForeignKeys())->audit($connection) === [], 'Upgraded guest graph is valid');
} finally {
    $migration->apply($connection);
    $repo()->removeForEvent($id);
    $connection->delete('glpi_planningexternalevents', ['id' => $id]);
    $connection->delete('glpi_users', ['id' => $guest]);
    $DB->clearSchemaCache();
}
echo $DB->getProvider() . ": normalized planning guests, calendar/search scope, public lifecycle, rollback and frozen upgrade passed.\n";
