<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use itsmng\Database\ForeignKeys;
use itsmng\Database\KnowledgeBaseAccess;
use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;
use itsmng\Database\Orm;
use itsmng\Database\Repository\KnowledgeBaseRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\SharedContentRepository;
use itsmng\Database\SharedContentAccess;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/content-audience-scopes.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$records = static fn () => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$rejected = static function (callable $operation, string $exception, ?string $message = null) use ($connection): bool {
    try {
        $connection->transactional($operation);
        return false;
    } catch (Throwable $error) {
        if (!$error instanceof $exception || ($message !== null && !str_contains($error->getMessage(), $message))) {
            throw $error;
        }
        return true;
    }
};
$savedSession = $_SESSION;
$definitions = [
    'glpi_groups_knowbaseitems' => ['KnowbaseItem', 'knowbaseitems_id', 'Group_KnowbaseItem', 'groups_id', 'getGroups'],
    'glpi_knowbaseitems_profiles' => ['KnowbaseItem', 'knowbaseitems_id', 'KnowbaseItem_Profile', 'profiles_id', 'getProfiles'],
    'glpi_groups_reminders' => ['Reminder', 'reminders_id', 'Group_Reminder', 'groups_id', 'getGroups'],
    'glpi_profiles_reminders' => ['Reminder', 'reminders_id', 'Profile_Reminder', 'profiles_id', 'getProfiles'],
    'glpi_groups_rssfeeds' => ['RSSFeed', 'rssfeeds_id', 'Group_RSSFeed', 'groups_id', 'getGroups'],
    'glpi_profiles_rssfeeds' => ['RSSFeed', 'rssfeeds_id', 'Profile_RSSFeed', 'profiles_id', 'getProfiles'],
];
$DB->beginTransaction();
try {
    $entity = (int)(new Entity())->add(['name' => 'Audience entity', 'entities_id' => 0]);
    $outside = (int)(new Entity())->add(['name' => 'Outside audience', 'entities_id' => 0]);
    $group = $fixtures->create('glpi_groups', ['name' => 'Audience readers']);
    $profile = (int)$_SESSION['glpiactiveprofile']['id'];
    $user = (int)Session::getLoginUserID();
    $rows = [];
    $documents = [];
    foreach ($definitions as $table => [$parent, $parentColumn, $class, $audienceColumn, $method]) {
        $audience = $audienceColumn === 'groups_id' ? $group : $profile;
        $parentId = $fixtures->create($parent::getTable(), ['name' => 'Scoped audience ' . $table, 'users_id' => null]);
        $model = new $class();
        $id = (int)$model->add([$parentColumn => $parentId, $audienceColumn => $audience, 'entities_id' => -1]);
        verify($id > 0 && $read($table, $id)['entities_id'] === null, 'Legacy unrestricted input becomes SQL NULL: ' . $table);
        verify($model->update(['id' => $id, 'entities_id' => 0]), 'Change scope to real root entity');
        verify($read($table, $id)['entities_id'] === 0, 'Root zero is retained: ' . $table);
        verify($records()->matching($table, ['id' => $id, 'entities_id' => -1]) === [], 'Root is not an unrestricted scope');
        verify(count($records()->matching($table, ['id' => $id, 'entities_id' => [-1, 0]])) === 1, 'Mixed unrestricted/root criteria');
        verify($model->update(['id' => $id, 'entities_id' => '-2']), 'Legacy negative scope update');
        verify($read($table, $id)['entities_id'] === null && count($records()->matching($table, ['id' => $id, 'entities_id' => -1])) === 1, 'Legacy negative criteria target unrestricted rows');
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $SQL_TOTAL_REQUEST = 0;
        $lookedUp = $class::$method($parentId);
        verify($lookedUp[$audience][0]['entities_id'] === null && $SQL_TOTAL_REQUEST === 0, 'Audience lookup uses ORM with native nullable scope');
        $orphan = [$parentColumn => $parentId, $audienceColumn => $audience, 'entities_id' => 2147483647];
        verify($rejected(fn () => $fixtures->create($table, $orphan), ForeignKeyConstraintViolationException::class), 'Entity FK rejects orphans: ' . $table);
        // Direct raw SQL must also reject old negative sentinels after migration.
        verify($rejected(fn () => $connection->update($table, ['entities_id' => -1], ['id' => $id]), ForeignKeyConstraintViolationException::class), 'Database cannot retain a sentinel instead of a reference');
        $rows[$table] = [$id, $parentId, $audience];
        if ($parent === 'KnowbaseItem') {
            $documents[$parentId] = $fixtures->create('glpi_documents', ['name' => 'Scoped knowledge document']);
            $fixtures->create('glpi_documents_items', ['documents_id' => $documents[$parentId], 'itemtype' => $parent, 'items_id' => $parentId]);
        }
    }
    // The model access check treats NULL scope as unrestricted for the matching audience.
    $_SESSION['glpigroups'] = [$group];
    $_SESSION['glpiactiveentities'] = [$outside];
    $_SESSION['glpiactiveentities_string'] = (string)$outside;
    $_SESSION['glpiactive_entity'] = $outside;
    $_SESSION['glpiactiveprofile']['reminder_public'] = READ;
    $_SESSION['glpiactiveprofile']['rssfeed_public'] = READ;
    $_SESSION['glpiactiveprofile']['knowbase'] = READ | UPDATE;
    $kbAccess = new KnowledgeBaseAccess($user, false, true, false, false, [$group], $profile, [$outside], [0]);
    $sharedAccess = new SharedContentAccess($user, true, [$group], $profile, [$outside], [0]);
    $listed = static function (string $parent, int $id) use ($DB, $documents, $kbAccess, $sharedAccess): bool {
        if ($parent === 'KnowbaseItem') {
            return (new KnowledgeBaseRepository(Orm::create($DB)))->hasDocument($documents[$id], $kbAccess);
        }
        $items = (new SharedContentRepository(Orm::create($DB)))->listing(strtolower($parent), $sharedAccess, false, false, new DateTimeImmutable());
        return in_array($id, array_column($items, 'id'), true);
    };
    foreach ($definitions as $table => [$parent, $parentColumn, $class, $audienceColumn, $method]) {
        [$id, $parentId] = $rows[$table];
        if ($parent !== 'KnowbaseItem') {
            $em = Orm::create($DB);
            $record = $em->find(\itsmng\Database\EntityRegistry::tables()[getTableForItemType($parent)], $parentId);
            $audience = $audienceColumn === 'groups_id' ? $record->audienceGroups : $record->audienceProfiles;
            verify($audience->count() === 1 && $audience->first()->id === $id, 'Native inverse collection hydrates the owning audience link: ' . $table);
        }
        $object = new $parent();
        verify($object->getFromDB($parentId) && $object->haveVisibilityAccess(), 'Model accepts unrestricted audience: ' . $table);
        verify($listed($parent, $parentId), 'Mapped listing accepts unrestricted audience: ' . $table);
        $relation = new $class();
        verify($relation->update(['id' => $id, 'entities_id' => $entity]), 'Restrict audience to a different entity');
        $object->getFromDB($parentId);
        verify(!$object->haveVisibilityAccess(), 'Model denies an outside entity scope: ' . $table);
        verify(!$listed($parent, $parentId), 'Mapped listing denies an outside entity scope: ' . $table);
        $relation->update(['id' => $id, 'entities_id' => 0, 'is_recursive' => false]);
        $object->getFromDB($parentId);
        verify(!$object->haveVisibilityAccess() && !$listed($parent, $parentId), 'Root without recursion does not grant unrelated descendants');
        $relation->update(['id' => $id, 'is_recursive' => true]);
        $object->getFromDB($parentId);
        verify($object->haveVisibilityAccess() && $listed($parent, $parentId), 'Recursive root scope grants descendants');
        $relation->update(['id' => $id, 'entities_id' => -1]);
    }
    $_SESSION = $savedSession;
    // Entity purge moves specific scopes to root/replacement; it never broadens them to NULL.
    foreach ($rows as $table => [$id]) {
        $class = $definitions[$table][2];
        (new $class())->update(['id' => $id, 'entities_id' => $entity]);
    }
    verify((new Entity())->delete(['id' => $entity, '_replace_by' => $outside], true), 'Purge scoped entity with replacement');
    foreach ($rows as $table => [$id]) {
        verify($read($table, $id)['entities_id'] === $outside, 'Entity replacement retains specific scope: ' . $table);
    }
    verify((new Entity())->delete(['id' => $outside], true), 'Purge scoped entity without replacement');
    foreach ($rows as $table => [$id]) {
        verify($read($table, $id)['entities_id'] === 0, 'Entity purge falls back to real root rather than unrestricted access');
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Scoped lifecycle leaves no dangling references');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
}

// Rebuild legacy columns in this disposable installation to test upgrade auditing.
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NullableReferences(ReferenceHistory::get('audience', 'RELATIONS'), 'content audience entity', -1);
$created = [];
$parents = [];
$migrationGroup = null;
try {
    $migrationGroup = $fixtures->create('glpi_groups', ['name' => 'Audience migration group']);
    foreach ($definitions as $table => [$parent, $parentColumn, $class, $audienceColumn]) {
        $connection->executeStatement($platform->getDropForeignKeySQL($quote(ForeignKeys::name($table, 'entities_id')), $table));
        $connection->executeStatement('UPDATE ' . $quote($table) . ' SET entities_id = -1 WHERE entities_id IS NULL');
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        $after->getColumn('entities_id')->setNotnull(true)->setDefault(-1);
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
        $created[$table] = [];
        $parents[$parent] ??= $fixtures->create($parent::getTable(), ['name' => 'Audience migration parent']);
        foreach ([-1, -2, 0] as $scope) {
            // Raw values reconstruct the former schema; ORM maps NULL scopes in new schemas.
            $base = $fixtures->create($table, [$parentColumn => $parents[$parent], $audienceColumn => $audienceColumn === 'groups_id' ? $migrationGroup : (int)$_SESSION['glpiactiveprofile']['id'], 'entities_id' => 0]);
            $connection->update($table, ['entities_id' => $scope], ['id' => $base]);
            $created[$table][] = $base;
        }
    }
    $table = array_key_last($definitions);
    $connection->update($table, ['entities_id' => 2147483647], ['id' => $created[$table][0]]);
    verify($rejected(fn () => $migration->apply($connection), RuntimeException::class, 'Nonzero orphaned content audience entity'), 'Orphan audit refuses migration before any DDL');
    foreach (array_keys($definitions) as $name) {
        verify($connection->createSchemaManager()->listTableColumns($name)['entities_id']->getNotnull(), 'Failed audit retains every legacy column');
    }
    $connection->update($table, ['entities_id' => -1], ['id' => $created[$table][0]]);
    $migration->apply($connection);
    foreach ($created as $table => [$global, $negative, $root]) {
        verify($read($table, $global)['entities_id'] === null && $read($table, $negative)['entities_id'] === null, 'All formerly unrestricted negative scopes normalize');
        verify($read($table, $root)['entities_id'] === 0, 'Migration retains the real root reference');
    }
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []], 'Scoped migration retry is idempotent');
} finally {
    foreach ($created as $table => $ids) {
        foreach ($ids as $id) {
            $connection->delete($table, ['id' => $id]);
        }
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    foreach ($parents as $class => $id) {
        (new $class())->delete(['id' => $id], true);
    }
    if ($migrationGroup !== null) {
        (new Group())->delete(['id' => $migrationGroup], true);
    }
}
echo $DB->getProvider() . ": content audience scopes, mapped lookups, visibility, entity purge and migration passed.\n";
