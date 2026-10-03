<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ITILSubjects;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-subjects.php /path/to/test-config\n");
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
$migration = new ITILSubjects();
$migration->apply($connection);
if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
    $connection->executeStatement('DROP INDEX glpi_itilfollowups_tickets_id');
    $migration->apply($connection);
    verify(isset($connection->createSchemaManager()->listTableIndexes('glpi_itilfollowups')['glpi_itilfollowups_tickets_id']), 'Retry creates a real index rather than renaming a synthetic FK index');
}
$DB->clearSchemaCache();
foreach ($migration->plan($connection) as $entry) {
    verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'] && !$entry['copy_legacy'], 'Idempotent subject migration');
}
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
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
            $failed = in_array($error->getSQLState(), ['23503', '23514', '23001', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$DB->beginTransaction();
try {
    $sameId = 900000011;
    $parents = [];
    foreach (['Ticket' => 'tickets', 'Problem' => 'problems', 'Change' => 'changes'] as $kind => $target) {
        $parents[$kind] = $fixtures->create('glpi_' . $target, ['id' => $sameId, 'name' => 'Subject ' . $kind]);
    }
    $children = [];
    foreach (['glpi_itilfollowups', 'glpi_itilsolutions'] as $table) {
        foreach (['Ticket' => 'tickets', 'Problem' => 'problems', 'Change' => 'changes'] as $kind => $target) {
            $id = $storage->insert($table, ['itemtype' => $kind, 'items_id' => $sameId, 'content' => "Subject O\'Reilly"]);
            $children[$table][$kind] = $id;
            $row = $read($table, $id);
            verify((int)$row['items_id'] === $sameId && (int)$row[$target . '_id'] === $sameId, 'Generated logical subject: ' . $table . ' ' . $kind);
            verify(count(array_filter([$row['tickets_id'], $row['problems_id'], $row['changes_id']], static fn ($id) => $id !== null)) === 1, 'Only one stored subject');
            verify(count((new RecordRepository(Orm::create($DB)))->matching($table, ['itemtype' => $kind, 'items_id' => $sameId])) === 1, 'Legacy mapped criteria selects exact type');
            $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $target . '_id' => 999999999]), 'Raw orphan subject rejected');
            $reject(static fn () => $connection->delete('glpi_' . $target, ['id' => $sameId]), 'Raw subject delete rejected');
        }
        foreach ([['itemtype' => 'Ticket'], ['itemtype' => 'UnknownPlugin', 'tickets_id' => $sameId],
            ['itemtype' => 'Ticket', 'problems_id' => $sameId], ['itemtype' => 'Ticket', 'tickets_id' => $sameId, 'changes_id' => $sameId],
            ['itemtype' => 'Ticket', 'tickets_id' => 0]] as $invalid) {
            $reject(static fn () => $connection->insert($table, $invalid), 'Raw conflicting/missing/unsupported subject rejected', $table . '_subject_kind');
        }
        $failed = false;
        try {
            $storage->update($table, $children[$table]['Ticket'], ['itemtype' => 'Problem']);
        } catch (InvalidArgumentException $error) {
            $failed = true;
        }
        verify($failed && $read($table, $children[$table]['Ticket'])['itemtype'] === 'Ticket', 'Retargeting requires a selected new subject');
        $storage->update($table, $children[$table]['Ticket'], ['itemtype' => 'Problem', 'items_id' => $sameId]);
        verify($read($table, $children[$table]['Ticket'])['tickets_id'] === null && (int)$read($table, $children[$table]['Ticket'])['problems_id'] === $sameId, 'Retarget clears old association');
        $storage->update($table, $children[$table]['Ticket'], ['itemtype' => 'Ticket', 'tickets_id' => $sameId]);
    }
    $em = Orm::create($DB);
    foreach ([Record\ITILFollowup::class, Record\ITILSolution::class] as $class) {
        $subject = new Record\Ticket();
        $subject->entities = $em->getReference(Record\Entity::class, 0);
        $native = new $class();
        $native->itemtype = 'Ticket';
        $native->ticket = $subject;
        $em->persist($subject);
        $em->persist($native);
        $em->flush();
        verify($native->items_id === $subject->id, 'Native ORM creates parent and refreshes generated subject');
        $native->ticket = $em->getReference(Record\Ticket::class, $sameId);
        $em->flush();
        verify($native->items_id === $sameId, 'Native ORM association update refreshes compatibility ID');
        $em->remove($native);
        $em->flush();
        $invalid = new $class();
        $invalid->itemtype = 'Problem';
        $invalid->ticket = $em->getReference(Record\Ticket::class, $sameId);
        $failed = false;
        try {
            $badEm = Orm::create($DB);
            $invalid->ticket = $badEm->getReference(Record\Ticket::class, $sameId);
            $badEm->persist($invalid);
            $badEm->flush();
        } catch (InvalidArgumentException $error) {
            $failed = true;
        }
        verify($failed, 'Native lifecycle validates kind/association agreement');
    }
    $em->clear();
    $publicFollowup = new ITILFollowup();
    $publicId = $publicFollowup->add(['itemtype' => 'Ticket', 'items_id' => $sameId, 'content' => 'Public subject followup']);
    verify($publicId > 0 && (int)$publicFollowup->fields['tickets_id'] === $sameId && (int)$publicFollowup->fields['items_id'] === $sameId, 'Public followup preserves logical subject');
    verify($publicFollowup->update(['id' => $publicId, 'content' => 'Updated subject followup']) && $publicFollowup->getFromDB($publicId), 'Partial public update preserves parent');
    $publicSolution = new ITILSolution();
    $solutionId = $publicSolution->add(['itemtype' => 'Ticket', 'items_id' => $sameId, 'content' => 'Public subject solution']);
    verify($solutionId > 0 && (int)$publicSolution->fields['tickets_id'] === $sameId, 'Public solution preserves subject');
    $mergeSource = (new Ticket())->add(['name' => 'Typed merge source', 'content' => 'Source ticket']);
    $mergeTarget = (new Ticket())->add(['name' => 'Typed merge target', 'content' => 'Target ticket']);
    verify($mergeSource > 0 && $mergeTarget > 0, 'Create public merge tickets');
    $mergeFollowup = (new ITILFollowup())->add(['itemtype' => 'Ticket', 'items_id' => $mergeSource, 'content' => 'Typed merge followup']);
    $mergeDocument = $fixtures->create('glpi_documents', ['name' => 'Typed merge document']);
    verify($mergeFollowup > 0 && (new Document_Item())->add(['itemtype' => 'Ticket', 'items_id' => $mergeSource, 'documents_id' => $mergeDocument]) > 0, 'Create typed merge attachments');
    $mergeStatus = [];
    Ticket::merge($mergeTarget, [$mergeSource], $mergeStatus, ['linktypes' => ['ITILFollowup', 'Document']]);
    verify(($mergeStatus[$mergeSource] ?? null) === 0, 'Public ticket merge succeeds with typed attachments');
    $mergedFollowups = (new ITILFollowup())->find(['itemtype' => 'Ticket', 'items_id' => $mergeTarget, 'content' => 'Typed merge followup']);
    $mergedDocuments = (new Document_Item())->find(['itemtype' => 'Ticket', 'items_id' => $mergeTarget, 'documents_id' => $mergeDocument]);
    verify(count($mergedFollowups) === 1 && (int)reset($mergedFollowups)['tickets_id'] === $mergeTarget
        && count($mergedDocuments) === 1 && (int)reset($mergedDocuments)['tickets_id'] === $mergeTarget, 'Merged attachments select only the destination owning ticket association');
    // The parent lifecycle removes only its own timeline, even with overlapping IDs.
    verify((new Ticket())->delete(['id' => $sameId], true), 'Public ticket purge with typed timeline');
    foreach (['glpi_itilfollowups', 'glpi_itilsolutions'] as $table) {
        verify($read($table, $children[$table]['Ticket']) === null, 'Ticket subject children purged');
        verify($read($table, $children[$table]['Problem']) !== null && $read($table, $children[$table]['Change']) !== null, 'Other subject types survive overlapping IDs');
    }
} finally {
    $DB->rollback();
}

// Reconstruct the old physical shape in this disposable database and exercise the upgrade.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$subject = $fixtures->create('glpi_tickets', ['id' => 950000156, 'name' => 'Frozen subject upgrade parent']);
$legacyIds = [];
try {
    foreach (['glpi_itilfollowups', 'glpi_itilsolutions'] as $table) {
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
            $old->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($without, $old)) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert($table, ['itemtype' => 'Ticket', 'items_id' => $subject, 'content' => 'Frozen subject']);
        $legacyIds[$table] = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
    }
    foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $subject], ['itemtype' => 'Ticket', 'items_id' => 999999999]] as $bad) {
        $connection->insert('glpi_itilsolutions', $bad);
        $id = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_itilsolutions');
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
        }
        verify($failed && !$manager->introspectTable('glpi_itilfollowups')->hasColumn('tickets_id'), 'All subject tables audited before any DDL');
        $connection->delete('glpi_itilsolutions', ['id' => $id]);
    }
    $connection->executeStatement('CREATE UNIQUE INDEX port_subject_key ON glpi_itilsolutions (items_id)');
    $connection->executeStatement('CREATE TABLE port_subject_dependency (subject_id INTEGER NOT NULL, CONSTRAINT port_subject_fk FOREIGN KEY (subject_id) REFERENCES glpi_itilsolutions (items_id))');
    try {
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Incoming typed');
        }
        verify($failed && !$manager->introspectTable('glpi_itilfollowups')->hasColumn('tickets_id'), 'Incoming custom dependencies refuse before any DDL');
    } finally {
        $connection->executeStatement('DROP TABLE port_subject_dependency');
        $connection->executeStatement($platform->getDropIndexSQL('port_subject_key', 'glpi_itilsolutions'));
    }
    $migration->apply($connection);
    $DB->clearSchemaCache();
    foreach ($legacyIds as $table => $id) {
        verify((int)$read($table, $id)['tickets_id'] === $subject && (int)$read($table, $id)['items_id'] === $subject, 'Frozen upgrade preserves real parent');
    }
    foreach ($migration->apply($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry preserves canonical data');
    }
    echo $DB->getProvider() . ": typed ITIL subjects, native/public lifecycle, FK/CHECK rejection, overlapping IDs and frozen upgrade passed.\n";
} finally {
    foreach ($legacyIds as $table => $id) {
        $connection->delete($table, ['id' => $id]);
    }
    $connection->delete('glpi_tickets', ['id' => $subject]);
}
