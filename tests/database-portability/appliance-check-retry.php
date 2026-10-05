<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\V220\ApplianceAssets;
use itsmng\Database\Migration\V220\ApplianceRecipients;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/appliance-check-retry.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_appliances_items', 'glpi_appliances_items_relations'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $platform = $connection->getDatabasePlatform();
    $postgres = $platform instanceof PostgreSQLPlatform;
    $mysql = $platform instanceof MySQLPlatform;
    $schema = $connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
    $fixtures = new FixtureRecords($DB);
    $exists = static fn (string $table, string $name): bool => (bool)$connection->fetchOne(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = 'CHECK'",
        [$schema, $table, $name]
    );
    $drop = static fn (string $table, string $name): int => $connection->executeStatement('ALTER TABLE ' . $table . ' DROP ' . ($mysql ? 'CHECK ' : 'CONSTRAINT ') . $name);
    foreach ([['glpi_appliances_items', 'appliances_id', 'glpi_appliances', new ApplianceAssets()],
        ['glpi_appliances_items_relations', 'appliances_items_id', 'glpi_appliances_items', new ApplianceRecipients()]] as [$table, $ownerColumn, $ownerTable, $migration]) {
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Do not alter a nonempty shared fixture');
        $name = $table . '_typed_item_kind';
        $other = 'port_' . substr($table, 5) . '_nonempty_kind';
        $original = Ledger::state($connection, $migration::PHASE);
        verify($original['complete'] && !$exists($table, $other), 'Fresh history and exclusively owned constraint fixture required');
        $branches = array_slice(EntityRegistry::discriminatedReferences($table)['items_id']['selections'], 0, 2, true);
        $kind = array_key_first($branches);
        $invalidRow = static function () use ($fixtures, $branches, $kind, $ownerColumn, $ownerTable): array {
            $values = [$ownerColumn => $fixtures->create($ownerTable), 'itemtype' => $kind];
            foreach ($branches as $branch) {
                $values[$branch['column']] = $fixtures->create($branch['target']);
            }
            return $values;
        };
        try {
            $drop($table, $name);
            $connection->executeStatement('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $name . ' CHECK (1 = 1)' . ($mysql ? ' NOT ENFORCED' : ''));
            $connection->executeStatement('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $other . " CHECK (itemtype <> '')");
            $connection->delete(Ledger::TABLE, ['version' => $migration::PHASE]);
            $before = $connection->fetchAllAssociative('SELECT version, state FROM ' . Ledger::TABLE . ' ORDER BY version');
            $plan = $migration->plan($connection);
            verify(count($plan[$table]['constraints']) >= 2, 'A same-name permissive or unenforced CHECK must be replaced');
            verify($connection->fetchAllAssociative('SELECT version, state FROM ' . Ledger::TABLE . ' ORDER BY version') === $before, 'Constraint repair preview stays read-only');
            // Every malformed canonical row must refuse before the owned constraint
            // is dropped. The transaction removes only our deliberately invalid data.
            $connection->beginTransaction();
            try {
                $connection->insert($table, $invalidRow());
                try {
                    $migration->plan($connection);
                    throw new LogicException('Permissive CHECK concealed existing invalid canonical data');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Canonical and legacy typed item references disagree') || str_contains($error->getMessage(), 'Invalid canonical typed item references'), 'Preflight reports the inconsistent owning selection');
                }
                verify($exists($table, $name) && Ledger::state($connection, $migration::PHASE) === null, 'Invalid preflight alters neither existing CHECK nor ledger');
            } finally {
                $connection->rollBack();
            }
            try {
                $migration->apply($connection, static function (string $phase, string $statement): void {
                    if ($phase === 'constraints' && str_contains($statement, ' DROP ')) {
                        throw new RuntimeException('Injected interruption after owned CHECK DROP');
                    }
                });
                throw new LogicException('Owned CHECK DROP did not execute');
            } catch (RuntimeException $error) {
                verify($error->getMessage() === 'Injected interruption after owned CHECK DROP', 'Real DDL interruption is surfaced');
            }
            verify($exists($table, $name) === $postgres, 'PostgreSQL rolls back DROP; MySQL retains the committed constraint gap');
            verify($postgres ? Ledger::state($connection, $migration::PHASE) === null : !Ledger::state($connection, $migration::PHASE)['complete'], 'Interruption retains the proper retry state');
            verify($exists($table, $other), 'Migration never drops an unrelated CHECK');
            $migration->apply($connection);
            verify($exists($table, $name) && $exists($table, $other) && Ledger::state($connection, $migration::PHASE)['complete'], 'Retry installs its owned CHECK and preserves the unrelated CHECK');
            if ($mysql) {
                verify($connection->fetchOne("SELECT ENFORCED FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ?", [$schema, $table, $name]) === 'YES', 'MySQL replacement is explicitly enforced');
            }
            $connection->beginTransaction();
            try {
                $values = $invalidRow(); // Parent fixture errors cannot count as rejection.
                $rejected = false;
                try {
                    $connection->insert($table, $values);
                } catch (DriverException $error) {
                    $rejected = in_array($error->getSQLState(), ['23514', '23000'], true)
                        || NativeConstraintRefusal::matchesSelectedCheck($error, $name);
                }
                verify($rejected, 'Native writes with multiple owning selections reject after repair');
            } finally {
                $connection->rollBack();
            }
            verify($migration->apply($connection) === [], 'Completed migration leaves its CHECK untouched on repeat');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($exists($table, $other)) {
                $drop($table, $other);
            }
            if ($exists($table, $name)) {
                $drop($table, $name);
            }
            $connection->executeStatement($migration::checkSql($table));
            Ledger::save($connection, $migration::PHASE, $original);
        }
    }
    verify((new SchemaCheck())->differences($connection) === [], 'CHECK fixtures restore the entire required schema');

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
echo $DB->getProvider() . ": permissive/unenforced owned CHECK replacement, invalid canonical preflight, DROP interruption, retry, native discriminator rejection and unrelated constraint preservation passed.\n";
