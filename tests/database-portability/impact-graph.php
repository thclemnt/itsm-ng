<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/impact-graph.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/HistoricalBooleanChecks.php';
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
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'Impact scope', 'entities_id' => 0]);
    $outside = $fixtures->create('glpi_entities', ['id' => $entityId + 1, 'name' => 'Outside impact scope', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactiveentities_string'] = (string)$entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $user = (int)Session::getLoginUserID();
    $repo = static fn () => new \itsmng\Database\Repository\ImpactRepository(Orm::create($DB));
    $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
    $computerIds = [];
    for ($i = 0; $i < 23; ++$i) {
        $computerIds[] = $fixtures->create('glpi_computers', ['name' => 'Mapped impact asset ' . $i, 'entities_id' => $entity]);
    }
    $fixtures->create('glpi_computers', ['name' => 'Mapped impact asset hidden', 'entities_id' => $outside]);
    $fixtures->create('glpi_computers', ['name' => 'Mapped impact asset deleted', 'entities_id' => $entity, 'is_deleted' => true]);
    $fixtures->create('glpi_computers', ['name' => 'Mapped impact asset template', 'entities_id' => $entity, 'is_template' => true]);
    $page = Impact::searchAsset('Computer', [], 'MAPPED IMPACT ASSET');
    verify($page['total'] === 23 && count($page['items']) === 20, 'Asset search scopes and paginates on both providers');
    $next = Impact::searchAsset('Computer', [], 'Mapped impact asset', 1);
    verify($ids($next['items']) === array_slice($computerIds, 20), 'Second page is stable without duplicates');
    verify(Impact::searchAsset('Computer', $computerIds, 'Mapped impact asset')['total'] === 0, 'Used assets excluded');
    $literalName = "O'Reilly C:\\new\\rack 日本語";
    $literal = $fixtures->create('glpi_computers', ['name' => $literalName, 'entities_id' => $entity]);
    verify($ids(Impact::searchAsset('Computer', [], $literalName)['items']) === [$literal], 'Literal quotes, backslashes and Unicode bind without SQL pre-escaping');
    $savedRights = $_SESSION['glpiactiveprofile']['computer'];
    $_SESSION['glpiactiveprofile']['computer'] = 0;
    verify(Impact::searchAsset('Computer', [], 'Mapped impact asset') === ['items' => [], 'total' => 0], 'Unreadable type denied before lookup');
    $_SESSION['glpiactiveprofile']['computer'] = $savedRights;
    $namedUser = $fixtures->create('glpi_users', ['name' => 'impact.login', 'firstname' => 'Anne Marie', 'realname' => "O'Neil"]);
    $lookup = $repo()->searchAssets('glpi_users', 'name', [], [], "O'Neil AnneMarie", 0, true, true, $user, []);
    verify($ids($lookup['items']) === [$namedUser] && $lookup['items'][0]['name'] === "Anne Marie O'Neil", 'User search compacts spaces and supports reverse name order');
    $lookup = $repo()->searchAssets('glpi_users', 'name', [], [], 'impact.login', 0, false, true, $user, []);
    verify($lookup['items'][0]['name'] === "O'Neil Anne Marie", 'User display respects configured name order');
    $project = $fixtures->create('glpi_projects', ['name' => 'Impact project', 'entities_id' => $entity]);
    $group = $fixtures->create('glpi_groups', ['name' => 'Impact project group']);
    $fixtures->create('glpi_projectteams', ['projects_id' => $project, 'itemtype' => 'User', 'items_id' => $user]);
    $fixtures->create('glpi_projectteams', ['projects_id' => $project, 'itemtype' => 'Group', 'items_id' => $group]);
    $hiddenProject = $fixtures->create('glpi_projects', ['name' => 'Impact project hidden', 'entities_id' => $entity]);
    $lookup = $repo()->searchAssets('glpi_projects', 'name', [], [], 'Impact project', 0, true, false, $user, [$group]);
    verify($lookup['total'] === 1 && $ids($lookup['items']) === [$project], 'Project audience uses EXISTS and does not multiply memberships');
    verify($repo()->searchAssets('glpi_projects', 'name', [], [], 'Impact project', 0, true, false, 0, [])['total'] === 0, 'Anonymous project audience denied');
    // Exercise every configured core asset mapping, including non-name display fields.
    foreach ($CFG_GLPI['impact_asset_types'] as $type => $icon) {
        $repo()->searchAssets($type::getTable(), $type::getNameField(), [], [], 'unlikely-impact-fixture', 0, true, true, $user, []);
    }
    $a = new Computer();
    $a->getFromDB($computerIds[0]);
    $b = new Computer();
    $b->getFromDB($computerIds[1]);
    $c = new Computer();
    $c->getFromDB($computerIds[2]);
    $context = $fixtures->create('glpi_impactcontexts', ['positions' => '{}', 'show_depends' => false, 'show_impact' => true]);
    $compound = $fixtures->create('glpi_impactcompounds', ['name' => 'Impact group']);
    $master = $fixtures->create('glpi_impactitems', ['itemtype' => 'Computer', 'items_id' => $a->getID(), 'impactcontexts_id' => $context, 'parent_id' => $compound, 'is_slave' => false]);
    $slave = $fixtures->create('glpi_impactitems', ['itemtype' => 'Computer', 'items_id' => $b->getID(), 'impactcontexts_id' => $context, 'parent_id' => $compound, 'is_slave' => true]);
    $otherSlave = $fixtures->create('glpi_impactitems', ['itemtype' => 'Computer', 'items_id' => $c->getID(), 'impactcontexts_id' => $context, 'parent_id' => $compound, 'is_slave' => true]);
    $edge = new ImpactRelation();
    $edgeId = $edge->add(['itemtype_source' => 'Computer', 'items_id_source' => $a->getID(), 'itemtype_impacted' => 'Computer', 'items_id_impacted' => $b->getID()]);
    verify($edgeId > 0, 'Create graph edge');
    verify(!$edge->add(['itemtype_source' => 'Computer', 'items_id_source' => $a->getID(), 'itemtype_impacted' => 'Computer', 'items_id_impacted' => $b->getID()]), 'Duplicate graph edge rejected');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    verify($repo()->itemId('Computer', $a->getID()) === $master && $repo()->relationCount('Computer', $a->getID(), ['Computer']) === 1, 'Mapped node and relation count');
    verify($ids($repo()->relations('Computer', $b->getID(), 'impacted')) === [$edgeId], 'Mapped incoming graph edge');
    verify($SQL_TOTAL_REQUEST === 0, 'Graph lookups bypass adapter SQL');
    verify(ImpactItem::findForItem($a)->getID() === $master, 'Legacy graph entry point uses mapped lookup');
    $params = json_decode(Impact::prepareParams($a), true);
    verify($params['is_slave'] === 0 && $params['show_depends'] === 0 && $params['show_impact'] === 1, 'Native booleans retain integer browser boundary');
    verify($b->delete(['id' => $b->getID()], true), 'Purge a slave asset');
    verify($read('glpi_impactitems', $slave) === null && $read('glpi_impactcontexts', $context) !== null, 'Slave purge preserves its owner context');
    verify($read('glpi_impactcompounds', $compound) !== null && $repo()->relations('Computer', $a->getID(), 'source') === [], 'Remaining pair keeps group; incident edges removed');
    verify($a->delete(['id' => $a->getID()], true), 'Purge context owner');
    verify($read('glpi_impactcontexts', $context) === null && $read('glpi_impactcompounds', $compound) === null, 'Owner context and undersized group removed');
    verify($read('glpi_impactitems', $otherSlave)['impactcontexts_id'] === null && $read('glpi_impactitems', $otherSlave)['parent_id'] === null, 'Surviving item remains with cleared optional associations');
    $context = $fixtures->create('glpi_impactcontexts', ['positions' => '{}']);
    $replacement = $fixtures->create('glpi_impactcontexts', ['positions' => '{}']);
    $node = new ImpactItem();
    verify($node->update(['id' => $otherSlave, 'impactcontexts_id' => $context]), 'Set context association');
    verify((new ImpactContext())->delete(['id' => $context, '_replace_by' => $replacement], true), 'Replace context through model lifecycle');
    verify($read('glpi_impactitems', $otherSlave)['impactcontexts_id'] === $replacement, 'Context references follow replacement');
    verify((new ImpactContext())->delete(['id' => $replacement], true), 'Purge shared context directly');
    verify($read('glpi_impactitems', $otherSlave)['impactcontexts_id'] === null, 'Direct purge clears constrained children');
    $compound = $fixtures->create('glpi_impactcompounds', ['name' => 'Direct group purge']);
    verify($node->update(['id' => $otherSlave, 'parent_id' => $compound]), 'Set compound association');
    verify((new ImpactCompound())->delete(['id' => $compound], true), 'Direct compound purge used by graph editing');
    verify($read('glpi_impactitems', $otherSlave)['parent_id'] === null, 'Direct compound purge clears membership');
    verify((new ForeignKeys())->audit($connection) === [], 'Impact graph remains valid');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$postgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
$migration = new \itsmng\Database\Migration\ImpactGraphReferences();
$created = [];
$historicalChecks = new HistoricalBooleanChecks($connection, $migration::FLAGS);
try {
    $historicalChecks->detach();
    $context = $fixtures->create('glpi_impactcontexts', ['positions' => '{}', 'show_depends' => false, 'show_impact' => true]);
    $created[] = ['glpi_impactcontexts', $context];
    $compound = $fixtures->create('glpi_impactcompounds', ['name' => 'Migrated impact group']);
    $created[] = ['glpi_impactcompounds', $compound];
    foreach (ReferenceHistory::get('optional', 'IMPACT_GRAPH')['glpi_impactitems'] as $column => $target) {
        $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_impactitems', $column), 'glpi_impactitems'));
        $connection->executeStatement('UPDATE glpi_impactitems SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
    }
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable('glpi_impactitems');
    $after = clone $before;
    foreach (['parent_id', 'impactcontexts_id'] as $column) {
        $after->getColumn($column)->setNotnull(true)->setDefault(0);
    }
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    if ($postgres) {
        foreach ($migration::FLAGS as $table => $columns) {
            foreach ($columns as $column) {
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER ' . $quote($column) . ' DROP DEFAULT');
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER ' . $quote($column) . ' TYPE SMALLINT USING (CASE WHEN ' . $quote($column) . ' THEN 1 ELSE 0 END)');
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER ' . $quote($column) . ' SET DEFAULT 1');
            }
        }
    }
    $first = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_impactitems');
    foreach ([0, 1] as $offset) {
        $connection->insert('glpi_impactitems', ['id' => $first + $offset, 'itemtype' => 'ImpactMigrationFixture', 'items_id' => $first + $offset, 'parent_id' => $offset ? $compound : 0, 'impactcontexts_id' => $offset ? $context : 0, 'is_slave' => $offset]);
        $created[] = ['glpi_impactitems', $first + $offset];
    }
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && count($plan['counts']) === 2, 'Impact migration plans nullable refs and provider flags');
    verify((int)$connection->fetchOne('SELECT parent_id FROM glpi_impactitems WHERE id = ?', [$first]) === 0, 'Planning is read-only');
    foreach (['parent_id', 'impactcontexts_id'] as $column) {
        $connection->update('glpi_impactitems', [$column => 2147483647], ['id' => $first]);
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Nonzero orphaned impact graph');
        }
        verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_impactitems')[$column]->getNotnull(), 'Invalid impact reference rejected before DDL');
        $connection->update('glpi_impactitems', [$column => 0], ['id' => $first]);
    }
    foreach ($migration::FLAGS as $table => $columns) {
        foreach ($columns as $column) {
            $rowId = $table === 'glpi_impactitems' ? $first : $context;
            $old = $connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$rowId]);
            $connection->update($table, [$column => 2], ['id' => $rowId]);
            verify((int)$connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$rowId]) === 2, 'Historical flag is genuinely invalid before the migration audit');
            $rejected = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $rejected = str_contains($error->getMessage(), 'Invalid impact graph boolean');
            }
            verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_impactitems')['parent_id']->getNotnull(), 'Invalid flag rejected before any reference DDL');
            $connection->update($table, [$column => $old], ['id' => $rowId]);
        }
    }
    $migration->apply($connection);
    verify($read('glpi_impactitems', $first)['parent_id'] === null && $read('glpi_impactitems', $first)['impactcontexts_id'] === null, 'Empty graph references become NULL');
    verify($read('glpi_impactitems', $first + 1)['parent_id'] === $compound && $read('glpi_impactitems', $first + 1)['impactcontexts_id'] === $context, 'Valid graph references remain intact');
    verify($read('glpi_impactitems', $first)['is_slave'] === 0 && $read('glpi_impactitems', $first + 1)['is_slave'] === 1, 'False and true slave flags preserved');
    verify($read('glpi_impactcontexts', $context)['show_depends'] === 0 && $read('glpi_impactcontexts', $context)['show_impact'] === 1, 'Visibility flags preserved');
    if ($postgres) {
        foreach ($migration::FLAGS as $table => $columns) {
            foreach ($columns as $column) {
                verify($connection->createSchemaManager()->listTableColumns($table)[$column]->getType() instanceof \Doctrine\DBAL\Types\BooleanType, 'Native PostgreSQL graph boolean');
            }
        }
    }
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Impact migration retries are idempotent');
} finally {
    try {
        foreach (array_reverse($created) as [$table, $id]) {
            $connection->delete($table, ['id' => $id]);
        }
        $migration->apply($connection);
        (new ForeignKeys())->apply($connection);
    } finally {
        $historicalChecks->restore();
    }
}
verify($historicalChecks->restored(), 'Impact historical fixture restores all native CHECKs and the exact current Boolean receipt');
echo $DB->getProvider() . ": Impact search, graph queries, ownership cleanup, native flags and migration passed.\n";
