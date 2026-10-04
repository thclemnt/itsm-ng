<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use itsmng\Database\Migration\LegacyToOrm;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/processor-incoming-core-refusal.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/fixtures/ProcessorIncomingReferences.php';
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

verify(str_starts_with($DB->dbdefault, 'itsm_port_') && !$DB->isSlave(), 'Dedicated configured writer required');
$connection = $DB->getDoctrineConnection();
verify(!$connection->isTransactionActive(), 'Incoming ownership contract owns an idle connection');
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$schema = (string)$connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
$prefix = 'itsm_port_incoming_' . bin2hex(random_bytes(5));
$external = $postgres ? $prefix . '_external' : (getenv('PORT_PROJECTION_REFERENCE_DB') ?: 'itsm_port_projection_references');
verify(str_starts_with($external, 'itsm_port_') && $external !== $schema, 'Separate disposable external ownership namespace required');
$quote = $platform->quoteIdentifier(...);
$qualified = static fn (string $namespace, string $name): string => $quote($namespace) . '.' . $quote($name);
$target = $prefix . '_target';
$consumer = $prefix . '_core';
$outside = $prefix . '_outside';
$coreForeign = $prefix . '_core_fk';
$externalForeign = $prefix . '_outside_fk';
$caseForeign = $prefix . '_case_fk';
$expected = new Schema();
$parent = $expected->createTable($target);
$parent->addColumn('id', 'bigint');
$parent->addColumn('second_key', 'bigint');
$parent->setPrimaryKey(['id']);
$parent->addUniqueIndex(['id', 'second_key'], $prefix . '_pair');
$child = $expected->createTable($consumer);
$child->addColumn('id', 'bigint');
$child->addColumn('owner_id', 'bigint', ['notnull' => false]);
$child->addColumn('payload', 'string', ['length' => 100]);
$child->setPrimaryKey(['id']);
$child->addForeignKeyConstraint($target, ['owner_id'], ['id'], ['onUpdate' => 'RESTRICT', 'onDelete' => 'RESTRICT'], $coreForeign);
$ownedParents = [];
$ownedChildren = [];
$externalOwned = false;
$primary = null;
$cleanup = [];

// Capture full owned rows and actual native declarations, not a metadata-only
// replacement. Every refusal must also preserve all raw canonical receipts.
$facts = static function () use ($connection, $postgres, &$ownedParents, &$ownedChildren, $qualified, $quote): array {
    $facts = ['ledger' => $connection->fetchAllAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version')];
    foreach ([...$ownedParents, ...$ownedChildren] as [$namespace, $name]) {
        $table = $qualified($namespace, $name);
        $native = $postgres
            ? $connection->fetchAllAssociative("SELECT f.conname, pg_get_constraintdef(f.oid) AS definition,
                f.convalidated, f.condeferrable, f.condeferred, f.confmatchtype, f.confupdtype, f.confdeltype
                FROM pg_catalog.pg_constraint f WHERE f.conrelid=to_regclass(?) ORDER BY f.conname", [$table])
            : $connection->fetchAllAssociative('SHOW CREATE TABLE ' . $table);
        $facts[$table] = ['rows' => $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY ' . $quote('id')), 'native' => $native];
    }
    return $facts;
};
$refuse = static function (callable $operation, string $diagnostic) use ($facts): void {
    $before = $facts();
    $refused = false;
    try {
        $operation();
    } catch (LogicException|RuntimeException $error) {
        if ($error->getMessage() !== $diagnostic) {
            throw $error;
        }
        $refused = true;
    }
    verify($refused && $facts() === $before, 'Native ownership refusal preserves every owned row/declaration and raw ledger: ' . $diagnostic);
};

try {
    foreach ([$target, $consumer] as $name) {
        verify(!$connection->createSchemaManager()->tablesExist([$name]), 'Every local fixture table must be absent');
    }
    if ($postgres) {
        verify(!$connection->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname=?', [$external]), 'Own external schema must be absent');
        $connection->executeStatement('CREATE SCHEMA ' . $quote($external));
        $externalOwned = true;
    } else {
        verify((bool)$connection->fetchOne('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$external]), 'Provision the configured external disposable database');
    }
    verify(!$connection->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$external, $outside]), 'Never adopt an external fixture table');
    foreach ($platform->getCreateTableSQL($parent) as $position => $sql) {
        $connection->executeStatement($sql);
        if ($position === 0) {
            $ownedParents[] = [$schema, $target];
        }
    }
    foreach ($platform->getCreateTableSQL($child) as $position => $sql) {
        $connection->executeStatement($sql);
        if ($position === 0) {
            $ownedChildren[] = [$schema, $consumer];
        }
    }
    $connection->insert($qualified($schema, $consumer), ['id' => 1, 'owner_id' => null, 'payload' => "Core O'Reilly 日本語"]);
    $owner = new ProcessorIncomingReferences($connection, $expected, $target);
    $before = $facts();
    $owner->detach();
    $owner->restore();
    verify($owner->restored() && $facts() === $before, 'Canonical incoming owner and all rows restore exactly through actual DDL');

    $externalTable = $qualified($external, $outside);
    $connection->executeStatement('CREATE TABLE ' . $externalTable . ' (id BIGINT NOT NULL PRIMARY KEY, parent_id BIGINT NULL,
        second_key BIGINT NULL, payload VARCHAR(100) NOT NULL, CONSTRAINT ' . $quote($externalForeign)
        . ' FOREIGN KEY (parent_id) REFERENCES ' . $qualified($schema, $target) . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT)');
    $ownedChildren[] = [$external, $outside];
    $connection->insert($externalTable, ['id' => 2, 'parent_id' => null, 'second_key' => null, 'payload' => "External O'Reilly 日本語"]);
    $refuse(static fn () => new ProcessorIncomingReferences($connection, $expected, $target), 'Refuse missing, unknown or custom incoming processor constraints before fixture DDL.');
    $refuse($owner->detach(...), 'Refuse changed or unknown incoming processor constraint during restoration.');

    $dropForeign = 'ALTER TABLE ' . $externalTable . ' DROP ' . ($postgres ? 'CONSTRAINT ' : 'FOREIGN KEY ') . $quote($externalForeign);
    $connection->executeStatement($dropForeign);
    $connection->executeStatement('ALTER TABLE ' . $externalTable . ' ADD CONSTRAINT ' . $quote($externalForeign)
        . ' FOREIGN KEY (parent_id, second_key) REFERENCES ' . $qualified($schema, $target) . ' (id, second_key) ON UPDATE RESTRICT ON DELETE RESTRICT');
    $refuse($owner->detach(...), $postgres
        ? 'Refuse changed or unknown incoming processor constraint during restoration.'
        : 'Single-column RESTRICT incoming processor ownership required.');
    $connection->executeStatement($dropForeign);
    verify($owner->restored(), 'Removing the actual external composite restores captured core ownership');

    if (!$postgres && (int)$connection->fetchOne('SELECT @@lower_case_table_names') === 0) {
        // Only a provider that admits distinct physical spellings can exercise
        // case-insensitive catalogue predicate overshoot without global changes.
        $caseTarget = strtoupper($target);
        verify(!$connection->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?', [$schema, $caseTarget]), 'The exact case-distinct target must be absent');
        $connection->executeStatement('CREATE TABLE ' . $qualified($schema, $caseTarget) . ' (id BIGINT NOT NULL PRIMARY KEY)');
        $ownedParents[] = [$schema, $caseTarget];
        $connection->executeStatement('ALTER TABLE ' . $externalTable . ' ADD CONSTRAINT ' . $quote($caseForeign)
            . ' FOREIGN KEY (parent_id) REFERENCES ' . $qualified($schema, $caseTarget) . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT');
        $selected = $connection->fetchAllAssociative('SELECT CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, UNIQUE_CONSTRAINT_SCHEMA, REFERENCED_TABLE_NAME
            FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA=? AND REFERENCED_TABLE_NAME=?', [$schema, $target]);
        $overshot = false;
        foreach ($selected as $row) {
            if ($row['CONSTRAINT_SCHEMA'] === $external && $row['TABLE_NAME'] === $outside && $row['CONSTRAINT_NAME'] === $caseForeign) {
                verify($row['UNIQUE_CONSTRAINT_SCHEMA'] === $schema && $row['REFERENCED_TABLE_NAME'] === $caseTarget, 'Predicate overshoot is proved by the actual different native target');
                $overshot = true;
            }
        }
        if ($overshot) {
            $refuse($owner->detach(...), 'Incoming processor native target spelling differs from fixture ownership.');
        } else {
            $before = $facts();
            verify($owner->restored() && $facts() === $before, 'An exact native predicate excludes the unrelated case-distinct target without mutation');
        }
        $connection->executeStatement('ALTER TABLE ' . $externalTable . ' DROP FOREIGN KEY ' . $quote($caseForeign));
        verify($owner->restored(), 'Removing the case-distinct consumer leaves exact original incoming ownership');
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    // Children precede parents even when a case-distinct parent was created last.
    foreach ([...array_reverse($ownedChildren), ...array_reverse($ownedParents)] as [$namespace, $name]) {
        try {
            $connection->executeStatement('DROP TABLE ' . $qualified($namespace, $name));
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    if ($externalOwned) {
        try {
            $connection->executeStatement('DROP SCHEMA ' . $quote($external));
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Additional incoming fixture cleanup failure: ' . (string)$error . "\n");
        } catch (Throwable) {
            // Reporting cannot replace the actual primary failure.
        }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw new RuntimeException('Incoming fixture cleanup failed.', previous: $cleanup[0]);
}
echo $DB->getProvider() . ": native incoming core restoration, external/composite refusal and provider-owned spelling checks passed.\n";
