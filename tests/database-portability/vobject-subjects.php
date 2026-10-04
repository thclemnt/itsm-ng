<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Glpi\CalDAV\Backend\Calendar;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\VObjectSubjects;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CalendarObjectRepository;
use itsmng\Database\Repository\RecordRepository;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/vobject-subjects.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_vobjects'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $migration = new VObjectSubjects();
    $migration->apply($connection);
    $DB->clearSchemaCache();
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Login');
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $table = 'glpi_vobjects';
    $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
    $kinds = array_keys($branches);
    $planningKinds = $CFG_GLPI['planning_types'];
    sort($kinds);
    sort($planningKinds);
    verify($kinds === $planningKinds && count($kinds) === 6, 'Every core calendar kind has an owning subject');
    $calendarData = static function (string $uid, string $summary): string {
        $calendar = new VCalendar(['VERSION' => '2.0']);
        $calendar->add('VEVENT', ['UID' => $uid, 'DTSTART' => '20300101T120000Z', 'DTEND' => '20300101T130000Z',
            'SUMMARY' => $summary, 'X-ORM-TEST' => "owner's preserved extension"]);
        return $calendar->serialize();
    };
    $reject = static function (callable $operation, string $message, ?string $expectedCheck = null) use ($connection): void {
        $connection->beginTransaction();
        try {
            $failed = false;
            try {
                $operation();
            } catch (DriverException $error) {
                $failed = in_array($error->getSQLState(), ['23502', '23503', '23514', '23505', '23001', '23000'], true)
                    || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
            }
            verify($failed, $message);
        } finally {
            $connection->rollBack();
        }
    };
    $DB->beginTransaction();
    try {
        $sameId = 4294967900;
        $links = $uids = [];
        foreach ($branches as $kind => $selection) {
            $uid = $uids[$kind] = 'typed-' . $kind . '-' . bin2hex(random_bytes(8));
            $values = ['id' => $sameId, 'uuid' => $uid];
            $values[in_array($kind, ['Reminder', 'PlanningExternalEvent'], true) ? 'text' : 'content'] = 'Planned calendar subject';
            $values[$kind === 'ProjectTask' ? 'plan_start_date' : 'begin'] = '2030-01-01 12:00:00';
            $values[$kind === 'ProjectTask' ? 'plan_end_date' : 'end'] = '2030-01-01 13:00:00';
            if (in_array($kind, ['Reminder', 'PlanningExternalEvent'], true)) {
                $values['users_id'] = Session::getLoginUserID();
            }
            if ($kind === 'ProjectTask') {
                $values['projects_id'] = $fixtures->create('glpi_projects', ['users_id' => Session::getLoginUserID()]);
            }
            $fixtures->create($selection['target'], $values);
            $data = $calendarData($uid, "Original owner's calendar");
            $object = new VObject();
            $id = $links[$kind] = $object->add(Toolbox::addslashes_deep(['itemtype' => $kind, 'items_id' => $sameId, 'data' => $data]));
            $row = $read($table, $id);
            verify($id > 0 && (int)$row[$selection['column']] === $sameId && (int)$row['items_id'] === $sameId
                && $row['data'] === $data, 'Public calendar data selects its wide owning subject and retains literal data: ' . $kind);
            $subject = new $kind();
            verify($subject->getFromDB($sameId), 'Load calendar subject');
            $calendar = $subject->getAsVCalendar();
            verify($calendar instanceof VCalendar && (string)$calendar->getBaseComponent()->{'X-ORM-TEST'} === "owner's preserved extension", 'Calendar conversion restores unknown properties: ' . $kind);
            $reject(fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => 999999999]), 'Native FK rejects an unknown calendar subject');
            $reject(fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Calendar FK requires public subject cleanup');
            $reject(fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => $sameId]), 'A subject cannot have duplicate stored calendar data');
        }
        foreach ([['itemtype' => null, 'projecttasks_id' => $sameId], ['itemtype' => 'UnknownPlugin', 'projecttasks_id' => $sameId],
            ['itemtype' => 'ProjectTask'], ['itemtype' => 'ProjectTask', 'reminders_id' => $sameId],
            ['itemtype' => 'ProjectTask', 'projecttasks_id' => 0], ['itemtype' => 'ProjectTask', 'projecttasks_id' => $sameId, 'reminders_id' => $sameId]] as $invalid) {
            $reject(fn () => $connection->insert($table, $invalid), 'Native CHECK requires one matching positive calendar subject', 'glpi_vobjects_typed_item_kind');
        }
        $storage = new MappedStorage($DB);
        $otherTask = $fixtures->create('glpi_projecttasks');
        $id = $links['PlanningExternalEvent'];
        $changes = $storage->update($table, $id, ['itemtype' => 'ProjectTask', 'items_id' => $otherTask]);
        verify($read($table, $id)['planningexternalevents_id'] === null && (int)$read($table, $id)['projecttasks_id'] === $otherTask
            && in_array('items_id', $changes, true), 'Retarget clears the old branch and reports logical identity');
        $storage->update($table, $id, ['itemtype' => 'PlanningExternalEvent', 'items_id' => $sameId]);
        $repository = new CalendarObjectRepository(Orm::create($DB));
        $backend = new Calendar();
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $CFG_GLPI['debug_sql'] = true;
        $DEBUG_SQL = [];
        $SQL_TOTAL_REQUEST = 0;
        foreach ($uids as $kind => $uid) {
            verify($repository->subjectsForUid($uid, [$kind, $kind]) === [['id' => $sameId, 'itemtype' => $kind]], 'Configured kinds and duplicate type entries retain one wide subject');
            verify($backend->getCalendarObject('user_' . Session::getLoginUserID(), $uid . '.ics') !== null, 'CalDAV URI resolves every core subject through ORM');
        }
        verify($repository->subjectsForUid("missing' OR 1=1 --", $CFG_GLPI['planning_types']) === [], 'UID queries bind literal values');
        $storage->update('glpi_projecttasks', $sameId, ['uuid' => $uids['PlanningExternalEvent']]);
        verify(count($repository->subjectsForUid($uids['PlanningExternalEvent'], $CFG_GLPI['planning_types'])) === 2
            && $backend->getCalendarObject('user_' . Session::getLoginUserID(), $uids['PlanningExternalEvent'] . '.ics') === null, 'An ambiguous UID across kinds fails closed');
        $storage->update('glpi_projecttasks', $sameId, ['uuid' => $uids['ProjectTask']]);
        verify($SQL_TOTAL_REQUEST === 0, 'CalDAV core UID lookup and conversion bypass adapter SQL: ' . json_encode($DEBUG_SQL['queries'] ?? []));

        $uid = 'created-calendar-' . bin2hex(random_bytes(8));
        $data = $calendarData($uid, "Created owner's event");
        $key = 'user_' . Session::getLoginUserID();
        $backend->createCalendarObject($key, $uid . '.ics', $data);
        $created = $repository->subjectsForUid($uid, ['PlanningExternalEvent'])[0]['id'];
        $object = new VObject();
        verify($object->getFromDBByCrit(['itemtype' => 'PlanningExternalEvent', 'items_id' => $created])
            && $object->fields['data'] === $data && (int)$object->fields['planningexternalevents_id'] === $created, 'Public CalDAV creation persists canonical subject and raw data');
        $objectId = $object->getID();
        $data = $calendarData($uid, "Updated owner's event");
        $backend->updateCalendarObject($key, $uid . '.ics', $data);
        verify($read($table, $objectId)['data'] === $data
            && (string)Reader::read($backend->getCalendarObject($key, $uid . '.ics')['calendardata'])->getBaseComponent()->{'X-ORM-TEST'} === "owner's preserved extension", 'Public CalDAV update reuses its calendar object and preserves extensions');
        $recall = $fixtures->create('glpi_planningrecalls', ['itemtype' => 'PlanningExternalEvent', 'items_id' => $created, 'users_id' => Session::getLoginUserID()]);
        $alert = $fixtures->create('glpi_alerts', ['itemtype' => 'PlanningRecall', 'items_id' => $recall, 'type' => Alert::ACTION]);
        $backend->deleteCalendarObject($key, $uid . '.ics');
        verify($read('glpi_planningexternalevents', $created) === null && $read($table, $objectId) === null
            && $read('glpi_planningrecalls', $recall) === null && $read('glpi_alerts', $alert) === null, 'CalDAV deletion uses the public purge lifecycle for data, recalls and delivery markers');

        $em = Orm::create($DB);
        $subject = new Record\ProjectTask();
        $subject->entities = $em->getReference(Record\Entity::class, 0);
        $native = new Record\VObject();
        $native->itemtype = 'ProjectTask';
        $native->projectTask = $subject;
        $native->data = $data;
        $em->persist($subject);
        $em->persist($native);
        $em->flush();
        $em->refresh($native);
        verify($native->items_id === $subject->id, 'Native subject and calendar data persist in one unit of work');
        $invalid = new Record\VObject();
        $invalid->itemtype = 'Reminder';
        $invalid->projectTask = $subject;
        try {
            $em->persist($invalid);
            $em->flush();
            throw new RuntimeException('Native mismatched calendar subject accepted');
        } catch (InvalidArgumentException) {
        }
        verify((new VObject())->delete(['id' => $native->id], true) && $read('glpi_projecttasks', $subject->id) !== null, 'Calendar data purge preserves its subject');
        foreach ($branches as $kind => $selection) {
            verify((new $kind())->delete(['id' => $sameId], true) && $read($table, $links[$kind]) === null, 'Public subject purge removes only its stored calendar data: ' . $kind);
            foreach (array_diff_key($links, [$kind => true]) as $otherKind => $link) {
                if ($read($branches[$otherKind]['target'], $sameId) !== null) {
                    verify($read($table, $link) !== null, 'Overlapping subject IDs remain isolated during purge');
                }
            }
        }
        verify((new ForeignKeys())->audit($connection) === [], 'Calendar lifecycle leaves no orphaned references');
    } finally {
        $DB->rollBack();
    }

    // Reconstruct this disposable table's nullable legacy discriminator and INT identity.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    $parent = $fixtures->create('glpi_planningexternalevents', ['id' => 950000101]);
    $legacyId = null;
    $columns = array_column($branches, 'column');
    try {
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
        $before = $manager->introspectTable($table);
        $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', $index->getColumns(), true));
        foreach ($indexes as $index) {
            $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
        $before = $manager->introspectTable($table);
        $legacy = clone $before;
        foreach ($legacy->getForeignKeys() as $foreign) {
            if (array_intersect($foreign->getLocalColumns(), $columns)) {
                $legacy->removeForeignKey($foreign->getName());
            }
        }
        foreach ($legacy->getIndexes() as $index) {
            if (array_intersect($index->getColumns(), $columns)) {
                $legacy->dropIndex($index->getName());
            }
        }
        foreach ($columns as $column) {
            $legacy->dropColumn($column);
        }
        $legacy->getColumn('itemtype')->setNotnull(false);
        $legacy->addColumn('items_id', 'integer', ['default' => 0]);
        foreach ($indexes as $index) {
            if ($index->isUnique()) {
                $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
            } else {
                $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
            }
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $data = ['itemtype' => 'PlanningExternalEvent', 'items_id' => $parent,
            'data' => $calendarData('preserved-upgrade', "Legacy owner's data"), 'date_creation' => '2026-01-01 12:00:00', 'date_mod' => '2026-01-02 12:00:00'];
        $connection->insert($table, $data);
        $legacyId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        foreach ([['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['items_id' => 999999999], ['items_id' => 0]] as $invalid) {
            $connection->update($table, $invalid, ['id' => $legacyId]);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('projecttasks_id'), 'Invalid legacy calendar subjects refuse before DDL');
            $connection->update($table, $data, ['id' => $legacyId]);
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD planningexternalevents_id BIGINT NULL');
        $connection->update($table, ['planningexternalevents_id' => $parent + 1], ['id' => $legacyId]);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'disagree');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('projecttasks_id'), 'Conflicting canonical calendar subjects refuse before DDL');
        $connection->update($table, ['planningexternalevents_id' => $parent], ['id' => $legacyId]);
        $migration->apply($connection);
        $DB->clearSchemaCache();
        $row = $read($table, $legacyId);
        verify((int)$row['items_id'] === $parent && (int)$row['planningexternalevents_id'] === $parent && $row['data'] === $data['data']
            && $row['date_creation'] === $data['date_creation'] && $row['date_mod'] === $data['date_mod'], 'Upgrade preserves identity, raw calendar data and timestamps');
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Completed calendar upgrade retry makes no changes');
        }
    } finally {
        if ($legacyId !== null) {
            $connection->delete($table, ['id' => $legacyId]);
        }
        $migration->apply($connection);
        (new ForeignKeys())->apply($connection);
        $connection->delete('glpi_planningexternalevents', ['id' => $parent]);
    }

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
echo $DB->getProvider() . ": six calendar subject FKs, public CalDAV CRUD, bound/ambiguous UID lookup, native persistence, wide overlapping IDs, purge and frozen upgrade passed.\n";
