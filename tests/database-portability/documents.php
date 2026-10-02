<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/documents.php /path/to/test-config\n");
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
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'Document scope', 'entities_id' => 0]);
    $outside = $fixtures->create('glpi_entities', ['id' => $entityId + 1, 'name' => 'Outside document scope', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [0, $entity];
    $_SESSION['glpiactiveentities_string'] = '0,' . $entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $user = (int)Session::getLoginUserID();
    $otherUser = $fixtures->create('glpi_users', ['name' => 'Document other author']);
    $category = $fixtures->create('glpi_documentcategories', ['name' => 'Visible heading']);
    $hiddenCategory = $fixtures->create('glpi_documentcategories', ['name' => 'Hidden heading']);
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Document source ticket', 'entities_id' => $entity]);
    $doc = new Document();
    $document = $doc->add(['name' => 'Mapped document', 'tickets_id' => $ticket, 'entities_id' => $entity, 'documentcategories_id' => $category]);
    verify($document > 0 && $doc->getFromDB($document) && $doc->fields['tickets_id'] === $ticket, 'Document lifecycle persists its optional ticket');
    $orphan = $fixtures->create('glpi_documents', ['name' => 'Unbound document']);
    $hidden = $fixtures->create('glpi_documents', ['entities_id' => $outside, 'documentcategories_id' => $hiddenCategory]);
    $hash = sha1('Local document ORM fixture');
    $content = $fixtures->create('glpi_documents', ['entities_id' => $entity, 'sha1sum' => $hash, 'documentcategories_id' => $category]);
    $fixtures->create('glpi_documents', ['entities_id' => $outside, 'sha1sum' => $hash]);
    $localFile = tempnam(sys_get_temp_dir(), 'itsm-doc-orm-');
    file_put_contents($localFile, 'Local document ORM fixture');
    try {
        verify($doc->getFromDBbyContent($entity, $localFile) && $doc->getID() === $content, 'Content lookup hashes a local file and matches the exact entity');
        verify(!$doc->getFromDBbyContent(0, $localFile), 'Content lookup does not cross entity scope');
    } finally {
        unlink($localFile);
    }
    $fixtures->create('glpi_documenttypes', ['name' => 'ORM upload', 'ext' => 'ormdoc', 'icon' => 'defaut-dist.png', 'is_uploadable' => true]);
    $fixtures->create('glpi_documenttypes', ['name' => 'ORM forbidden upload', 'ext' => 'ormdisabled', 'is_uploadable' => false]);
    $fixtures->create('glpi_documenttypes', ['name' => 'ORM regex upload', 'ext' => '/^orm[0-9]+$/', 'is_uploadable' => true]);
    $repo = static fn () => new \itsmng\Database\Repository\DocumentRepository(Orm::create($DB));
    // Build entity-scope inputs before measuring repository execution.
    $scope = ['is_deleted' => false] + getEntitiesRestrictCriteria('glpi_documents', '', $entity, true);
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    verify(Document::isValidDoc('file.OrMdOc') === 'ORMDOC' && Document::isValidDoc('file.ormdisabled') === '', 'Extension matching is case-insensitive and respects upload permission');
    verify(Document::isValidDoc('file.orm42') === 'ORM42' && Document::isValidDoc('file.ormbad') === '', 'Configured regular expressions remain supported');
    verify($repo()->icon('ORMDOC') === 'defaut-dist.png', 'Icon lookup is case-insensitive');
    verify(isset($repo()->categories($scope)[$category]) && !isset($repo()->categories($scope)[$hiddenCategory]), 'Headings obey document entity scope');
    verify(!isset($repo()->categories($scope + ['NOT' => ['id' => [$document, $content]]])[$category]), 'Used document exclusion removes an otherwise empty heading');
    verify($SQL_TOTAL_REQUEST === 0, 'Content/type/heading queries bypass adapter SQL');
    $bind = static fn (int $docid, string $type, int $id): int => $fixtures->create('glpi_documents_items', ['documents_id' => $docid, 'itemtype' => $type, 'items_id' => $id, 'entities_id' => $entity]);
    $ticketBinding = $bind($document, 'Ticket', $ticket);
    $timelineBinding = $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Ticket', 'items_id' => $ticket, 'entities_id' => $entity, 'timeline_position' => 1]);
    $bindings = $repo()->bindingsForItem('Ticket', $ticket);
    verify(array_column($bindings, 'id') === [$ticketBinding, $timelineBinding]
        && array_column($bindings, 'documents_id') === [$document, $document], 'Document selection preserves individual binding identities and timeline roles');
    verify(count($repo()->documentsForItem('Ticket', $ticket)) === 2, 'Shared document selection preserves one row per binding');
    verify($repo()->bindingsForItem('Problem', $ticket) === [] && $repo()->bindingsForItem('Ticket', 0) === [], 'Binding selection distinguishes subject kinds and absent targets');
    $attachmentFile = tempnam(GLPI_TMP_DIR, 'orm-linked-document-');
    try {
        file_put_contents($attachmentFile, 'Binding fixture');
        $connection->update('glpi_documents', ['filename' => 'Binding fixture.txt', 'filepath' => '_tmp/' . basename($attachmentFile)], ['id' => $document]);
        require_once GLPI_ROOT . '/src/twig/twig.utils.php';
        $SQL_TOTAL_REQUEST = 0;
        $options = getLinkedDocumentsForItem('Ticket', $ticket);
        verify(array_keys($options) === [$ticketBinding, $timelineBinding]
            && str_contains($options[$ticketBinding], 'Binding fixture.txt (15B)')
            && str_contains($options[$ticketBinding], $doc->getFormURLWithID($document)), 'Actual form document helper retains binding keys, model URLs, file names and sizes');
        verify($SQL_TOTAL_REQUEST === 0 && getLinkedDocumentsForItem('Problem', $ticket) === [], 'Form document selector bypasses adapter queries and keeps subject scope');
    } finally {
        unlink($attachmentFile);
    }
    verify(!in_array($document, $repo()->orphanIds(), true) && in_array($orphan, $repo()->orphanIds(), true), 'Orphan selector excludes bound documents without executing cleanup');
    $none = new \itsmng\Database\ITILDocumentAccess($user, false, false, false, false, false);
    verify($repo()->linkedToITIL($document, 'Ticket', $ticket, $none), 'Direct ITIL association requires no child-object rights');
    verify(!$repo()->linkedToITIL($document, 'Problem', $ticket, $none), 'Direct attachment distinguishes ITIL type');
    foreach (['Ticket', 'Change', 'Problem'] as $type) {
        $parent = $type === 'Ticket' ? $ticket : $fixtures->create($type::getTable(), ['name' => 'Attachment parent']);
        foreach (['followup', 'task', 'solution'] as $kind) {
            $childType = match ($kind) {
                'followup' => 'ITILFollowup', 'task' => $type . 'Task', 'solution' => 'ITILSolution'
            };
            $values = $kind === 'task' ? [$type::getForeignKeyField() => $parent] : ['itemtype' => $type, 'items_id' => $parent];
            if ($kind !== 'solution') {
                $values += ['is_private' => true, 'users_id' => $otherUser];
            }
            $child = $fixtures->create($childType::getTable(), $values);
            $attachment = $fixtures->create('glpi_documents', ['name' => $type . ' ' . $kind . ' attachment']);
            $bind($attachment, $childType, $child);
            verify(!$repo()->linkedToITIL($attachment, $type, $parent, $none), 'No child rights grant no attachment: ' . $childType);
            $publicOnly = new \itsmng\Database\ITILDocumentAccess($user, true, false, true, true, false);
            verify($repo()->linkedToITIL($attachment, $type, $parent, $publicOnly) === ($kind === 'solution'), 'Private child scope: ' . $childType);
            $owner = new \itsmng\Database\ITILDocumentAccess($otherUser, true, false, true, true, false);
            verify($repo()->linkedToITIL($attachment, $type, $parent, $owner), 'Private author can see own child attachment: ' . $childType);
            $private = new \itsmng\Database\ITILDocumentAccess($user, true, true, true, true, true);
            verify($repo()->linkedToITIL($attachment, $type, $parent, $private), 'Private child right grants attachment: ' . $childType);
        }
    }
    $reminder = $fixtures->create('glpi_reminders', ['name' => 'Attachment reminder', 'users_id' => $user]);
    $bind($document, 'Reminder', $reminder);
    $shared = new \itsmng\Database\Repository\SharedContentRepository(Orm::create($DB));
    $ownerAccess = new \itsmng\Database\SharedContentAccess($user, false, [], 0, [$entity], []);
    verify($shared->reminderHasDocument($document, $ownerAccess), 'Reminder owner sees attachment without public reminder rights');
    verify(!$shared->reminderHasDocument($document, new \itsmng\Database\SharedContentAccess($otherUser, false, [], 0, [$entity], [])), 'Unrelated reminder viewer denied');
    $article = $fixtures->create('glpi_knowbaseitems', ['name' => 'FAQ attachment', 'is_faq' => true]);
    $faqDoc = $fixtures->create('glpi_documents', ['name' => 'FAQ-only document']);
    $bind($faqDoc, 'KnowbaseItem', $article);
    $kb = new \itsmng\Database\Repository\KnowledgeBaseRepository(Orm::create($DB));
    $publicFaq = new \itsmng\Database\KnowledgeBaseAccess(0, false, false, true, true, [], 0, [], []);
    verify(!$kb->hasDocument($faqDoc, $publicFaq), 'Multi-entity public FAQ requires an explicit root grant');
    $fixtures->create('glpi_entities_knowbaseitems', ['knowbaseitems_id' => $article, 'entities_id' => 0, 'is_recursive' => true]);
    verify($kb->hasDocument($faqDoc, $publicFaq), 'Public recursive-root FAQ exposes only its attached document');
    verify(!$kb->hasDocument($orphan, $publicFaq), 'Public FAQ grant cannot expose an unbound document');
    $doc->getFromDB($document);
    $replacement = $fixtures->create('glpi_tickets', ['name' => 'Replacement document ticket']);
    verify((new Ticket())->delete(['id' => $ticket, '_replace_by' => $replacement], true), 'Ticket replacement maintains optional document origin');
    verify($doc->getFromDB($document) && $doc->fields['tickets_id'] === $replacement, 'Document origin reassigned');
    verify((new Ticket())->delete(['id' => $replacement], true), 'Ticket purge keeps its document');
    verify($doc->getFromDB($document) && $doc->fields['tickets_id'] === null, 'Purged origin becomes NULL');
    verify($doc->update(['id' => $document, 'tickets_id' => 0]) && $doc->getFromDB($document) && $doc->fields['tickets_id'] === null, 'Legacy empty-ticket writes normalize to NULL');
    verify((new ForeignKeys())->audit($connection) === [], 'Document graph remains valid');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new \itsmng\Database\Migration\DocumentTicketReferences();
$table = 'glpi_documents';
$column = 'tickets_id';
$ids = [];
$legacyTicket = null;
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
    $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->getColumn($column)->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $legacyTicket = $fixtures->create('glpi_tickets', ['name' => 'Preserved document ticket']);
    $firstId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_documents');
    foreach ([0, $legacyTicket] as $offset => $value) {
        $ids[] = $firstId + $offset;
        $connection->insert($table, ['id' => $firstId + $offset, 'name' => 'Legacy document migration', $column => $value]);
    }
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && $plan['counts'][$table . '.' . $column] > 0, 'Migration plans nullable DDL and zero normalization');
    verify((int)$connection->fetchOne('SELECT tickets_id FROM glpi_documents WHERE id = ?', [$firstId]) === 0, 'Planning preserves legacy data');
    $connection->update($table, [$column => 2147483647], ['id' => $firstId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned document ticket');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns($table)[$column]->getNotnull(), 'Nonzero orphans rejected before DDL');
    $connection->update($table, [$column => 0], ['id' => $firstId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT tickets_id FROM glpi_documents WHERE id = ?', [$firstId]) === null, 'Empty document ticket becomes NULL');
    verify((int)$connection->fetchOne('SELECT tickets_id FROM glpi_documents WHERE id = ?', [$firstId + 1]) === $legacyTicket, 'Valid nonzero document ticket preserved');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Migration retries are idempotent');
} finally {
    foreach ($ids as $id) {
        $connection->delete($table, ['id' => $id]);
    }
    if ($legacyTicket !== null) {
        $connection->delete('glpi_tickets', ['id' => $legacyTicket]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Document queries, attachment permissions, ticket ownership, purge and migration passed.\n";
