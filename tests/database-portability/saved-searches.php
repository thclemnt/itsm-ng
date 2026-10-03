<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\SavedSearchReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/saved-searches.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $viewer = (int)Session::getLoginUserID();
    $owner = $fixtures->create('glpi_users', ['name' => 'Saved search owner']);
    $entity = $fixtures->create('glpi_entities', ['id' => (int)$connection->fetchOne('SELECT MAX(id) + 100 FROM glpi_entities'), 'name' => 'Saved search foreign entity']);
    $repo = new \itsmng\Database\Repository\SavedSearchRepository(Orm::create($DB));
    $base = ['itemtype' => 'Computer', 'type' => SavedSearch::SEARCH, 'entities_id' => 0, 'query' => '', 'users_id' => $viewer];
    $mine = $fixtures->create('glpi_savedsearches', ['name' => 'Mapped private', 'is_private' => true] + $base);
    $other = $fixtures->create('glpi_savedsearches', ['name' => 'Other private', 'is_private' => true, 'users_id' => $owner] + $base);
    $public = $fixtures->create('glpi_savedsearches', ['name' => 'Mapped public', 'is_private' => false, 'users_id' => $owner] + $base);
    $outside = $fixtures->create('glpi_savedsearches', ['name' => 'Foreign public', 'is_private' => false, 'entities_id' => $entity] + $base);
    $ownerless = $fixtures->create('glpi_savedsearches', ['name' => 'Ownerless private', 'is_private' => true, 'users_id' => null] + $base);
    $all = [$mine, $other, $public, $outside, $ownerless];
    $defaults = new SavedSearch();
    $defaults->markDefault($mine);
    verify(SavedSearch_User::getDefault(0, 'Computer') === false, 'Anonymous user has no default search');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $scope = ['glpi_savedsearches.entities_id' => 0];
    $visible = $repo->visible($viewer, true, $scope);
    verify(isset($visible['private'][$mine]) && !isset($visible['private'][$other]) && !isset($visible['private'][$ownerless]), 'Only current user private searches visible');
    verify(isset($visible['public'][$public]) && !isset($visible['public'][$outside]), 'Public searches obey entity scope');
    verify($visible['private'][$mine]['IS_DEFAULT'] > 0, 'Default membership projected');
    verify($repo->visible($viewer, false, $scope)['public'] === [], 'No public permission cannot enumerate public or private searches');
    verify($repo->visible(0, true, $scope) === ['private' => [], 'public' => []], 'Anonymous viewer sees no personal list');
    verify($SQL_TOTAL_REQUEST === 0, 'Saved search listing uses ORM');
    $defaults->markDefault($public);
    $visible = $repo->visible($viewer, true, $scope);
    verify($visible['private'][$mine]['IS_DEFAULT'] === null && $visible['public'][$public]['IS_DEFAULT'] > 0, 'Changing default replaces same itemtype membership');
    $defaults->unmarkDefault($public);
    verify($repo->visible($viewer, true, $scope)['public'][$public]['IS_DEFAULT'] === null, 'Unmark default');
    $defaults->markDefault($mine);
    verify($defaults->unmarkDefaults([$mine]) === true && $repo->visible($viewer, true, $scope)['private'][$mine]['IS_DEFAULT'] === null, 'Bulk unmark uses lifecycle deletion');
    $defaults->setDoCount([$mine], SavedSearch::COUNT_YES);
    $defaults->setEntityRecur([$mine], $entity, true);
    verify($read('glpi_savedsearches', $mine)['do_count'] === SavedSearch::COUNT_YES && $read('glpi_savedsearches', $mine)['is_recursive'] === 1, 'Bulk settings retain enum and boolean types');
    $defaults->setEntityRecur([$mine], 0, false);
    verify($read('glpi_savedsearches', $mine)['entities_id'] === 0, 'Root entity zero is preserved');
    $defaults->setDoCount([], SavedSearch::COUNT_AUTO);
    $defaults->setEntityRecur([], -1, false);
    $at = new DateTimeImmutable('2030-01-01 12:00:00');
    $repo->recordExecution($mine, 123, true, $at);
    (new \itsmng\Database\Repository\SavedSearchRepository(Orm::create($DB)))->recordExecution($mine, 456, true, $at);
    $repo->recordExecution($mine, 789, false, $at);
    $row = $read('glpi_savedsearches', $mine);
    verify($row['counter'] === 2 && $row['last_execution_time'] === 789 && $row['last_execution_date'] === $at->format('Y-m-d H:i:s'), 'Stats use atomic counter and refresh does not increment usage');
    $ids = static fn ($rows) => array_values(array_intersect(array_column($rows, 'id'), $all));
    verify($ids($repo->stale($at)) === [], 'Stale selection excludes NULL and exact boundary');
    verify($ids($repo->stale($at->modify('+1 second'))) === [$mine], 'Stale selection uses strict timestamp boundary');
    verify(in_array('Computer', SavedSearch::getUsedItemtypes(), true), 'Mapped distinct itemtypes');
    $active = $fixtures->create('glpi_savedsearches_alerts', ['savedsearches_id' => $public, 'is_active' => true, 'name' => 'Mapped alert']);
    $inactive = $fixtures->create('glpi_savedsearches_alerts', ['savedsearches_id' => $mine, 'is_active' => false]);
    $orphanAlert = $fixtures->create('glpi_savedsearches_alerts', ['savedsearches_id' => $ownerless, 'is_active' => true]);
    $alerts = array_column($repo->activeAlerts(), 'id');
    verify(in_array($active, $alerts, true) && !in_array($inactive, $alerts, true) && !in_array($orphanAlert, $alerts, true), 'Only active alerts with an execution owner selected');
    $search = new SavedSearch();
    verify($search->getFromDB($public), 'Load search for alerts view');
    ob_start();
    SavedSearch_Alert::showForSavedSearch($search);
    $alertHtml = ob_get_clean();
    verify(str_contains($alertHtml, 'Mapped alert'), 'Mapped alerts render through existing view');
    $privateAlert = $fixtures->create('glpi_savedsearches_alerts', ['savedsearches_id' => $other, 'is_active' => true]);
    $default = $fixtures->create('glpi_savedsearches_users', ['savedsearches_id' => $other, 'users_id' => $owner, 'itemtype' => 'Computer']);
    verify((new User())->delete(['id' => $owner], true), 'Purge owner via lifecycle');
    verify($read('glpi_savedsearches', $public)['users_id'] === null, 'Public search retained without owner');
    verify($read('glpi_savedsearches', $other) === null && $read('glpi_savedsearches_alerts', $privateAlert) === null && $read('glpi_savedsearches_users', $default) === null, 'Private search, alert and default removed with owner');
    verify(!in_array($active, array_column($repo->activeAlerts(), 'id'), true), 'Detached public search cannot impersonate removed owner');
    verify((new SavedSearch())->delete(['id' => $public], true), 'Purge public saved search');
    verify($read('glpi_savedsearches_alerts', $active) === null, 'Search purge removes alert before RESTRICT parent');
    $session = $_SESSION;
    $_SESSION['glpiID'] = 0;
    $_SESSION['glpiactiveprofile'] = [];
    $search = new SavedSearch();
    verify($search->getFromDB($ownerless), 'Load ownerless search');
    verify(!$search->canViewItem() && !$search->canCreateItem() && !$search->canUpdateItem(), 'Absent owner never confers anonymous ownership');
    $_SESSION = $session;
    $rights = $_SESSION['glpiactiveprofile'];
    $_SESSION['glpiactiveprofile']['bookmark_public'] = 0;
    $_SESSION['glpiactiveentities'] = [0];
    ob_start();
    (new SavedSearch())->displayMine();
    $html = ob_get_clean();
    verify(str_contains($html, 'Mapped private') && !str_contains($html, 'Foreign public') && !str_contains($html, 'Ownerless private'), 'Rendered personal list respects denied public access');
    $_SESSION['glpiactiveprofile'] = $rights;
    verify((new ForeignKeys())->audit($connection) === [], 'Saved searches graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new SavedSearchReferences();
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'SAVED_SEARCHES') as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_savedsearches');
    $connection->insert('glpi_savedsearches', ['id' => $legacy, 'name' => 'legacy-name', 'itemtype' => 'Computer']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy saved-search owner migration has a plan');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_savedsearches WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_savedsearches', ['users_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned saved-search owner');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_savedsearches')['users_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_savedsearches', ['users_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_savedsearches WHERE id = ?', [$legacy]) === null, 'Legacy owner default becomes NULL');
    verify($migration->apply($connection) === [], 'saved-search owner migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_savedsearches', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Saved searches, nullable references, scoped listing, alert selection, atomic statistics and migration passed.\n";
