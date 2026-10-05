<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use itsmng\Database\Entity\ItemKanban;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\V220\KanbanOwnership;
use itsmng\Database\Orm;
use itsmng\Database\Repository\KanbanRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/kanban.php /path/to/test-config\n");
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
class DeniedKanbanProject extends Project
{
    public static function getTable($classname = null)
    {
        return Project::getTable();
    }

    public function prepareKanbanStateForUpdate($oldstate, $newstate, $users_id)
    {
        return false;
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$repo = static fn () => new KanbanRepository(Orm::create($DB));
$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_items_kanbans', 'id', $id);
$rejected = static function (callable $operation, string $exception, ?string $message = null) use ($connection): bool {
    try {
        $connection->transactional($operation);
        return false;
    } catch (Throwable $error) {
        if (!$error instanceof $exception && !($exception === ForeignKeyConstraintViolationException::class && $error instanceof \Doctrine\DBAL\Exception\DriverException && $error->getSQLState() === '23001')) {
            throw $error;
        }
        if ($message !== null && !str_contains($error->getMessage(), $message)) {
            throw $error;
        }
        return true;
    }
};
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'kanban_purge_fixture']);
set_error_handler(static function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$DB->beginTransaction();
try {
    $user = $fixtures->create('glpi_users', ['name' => 'Kanban owner']);
    $other = $fixtures->create('glpi_users', ['name' => 'Other Kanban owner']);
    $project = $fixtures->create('glpi_projects', ['name' => 'Kanban project']);
    $state = [
        ['column' => 'one', 'cards' => ["O'Reilly C:\\new\\日本語", 'second'], 'visible' => true, 'folded' => false],
        ['column' => 'two', 'cards' => [], 'visible' => true, 'folded' => false],
    ];
    $_SESSION['glpiID'] = $user;
    $_SESSION['glpi_currenttime'] = '2026-09-29 10:00:00';
    verify(Item_Kanban::loadStateForItem('Project', $project) === [], 'Absent shared state is empty');
    verify(!Item_Kanban::saveStateForItem('Project', $project, 'invalid state'), 'Non-array state is rejected without writing');
    verify(Item_Kanban::saveStateForItem('Project', $project, $state), 'Save shared project board');
    verify(Item_Kanban::loadStateForItem('Project', $project) === $state, 'State preserves arrays, flags, Unicode, quotes and backslashes');
    verify(Item_Kanban::loadStateForItem('Project', $project, $_SESSION['glpi_currenttime']) === null, 'Unchanged timestamp avoids retransmitting state');
    verify(Item_Kanban::loadStateForItem('Project', $project, '2026-09-28 10:00:00') === $state, 'Older timestamp loads state');
    $_SESSION['glpiID'] = $other;
    verify(Item_Kanban::loadStateForItem('Project', $project) === $state, 'Shared project state is visible to another owner');
    verify(Item_Kanban::saveStateForItem('Project', 0, $state), 'Save private aggregate board');
    $_SESSION['glpiID'] = $user;
    verify(Item_Kanban::loadStateForItem('Project', 0) === [], 'Private board is isolated per user');
    verify(Item_Kanban::saveStateForItem('Project', 0, []), 'Save empty private board');
    verify(Item_Kanban::loadStateForItem('Project', 0) === [], 'Empty state round trips');
    verify(Item_Kanban::saveStateForItem('Project', 0, ['personal' => 'retained']), 'Save replacement owner state');
    verify(!Item_Kanban::saveStateForItem(DeniedKanbanProject::class, $project, $state), 'Preparation hook still denies saves');
    verify($repo()->load(DeniedKanbanProject::class, $project, 0) === [], 'Denied save creates no state');
    Item_Kanban::moveColumn('Project', $project, 'one', 1);
    verify(Item_Kanban::getAllShownColumns('Project', $project) === ['two', 'one'], 'First column can be moved');
    Item_Kanban::moveCard('Project', $project, 'second', 'two', -1);
    verify(Item_Kanban::loadStateForItem('Project', $project)[0]['cards'] === ['second'], 'Moving card removes old position and clamps negative target');
    Item_Kanban::collapseColumn('Project', $project, 'two');
    Item_Kanban::hideColumn('Project', $project, 'two');
    verify(Item_Kanban::loadStateForItem('Project', $project)[0]['folded'] && !Item_Kanban::loadStateForItem('Project', $project)[0]['visible'], 'Column visibility and collapse persist');
    Item_Kanban::expandColumn('Project', $project, 'two');
    Item_Kanban::showColumn('Project', $project, 'two');
    Item_Kanban::showColumn('Project', $project, 'three');
    verify(Item_Kanban::getAllShownColumns('Project', $project) === ['two', 'one', 'three'], 'Expand/show retains order and adds missing column');
    $record = (new RecordRepository(Orm::create($DB)))->matching('glpi_items_kanbans', ['itemtype' => 'Project', 'items_id' => $project, 'users_id' => 0])[0];
    verify($record['users_id'] === null && $record['owner_key'] === 0, 'Shared state maps to nullable owner and generated identity');
    $_SESSION['glpi_currenttime'] = '2026-09-29 11:00:00';
    Item_Kanban::saveStateForItem('Project', $project, $state);
    verify($read($record['id'])['date_creation'] === '2026-09-29 10:00:00' && $read($record['id'])['date_mod'] === '2026-09-29 11:00:00', 'Update preserves creation and advances modification time');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    verify(Item_Kanban::saveStateForItem('Project', $project, $state), 'Save through application entry point');
    verify(Item_Kanban::loadStateForItem('Project', $project) === $state && $SQL_TOTAL_REQUEST === 0, 'Kanban entry points bypass legacy SQL');
    foreach ([null, $other] as $owner) {
        $item = $owner === null ? $project : 0;
        verify($rejected(fn () => $fixtures->create('glpi_items_kanbans', ['itemtype' => 'Project', 'items_id' => $item, 'users_id' => $owner]), UniqueConstraintViolationException::class), 'Duplicate shared/private state rejected by database');
    }
    verify($rejected(fn () => $fixtures->create('glpi_items_kanbans', ['itemtype' => 'Project', 'items_id' => $project, 'users_id' => 2147483647]), ForeignKeyConstraintViolationException::class), 'Dangling owner rejected');
    verify($rejected(fn () => $connection->delete('glpi_users', ['id' => $other]), ForeignKeyConstraintViolationException::class), 'Database restricts owner deletion until lifecycle cleanup');
    verify((new User())->delete(['id' => $other, '_replace_by' => $user], true), 'User replacement purges private Kanban state');
    verify($repo()->load('Project', 0, $other) === [] && $repo()->load('Project', $project, 0) === $state, 'Purged owner never promotes state to shared');
    verify($repo()->load('Project', 0, $user) === ['personal' => 'retained'], 'Existing replacement user state remains intact');
    $otherProject = $fixtures->create('glpi_projects', ['name' => 'Retained Kanban project']);
    verify(Item_Kanban::saveStateForItem('Project', $otherProject, ['other' => 'shared']), 'Another Project owns a separate shared board');
    $snapshot = static function () use ($connection): array {
        $graph = [];
        foreach (['glpi_projects', 'glpi_items_kanbans', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
            $graph[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->getDatabasePlatform()->quoteIdentifier($table) . ' ORDER BY id');
        }
        return $graph;
    };
    $beforePurge = $snapshot();
    verify(!(new Item_Kanban())->update(['id' => $record['id'], 'items_id' => 0]) && $snapshot() === $beforePurge, 'Shared board cannot be retargeted to an aggregate through a missing required User endpoint');
    $boardPurges = 0;
    $PLUGIN_HOOKS['pre_item_purge']['kanban_purge_fixture'][Item_Kanban::class] = static function ($item) use ($record, &$boardPurges): void {
        if ((int)$item->getID() === (int)$record['id']) {
            ++$boardPurges;
            $item->input = false;
        }
    };
    verify(!(new Project())->delete(['id' => $project], true), 'Refused public board purge cancels its Project purge');
    verify($boardPurges === 1 && $snapshot() === $beforePurge && $connection->getTransactionNestingLevel() === 1, 'Board veto restores Project, shared and private boards, history and caller transaction');
    $boardPurges = 0;
    $PLUGIN_HOOKS['pre_item_purge']['kanban_purge_fixture'][Item_Kanban::class] = static function ($item) use ($record, &$boardPurges): void {
        if ((int)$item->getID() === (int)$record['id']) {
            ++$boardPurges;
        }
    };
    verify((new Project())->delete(['id' => $project], true), 'Project lifecycle purges shared board');
    verify($boardPurges === 1 && $read($record['id']) === null, 'Board state removed once through its public purge lifecycle with project');
    verify($repo()->load('Project', $otherProject, 0) === ['other' => 'shared']
        && $repo()->load('Project', 0, $user) === ['personal' => 'retained']
        && (int)$connection->fetchOne('SELECT id FROM glpi_projects WHERE id = ?', [$otherProject]) === $otherProject, 'Project purge preserves other Projects, their shared boards and the current user private aggregate');
    unset($PLUGIN_HOOKS['pre_item_purge']['kanban_purge_fixture']);
    verify((new ForeignKeys())->audit($connection) === [], 'Ownership lifecycle leaves no orphans');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
    restore_error_handler();
}

// Interleave two real connections after the first reader has seen no row.
// This deterministically exercises the initial-save race and its rollback/retry.
$otherAdapter = new DB();
$otherAdapter->setTimezone(date_default_timezone_get());
$otherConnection = $otherAdapter->getDoctrineConnection();
try {
    foreach ([0, (int)Session::getLoginUserID()] as $owner) {
        foreach (['first', 'last'] as $finalValue) {
            $em = Orm::create($DB);
            $raceType = 'KanbanRace' . bin2hex(random_bytes(5));
            $listener = new class ($otherConnection, $em->getConfiguration(), $raceType, $owner) {
                public function __construct(private $connection, private $configuration, private string $type, private int $owner)
                {
                }

                public function prePersist(\Doctrine\ORM\Event\PrePersistEventArgs $event): void
                {
                    if ($event->getObject() instanceof ItemKanban) {
                        (new KanbanRepository(new \Doctrine\ORM\EntityManager($this->connection, $this->configuration)))
                            ->save($this->type, 1, $this->owner, ['winner' => 'first'], new DateTimeImmutable('2026-09-29 10:00:00'));
                    }
                }
            };
            $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::prePersist], $listener);
            try {
                (new KanbanRepository($em))->save($raceType, 1, $owner, ['winner' => $finalValue], new DateTimeImmutable('2026-09-29 10:00:00'));
                verify($repo()->load($raceType, 1, $owner) === ['winner' => $finalValue], 'Concurrent first saves converge, including identical saves');
                $rows = (new RecordRepository(Orm::create($DB)))->matching('glpi_items_kanbans', ['itemtype' => $raceType]);
                verify(count($rows) === 1 && $rows[0]['date_creation'] === '2026-09-29 10:00:00', 'Initial-save race preserves one row and original creation time');
            } finally {
                $connection->delete('glpi_items_kanbans', ['itemtype' => $raceType]);
            }
        }
    }
} finally {
    $otherConnection->close();
}

$platform = $connection->getDatabasePlatform();
$migration = new KanbanOwnership();
$table = 'glpi_items_kanbans';
$type = 'KanbanMigration' . bin2hex(random_bytes(5));
$quote = $platform->quoteIdentifier(...);
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, 'users_id'), $table));
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->dropIndex(KanbanOwnership::indexName($platform));
    $after->dropColumn('owner_key');
    $after->addUniqueIndex(['itemtype', 'items_id', 'users_id'], KanbanOwnership::indexName($platform));
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->insert($table, ['itemtype' => $type, 'items_id' => 1, 'users_id' => null, 'state' => '{}']);
    $connection->insert($table, ['itemtype' => $type, 'items_id' => 1, 'users_id' => 0, 'state' => '{}']);
    verify($rejected(fn () => $migration->plan($connection), RuntimeException::class, 'Duplicate Kanban owner states'), 'Duplicate nullable/zero shared states block migration');
    verify(!$manager->introspectTable($table)->hasColumn('owner_key'), 'Duplicate audit makes no schema changes');
    $connection->delete($table, ['itemtype' => $type, 'users_id' => 0]);
    $connection->executeStatement('UPDATE glpi_items_kanbans SET users_id = 0 WHERE users_id IS NULL');
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->getColumn('users_id')->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->insert($table, ['itemtype' => $type, 'items_id' => 2, 'users_id' => 2147483647, 'state' => '{}']);
    verify($rejected(fn () => $migration->apply($connection), RuntimeException::class, 'Nonzero orphaned Kanban owner references'), 'Orphan owner blocks migration before DDL');
    verify($manager->listTableColumns($table)['users_id']->getNotnull(), 'Orphan refusal preserves old column');
    $connection->update($table, ['users_id' => (int)Session::getLoginUserID()], ['itemtype' => $type, 'items_id' => 2]);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && $plan['counts'] !== [], 'Plans schema and zero normalization');
    $migration->apply($connection);
    $rows = (new RecordRepository(Orm::create($DB)))->matching($table, ['itemtype' => $type], ['items_id']);
    verify($rows[0]['users_id'] === null && $rows[1]['users_id'] === (int)Session::getLoginUserID(), 'Migration preserves shared and personal state ownership');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Retry is idempotent');
    verify($rejected(fn () => $connection->insert($table, ['itemtype' => $type, 'items_id' => 1, 'users_id' => null]), UniqueConstraintViolationException::class), 'Migrated shared state remains unique');
} finally {
    $connection->delete($table, ['itemtype' => $type]);
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Kanban state, ownership, uniqueness, concurrent first save and migration passed.\n";
