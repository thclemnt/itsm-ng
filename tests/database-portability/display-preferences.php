<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\DisplayPreferenceOwnership;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DisplayPreferenceRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/display-preferences.php /path/to/test-config\n");
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
$repo = static fn () => new DisplayPreferenceRepository(Orm::create($DB));
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
set_error_handler(static function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);

$DB->beginTransaction();
try {
    $user = $fixtures->create('glpi_users', ['name' => 'Display owner']);
    $other = $fixtures->create('glpi_users', ['name' => 'Other display owner']);
    $type = "Display O'Reilly \\Example 日本語";
    $defaults = [];
    foreach ([10 => 5, 20 => 5, 30 => 9] as $num => $rank) {
        $defaults[] = $fixtures->create('glpi_displaypreferences', ['itemtype' => $type, 'num' => $num, 'rank' => $rank]);
    }
    verify(DisplayPreference::getForTypeUser($type, $user) === [10, 20, 30], 'Absent personal list uses defaults, with stable order for tied ranks');
    $private = $fixtures->create('glpi_displaypreferences', ['itemtype' => $type, 'num' => 90, 'rank' => 1, 'users_id' => $user]);
    verify(DisplayPreference::getForTypeUser($type, $user) === [90], 'Personal list replaces all defaults');
    verify(DisplayPreference::getForTypeUser($type, $other) === [10, 20, 30], 'Other user preferences remain isolated');
    $_SESSION['glpiID'] = $user;
    $_SESSION['glpiactiveprofile']['search_config'] = DisplayPreference::PERSONAL;
    $model = new DisplayPreference();
    $added = $model->add(['itemtype' => addslashes($type), 'num' => 91, 'users_id' => $user]);
    verify($added > 0 && $repo()->nextRank($type, $user) === 3, 'Model add assigns the next personal rank with escaped legacy input');
    verify(!$model->activatePerso(['itemtype' => addslashes($type), 'users_id' => $user]), 'Repeated activation preserves a configured list');
    verify(!$model->activatePerso(['itemtype' => addslashes($type), 'users_id' => $other]), 'Cannot activate another user preferences');
    verify(!$model->orderItem(['id' => $defaults[1], 'itemtype' => addslashes($type), 'users_id' => 0], 'up'), 'Personal right does not grant default-list writes');
    $_SESSION['glpiID'] = $other;
    $_SESSION['glpiactiveprofile']['search_config'] = 0;
    verify(!$model->activatePerso(['itemtype' => addslashes($type), 'users_id' => $other]), 'Activation requires the personal right');
    $_SESSION['glpiactiveprofile']['search_config'] = DisplayPreference::PERSONAL;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    verify($model->activatePerso(['itemtype' => addslashes($type), 'users_id' => $other]), 'Activate a complete mapped copy of default columns');
    verify(!$model->activatePerso(['itemtype' => addslashes($type), 'users_id' => $other]), 'Activation is idempotent');
    verify(DisplayPreference::getForTypeUser($type, $other) === [10, 20, 30], 'Activation preserves default order and contents');
    $rows = $repo()->rows($type, $other);
    verify($model->orderItem(['id' => $rows[1]['id'], 'itemtype' => addslashes($type), 'users_id' => $other], 'up'), 'Reorder tied ranks');
    verify(DisplayPreference::getForTypeUser($type, $other) === [20, 10, 30], 'Reorder is stable and scoped');
    verify(array_column($repo()->rows($type, $other), 'rank') === [1, 2, 3], 'Reorder repairs gaps and ties');
    verify(!$model->orderItem(['id' => $private, 'itemtype' => addslashes($type), 'users_id' => $other], 'down'), 'Foreign owner row cannot be moved by spoofing scope');
    verify(!$model->orderItem(['id' => $rows[1]['id'], 'itemtype' => 'WrongType', 'users_id' => $other], 'down'), 'Row must belong to the selected item type');
    verify(!$model->orderItem(['id' => $rows[1]['id'], 'itemtype' => addslashes($type), 'users_id' => $other], 'up'), 'Moving first row up is a safe no-op');
    verify(!$repo()->move($type, $other, $rows[1]['id'], 'invalid'), 'Invalid directions are rejected');
    verify($SQL_TOTAL_REQUEST === 0, 'Preference activation, lookup and ordering bypass legacy SQL');
    verify($repo()->countsByType($other) === [['itemtype' => $type, 'nb' => 3]], 'Mapped per-type counts');
    $beforeMove = $repo()->rows($type, $other);
    $failingManager = Orm::create($DB);
    $failingManager->getEventManager()->addEventListener([\Doctrine\ORM\Events::preUpdate], new class () {
        private int $updates = 0;
        public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $event): void
        {
            if (++$this->updates === 2) {
                throw new RuntimeException('Injected second-rank failure');
            }
        }
    });
    verify($rejected(fn () => (new DisplayPreferenceRepository($failingManager))->move($type, $other, $rows[0]['id'], 'up'), RuntimeException::class, 'Injected second-rank failure'), 'Exercise failure after the first rank update');
    verify($repo()->rows($type, $other) === $beforeMove, 'Failed reorder rolls back every rank update');

    verify(DisplayPreference::getForTypeUser($type, 0) === [10, 20, 30], 'Personal reorder leaves defaults unchanged');
    $_SESSION['glpiactiveprofile']['search_config'] |= DisplayPreference::GENERAL;
    verify($model->orderItem(['id' => $defaults[0], 'itemtype' => addslashes($type), 'users_id' => 0], 'down'), 'Default-list ordering requires and accepts the general right');
    verify(DisplayPreference::getForTypeUser($type, 0) === [20, 10, 30], 'Default list reordered independently');
    foreach ([null, $other] as $owner) {
        verify($rejected(fn () => $fixtures->create('glpi_displaypreferences', ['itemtype' => $type, 'num' => 10, 'users_id' => $owner]), UniqueConstraintViolationException::class), 'Shared and personal columns are unique in the database');
    }
    verify($rejected(fn () => $fixtures->create('glpi_displaypreferences', ['itemtype' => $type, 'users_id' => 2147483647]), ForeignKeyConstraintViolationException::class), 'Dangling preference owner rejected');
    verify($rejected(fn () => $connection->delete('glpi_users', ['id' => $user]), ForeignKeyConstraintViolationException::class), 'Owner deletion requires preference cleanup');
    verify((new User())->delete(['id' => $user, '_replace_by' => $other], true), 'User replacement cleans private preferences');
    verify($repo()->rows($type, $user) === [] && DisplayPreference::getForTypeUser($type, 0) === [20, 10, 30], 'Private preferences never become defaults on purge');
    verify(DisplayPreference::getForTypeUser($type, $other) === [20, 10, 30], 'Replacement user keeps their own preferences');
    $beforeBulk = $repo()->rows($type, $other);
    $replaceManager = Orm::create($DB);
    $replaceManager->getEventManager()->addEventListener([\Doctrine\ORM\Events::preUpdate], new class () {
        private int $updates = 0;
        public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $event): void
        {
            if (++$this->updates === 2) {
                throw new RuntimeException('Injected bulk-save failure');
            }
        }
    });
    verify($rejected(fn () => (new DisplayPreferenceRepository($replaceManager))->replaceColumns($type, $other, [10, 40], [20]), RuntimeException::class, 'Injected bulk-save failure'), 'Exercise failure during modal bulk save');
    verify($repo()->rows($type, $other) === $beforeBulk, 'Bulk failure rolls back inserts and rank updates');
    $SQL_TOTAL_REQUEST = 0;
    verify($repo()->replaceColumns($type, $other, [10, 40, 40], [20]), 'Save complete modal selection');
    verify(DisplayPreference::getForTypeUser($type, $other) === [10, 40, 20], 'Bulk save deduplicates, removes old columns and retains protected columns');
    $afterBulk = $repo()->rows($type, $other);
    verify($afterBulk[2]['id'] === $beforeBulk[0]['id'], 'Retained column identities survive modal saves');
    verify(!$repo()->replaceColumns('UnactivatedPreference', $other, [2], []), 'Personal bulk save requires prior activation');
    verify($repo()->replaceColumns($type, 0, [30, 10], [20]), 'Default modal save supports a NULL owner');
    verify(DisplayPreference::getForTypeUser($type, 0) === [30, 10, 20], 'Default modal selection retains protected columns');
    verify($repo()->replaceColumns('NewSharedDisplayDefaults', 0, [2, 3], []), 'A new shared list can be created without existing preference rows');
    verify(array_column($repo()->rows('NewSharedDisplayDefaults', 0), 'users_id') === [null, null], 'New default columns retain absent-user semantics');
    verify($SQL_TOTAL_REQUEST === 0, 'Modal bulk saves bypass adapter SQL');
    // Exercise fallback generation for a real searchable type without defaults.
    (new DisplayPreference())->deleteByCriteria(['itemtype' => 'Computer', 'users_id' => 0]);
    verify($model->activatePerso(['itemtype' => 'Computer', 'users_id' => $other]), 'Fallback creates a valid column when no defaults exist');
    $fallback = DisplayPreference::getForTypeUser('Computer', $other);
    verify(count($fallback) === 1 && $fallback[0] !== 1 && isset(Search::getOptions('Computer')[$fallback[0]]), 'Fallback selects a real non-name search option');
    foreach (['showFormPerso', 'showFormGlobal'] as $method) {
        ob_start();
        $model->$method('/front/displaypreference.form.php', 'Computer');
        $html = ob_get_clean();
        verify(str_contains($html, 'name="users_id"') && str_contains($html, 'data-itsm-table-config') && !str_contains($html, 'Warning:'), 'Render mapped preference form: ' . $method);
    }
    ob_start();
    DisplayPreference::showForUser($other);
    $html = ob_get_clean();
    verify(str_contains($html, 'List of Items with Actions'), 'Render mapped per-user type summary');
    verify((new ForeignKeys())->audit($connection) === [], 'Preference lifecycle leaves no orphan references');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}

$platform = $connection->getDatabasePlatform();
$migration = new DisplayPreferenceOwnership();
$table = 'glpi_displaypreferences';
$type = 'DisplayPreferenceMigration' . bin2hex(random_bytes(5));
$quote = $platform->quoteIdentifier(...);
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, 'users_id'), $table));
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->dropIndex(DisplayPreferenceOwnership::indexName($platform));
    $after->dropColumn('owner_key');
    $after->addUniqueIndex(['users_id', 'itemtype', 'num'], DisplayPreferenceOwnership::indexName($platform));
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->insert($table, ['itemtype' => $type, 'num' => 1, 'users_id' => null]);
    $connection->insert($table, ['itemtype' => $type, 'num' => 1, 'users_id' => 0]);
    verify($rejected(fn () => $migration->plan($connection), RuntimeException::class, 'Duplicate display preference owners'), 'Duplicate nullable/zero shared states block migration');
    verify(!$manager->introspectTable($table)->hasColumn('owner_key'), 'Duplicate audit makes no schema changes');
    $connection->delete($table, ['itemtype' => $type, 'users_id' => 0]);
    $connection->executeStatement('UPDATE glpi_displaypreferences SET users_id = 0 WHERE users_id IS NULL');
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->getColumn('users_id')->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->insert($table, ['itemtype' => $type, 'num' => 2, 'users_id' => 2147483647]);
    verify($rejected(fn () => $migration->apply($connection), RuntimeException::class, 'Nonzero orphaned display preference owner references'), 'Orphan owner blocks migration before DDL');
    verify($manager->listTableColumns($table)['users_id']->getNotnull(), 'Orphan refusal preserves old column');
    $connection->update($table, ['users_id' => (int)Session::getLoginUserID()], ['itemtype' => $type, 'num' => 2]);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && $plan['counts'] !== [], 'Plans schema and zero normalization');
    $migration->apply($connection);
    $rows = (new RecordRepository(Orm::create($DB)))->matching($table, ['itemtype' => $type], ['num']);
    verify($rows[0]['users_id'] === null && $rows[1]['users_id'] === (int)Session::getLoginUserID(), 'Migration preserves shared and personal state ownership');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'Retry is idempotent');
    verify($rejected(fn () => $connection->insert($table, ['itemtype' => $type, 'num' => 1, 'users_id' => null]), UniqueConstraintViolationException::class), 'Migrated shared state remains unique');
} finally {
    $connection->delete($table, ['itemtype' => $type]);
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Display preferences, scoped ordering, activation, ownership and migration passed.\n";
