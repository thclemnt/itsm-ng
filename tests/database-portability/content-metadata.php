<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ContentMetadataReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ContentRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/content-metadata.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$DB->beginTransaction();
try {
    $owner = $fixtures->create('glpi_users', ['name' => 'Content original', 'picture' => 'original.png']);
    $replacement = $fixtures->create('glpi_users', ['name' => 'Content replacement', 'picture' => 'replacement.png']);
    $other = $fixtures->create('glpi_users', ['name' => 'Other content author']);
    $records = $others = [];
    foreach (ReferenceHistory::get('optional', 'CONTENT_METADATA') as $table => $relations) {
        $columns = array_keys(array_filter($relations, static fn ($target) => $target === 'glpi_users'));
        $records[$table] = $fixtures->create($table, array_fill_keys($columns, $owner));
        $others[$table] = $fixtures->create($table, array_fill_keys($columns, $other));
    }
    $revisionCount = count((new KnowbaseItem_Revision())->find([]));
    $user = new User();
    verify($user->delete(['id' => $owner, '_replace_by' => $replacement], true), 'Replace content author');
    foreach (ReferenceHistory::get('optional', 'CONTENT_METADATA') as $table => $relations) {
        $model = getItemForItemtype(getItemTypeForTable($table));
        verify($model->getFromDB($records[$table]), 'Content retained after author replacement');
        foreach ($relations as $column => $target) {
            if ($target === 'glpi_users') {
                verify((int)$model->fields[$column] === $replacement, 'Author replacement: ' . $table . '.' . $column);
            }
        }
    }
    verify($user->delete(['id' => $replacement], true), 'Purge content author');
    foreach (ReferenceHistory::get('optional', 'CONTENT_METADATA') as $table => $relations) {
        $model = getItemForItemtype(getItemTypeForTable($table));
        verify($model->getFromDB($records[$table]), 'Historical content survives author purge');
        foreach ($relations as $column => $target) {
            if ($target === 'glpi_users') {
                verify($model->fields[$column] === null, 'Nullable historical author: ' . $table . '.' . $column);
                verify(count($model->find(['id' => $records[$table], $column => 0])) === 1, 'Legacy missing-author lookup');
            }
        }
        verify($model->getFromDB($others[$table]), 'Other content retained');
        foreach ($relations as $column => $target) {
            if ($target === 'glpi_users') {
                verify((int)$model->fields[$column] === $other, 'Other author retained');
            }
        }
    }
    verify(count((new KnowbaseItem_Revision())->find([])) === $revisionCount, 'Author cleanup does not create content revisions');
    $translation = new KnowbaseItemTranslation();
    verify($translation->update(['id' => $records['glpi_knowbaseitemtranslations'], 'name' => 'Edited translation']), 'Edit translated content after author purge');
    verify(count((new KnowbaseItem_Revision())->find([])) === $revisionCount + 1, 'Content edit still snapshots its previous revision');
    $comment = new KnowbaseItem_Comment();
    verify($comment->getFromDB($records['glpi_knowbaseitems_comments']), 'Load historical comment');
    $comments = (new \itsmng\Database\Repository\KnowledgeBaseRepository(Orm::create($DB)))->comments((int)$comment->fields['knowbaseitems_id'], $comment->fields['language']);
    verify(str_contains(KnowbaseItem_Comment::displayComments($comments, false), 'Unknown user'), 'Deleted comment author renders explicitly');
    $category = (new DocumentCategory())->add(['name' => 'Content category']);
    $categoryReplacement = (new DocumentCategory())->add(['name' => 'Content replacement category']);
    $document = $fixtures->create('glpi_documents', ['name' => 'Content document', 'users_id' => $other, 'documentcategories_id' => $category]);
    $cat = new DocumentCategory();
    verify($cat->delete(['id' => $category, '_replace_by' => $categoryReplacement], true), 'Replace document category');
    $doc = new Document();
    verify($doc->getFromDB($document) && (int)$doc->fields['documentcategories_id'] === (int)$categoryReplacement, 'Document category reassigned');
    verify($cat->delete(['id' => $categoryReplacement], true), 'Purge document category');
    verify($doc->getFromDB($document) && $doc->fields['documentcategories_id'] === null, 'Document survives category purge');

    $computer = $fixtures->create('glpi_computers', ['name' => 'Content computer']);
    $asset = new Computer();
    verify($asset->getFromDB($computer), 'Load note parent');
    $note = new Notepad();
    $noteId = $note->add(['itemtype' => 'Computer', 'items_id' => $computer, 'content' => "A note's text"]);
    verify((int)$noteId > 0, 'Create note through lifecycle');
    verify($note->update(['id' => $noteId, 'content' => 'Edited note']), 'Edit note');
    verify($note->getFromDB($noteId) && (int)$note->fields['users_id_lastupdater'] === (int)Session::getLoginUserID(), 'Content edit records current editor');
    $anonymousNote = $fixtures->create('glpi_notepads', ['itemtype' => 'Computer', 'items_id' => $computer, 'content' => 'Historical note', 'date_mod' => null]);
    $notes = Notepad::getAllForItem($asset);
    verify(count($notes) === 2 && $notes[0]['content'] === 'Edited note' && $notes[1]['users_id'] === null && $notes[1]['picture'] === null, 'Notes retain missing authors and order by edit date');
    $repo = new ContentRepository(Orm::create($DB));
    $assoc = $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Computer', 'items_id' => $computer, 'users_id' => $other]);
    $assoc2 = $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Computer', 'items_id' => $computer, 'timeline_position' => 1]);
    $scope = ['entities_id' => 0];
    $rows = $repo->documents('Computer', $computer, $scope, 'name', 'ASC');
    verify(array_column($rows, 'assocID') === [$assoc, $assoc2], 'Multiple timeline associations are not collapsed');
    verify($rows[0]['headings'] === null, 'Uncategorized document remains visible');
    verify($repo->documentIds('Computer', $computer) === [$document, $document], 'Cloning reads attachment references');
    $unusedCategory = $fixtures->create('glpi_documentcategories', ['name' => 'Unused heading']);
    $usedCategory = $fixtures->create('glpi_documentcategories', ['name' => 'Used heading']);
    $peerA = $fixtures->create('glpi_documents', ['name' => 'Peer A', 'documentcategories_id' => $usedCategory]);
    $peerB = $fixtures->create('glpi_documents', ['name' => 'Peer B']);
    $fixtures->create('glpi_documents_items', ['documents_id' => $peerA, 'itemtype' => 'Document', 'items_id' => $document]);
    $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Document', 'items_id' => $peerB]);
    verify(array_column($repo->documents('Document', $document, $scope, 'name', 'ASC'), 'id') === [$peerA, $peerB], 'Document links show the opposite endpoint in both directions');
    verify(array_column($repo->documentHeadings(), 'id') === [$usedCategory], 'Only used document headings selected');
    $foreign = (new Entity())->add(['name' => 'Foreign content entity', 'entities_id' => 0]);
    $hidden = $fixtures->create('glpi_documents', ['name' => 'Hidden document', 'entities_id' => $foreign]);
    $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Document', 'items_id' => $hidden]);
    verify(count($repo->documents('Document', $document, $scope, 'name', 'ASC')) === 2, 'Reverse link filters actual peer entity');
    verify($repo->documentCount(['entities_id' => $foreign]) === 1, 'Document count respects scope');
    $fixtures->create('glpi_documents', ['name' => 'Deleted document', 'entities_id' => $foreign, 'is_deleted' => true]);
    verify($repo->documentCount(['entities_id' => $foreign]) === 1, 'Document count excludes deleted records');
    foreach (['name', 'entity', 'filename', 'link', 'headings', 'mime', 'tag', 'assocdate'] as $sort) {
        verify(count($repo->documents('Computer', $computer, $scope, $sort, 'DESC')) === 2, 'Allowed ordering: ' . $sort);
    }
    $unnamed = $fixtures->create('glpi_documents', ['name' => null]);
    $fixtures->create('glpi_documents_items', ['documents_id' => $unnamed, 'itemtype' => 'Computer', 'items_id' => $computer]);
    verify(array_column($repo->documents('Computer', $computer, $scope, 'name', 'ASC'), 'id') === [$unnamed, $document, $document], 'Ascending document order places NULL names first on both engines');
    verify(array_column($repo->documents('Computer', $computer, $scope, 'name', 'DESC'), 'id') === [$document, $document, $unnamed], 'Descending document order places NULL names last on both engines');
    verify(str_contains(getUserName($other), 'Other content author'), 'Mapped user name lookup');
    verify(getUserName($owner) === '' && getUserName(0) === '', 'Missing user name lookup');
    $tooltip = getUserName($other, 2);
    verify(str_contains($tooltip['name'], 'Other content author') && str_contains($tooltip['comment'], 'Other content author'), 'User tooltip contract');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    Notepad::getAllForItem($asset);
    $repo->documents('Document', $document, $scope, 'name', 'ASC');
    $repo->documentHeadings();
    $repo->documentCount($scope);
    getUserName($other);
    verify($SQL_TOTAL_REQUEST === 0, 'Content queries and plain user names use ORM');
    verify((new ForeignKeys())->audit($connection) === [], 'Content relationship graph is valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new ContentMetadataReferences();
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'CONTENT_METADATA') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_documents');
    $connection->insert('glpi_documents', ['id' => $legacy, 'name' => 'Legacy content metadata']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy content migration has a plan');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_documents WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_documents', ['users_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned content metadata');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_documents')['users_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_documents', ['users_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_documents WHERE id = ?', [$legacy]) === null, 'Legacy author becomes NULL');
    verify($migration->apply($connection) === [], 'Content migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_documents', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": content authors, categories, document links, notes and migration passed.\n";
