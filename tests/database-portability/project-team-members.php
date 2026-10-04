<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ProjectTeamMembers;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\ProjectRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/project-team-members.php /path/to/test-config\n");
    exit(2);
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_projectteams', 'glpi_projecttaskteams'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $migration = new ProjectTeamMembers();
    $migration->apply($connection);
    $DB->clearSchemaCache();
    foreach ($migration->plan($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Idempotent team migration');
    }
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Login');
    $CFG_GLPI['use_notifications'] = false;
    $savedCache = $GLPI_CACHE;
    $GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(['memory_limit' => '256M']), GLPI_CACHE_DIR, false);
    $fixtures = new FixtureRecords($DB);
    $storage = new MappedStorage($DB);
    $read = static fn ($table, $id) => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
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
    $scopes = ['glpi_projectteams' => [ProjectTeam::class, 'projects_id', Record\ProjectTeam::class],
        'glpi_projecttaskteams' => [ProjectTaskTeam::class, 'projecttasks_id', Record\ProjectTaskTeam::class]];
    $members = ['User' => 'users', 'Group' => 'groups', 'Supplier' => 'suppliers', 'Contact' => 'contacts'];
    $DB->beginTransaction();
    try {
        $sameId = 900000071;
        $project = $fixtures->create('glpi_projects', ['name' => 'Typed team project']);
        $task = $fixtures->create('glpi_projecttasks', ['projects_id' => $project]);
        $other = $fixtures->create('glpi_projects');
        $parents = ['projects_id' => $project, 'projecttasks_id' => $task];
        $links = [];
        foreach ($members as $kind => $target) {
            $fixtures->create('glpi_' . $target, ['id' => $sameId, 'name' => 'Member ' . $kind]);
            foreach ($scopes as $table => [$publicClass, $column, $recordClass]) {
                $public = new $publicClass();
                $id = $public->add([$column => $parents[$column], 'itemtype' => $kind, 'items_id' => $sameId]);
                verify($id > 0 && (int)$public->fields[$target . '_id'] === $sameId && (int)$public->fields['items_id'] === $sameId, 'Public typed membership: ' . $table . '.' . $kind);
                $links[$table][$kind] = $id;
                $records = new RecordRepository(Orm::create($DB));
                verify($records->countMatching($table, [$column => $parents[$column], 'itemtype' => $kind, 'items_id' => $sameId]) === 1, 'Legacy criteria isolate overlapping member IDs');
                $reject(fn () => $connection->insert($table, [$column => $parents[$column], 'itemtype' => $kind, $target . '_id' => $sameId]), 'Uniqueness rejects duplicate membership');
                $reject(fn () => $connection->insert($table, [$column => $parents[$column], 'itemtype' => $kind, $target . '_id' => 999999999]), 'FK rejects unknown member');
            }
            $reject(fn () => $connection->delete('glpi_' . $target, ['id' => $sameId]), 'FK rejects member deletion outside lifecycle');
        }
        $secondContact = $fixtures->create('glpi_contacts', ['name' => 'Retargeted contact']);
        foreach ($scopes as $table => [$publicClass, $column, $recordClass]) {
            foreach ([['itemtype' => null, 'users_id' => $sameId], ['itemtype' => 'UnknownPlugin', 'users_id' => $sameId],
                ['itemtype' => 'User'], ['itemtype' => 'User', 'groups_id' => $sameId], ['itemtype' => 'User', 'users_id' => 0],
                ['itemtype' => 'User', 'users_id' => $sameId, 'groups_id' => $sameId]] as $invalid) {
                $reject(fn () => $connection->insert($table, $invalid + [$column => $parents[$column]]), 'Required member kind and exactly-one branch', $table . '_typed_item_kind');
            }
            $id = $links[$table]['User'];
            $changes = $storage->update($table, $id, ['itemtype' => 'Contact', 'items_id' => $secondContact]);
            $row = $read($table, $id);
            verify($row['users_id'] === null && (int)$row['contacts_id'] === $secondContact && in_array('items_id', $changes, true), 'Retarget clears old branch and reports logical identity');
            $storage->update($table, $id, ['itemtype' => 'User', 'users_id' => $sameId]);
            $storage->update($table, $id, []);
            verify((int)$read($table, $id)['items_id'] === $sameId && $read($table, $id)['contacts_id'] === null, 'Canonical retarget and partial update preserve exact member');
            foreach ([['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'User', 'items_id' => 0], ['itemtype' => 'User', 'users_id' => $sameId, 'groups_id' => $sameId]] as $invalid) {
                try {
                    $storage->update($table, $id, $invalid);
                    throw new RuntimeException('Invalid logical member accepted');
                } catch (InvalidArgumentException) {
                }
            }
        }
        $repository = new ProjectRepository(Orm::create($DB));
        foreach ($members as $kind => $target) {
            verify($repository->projectTeamMemberIds($project, $kind) === [$sameId] && $repository->taskTeamMemberIds($task, $kind) === [$sameId], 'Projection uses selected owning member');
        }
        $before = $SQL_TOTAL_REQUEST;
        $repository->projectTeamMemberIds($project, 'User');
        $repository->taskTeamMemberIds($task, 'Group');
        verify($SQL_TOTAL_REQUEST === $before, 'Warmed member projections bypass adapter queries');
        $publicProject = new Project();
        verify($publicProject->getFromDB($project), 'Load clone source');
        $clone = $publicProject->clone(['name' => 'Cloned typed team project']);
        verify($clone > 0, 'Public project clone');
        $clonedTeam = ProjectTeam::getTeamFor($clone);
        foreach ($members as $kind => $target) {
            verify(count($clonedTeam[$kind]) === 1 && (int)$clonedTeam[$kind][0][$target . '_id'] === $sameId, 'Clone preserves owning member: ' . $kind);
        }
        $em = Orm::create($DB);
        $nativeUser = new Record\User();
        $nativeUser->name = 'Native team user';
        $nativeUser->entities = $em->getReference(Record\Entity::class, 0);
        $nativeTeam = new Record\ProjectTeam();
        $nativeTeam->projects = $em->getReference(Record\Project::class, $project);
        $nativeTeam->itemtype = 'User';
        $nativeTeam->user = $nativeUser;
        $nativeTaskTeam = new Record\ProjectTaskTeam();
        $nativeTaskTeam->projecttasks = $em->getReference(Record\ProjectTask::class, $task);
        $nativeTaskTeam->itemtype = 'User';
        $nativeTaskTeam->user = $nativeUser;
        foreach ([$nativeUser, $nativeTeam, $nativeTaskTeam] as $record) {
            $em->persist($record);
        }
        $em->flush();
        $em->refresh($nativeTeam);
        verify($nativeUser->id > 0 && $nativeTeam->items_id === $nativeUser->id, 'Native members and parent persist together');
        foreach ([Record\ProjectTeam::class, Record\ProjectTaskTeam::class] as $class) {
            $invalidEm = Orm::create($DB);
            $invalid = new $class();
            $invalid->itemtype = 'Group';
            $invalid->user = $invalidEm->getReference(Record\User::class, $sameId);
            if ($invalid instanceof Record\ProjectTeam) {
                $invalid->projects = $invalidEm->getReference(Record\Project::class, $project);
            } else {
                $invalid->projecttasks = $invalidEm->getReference(Record\ProjectTask::class, $task);
            }
            try {
                $invalidEm->persist($invalid);
                $invalidEm->flush();
                throw new RuntimeException('Native invalid member accepted');
            } catch (InvalidArgumentException) {
            }
        }
        foreach ($members as $kind => $target) {
            verify((new $kind())->delete(['id' => $sameId], true), 'Public member purge: ' . $kind);
            foreach ($scopes as $table => [$publicClass, $column, $recordClass]) {
                verify($read($table, $links[$table][$kind]) === null, 'Member purge removes its own relation');
                foreach (array_keys($members) as $otherKind) {
                    if (!in_array($otherKind, array_slice(array_keys($members), 0, array_search($kind, array_keys($members)) + 1), true)) {
                        verify($read($table, $links[$table][$otherKind]) !== null, 'Member purge preserves overlapping kinds');
                    }
                }
            }
        }
        verify((new ProjectTask())->delete(['id' => $task], true), 'Public task purge');
        verify($read('glpi_projecttaskteams', $nativeTaskTeam->id) === null && $read('glpi_projectteams', $nativeTeam->id) !== null, 'Task purge isolates its team');
        verify((new Project())->delete(['id' => $project], true), 'Public project purge');
        verify($read('glpi_projectteams', $nativeTeam->id) === null, 'Project purge removes its team');
    } finally {
        $DB->rollback();
        $GLPI_CACHE = $savedCache;
    }

    // Reconstruct both legacy shapes in this disposable database, outside application transactions.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    $project = $fixtures->create('glpi_projects');
    $task = $fixtures->create('glpi_projecttasks', ['projects_id' => $project]);
    $user = $fixtures->create('glpi_users', ['id' => 950000143, 'name' => 'Frozen team member']);
    $parents = ['projects_id' => $project, 'projecttasks_id' => $task];
    $columns = array_map(static fn ($target) => $target . '_id', array_values($members));
    $legacyIds = [];
    $hasMemberIndex = static function (string $table) use ($manager): bool {
        foreach ($manager->listTableIndexes($table) as $index) {
            if (array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()) === ['itemtype', 'items_id']) {
                return true;
            }
        }
        return false;
    };
    try {
        foreach ($scopes as $table => [$publicClass, $column, $recordClass]) {
            $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
            $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
            $before = $manager->introspectTable($table);
            $legacyIndexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
            $temporaryParentIndex = $table . '_port_parent';
            if (!$platform instanceof PostgreSQLPlatform) {
                // The unique member key can be the only physical support for the owner FK.
                $connection->executeStatement('CREATE INDEX ' . $temporaryParentIndex . ' ON ' . $table . ' (' . $column . ')');
            }
            foreach ($legacyIndexes as $index) {
                $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
            }
            $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
            $before = $manager->introspectTable($table);
            $without = clone $before;
            foreach ($without->getForeignKeys() as $foreign) {
                if (array_intersect($foreign->getLocalColumns(), $columns)) {
                    $without->removeForeignKey($foreign->getName());
                }
            }
            foreach ($without->getIndexes() as $index) {
                if (array_intersect(array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), $columns)) {
                    $without->dropIndex($index->getName());
                }
            }
            foreach ($columns as $memberColumn) {
                $without->dropColumn($memberColumn);
            }
            $without->getColumn('itemtype')->setNotnull(false);
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $without)) as $sql) {
                $connection->executeStatement($sql);
            }
            $old = clone $without;
            $old->addColumn('items_id', 'integer', ['default' => 0]);
            foreach ($legacyIndexes as $index) {
                if ($index->isUnique()) {
                    $old->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
                } else {
                    $old->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                }
            }
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($without, $old)) as $sql) {
                $connection->executeStatement($sql);
            }
            if (!$platform instanceof PostgreSQLPlatform) {
                $connection->executeStatement($platform->getDropIndexSQL($temporaryParentIndex, $table));
            }
            verify($hasMemberIndex($table), 'Reconstruction preserves the legacy member index: ' . $table);
            $connection->insert($table, ['itemtype' => 'User', 'items_id' => $user, $column => $parents[$column]]);
            $legacyIds[$table] = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        }
        foreach ([['itemtype' => null, 'items_id' => $user], ['itemtype' => 'UnknownPlugin', 'items_id' => $user], ['itemtype' => 'User', 'items_id' => 999999999]] as $bad) {
            $connection->insert('glpi_projecttaskteams', $bad + ['projecttasks_id' => $task]);
            $id = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_projecttaskteams');
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable('glpi_projectteams')->hasColumn('users_id') && !$manager->introspectTable('glpi_projecttaskteams')->hasColumn('users_id'), 'Both tables audited before any DDL');
            $connection->delete('glpi_projecttaskteams', ['id' => $id]);
        }
        $connection->executeStatement('CREATE UNIQUE INDEX port_team_key ON glpi_projectteams (items_id)');
        $connection->executeStatement('CREATE TABLE port_team_dependency (member_id INTEGER NOT NULL, CONSTRAINT port_team_fk FOREIGN KEY (member_id) REFERENCES glpi_projectteams (items_id))');
        try {
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Incoming typed');
            }
            verify($failed && !$manager->introspectTable('glpi_projectteams')->hasColumn('users_id'), 'Incoming custom dependencies refuse before DDL');
        } finally {
            $connection->executeStatement('DROP TABLE port_team_dependency');
            $connection->executeStatement($platform->getDropIndexSQL('port_team_key', 'glpi_projectteams'));
        }
        verify($hasMemberIndex('glpi_projectteams'), 'Dependency probe preserves the legacy member index');
        $migration->apply($connection);
        $DB->clearSchemaCache();
        foreach ($legacyIds as $table => $id) {
            verify($hasMemberIndex($table), 'Upgrade preserves the legacy member index: ' . $table);
            $row = $read($table, $id);
            verify((int)$row['users_id'] === $user && (int)$row['items_id'] === $user, 'Upgrade preserves selected member');
        }
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry is idempotent');
        }
        echo $DB->getProvider() . ": typed project/team members, native/public writes, clone/purge, owning projections, FK/CHECK/uniqueness rejection, overlapping IDs and frozen upgrade passed.\n";
    } finally {
        foreach ($legacyIds as $table => $id) {
            $connection->delete($table, ['id' => $id]);
        }
        $migration->apply($connection);
        $DB->clearSchemaCache();
        $connection->delete('glpi_projecttasks', ['id' => $task]);
        $connection->delete('glpi_projects', ['id' => $project]);
        $connection->delete('glpi_users', ['id' => $user]);
    }

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
