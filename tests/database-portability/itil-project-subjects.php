<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ITILProjectSubjects;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\ItilProjectRepository;
use itsmng\Database\Repository\ITILTaskRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-project-subjects.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$connection = $DB->getDoctrineConnection();
$migration = new ITILProjectSubjects();
$migration->apply($connection);
$DB->clearSchemaCache();
foreach ($migration->plan($connection) as $entry) {
    verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Idempotent project subject migration');
}
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn ($id) => (new RecordRepository(Orm::create($DB)))->find('glpi_itils_projects', 'id', $id);
$reject = static function (callable $operation, string $message, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $failed = in_array($error->getSQLState(), ['23503', '23514', '23505', '23001', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$DB->beginTransaction();
try {
    $sameId = 900000031;
    $project = $fixtures->create('glpi_projects', ['name' => 'Project subject host']);
    $otherProject = $fixtures->create('glpi_projects', ['name' => 'Other project']);
    $links = [];
    foreach (['Ticket' => 'tickets', 'Problem' => 'problems', 'Change' => 'changes'] as $kind => $target) {
        $fixtures->create('glpi_' . $target, ['id' => $sameId, 'name' => 'Subject ' . $kind]);
        $public = new Itil_Project();
        $id = $public->add(['itemtype' => $kind, 'items_id' => $sameId, 'projects_id' => $project]);
        verify($id > 0 && (int)$public->fields[$target . '_id'] === $sameId && (int)$public->fields['items_id'] === $sameId, 'Public link uses canonical subject: ' . $kind);
        $links[$kind] = $id;
        $criteria = new RecordRepository(Orm::create($DB));
        verify($criteria->countMatching('glpi_itils_projects', ['itemtype' => $kind, 'items_id' => $sameId, 'projects_id' => $project]) === 1, 'Legacy criteria distinguish overlapping subjects');
        $repository = new ItilProjectRepository(Orm::create($DB));
        verify(array_column($repository->subjects($kind, $project), 'linkid') === [$id], 'Project tab has the typed subject');
        verify(array_column($repository->projects($kind, $sameId), 'id') === [$project], 'ITIL tab has the typed project');
        $reject(fn () => $connection->insert('glpi_itils_projects', ['itemtype' => $kind, $target . '_id' => $sameId, 'projects_id' => $project]), 'Database rejects duplicate logical link');
        $reject(fn () => $connection->insert('glpi_itils_projects', ['itemtype' => $kind, $target . '_id' => 999999999, 'projects_id' => $project]), 'Database rejects subject orphan');
        $reject(fn () => $connection->delete('glpi_' . $target, ['id' => $sameId]), 'Database rejects parent deletion outside lifecycle');
        $task = $fixtures->create('glpi_' . strtolower($kind) . 'tasks', [$target . '_id' => $sameId, 'content' => 'Project planning', 'begin' => '2031-01-01 10:00:00', 'end' => '2031-01-01 11:00:00']);
        verify(array_column((new ITILTaskRepository(Orm::create($DB)))->parentTasks($kind . 'Task', $sameId), 'id') === [$task], 'Project planning task query follows parent association');
    }
    foreach ([['itemtype' => 'Unknown'], ['itemtype' => 'Ticket'], ['itemtype' => 'Ticket', 'tickets_id' => 0], ['itemtype' => 'Ticket', 'problems_id' => $sameId], ['itemtype' => 'Ticket', 'tickets_id' => $sameId, 'problems_id' => $sameId]] as $invalid) {
        $reject(fn () => $connection->insert('glpi_itils_projects', $invalid + ['projects_id' => $otherProject]), 'Database rejects invalid subject branches', 'glpi_itils_projects_subject_kind');
    }
    $storage->update('glpi_itils_projects', $links['Ticket'], ['projects_id' => $otherProject]);
    verify((int)$read($links['Ticket'])['tickets_id'] === $sameId, 'Partial link update preserves subject');
    $storage->update('glpi_itils_projects', $links['Ticket'], ['itemtype' => 'Problem', 'items_id' => $sameId]);
    verify($read($links['Ticket'])['tickets_id'] === null && (int)$read($links['Ticket'])['problems_id'] === $sameId, 'Retarget replaces owning subject');
    $storage->update('glpi_itils_projects', $links['Ticket'], ['itemtype' => 'Ticket', 'tickets_id' => $sameId]);

    $em = Orm::create($DB);
    $parent = new Record\Ticket();
    $parent->entities = $em->getReference(Record\Entity::class, 0);
    $native = new Record\ItilProject();
    $native->projects = $em->getReference(Record\Project::class, $project);
    $native->itemtype = 'Ticket';
    $native->ticket = $parent;
    $em->persist($parent);
    $em->persist($native);
    $em->flush();
    verify($native->items_id === $parent->id, 'Native flush creates parent and refreshes generated subject');
    $em->remove($native);
    $em->flush();
    $em->clear();
    $invalid = new Record\ItilProject();
    $invalid->itemtype = 'Problem';
    $badEm = Orm::create($DB);
    $invalid->ticket = $badEm->getReference(Record\Ticket::class, $sameId);
    $invalid->projects = $badEm->getReference(Record\Project::class, $project);
    $failed = false;
    try {
        $badEm->persist($invalid);
        $badEm->flush();
    } catch (InvalidArgumentException $error) {
        $failed = true;
    }
    verify($failed, 'Native lifecycle rejects kind/association disagreement');

    $projectModel = new Project();
    verify($projectModel->getFromDB($project), 'Load project for tab');
    ob_start();
    Itil_Project::showForProject($projectModel);
    $html = ob_get_clean();
    verify(str_contains($html, 'Subject Problem') && str_contains($html, 'Subject Change') && str_contains($html, '2031'), 'Rendered project tab uses typed subjects and task dates');
    verify(str_contains($html, 'item[Itil_Project][' . $links['Problem'] . ']')
        && !str_contains($html, 'item[Itil_Project][' . $sameId . ']'), 'Project actions carry relationship IDs');
    $ticketModel = new Ticket();
    verify($ticketModel->getFromDB($sameId), 'Load ticket for project tab');
    ob_start();
    Itil_Project::showForItil($ticketModel);
    $html = ob_get_clean();
    verify(str_contains($html, 'Other project') && str_contains($html, 'item[Itil_Project][' . $links['Ticket'] . ']')
        && !str_contains($html, 'item[Itil_Project][' . $links['Problem'] . ']'), 'Rendered ITIL tab scopes its relation rows');

    // Warm repository reads execute on the supplied connection without legacy adapter SQL.
    $repository = new ItilProjectRepository(Orm::create($DB));
    $repository->subjects('Problem', $project);
    $before = $SQL_TOTAL_REQUEST ?? 0;
    $repository->subjects('Problem', $project);
    $repository->projects('Ticket', $sameId);
    verify(($SQL_TOTAL_REQUEST ?? 0) === $before, 'Typed projections use ORM without adapter queries');
    verify((new Ticket())->delete(['id' => $sameId], true), 'Public Ticket purge');
    verify($read($links['Ticket']) === null && $read($links['Problem']) !== null && $read($links['Change']) !== null, 'Ticket purge preserves overlapping Problem/Change links');
    verify((new Project())->delete(['id' => $project], true), 'Public project purge');
    verify($read($links['Problem']) === null && $read($links['Change']) === null, 'Project purge removes remaining typed links');
} finally {
    $DB->rollback();
}

// Reconstruct the old physical shape in this disposable database and exercise the upgrade.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$project = $fixtures->create('glpi_projects');
$subject = $fixtures->create('glpi_tickets', ['id' => 950000157, 'name' => 'Frozen subject upgrade parent']);
$legacyIds = [];
try {
    foreach (['glpi_itils_projects'] as $table) {
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_subject_kind');
        $before = $manager->introspectTable($table);
        $legacyIndexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
        foreach ($legacyIndexes as $index) {
            $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
        }
        // Drop the generated dependent before its source columns, regardless of DBAL's column order.
        $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
        $before = $manager->introspectTable($table);
        $without = clone $before;
        foreach ($without->getForeignKeys() as $foreign) {
            if (array_intersect($foreign->getLocalColumns(), ['tickets_id', 'problems_id', 'changes_id'])) {
                $without->removeForeignKey($foreign->getName());
            }
        }
        foreach ($without->getIndexes() as $index) {
            $columns = array_map(static fn ($column) => trim($column, '`"'), $index->getColumns());
            if (array_intersect($columns, ['items_id', 'tickets_id', 'problems_id', 'changes_id'])) {
                $without->dropIndex($index->getName());
            }
        }
        foreach (['tickets_id', 'problems_id', 'changes_id'] as $column) {
            $without->dropColumn($column);
        }
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
        $connection->insert($table, ['itemtype' => 'Ticket', 'items_id' => $subject, 'projects_id' => $project]);
        $legacyIds[$table] = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
    }
    foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $subject], ['itemtype' => 'Ticket', 'items_id' => 999999999]] as $bad) {
        $connection->insert('glpi_itils_projects', $bad + ['projects_id' => $project]);
        $id = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_itils_projects');
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
        }
        verify($failed && !$manager->introspectTable('glpi_itils_projects')->hasColumn('tickets_id'), 'All subject tables audited before any DDL');
        $connection->delete('glpi_itils_projects', ['id' => $id]);
    }
    $connection->executeStatement('CREATE UNIQUE INDEX port_subject_key ON glpi_itils_projects (items_id)');
    $connection->executeStatement('CREATE TABLE port_subject_dependency (subject_id INTEGER NOT NULL, CONSTRAINT port_subject_fk FOREIGN KEY (subject_id) REFERENCES glpi_itils_projects (items_id))');
    try {
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Incoming typed');
        }
        verify($failed && !$manager->introspectTable('glpi_itils_projects')->hasColumn('tickets_id'), 'Incoming custom dependencies refuse before any DDL');
    } finally {
        $connection->executeStatement('DROP TABLE port_subject_dependency');
        $connection->executeStatement($platform->getDropIndexSQL('port_subject_key', 'glpi_itils_projects'));
    }
    $migration->apply($connection);
    $DB->clearSchemaCache();
    foreach ($legacyIds as $table => $id) {
        verify((int)$read($id)['tickets_id'] === $subject && (int)$read($id)['items_id'] === $subject, 'Frozen upgrade preserves real parent');
    }
    foreach ($migration->apply($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry preserves canonical data');
    }
    echo $DB->getProvider() . ": typed ITIL project links, repository/rendered tabs, native/public lifecycle, FK/CHECK/uniqueness rejection, overlapping IDs and frozen upgrade passed.\n";
} finally {
    foreach ($legacyIds as $table => $id) {
        $connection->delete($table, ['id' => $id]);
    }
    $connection->delete('glpi_tickets', ['id' => $subject]);
    $connection->delete('glpi_projects', ['id' => $project]);
}
