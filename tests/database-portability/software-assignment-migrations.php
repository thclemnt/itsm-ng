<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\SoftwareInstallationSubjects20261011;
use itsmng\Database\Migration\SoftwareLicenseSubjects20261011;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-assignment-migrations.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable parent required');
$name = getenv('PORT_SOFTWARE_SUBJECTS_DB') ?: 'itsm_port_software_subjects';
verify(str_starts_with($name, 'itsm_port_') && str_ends_with($name, '_software_subjects') && $name !== $DB->dbdefault, 'Dedicated assignment migration database required');
$database = DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $name);
verify($database->connected, 'Provision the isolated software assignment migration database');
$connection = $database->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$subjects = ['Computer' => 'computers', 'Monitor' => 'monitors', 'NetworkEquipment' => 'networkequipments', 'Peripheral' => 'peripherals', 'Phone' => 'phones', 'Printer' => 'printers'];
$owned = ['glpi_entities', ...array_map(static fn (string $subject): string => 'glpi_' . $subject, $subjects),
    'glpi_softwares', 'glpi_softwareversions', 'glpi_softwarelicenses', 'glpi_items_softwareversions', 'glpi_items_softwarelicenses', 'itsmng_migrations'];
verify(array_diff($manager->listTableNames(), $owned) === [], 'No unrelated tables may be reset');
$reset = static function () use ($connection, $manager, $platform, $postgres, $owned): void {
    foreach (array_reverse($owned) as $table) {
        if ($manager->tablesExist([$table])) {
            $connection->executeStatement('DROP TABLE ' . $platform->quoteIdentifier($table) . ($postgres ? ' CASCADE' : ''));
        }
    }
};
$reset();
try {
    // Minimal frozen source context, independent of today's entity declarations.
    foreach (['glpi_entities', 'glpi_softwares', 'glpi_softwareversions', 'glpi_softwarelicenses', ...array_map(static fn (string $subject): string => 'glpi_' . $subject, $subjects)] as $name) {
        $table = new Table($name);
        $table->addColumn('id', 'bigint', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('entities_id', 'bigint', ['notnull' => $name !== 'glpi_entities', 'default' => $name === 'glpi_entities' ? null : 0]);
        $table->addColumn('is_recursive', 'boolean', ['default' => false]);
        if (in_array($name, ['glpi_softwareversions', 'glpi_softwarelicenses'], true)) {
            $table->addColumn('softwares_id', 'bigint');
        }
        $manager->createTable($table);
    }
    foreach ([false, true] as $licenses) {
        $table = new Table($licenses ? 'glpi_items_softwarelicenses' : 'glpi_items_softwareversions');
        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        $table->addColumn($licenses ? 'softwarelicenses_id' : 'softwareversions_id', 'bigint');
        $table->addColumn('items_id', 'bigint', ['default' => 0, 'comment' => 'Frozen subject provenance: itemtype = Computer; CASE itemtype ']);
        $table->addColumn('itemtype', 'string', ['length' => 100]);
        foreach (['is_deleted', 'is_dynamic'] as $flag) {
            $table->addColumn($flag, 'boolean', ['default' => false]);
        }
        if (!$licenses) {
            $table->addColumn('entities_id', 'bigint', ['default' => 0]);
            $table->addColumn('is_deleted_item', 'boolean', ['default' => false]);
            $table->addColumn('is_template_item', 'boolean', ['default' => false]);
            $table->addColumn('date_install', 'date', ['notnull' => false]);
            $table->addUniqueIndex(['itemtype', 'items_id', 'softwareversions_id'], 'items_softwareversions_unicity');
        }
        $manager->createTable($table);
    }
    foreach ([0 => null, 1 => 0, 2 => 0] as $id => $parent) {
        $connection->insert('glpi_entities', ['id' => $id, 'entities_id' => $parent]);
    }
    $connection->insert('glpi_softwares', ['id' => 1]);
    $connection->insert('glpi_softwareversions', ['id' => 1, 'softwares_id' => 1]);
    $connection->insert('glpi_softwarelicenses', ['id' => 1, 'softwares_id' => 1]);
    $sameId = 4294970801;
    foreach ($subjects as $subject) {
        $connection->insert('glpi_' . $subject, ['id' => $sameId]);
    }
    $migrations = ['glpi_items_softwareversions' => new SoftwareInstallationSubjects20261011(), 'glpi_items_softwarelicenses' => new SoftwareLicenseSubjects20261011()];
    foreach ($migrations as $table => $migration) {
        $parent = $table === 'glpi_items_softwareversions' ? 'softwareversions_id' : 'softwarelicenses_id';
        foreach ([['itemtype' => 'PluginInventoryAsset', 'items_id' => $sameId], ['itemtype' => 'computer', 'items_id' => $sameId],
            ['itemtype' => 'Computer ', 'items_id' => $sameId], ['itemtype' => 'Computer', 'items_id' => 0],
            ['itemtype' => 'Computer', 'items_id' => $sameId + 99], ['itemtype' => 'Computer', 'items_id' => $sameId, $parent => 99]] as $invalid) {
            $connection->insert($table, ['id' => 701] + $invalid + [$parent => 1]);
            try {
                $migration->apply($connection);
                throw new LogicException('Invalid source accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), $table), 'Concrete table/row diagnostic');
            }
            verify(Ledger::state($connection, $migration::VERSION) === null && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Read-only preflight leaves columns and ledger untouched');
            $connection->delete($table, ['id' => 701]);
        }
        // Sibling ends and wrong installation owner state are not repaired silently.
        $connection->update('glpi_computers', ['entities_id' => 1], ['id' => $sameId]);
        $connection->update($parent === 'softwareversions_id' ? 'glpi_softwareversions' : 'glpi_softwarelicenses', ['entities_id' => 2], ['id' => 1]);
        $connection->insert($table, ['id' => 701, 'itemtype' => 'Computer', 'items_id' => $sameId, $parent => 1]
            + ($parent === 'softwareversions_id' ? ['entities_id' => 1] : []));
        try {
            $migration->apply($connection);
            throw new LogicException('Sibling ownership accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'entity scope'), 'Sibling ownership diagnostics preserve source rows');
        }
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 1, 'Invalid ownership row remains intact');
        $connection->delete($table, ['id' => 701]);
        $connection->update('glpi_computers', ['entities_id' => 0], ['id' => $sameId]);
        $connection->update($parent === 'softwareversions_id' ? 'glpi_softwareversions' : 'glpi_softwarelicenses', ['entities_id' => 0], ['id' => 1]);
        $parentTable = $parent === 'softwareversions_id' ? 'glpi_softwareversions' : 'glpi_softwarelicenses';
        $probe = static function (bool $accepted, string $diagnostic = '') use ($migration, $connection): void {
            if ($accepted) {
                verify($migration->plan($connection) !== [], 'Valid historical scope produces a read-only migration plan');
            } else {
                try {
                    $migration->plan($connection);
                    throw new LogicException('Invalid historical scope accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), $diagnostic), 'Historical scope diagnostic ' . $diagnostic);
                }
            }
            verify(Ledger::state($connection, $migration::VERSION) === null, 'Historical scope preflight creates no ledger');
        };
        if (!$postgres) {
            // Historical MySQL BOOLEAN is physically TINYINT and can contain non-boolean values.
            $connection->update('glpi_computers', ['is_recursive' => 2], ['id' => $sameId]);
            $connection->insert($table, ['id' => 701, 'itemtype' => 'Computer', 'items_id' => $sameId, $parent => 1]);
            $probe(false, 'zero/one recursive flags');
            $connection->delete($table, ['id' => 701]);
            $connection->update('glpi_computers', ['is_recursive' => 0], ['id' => $sameId]);
        }
        if ($parent === 'softwareversions_id') {
            $connection->insert($table, ['id' => 701, 'itemtype' => 'Computer', 'items_id' => $sameId, $parent => 1, 'entities_id' => 1]);
            $probe(false, 'subject ownership');
            $connection->delete($table, ['id' => 701]);
        }
        $connection->update($parentTable, ['softwares_id' => 99], ['id' => 1]);
        $connection->insert($table, ['id' => 701, 'itemtype' => 'Computer', 'items_id' => $sameId, $parent => 1]);
        $probe(false, 'Missing version/licence or owning Software');
        $connection->delete($table, ['id' => 701]);
        $connection->update($parentTable, ['softwares_id' => 1], ['id' => 1]);
        // A recursive ancestor parent may reach a child subject.
        $connection->update('glpi_computers', ['entities_id' => 1], ['id' => $sameId]);
        $connection->update($parentTable, ['is_recursive' => 1], ['id' => 1]);
        $connection->update('glpi_softwares', ['is_recursive' => 1], ['id' => 1]);
        $connection->insert($table, ['id' => 701, 'itemtype' => 'Computer', 'items_id' => $sameId, $parent => 1]
            + ($parent === 'softwareversions_id' ? ['entities_id' => 1] : []));
        $probe(true);
        if ($parent === 'softwarelicenses_id') {
            $connection->update('glpi_softwares', ['is_recursive' => 0], ['id' => 1]);
            $probe(false, 'entity scope');
        }
        $connection->delete($table, ['id' => 701]);
        // Reverse direction: a recursive ancestor subject may reach a child parent.
        $connection->update('glpi_computers', ['entities_id' => 0, 'is_recursive' => 1], ['id' => $sameId]);
        $connection->update($parentTable, ['entities_id' => 1, 'is_recursive' => 0], ['id' => 1]);
        $connection->insert($table, ['id' => 701, 'itemtype' => 'Computer', 'items_id' => $sameId, $parent => 1]);
        $probe(true);
        $connection->update('glpi_computers', ['is_recursive' => 0], ['id' => $sameId]);
        $probe(false, 'entity scope');
        $connection->delete($table, ['id' => 701]);
        $connection->update($parentTable, ['entities_id' => 0, 'is_recursive' => 0], ['id' => 1]);
        $connection->update('glpi_softwares', ['is_recursive' => 0], ['id' => 1]);
        $id = 800;
        foreach ($subjects as $kind => $subject) {
            $connection->insert($table, ['id' => ++$id, 'itemtype' => $kind, 'items_id' => $sameId, $parent => 1, 'is_dynamic' => 1]
                + ($parent === 'softwareversions_id' ? ['date_install' => '2026-10-02'] : []));
            if ($parent === 'softwarelicenses_id') {
                $connection->insert($table, ['id' => ++$id, 'itemtype' => $kind, 'items_id' => $sameId, $parent => 1, 'is_deleted' => 1]);
            }
        }
        $before = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        foreach (['columns', 'copy', 'projection', 'constraints'] as $phase) {
            try {
                $migration->apply($connection, static function (string $step) use ($phase, $connection, $platform, $table, $postgres): void {
                    if ($step === $phase) {
                        if (!$postgres && $phase === 'projection') {
                            // Model a committed projection whose comment vanished before
                            // its checkpoint: retry must restore both original provenance
                            // and the frozen exact-kind expression, not the generic CASE.
                            $expression = $connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'items_id']);
                            verify(is_string($expression) && $expression !== '', 'Interrupted native projection exists before comment-loss fixture');
                            $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' MODIFY COLUMN items_id BIGINT GENERATED ALWAYS AS (' . $expression . ") STORED COMMENT ''");
                        }
                        throw new RuntimeException('Injected assignment ' . $phase . ' interruption');
                    }
                });
                throw new LogicException('Interruption did not execute');
            } catch (RuntimeException $error) {
                verify($error->getMessage() === 'Injected assignment ' . $phase . ' interruption', 'Phase fault is surfaced');
            }
            verify((Ledger::state($connection, $migration::VERSION)['complete'] ?? false) !== true, 'Interrupted migration is not complete');
            // PG retains all-or-nothing state; Maria resumes committed phases.
            if ($postgres) {
                verify(!$manager->introspectTable($table)->hasColumn('computers_id'), 'PostgreSQL interruption rolls back DDL and rows');
            }
        }
        $migration->apply($connection);
        verify($migration->plan($connection) === [] && Ledger::state($connection, $migration::VERSION)['complete'], 'Retry converges and becomes idempotent');
        $after = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        verify(count($after) === count($before), 'Every source assignment, including duplicate licences, survives');
        foreach ($before as $offset => $row) {
            foreach ($row as $column => $value) {
                verify((string)$after[$offset][$column] === (string)$value, 'Source field preserved ' . $table . '.' . $column);
            }
            verify((int)$after[$offset][$subjects[$row['itemtype']] . '_id'] === $sameId, 'Selected frozen owning branch copied');
        }
        verify($manager->introspectTable($table)->getColumn('items_id')->getComment() === 'Frozen subject provenance: itemtype = Computer; CASE itemtype ', 'Retry preserves compatibility column comments');
        verify(count($manager->listTableForeignKeys($table)) === 6, 'Every concrete subject has an actual FK');
        $expression = $connection->fetchOne($postgres
            ? "SELECT generation_expression FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'items_id'"
            : "SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'items_id'", [$table]);
        verify(is_string($expression) && ($postgres || preg_match('/cast\\s*\\([^)]*itemtype[^)]*\\bas\\s+(?:binary|char\\s+charset\\s+binary)\\b/i', $expression) === 1), 'Native replay/retry retains the frozen exact-kind projection');
        foreach (['computer', 'Computer '] as $invalidKind) {
            $connection->beginTransaction();
            try {
                $rejected = false;
                try {
                    $connection->insert($table, ['itemtype' => $invalidKind, 'computers_id' => $sameId, $parent => 1]);
                } catch (\Doctrine\DBAL\Exception\DriverException) {
                    $rejected = true;
                }
                verify($rejected, 'Frozen native CHECK rejects case/space folding after comment-loss retry');
            } finally {
                $connection->rollBack();
            }
        }

    }
} finally {
    $reset();
}
echo $database->getProvider() . ": frozen software adoption, read-only plugin/scope diagnostics, row multiplicity and four-phase retry passed.\n";
