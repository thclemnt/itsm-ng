<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/knowledgebase.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $article = $fixtures->create('glpi_knowbaseitems', ['name' => "O'Reilly knowledge 日本語"]);
    $other = $fixtures->create('glpi_knowbaseitems');
    $root = $fixtures->create('glpi_knowbaseitems_comments', ['knowbaseitems_id' => $article, 'comment' => 'Root']);
    $reply = $fixtures->create('glpi_knowbaseitems_comments', ['knowbaseitems_id' => $article, 'parent_comment_id' => $root, 'comment' => 'Reply']);
    $leaf = $fixtures->create('glpi_knowbaseitems_comments', ['knowbaseitems_id' => $article, 'parent_comment_id' => $reply, 'comment' => 'Leaf']);
    $sibling = $fixtures->create('glpi_knowbaseitems_comments', ['knowbaseitems_id' => $article, 'parent_comment_id' => $root]);
    $fixtures->create('glpi_knowbaseitems_comments', ['knowbaseitems_id' => $other]);
    $fixtures->create('glpi_knowbaseitems_comments', ['knowbaseitems_id' => $article, 'language' => 'fr_FR']);
    $tree = KnowbaseItem_Comment::getCommentsForKbItem($article, null);
    verify(count($tree) === 1 && $tree[0]['id'] === $root, 'Article and language isolation');
    verify(array_column($tree[0]['answers'], 'id') === [$reply, $sibling], 'Replies preserve ID ordering');
    verify($tree[0]['answers'][0]['answers'][0]['id'] === $leaf, 'Nested reply hydration');
    verify(count(KnowbaseItem_Comment::getCommentsForKbItem($article, null, $root)) === 2, 'Explicit subtree');
    $comment = new KnowbaseItem_Comment();
    verify($comment->delete(['id' => $reply], true), 'Delete an intermediate comment');
    $tree = KnowbaseItem_Comment::getCommentsForKbItem($article, null);
    verify(array_column($tree[0]['answers'], 'id') === [$leaf, $sibling], 'Replies move to the deleted comment parent');
    verify($comment->delete(['id' => $root], true), 'Delete root comment');
    verify(array_column(KnowbaseItem_Comment::getCommentsForKbItem($article, null), 'id') === [$leaf, $sibling], 'Replies remain visible as roots');
    $DB->beginTransaction();
    try {
        $writer = new \itsmng\Database\Repository\RecordWriter(\itsmng\Database\Orm::create($DB));
        $writer->update('glpi_knowbaseitems_comments', $leaf, ['parent_comment_id' => $sibling]);
        $writer->update('glpi_knowbaseitems_comments', $sibling, ['parent_comment_id' => $leaf]);
        $cycleRejected = false;
        try {
            KnowbaseItem_Comment::getCommentsForKbItem($article, null, $leaf);
        } catch (UnexpectedValueException $expected) {
            $cycleRejected = true;
        }
        verify($cycleRejected, 'Malformed ancestry cannot recurse indefinitely');
    } finally {
        $DB->rollBack();
    }

    $repository = new \itsmng\Database\Repository\KnowledgeBaseRepository(\itsmng\Database\Orm::create($DB));
    verify($repository->commentCount($article, null) === 2 && $repository->commentCount($article, 'fr_FR') === 1, 'Mapped comment tab counts');
    verify($repository->nextRevision($article, '') === 1, 'First revision');
    for ($revision = 1; $revision <= 4; $revision++) {
        $fixtures->create('glpi_knowbaseitems_revisions', ['knowbaseitems_id' => $article, 'language' => '', 'revision' => $revision]);
    }
    $fixtures->create('glpi_knowbaseitems_revisions', ['knowbaseitems_id' => $article, 'language' => 'fr_FR', 'revision' => 7]);
    verify($repository->revisionCount($article, '') === 4, 'Revision count excludes other languages');
    verify(array_column($repository->revisions($article, '', 2, 1), 'revision') === [3, 2], 'Database-side revision pagination');
    verify($repository->nextRevision($article, '') === 5 && $repository->nextRevision($article, 'fr_FR') === 8, 'Independent language revision sequences');
    $fixtures->create('glpi_knowbaseitemtranslations', ['knowbaseitems_id' => $article, 'language' => 'fr_FR']);
    $item = new KnowbaseItem();
    verify($item->getFromDB($article), 'Article hydration');
    verify(KnowbaseItemTranslation::getAlreadyTranslatedForItem($item) === ['fr_FR' => 'fr_FR'], 'Mapped language selector');
    verify(KnowbaseItemTranslation::getNumberOfTranslationsForItem($item) === 1, 'Mapped translation tab count');
    $views = $item->fields['view'];
    $item->updateCounter();
    $item->updateCounter();
    $item->addToFaq();
    verify($item->getFromDB($article) && $item->fields['view'] === $views + 2 && $item->fields['is_faq'] === 1, 'Atomic counter and typed FAQ flag');
    verify($item->delete(['id' => $article], true), 'Purge article with replies, revisions and translations');
    verify((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No dangling references after purge');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped knowledge-base trees, reply preservation, revision pagination, counters and purges passed.\n";
