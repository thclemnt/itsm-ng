<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/operating-system-purge.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$connection = $DB->getDoctrineConnection();
verify(!$connection->isTransactionActive(), 'Exercise real autocommit lifecycle');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$activated = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $activated->getValue();
$activated->setValue(null, [...$savedPlugins, 'os_purge_fixture']);
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$records = static fn () => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$prefix = 'OS purge ' . bin2hex(random_bytes(5));
$owners = [];
$assignments = [];
$source = new OperatingSystem();
$sourceId = 0;
$concurrentId = null;
$otherDatabase = null;
try {
    $sourceId = $source->add(['name' => $prefix]);
    verify($sourceId > 0, 'Create actual OS source');
    foreach (['first', 'second'] as $label) {
        $owners[] = $owner = $fixtures->create('glpi_computers', ['name' => $prefix . ' ' . $label]);
        $assignments[] = $id = (new Item_OperatingSystem())->add(['itemtype' => 'Computer', 'items_id' => $owner, 'operatingsystems_id' => $sourceId, 'license_number' => $label . ' retained license', 'is_dynamic' => true]);
        verify($id > 0, 'Create actual independently licensed assignment');
    }
    $snapshot = static fn (): array => [
        $read('glpi_operatingsystems', $sourceId),
        $read('glpi_items_operatingsystems', $assignments[0]),
        $read('glpi_items_operatingsystems', $assignments[1]),
        $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id'),
    ];
    $before = $snapshot();
    $visited = [];
    $firstWasChanged = false;
    $PLUGIN_HOOKS['pre_item_update']['os_purge_fixture'][Item_OperatingSystem::class] = static function ($item) use (&$visited, &$firstWasChanged, $read): void {
        $visited[] = (int)$item->getID();
        if (count($visited) === 2) {
            $firstWasChanged = $read('glpi_items_operatingsystems', $visited[0])['operatingsystems_id'] === null;
            $item->input = false;
        }
    };
    verify(!$source->delete(['id' => $sourceId], true), 'Refused second actual child cleanup cancels public OS purge');
    verify(count($visited) === 2 && $firstWasChanged && $snapshot() === $before && !$connection->isTransactionActive(), 'Autocommit refusal rolls back already applied cleanup, both licenses, source and audit history');

    // A committed second connection inserts the competing empty assignment after
    // domain preflight, at the real child-update hook boundary. It must survive
    // independently while the entire failed source purge rolls back.
    $otherDatabase = DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $DB->dbdefault);
    verify($otherDatabase->connected, 'Independent application database connection');
    $concurrent = $otherDatabase->getDoctrineConnection();
    verify($concurrent !== $connection && !$concurrent->isTransactionActive(), 'Concurrent connection owns an independent autocommit unit');
    $concurrent->executeStatement($concurrent->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '3s'" : 'SET SESSION innodb_lock_wait_timeout = 3');
    $visited = [];
    $firstWasChanged = false;
    $PLUGIN_HOOKS['pre_item_update']['os_purge_fixture'][Item_OperatingSystem::class] = static function ($item) use (&$visited, &$firstWasChanged, &$concurrentId, $concurrent, $read): void {
        $visited[] = (int)$item->getID();
        if (count($visited) === 2) {
            $firstWasChanged = $read('glpi_items_operatingsystems', $visited[0])['operatingsystems_id'] === null;
            $concurrent->insert('glpi_items_operatingsystems', ['itemtype' => 'Computer', 'computers_id' => $item->fields['computers_id'], 'license_number' => 'Concurrent retained license']);
            $concurrentId = (int)$concurrent->lastInsertId();
        }
    };
    $refused = false;
    $collisionPath = 'domain refusal';
    try {
        $refused = !$source->delete(['id' => $sourceId], true);
    } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        // Repeatable-read may defer recognition of the committed competitor to
        // the authoritative unique constraint rather than the domain preview.
        $refused = true;
        $collisionPath = 'native unique constraint';
    }
    verify($refused, 'Late committed assignment collision cancels actual public purge');
    verify($concurrentId > 0 && count($visited) === 2 && $firstWasChanged, 'Collision occurred after one real cleanup had already changed a licensed assignment');
    verify($snapshot() === $before && !$connection->isTransactionActive(), 'Concurrent collision rolls back every source change and original audit row');
    verify($read('glpi_items_operatingsystems', $concurrentId)['license_number'] === 'Concurrent retained license' && $read('glpi_items_operatingsystems', $concurrentId)['operatingsystems_id'] === null, 'Separately committed inventory survives source lifecycle rollback');
    $PLUGIN_HOOKS = $savedHooks;
    verify((new Item_OperatingSystem())->delete(['id' => $concurrentId], true), 'Delete concurrent fixture without touching retained source assignments');
    $concurrentId = null;
    verify($source->delete(['id' => $sourceId], true), 'Authorized purge succeeds after the real conflict is resolved');
    verify($read('glpi_operatingsystems', $sourceId) === null, 'Successful purge removes its source');
    foreach ($assignments as $index => $id) {
        $row = $read('glpi_items_operatingsystems', $id);
        verify($row['operatingsystems_id'] === null && $row['license_number'] === ['first retained license', 'second retained license'][$index], 'Successful purge preserves each independently licensed assignment');
    }
    verify((new SchemaCheck())->differences($connection) === [], 'Autocommit lifecycle keeps canonical schema');
} finally {
    $PLUGIN_HOOKS = $savedHooks;
    $activated->setValue(null, $savedPlugins);
    if ($concurrentId !== null && $read('glpi_items_operatingsystems', $concurrentId) !== null) {
        (new Item_OperatingSystem())->delete(['id' => $concurrentId], true);
    }
    foreach ($owners as $id) {
        (new Computer())->delete(['id' => $id], true);
    }
    if ($sourceId > 0 && $read('glpi_operatingsystems', $sourceId) !== null) {
        (new OperatingSystem())->delete(['id' => $sourceId], true);
    }
    $otherDatabase?->getDoctrineConnection()->close();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
}
echo $DB->getProvider() . ': actual OS child-hook refusal and committed independent-connection collision (' . $collisionPath . ") roll back partial autocommit cleanup while preserving source, both licenses and audit history; resolved purge retains assignments.\n";
