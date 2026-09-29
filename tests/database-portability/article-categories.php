<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\KnowledgeBaseAccess;
use itsmng\Database\Migration\ArticleCategoryReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\KnowledgeBaseRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/article-categories.php /path/to/test-config\n");
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
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$DB->beginTransaction();
try {
    $viewer = $fixtures->create('glpi_users', ['name' => 'KB category viewer']);
    $other = $fixtures->create('glpi_users', ['name' => 'KB category other']);
    $group = $fixtures->create('glpi_groups', ['name' => 'KB category group']);
    $profile = $fixtures->create('glpi_profiles', ['name' => 'KB category profile']);
    $child = (new Entity())->add(['name' => 'KB child scope', 'entities_id' => 0]);
    $foreign = (new Entity())->add(['name' => 'KB foreign scope', 'entities_id' => 0]);
    $parent = (new KnowbaseItemCategory())->add(['name' => 'Visible category ancestor']);
    $category = (new KnowbaseItemCategory())->add(['name' => 'Visible category child', 'knowbaseitemcategories_id' => $parent]);
    $empty = (new KnowbaseItemCategory())->add(['name' => 'Invisible empty category']);
    $article = static fn (array $values = []) => $fixtures->create('glpi_knowbaseitems', $values + [
        'knowbaseitemcategories_id' => $category, 'users_id' => $other, 'name' => 'Category article',
    ]);
    $own = $article(['users_id' => $viewer]);
    $direct = $article();
    foreach ([$own, $direct] as $id) {
        $fixtures->create('glpi_knowbaseitems_users', ['knowbaseitems_id' => $id, 'users_id' => $viewer]);
    }
    $groupGlobal = $article();
    $groupRecursive = $article();
    $groupHidden = $article();
    foreach ([[$own, -1, false], [$groupGlobal, -1, false], [$groupRecursive, 0, true], [$groupHidden, $foreign, false]] as [$id, $entity, $recursive]) {
        $fixtures->create('glpi_groups_knowbaseitems', ['knowbaseitems_id' => $id, 'groups_id' => $group, 'entities_id' => $entity, 'is_recursive' => $recursive]);
    }
    $profileGlobal = $article();
    $profileHidden = $article();
    foreach ([[$profileGlobal, -1], [$profileHidden, $foreign]] as [$id, $entity]) {
        $fixtures->create('glpi_knowbaseitems_profiles', ['knowbaseitems_id' => $id, 'profiles_id' => $profile, 'entities_id' => $entity]);
    }
    $entityRecursive = $article();
    $entityDirect = $article();
    $entityHidden = $article();
    $public = $article(['is_faq' => true]);
    $publicWithoutGrant = $article(['is_faq' => true]);
    $unownedPrivate = $article(['users_id' => null]);
    foreach ([[$entityRecursive, 0, true], [$entityDirect, $child, false], [$entityHidden, $foreign, false], [$public, 0, true]] as [$id, $entity, $recursive]) {
        $fixtures->create('glpi_entities_knowbaseitems', ['knowbaseitems_id' => $id, 'entities_id' => $entity, 'is_recursive' => $recursive]);
    }
    $repo = new KnowledgeBaseRepository(Orm::create($DB));
    $access = new KnowledgeBaseAccess($viewer, false, true, true, true, [$group], $profile, [$child], [0]);
    verify(($repo->categoryCounts($access)[$category] ?? 0) === 8, 'Owner/direct/group/profile/entity visibility counts each article once');
    $faq = new KnowledgeBaseAccess($viewer, false, false, true, true, [$group], $profile, [$child], [0]);
    verify(($repo->categoryCounts($faq)[$category] ?? 0) === 1, 'FAQ-only viewer does not count private articles');
    $emptyScope = new KnowledgeBaseAccess($viewer, false, true, false, true, [], 0, [], []);
    verify(($repo->categoryCounts($emptyScope)[$category] ?? 0) === 2, 'No groups/profile/entities retains only own and directly shared articles');
    $anonymous = new KnowledgeBaseAccess(0, false, false, true, true, [], 0, [], []);
    verify(($repo->categoryCounts($anonymous)[$category] ?? 0) === 1, 'Anonymous multientity FAQ requires recursive root grant');
    $single = new KnowledgeBaseAccess(0, false, false, true, false, [], 0, [], []);
    verify(($repo->categoryCounts($single)[$category] ?? 0) === 2, 'Single-entity public FAQ');
    $closed = new KnowledgeBaseAccess(0, false, false, false, false, [], 0, [], []);
    verify($repo->categoryCounts($closed) === [], 'Disabled public FAQ exposes no categories');
    $admin = new KnowledgeBaseAccess($viewer, true, true, false, true, [], 0, [], []);
    verify(($repo->categoryCounts($admin)[$category] ?? 0) === 13, 'Administrator counts all articles without duplicate grant rows');
    $session = $_SESSION;
    $_SESSION['glpiID'] = $viewer;
    $_SESSION['glpiactiveprofile']['knowbase'] = READ;
    $_SESSION['glpiactiveprofile']['id'] = $profile;
    $_SESSION['glpigroups'] = [$group];
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpishowallentities'] = false;
    $tree = Knowbase::getJstreeCategoryList();
    $ids = array_column($tree, 'id');
    verify(in_array((string)$parent, $ids, true) && in_array((string)$category, $ids, true), 'Tree keeps ancestors of visible articles');
    verify(!in_array((string)$empty, $ids, true), 'Tree prunes empty branches');
    $node = array_values(array_filter($tree, static fn ($row) => $row['id'] === (string)$category))[0];
    verify(str_contains($node['text'], '(8)') && $node['parent'] === (string)$parent, 'Tree count and parent view model');
    $_SESSION = [];
    $item = new KnowbaseItem();
    verify($item->getFromDB($unownedPrivate) && !$item->canViewItem() && !$item->canUpdateItem(), 'NULL author never grants anonymous ownership');
    $_SESSION = $session;
    $replacement = (new KnowbaseItemCategory())->add(['name' => 'Replacement article category']);
    $cat = new KnowbaseItemCategory();
    verify($cat->delete(['id' => $category, '_replace_by' => $replacement], true), 'Replace article category');
    verify($item->getFromDB($own) && (int)$item->fields['knowbaseitemcategories_id'] === (int)$replacement, 'Article reassigned');
    verify($cat->delete(['id' => $replacement], true), 'Purge category retaining articles');
    verify($item->getFromDB($own) && $item->fields['knowbaseitemcategories_id'] === null, 'Article becomes uncategorized');
    verify(in_array($own, KnowbaseItem::getForCategory(0), true), 'Legacy uncategorized lookup uses NULL association');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->categories($access);
    verify($SQL_TOTAL_REQUEST === 0, 'Tree count and metadata queries use ORM');
    verify((new ForeignKeys())->audit($connection) === [], 'Category graph remains valid');
} finally {
    if (isset($session)) {
        $_SESSION = $session;
    }
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$migration = new ArticleCategoryReferences();
$legacy = null;
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_knowbaseitems', 'knowbaseitemcategories_id'), 'glpi_knowbaseitems'));
    $connection->executeStatement('UPDATE glpi_knowbaseitems SET knowbaseitemcategories_id = 0 WHERE knowbaseitemcategories_id IS NULL');
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable('glpi_knowbaseitems');
    $after = clone $before;
    $after->getColumn('knowbaseitemcategories_id')->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_knowbaseitems');
    $connection->insert('glpi_knowbaseitems', ['id' => $legacy, 'name' => 'Legacy category']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy category migration plan');
    $connection->update('glpi_knowbaseitems', ['knowbaseitemcategories_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned article category');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_knowbaseitems')['knowbaseitemcategories_id']->getNotnull(), 'Orphan refusal before DDL');
    $connection->update('glpi_knowbaseitems', ['knowbaseitemcategories_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT knowbaseitemcategories_id FROM glpi_knowbaseitems WHERE id = ?', [$legacy]) === null, 'Uncategorized legacy value becomes NULL');
    verify($migration->apply($connection) === [], 'Category migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_knowbaseitems', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": article category lifecycle, ORM visibility counts, anonymous ownership and migration passed.\n";
