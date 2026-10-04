<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\DocumentSubjects;
use itsmng\Database\Migration\DomainDocuments20261006;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ContentRepository;
use itsmng\Database\Repository\TransferBindingRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/document-subjects.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
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
$migration = new DocumentSubjects();
$frozenTargets = (new ReflectionMethod(DocumentSubjects::class, 'targets'))->invoke(null);
verify(count($frozenTargets) === 33 && !isset($frozenTargets['Domain']), 'Historical document migration retains its frozen thirty-three subjects');
$migration->apply($connection);
$DB->clearSchemaCache();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$configuration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$reject = static function (callable $operation, string $message, ?string $omittedRequiredColumn = null, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $failed = NativeConstraintRefusal::matches($error, $omittedRequiredColumn)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$table = 'glpi_documents_items';
$branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
$expected = $CFG_GLPI['document_types'];
$actual = array_keys($branches);
sort($expected);
sort($actual);
verify($actual === $expected && count($branches) === 34, 'Every configured document subject has an owning association');
$DB->beginTransaction();
try {
    $sameId = 4294968601;
    $document = $fixtures->create('glpi_documents', ['name' => "Attachment O'Reilly"]);
    $otherDocument = $fixtures->create('glpi_documents', ['name' => 'Other attachment']);
    $owner = $fixtures->create('glpi_entities');
    $author = $fixtures->create('glpi_users', ['name' => 'Separate author']);
    $links = [];
    foreach ($branches as $kind => $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId]);
        $model = new Document_Item();
        $id = $model->add(['documents_id' => $document, 'itemtype' => $kind, 'items_id' => $sameId,
            'users_id' => $author, 'entities_id' => $owner, 'timeline_position' => 0, '_do_update_ticket' => false]);
        verify($id > 0 && $model->fields[$selection['column']] === $sameId && $model->fields['items_id'] === $sameId, 'Public owning document subject: ' . $kind);
        verify($model->fields['documents_id'] === $document && $model->fields['users_id'] === $author && $model->fields['entities_id'] === $owner, 'Parent, author and scope remain independent: ' . $kind);
        $links[$kind] = $id;
        $reject(static fn () => $connection->insert($table, ['documents_id' => $document, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Orphan rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Subject purge restricted: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['documents_id' => $document, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate rejected: ' . $kind);
        $manager = Orm::create($DB);
        $native = new Record\DocumentItem();
        $native->documents = $manager->getReference(Record\Document::class, $otherDocument);
        $native->entities = $manager->getReference(Record\Entity::class, $owner);
        $native->users = $manager->getReference(Record\User::class, $author);
        $native->itemtype = $kind;
        $association = Record\DocumentItem::referenceAssociation($kind);
        $target = $manager->getClassMetadata(Record\DocumentItem::class)->getAssociationTargetClass($association);
        $native->{$association} = $manager->getReference($target, $sameId);
        $manager->persist($native);
        $manager->flush();
        verify($native->items_id === $sameId, 'Native graph generates legacy identity: ' . $kind);
        $manager->remove($native);
        $manager->flush();
        $manager->clear();
    }
    foreach ([[], ['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'Entity'], ['itemtype' => 'Entity', 'subject_entities_id' => null], ['itemtype' => 'Entity', 'subject_entities_id' => -1], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert($table, $invalid + ['documents_id' => $otherDocument]), 'Missing/unknown/negative/zero/wrong/multiple branch rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, 'glpi_documents_items_typed_item_kind');
    }
    $rootLink = new Document_Item();
    $root = $rootLink->add(['documents_id' => $document, 'itemtype' => 'Entity', 'items_id' => 0, 'users_id' => $author, 'entities_id' => $owner]);
    verify($root > 0 && $rootLink->fields['subject_entities_id'] === 0 && $rootLink->fields['items_id'] === 0
        && $rootLink->fields['entities_id'] === $owner, 'Public root entity zero is selected independently of attachment ownership');
    $manager = Orm::create($DB);
    $nativeRoot = new Record\DocumentItem();
    $nativeRoot->documents = $manager->getReference(Record\Document::class, $otherDocument);
    $nativeRoot->entities = $manager->getReference(Record\Entity::class, $owner);
    $nativeRoot->subjectEntity = $manager->getReference(Record\Entity::class, 0);
    $nativeRoot->itemtype = 'Entity';
    $manager->persist($nativeRoot);
    $manager->flush();
    verify($nativeRoot->items_id === 0, 'Native root identity is zero, not NULL');
    $manager->remove($nativeRoot);
    $manager->flush();
    $manager->clear();
    verify(!(new Document_Item())->add(['documents_id' => $document, 'itemtype' => 'Document', 'items_id' => $document]), 'Public document self-link remains rejected');
    $secondPosition = $fixtures->create($table, ['documents_id' => $document, 'itemtype' => 'Computer', 'items_id' => $sameId, 'timeline_position' => 1]);
    $repo = new ContentRepository(Orm::create($DB));
    $SQL_TOTAL_REQUEST = 0;
    verify($repo->documentIds('Computer', $sameId) === [$document, $document] && $repo->documentIds('Entity', 0) === [$document], 'Owning attachment lookup retains timeline rows and real root');
    verify(count($repo->documents('Computer', $sameId, ['entities_id' => 0], 'name', 'ASC')) === 2
        && $repo->documents('Unsupported', $sameId, [], 'name', 'ASC') === [], 'Mapped subject listing isolates kinds');
    verify(count($repo->documents('Entity', 0, ['entities_id' => 0], 'name', 'ASC')) === 1 && $SQL_TOTAL_REQUEST === 0, 'Root subject listing uses ORM');
    $back = $fixtures->create($table, ['documents_id' => $sameId, 'itemtype' => 'Document', 'items_id' => $otherDocument]);
    verify(array_column($repo->documents('Document', $sameId, ['entities_id' => 0], 'name', 'ASC'), 'id') === [$document, $otherDocument], 'Document associations show the opposite endpoint in either direction');
    $transfer = TransferBindingRepository::documents(Orm::create($DB));
    verify(count($transfer->links('User', $sameId)) === 1 && count($transfer->links('Entity', 0)) === 1, 'Transfer uses selected user/root associations');
    $retargetEntity = $fixtures->create('glpi_entities');
    $transfer->move($root, null, $retargetEntity);
    verify($read($table, $root)['subject_entities_id'] === $retargetEntity && $read($table, $root)['entities_id'] === $owner, 'Root retarget updates subject without changing ownership');
    $transfer->move($root, null, 0);
    $copy = $transfer->copy($otherDocument, 'Entity', 0);
    verify($read($table, $copy)['subject_entities_id'] === 0, 'Transfer copy preserves a selected root');
    $transfer->unlink('Entity', 0);
    verify($read($table, $root) === null && $read($table, $copy) === null && $read($table, $links['User']) !== null, 'Root unlink isolates subject kind');
    $retargetMonitor = $fixtures->create('glpi_monitors');
    $storage->update($table, $links['Computer'], Record\DocumentItem::withReference([], 'Monitor', $retargetMonitor));
    verify($read($table, $links['Computer'])['computers_id'] === null && $read($table, $links['Computer'])['monitors_id'] === $retargetMonitor, 'Retarget clears the old owning branch');
    // Author purge retains attachments; selected-user purge removes its own links.
    verify((new User())->delete(['id' => $author], true), 'Public author purge');
    verify($read($table, $links['User'])['users_id'] === null && $read($table, $links['User'])['subject_users_id'] === $sameId, 'Author purge retains the distinct subject user');
    verify((new User())->delete(['id' => $sameId], true), 'Public subject user purge');
    verify($read($table, $links['User']) === null && $read($table, $links['Monitor']) !== null, 'Subject user purge removes only its selected links');
    verify((new Document())->delete(['id' => $sameId], true), 'Public linked document purge');
    verify($read($table, $links['Document']) === null && $read($table, $back) === null && $read('glpi_documents', $document) !== null, 'Linked-document purge cleans both roles and preserves unrelated documents');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $configuration;
}

// Frozen upgrade checks reconstruct only these owned disposable fixture tables.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
foreach ([['Document', 'glpi_documents_items', 'documents_id']] as [$type, $table, $parentColumn]) {
    $nativeExact = new ExactSubjectHistoricalFixture($connection, [$table]);
    $computer = $fixtures->create('glpi_computers', ['id' => 950000172]);
    $parentTable = (new $type())->getTable();
    $parent = $fixtures->create($parentTable);
    $id = $rootId = null;
    $domainStage = new DomainDocuments20261006();
    $domainState = Ledger::state($connection, DomainDocuments20261006::VERSION);
    $historicalStarted = false;
    $historicalPrimary = null;
    $historicalCleanup = [];
    try {
        $nativeExact->beginOwnedAlteration();
        $historicalStarted = true;
        $connection->delete('itsmng_migrations', ['version' => DomainDocuments20261006::VERSION]);
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
        $before = $manager->introspectTable($table);
        $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
        // MariaDB may use the composite unique index to support the parent FK.
        // Keep that FK enforceable while reconstructing the old scalar identity.
        $support = new \Doctrine\DBAL\Schema\Index($table . '_fixture_parent', [$parentColumn]);
        $connection->executeStatement($platform->getCreateIndexSQL($support, $table));
        foreach ($indexes as $index) {
            $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
        $before = $manager->introspectTable($table);
        $legacy = clone $before;
        $columns = array_column($branches, 'column');
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
            if ($index->isUnique()) {
                $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
            } else {
                $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
            }
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->executeStatement($platform->getDropIndexSQL($support->getName(), $table));
        $connection->insert($table, [$parentColumn => $parent, 'itemtype' => 'Computer', 'items_id' => $computer]);
        $id = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        $connection->insert($table, [$parentColumn => $parent, 'itemtype' => 'Entity', 'items_id' => 0]);
        $rootId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Entity', 'items_id' => -1]] as $bad) {
            $connection->insert($table, $bad + [$parentColumn => $parent]);
            $badId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Invalid legacy asset refuses before DDL: ' . $type);
            $connection->delete($table, ['id' => $badId]);
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD computers_id BIGINT NULL');
        $connection->update($table, ['computers_id' => $computer + 1], ['id' => $id]);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'disagree');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('monitors_id'), 'Conflicting canonical/legacy asset refuses before DDL: ' . $type);
        $connection->update($table, ['computers_id' => $computer], ['id' => $id]);
        $migration->apply($connection);
        verify(!$manager->introspectTable($table)->hasColumn('domains_id'), 'Frozen historical replay does not acquire the later Domain subject');
        $domainStage->apply($connection);
        $DB->clearSchemaCache();
        $row = $read($table, $id);
        verify($read($table, $rootId)['subject_entities_id'] === 0 && $read($table, $rootId)['items_id'] === 0, 'Upgrade preserves real root subject zero');
        verify($row['computers_id'] === $computer && $row['items_id'] === $computer && $row[$parentColumn] === $parent, 'Upgrade preserves parent/asset/relation identifiers: ' . $type);
        foreach ($indexes as $index) {
            verify($manager->introspectTable($table)->getIndex($index->getName())->isUnique() === $index->isUnique(), 'Upgrade preserves index uniqueness: ' . $type);
        }
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry is idempotent: ' . $type);
        }
        verify($domainStage->plan($connection) === [], 'Appended Domain document stage is idempotent after historical replay');
    } catch (Throwable $error) {
        $historicalPrimary = $error;
    } finally {
        try {
            if ($rootId !== null) {
                $connection->delete($table, ['id' => $rootId]);
            }
            if ($id !== null) {
                $connection->delete($table, ['id' => $id]);
            }
            $connection->delete($parentTable, ['id' => $parent]);
            $connection->delete('glpi_computers', ['id' => $computer]);
            if ($historicalStarted) {
                $domainStage->apply($connection);
                if ($domainState !== null) {
                    Ledger::save($connection, DomainDocuments20261006::VERSION, $domainState);
                }
            }
            $DB->clearSchemaCache();
        } catch (Throwable $error) {
            $historicalCleanup[] = $error;
        }
        try {
            $nativeExact->restore();
        } catch (Throwable $error) {
            $historicalCleanup[] = $error;
        }
    }
    foreach ($historicalCleanup as $error) {
        try {
            fwrite(STDERR, 'Additional historical fixture cleanup failure: ' . $error::class . "\n");
        } catch (Throwable) {
        }
    }
    if ($historicalPrimary !== null) {
        throw $historicalPrimary;
    }
    if ($historicalCleanup !== []) {
        throw new RuntimeException('Historical document fixture cleanup failed.', previous: $historicalCleanup[0]);
    }
}
echo "PASS: thirty-four current document subject FKs and frozen thirty-three-subject replay, real root, independent roles, native/public writes, queries, transfer, purge and frozen upgrade\n";
