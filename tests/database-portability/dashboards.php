<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\DashboardOwnership;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DashboardRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/dashboards.php /path/to/test-config\n");
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
$repo = static fn () => new DashboardRepository(Orm::create($DB));
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


$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_dashboards', 'id', $id);
$DB->beginTransaction();
try {
    $connection->executeStatement('DELETE FROM glpi_dashboards');
    $user = $fixtures->create('glpi_users', ['name' => 'Dashboard owner']);
    $other = $fixtures->create('glpi_users', ['name' => 'Other dashboard owner']);
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Dashboard profile']);
    $outside = $fixtures->create('glpi_profiles', ['name' => 'Other dashboard profile']);
    foreach ([$profile, $outside] as $id) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $id, 'name' => 'profile', 'rights' => 0]);
    }
    $make = static fn (?int $owner, ?int $scope, string $name): int => $fixtures->create('glpi_dashboards', ['userId' => $owner, 'profileId' => $scope, 'name' => $name, 'content' => '[]']);
    $global = $make(null, null, 'Global');
    $scoped = $make(null, $profile, 'Profile');
    $personal = $make($user, null, 'Personal');
    $specific = $make($user, $profile, 'Personal profile');
    $hidden = $make($other, $outside, 'Other user and profile');
    $wrongProfile = $make(null, $outside, 'Other profile default');
    verify($repo()->forUser($user, $profile) === $specific, 'Personal profile dashboard wins');
    verify($repo()->forUser($user, $outside) === $personal, 'Personal unscoped dashboard precedes profile default');
    verify($repo()->forUser($other, $profile) === $scoped, 'Another user sees the active profile default');
    verify($repo()->forUser($other, 0) === $global, 'Global fallback excludes inactive profile dashboards');
    verify($repo()->forUser(0, $profile) === null, 'Anonymous dashboard lookup is denied');
    $_SESSION['glpiID'] = $user;
    $_SESSION['glpiactiveprofile']['id'] = $profile;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $dashboard = new Dashboard();
    verify($dashboard->getForUser() && (int)$dashboard->getID() === $specific, 'Dashboard application entry point uses mapped selection');
    verify($SQL_TOTAL_REQUEST === 0, 'Dashboard selection bypasses legacy SQL');
    $literal = "O'Reilly \\path 日本語";
    verify($dashboard->update(['id' => $specific, 'name' => addslashes($literal)]), 'Mapped dashboard update');
    verify($read($specific)['name'] === $literal, 'Title round trips through legacy model boundary');
    $added = (new Dashboard())->add(['name' => 'Generated dashboard', 'content' => '[]', 'profileId' => $outside, 'userId' => $user]);
    verify($added > 0 && $added !== $specific, 'Model insert uses generated numeric dashboard identity');
    foreach ([[null, null], [null, $profile], [$user, null], [$user, $profile]] as [$owner, $scope]) {
        verify($rejected(fn () => $make($owner, $scope, 'Duplicate'), UniqueConstraintViolationException::class), 'Every shared/private owner combination remains unique');
    }
    foreach (['profileId', 'userId'] as $column) {
        verify($rejected(fn () => $fixtures->create('glpi_dashboards', [$column => 2147483647, 'name' => 'Orphan', 'content' => '[]']), ForeignKeyConstraintViolationException::class), 'Dangling owner rejected: ' . $column);
    }
    verify((new User())->delete(['id' => $user, '_replace_by' => $other], true), 'Purge user with personal dashboards');
    verify($read($personal) === null && $read($specific) === null && $read($added) === null, 'Personal dashboards are deleted rather than made shared');
    verify($read($hidden) !== null && $read($global) !== null, 'Replacement user and global dashboards survive');
    verify((new Profile())->delete(['id' => $profile, '_replace_by' => $outside], true), 'Purge profile with dashboard');
    verify($read($scoped) === null && $read($wrongProfile) !== null, 'Profile purge does not overwrite replacement profile dashboard');
    verify((new ForeignKeys())->audit($connection) === [], 'Dashboard lifecycle leaves no dangling references');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}

// Execute the ORM's own generated DDL as well as testing the baseline schema.
// Mixed-case owner columns must be quoted for the active provider in both paths.
$em = Orm::create($DB);
$metadata = $em->getClassMetadata(\itsmng\Database\Entity\Dashboard::class);
$metadata->setPrimaryTable(['name' => 'glpi_dashboard_mapping_probe', 'uniqueConstraints' => ['dashboard_probe_owners' => ['columns' => ['profile_key', 'user_key']]]]);
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
try {
    foreach ((new \Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql([$metadata]) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->executeStatement('INSERT INTO glpi_dashboard_mapping_probe (name, content) VALUES (?, ?)', ['Mapping probe', '[]']);
    $row = $connection->fetchAssociative('SELECT * FROM glpi_dashboard_mapping_probe');
    verify((int)$row['id'] > 0 && (int)$row['profile_key'] === 0 && (int)$row['user_key'] === 0, 'ORM DDL generates valid identity and nullable-owner keys');
} finally {
    $connection->executeStatement('DROP TABLE IF EXISTS glpi_dashboard_mapping_probe');
}

$migration = new DashboardOwnership();
$table = 'glpi_dashboards';
$created = [];
try {
    foreach (['profileId', 'userId'] as $column) {
        $connection->executeStatement($platform->getDropForeignKeySQL($quote(ForeignKeys::name($table, $column)), $table));
        $connection->executeStatement('UPDATE glpi_dashboards SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
    }
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->dropIndex('dashboard_owners');
    foreach (DashboardOwnership::KEYS as $key => $column) {
        $after->dropColumn($key);
        $after->getColumn($column)->setNotnull(true)->setDefault(0);
    }
    // The legacy id was UNIQUE AUTO_INCREMENT even with a composite primary key.
    if (!$after->hasIndex('legacy_dashboard_id')) {
        $after->addUniqueIndex(['id'], 'legacy_dashboard_id');
    }
    $after->dropPrimaryKey();
    $after->setPrimaryKey(['`profileId`', '`userId`']);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    // Reconstruct legacy auto-increment after DBAL's temporary PK-change removal.
    $before = $manager->introspectTable($table);
    $after = clone $before;
    $after->getColumn('id')->setAutoincrement(true);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $user = (int)Session::getLoginUserID();
    $profile = (int)$_SESSION['glpiactiveprofile']['id'];
    $first = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_dashboards');
    foreach ([[0, 0], [$profile, $user]] as $offset => [$scope, $owner]) {
        $connection->executeStatement('INSERT INTO glpi_dashboards (id, name, content, ' . $quote('profileId') . ', ' . $quote('userId') . ') VALUES (?, ?, ?, ?, ?)', [$first + $offset, 'Migrated dashboard', '{"literal":"日本語"}', $scope, $owner]);
        $created[] = $first + $offset;
    }
    foreach (['profileId', 'userId'] as $column) {
        $connection->executeStatement('UPDATE glpi_dashboards SET ' . $quote($column) . ' = ? WHERE id = ?', [2147483647, $first]);
        verify($rejected(fn () => $migration->apply($connection), RuntimeException::class, 'Nonzero orphaned dashboard owner'), 'Orphan audit rejects before PK changes');
        verify(count($manager->introspectTable($table)->getPrimaryKey()->getColumns()) === 2, 'Failed audit retains old composite identity');
        $connection->executeStatement('UPDATE glpi_dashboards SET ' . $quote($column) . ' = 0 WHERE id = ?', [$first]);
    }
    $migration->apply($connection);
    verify($read($first)['profileId'] === null && $read($first)['userId'] === null, 'Shared scope normalizes to NULL');
    verify($read($first + 1)['profileId'] === $profile && $read($first + 1)['userId'] === $user, 'Valid dashboard scope survives');
    verify($read($first)['content'] === '{"literal":"日本語"}', 'Content and numeric identifiers are preserved');
    verify($manager->introspectTable($table)->getPrimaryKey()->getColumns() === ['id'] && $manager->listTableColumns($table)['id']->getAutoincrement(), 'Numeric primary key retains generated identity');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []], 'Dashboard migration is idempotent');
    $generated = $fixtures->create($table, ['name' => 'After migration', 'content' => '[]', 'profileId' => $profile]);
    $created[] = $generated;
    verify($generated > 0 && $read($generated)['profileId'] === $profile, 'Generated identity remains usable after migration');

    // Simulate an interrupted rollout before the normalized unique index exists.
    $connection->executeStatement($platform->getDropIndexSQL('dashboard_owners', $table));
    $duplicate = $first + 1000;
    $connection->executeStatement('INSERT INTO glpi_dashboards (id, name, content, ' . $quote('profileId') . ', ' . $quote('userId') . ') VALUES (?, ?, ?, 0, 0)', [$duplicate, 'Duplicate zero owners', '[]']);
    $created[] = $duplicate;
    verify($rejected(fn () => $migration->apply($connection), RuntimeException::class, 'Duplicate normalized dashboard owners'), 'Partial migration refuses zero/NULL duplicate scope before DDL');
    verify(!$manager->introspectTable($table)->hasIndex('dashboard_owners'), 'Rejected duplicate audit leaves schema unchanged');
    $connection->delete($table, ['id' => $duplicate]);
    $migration->apply($connection);
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []], 'Partial migration can resume after duplicate repair');
} finally {
    foreach ($created as $id) {
        $connection->delete($table, ['id' => $id]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    if ($connection->createSchemaManager()->introspectTable($table)->hasIndex('legacy_dashboard_id')) {
        $connection->executeStatement($platform->getDropIndexSQL('legacy_dashboard_id', $table));
    }
    $DB->synchronizeSequences();
}
echo $DB->getProvider() . ": Dashboard selection, ownership, generated mapping DDL and migration passed.\n";
