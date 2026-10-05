<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\V220\PlanningRecallSubjects;
use itsmng\Database\Orm;
use itsmng\Database\Repository\PlanningRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/planning-recall-subjects.php /path/to/test-config\n");
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
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_planningrecalls'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $migration = new PlanningRecallSubjects();
    $migration->apply($connection);
    $DB->clearSchemaCache();
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Login');
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $table = 'glpi_planningrecalls';
    $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
    $kinds = array_keys($branches);
    $planningKinds = $CFG_GLPI['planning_types'];
    sort($kinds);
    sort($planningKinds);
    verify($kinds === $planningKinds && count($kinds) === 6, 'Every core planning kind has an owning subject association');
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
        $user = Session::getLoginUserID();
        $other = $fixtures->create('glpi_users', ['name' => 'Other typed recall recipient']);
        $sameId = 4294967800;
        $begin = '2030-01-01 12:00:00';
        $links = $alerts = [];
        foreach ($branches as $kind => $selection) {
            $field = $kind === 'ProjectTask' ? 'plan_start_date' : 'begin';
            $fixtures->create($selection['target'], ['id' => $sameId, $field => $begin]);
            $recall = new PlanningRecall();
            $id = $recall->add(['itemtype' => $kind, 'items_id' => $sameId, 'users_id' => $user, 'before_time' => 3600, 'when' => '2030-01-01 11:00:00']);
            verify($id > 0 && (int)$read($table, $id)[$selection['column']] === $sameId, 'Public recall selects its owning subject: ' . $kind);
            verify($recall->getFromDBForItemAndUser($kind, $sameId, $user) && (int)$recall->fields['items_id'] === $sameId, 'Legacy lookup retains wide, overlapping subject IDs');
            $links[$kind] = $id;
            $alerts[$kind] = $fixtures->create('glpi_alerts', ['itemtype' => 'PlanningRecall', 'items_id' => $id, 'type' => Alert::ACTION, 'date' => $begin]);
            $reject(fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => 999999999, 'users_id' => $user]), 'Native FK rejects an unknown planning subject');
            $reject(fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Native FK requires subject lifecycle cleanup');
        }
        foreach ([['itemtype' => 'ProjectTask'], ['itemtype' => 'UnknownPlugin', 'projecttasks_id' => $sameId],
            ['itemtype' => 'ProjectTask', 'planningexternalevents_id' => $sameId], ['itemtype' => 'ProjectTask', 'projecttasks_id' => 0],
            ['itemtype' => 'ProjectTask', 'projecttasks_id' => $sameId, 'reminders_id' => $sameId]] as $invalid) {
            $reject(fn () => $connection->insert($table, ['users_id' => $other] + $invalid), 'Native CHECK enforces one matching positive subject', 'glpi_planningrecalls_typed_item_kind');
        }
        $storage = new MappedStorage($DB);
        $id = $links['PlanningExternalEvent'];
        $changes = $storage->update($table, $id, ['users_id' => $other, 'itemtype' => 'ProjectTask', 'items_id' => $sameId]);
        verify($read($table, $id)['planningexternalevents_id'] === null && (int)$read($table, $id)['projecttasks_id'] === $sameId
            && in_array('items_id', $changes, true), 'Retarget clears the old association and reports the logical identity');
        $storage->update($table, $id, ['itemtype' => 'PlanningExternalEvent', 'users_id' => $user, 'items_id' => $sameId]);
        $SQL_TOTAL_REQUEST = 0;
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $repository = new PlanningRepository(Orm::create($DB));
        $repository->rescheduleRecalls('PlanningExternalEvent', $sameId, new DateTimeImmutable('2030-01-02 12:00:00'));
        foreach ($links as $kind => $link) {
            verify($read($table, $link)['when'] === ($kind === 'PlanningExternalEvent' ? '2030-01-02 11:00:00' : '2030-01-01 11:00:00'), 'Owning subject selection isolates overlapping kinds');
        }
        verify($SQL_TOTAL_REQUEST === 0, 'Recall rescheduling and mapped reads bypass adapter SQL');
        PlanningRecall::manageDatas(['itemtype' => 'PlanningExternalEvent', 'items_id' => $sameId, 'users_id' => $user, 'before_time' => 1800, 'field' => 'begin']);
        verify($read($table, $id)['before_time'] === 1800 && $read($table, $id)['when'] === '2030-01-01 11:30:00'
            && $read('glpi_alerts', $alerts['PlanningExternalEvent']) === null, 'Public recall edit recomputes its time and clears delivered markers');
        $em = Orm::create($DB);
        $subject = new Record\ProjectTask();
        $subject->entities = $em->getReference(Record\Entity::class, 0);
        $native = new Record\PlanningRecall();
        $native->itemtype = 'ProjectTask';
        $native->projectTask = $subject;
        $native->users = $em->getReference(Record\User::class, $user);
        $native->before_time = 0;
        $em->persist($subject);
        $em->persist($native);
        $em->flush();
        $em->refresh($native);
        verify($native->items_id === $subject->id, 'Native subject and recall persist in one unit of work');
        $invalidEm = Orm::create($DB);
        $invalid = new Record\PlanningRecall();
        $invalid->itemtype = 'Reminder';
        $invalid->projectTask = $invalidEm->getReference(Record\ProjectTask::class, $subject->id);
        $invalid->users = $invalidEm->getReference(Record\User::class, $user);
        try {
            $invalidEm->persist($invalid);
            $invalidEm->flush();
            throw new RuntimeException('Native mismatched recall subject accepted');
        } catch (InvalidArgumentException) {
        }
        verify((new PlanningRecall())->delete(['id' => $native->id], true), 'Public recall purge');
        verify($read('glpi_projecttasks', $subject->id) !== null, 'Recall purge preserves its subject');
        foreach ($branches as $kind => $selection) {
            verify((new $kind())->delete(['id' => $sameId], true), 'Public subject purge: ' . $kind);
            verify($read($table, $links[$kind]) === null && $read('glpi_alerts', $alerts[$kind]) === null, 'Subject purge removes its recalls and delivery markers');
            foreach (array_diff_key($links, [$kind => true]) as $otherKind => $link) {
                if ($read($branches[$otherKind]['target'], $sameId) !== null) {
                    verify($read($table, $link) !== null, 'Subject purge preserves overlapping IDs of other kinds');
                }
            }
        }
        verify((new ForeignKeys())->audit($connection) === [], 'No orphaned owning references');
    } finally {
        $DB->rollBack();
    }

    // Reconstruct only this legacy table in the disposable fixture; keep recipient FKs and indexes.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    $parent = $fixtures->create('glpi_planningexternalevents', ['id' => 950000081, 'begin' => '2030-01-01 12:00:00']);
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
        $legacy->addColumn('items_id', 'integer', ['default' => 0]);
        foreach ($indexes as $index) {
            $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $data = ['itemtype' => 'PlanningExternalEvent', 'items_id' => $parent, 'users_id' => Session::getLoginUserID(), 'before_time' => 3600, $platform->quoteIdentifier('when') => '2030-01-01 11:00:00'];
        $connection->insert($table, $data);
        $legacyId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        foreach ([['itemtype' => 'UnknownPlugin'], ['items_id' => 999999999], ['items_id' => 0]] as $invalid) {
            $connection->update($table, $invalid, ['id' => $legacyId]);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('projecttasks_id'), 'Invalid legacy subjects refuse before DDL');
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
        verify($failed && !$manager->introspectTable($table)->hasColumn('projecttasks_id'), 'Conflicting canonical subjects refuse before DDL');
        $connection->update($table, ['planningexternalevents_id' => $parent], ['id' => $legacyId]);
        $migration->apply($connection);
        $DB->clearSchemaCache();
        $row = $read($table, $legacyId);
        verify((int)$row['items_id'] === $parent && (int)$row['planningexternalevents_id'] === $parent && $row['before_time'] === 3600
            && $row['when'] === '2030-01-01 11:00:00', 'Upgrade retains logical identity, subject, scheduling date and recall policy');
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Completed upgrade retry makes no changes');
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
echo $DB->getProvider() . ": six recall subject FKs, native/public persistence, wide overlapping IDs, rescheduling, purge, migration refusal and retry passed.\n";
