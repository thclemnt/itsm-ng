<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\BaselineSchema;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\DocumentSubjects;
use itsmng\Database\Migration\DomainDocuments20261006;
use itsmng\Database\Migration\ExactDiscriminators20261010;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\OperatingSystemSubjects20261006;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/pending-subject-preflight.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
require __DIR__ . '/fixtures/NativeBooleanFixture.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_') && !$DB->isSlave(), 'Disposable canonical primary required');
$connection = $DB->getDoctrineConnection();
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$table = 'glpi_documents_items';
$history = new History();
$migration = new DomainDocuments20261006();
verify($connection->getTransactionNestingLevel() === 0 && History::pendingVersions($connection) === []
    && (new SchemaCheck())->differences($connection) === [], 'Complete canonical starting history outside caller frames');
verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Refuse to reconstruct unrelated document links');
verify(Ledger::state($connection, $migration::GENERAL_RECEIPT) === null, 'Refuse to overwrite an existing deferred source receipt');
$required = (new BaselineSchema())->build($platform)->getTable($table);
$booleans = new NativeBooleanFixture($connection, $table);
$nativeExact = new ExactSubjectHistoricalFixture($connection, [$table]);
$saved = Ledger::states($connection);
$rawLedger = static fn (): array => $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
$originalLedger = $rawLedger();
$facts = static function () use ($connection, $manager, $platform, $table, $rawLedger): array {
    return [$connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'),
        $platform->getCreateTableSQL($manager->introspectTable($table)),
        $connection->fetchAllAssociative('SELECT column_name, data_type, is_nullable, column_default, '
            . ($platform instanceof PostgreSQLPlatform ? 'is_generated' : 'extra')
            . ', generation_expression FROM information_schema.columns WHERE table_schema = '
            . ($platform instanceof PostgreSQLPlatform ? 'current_schema()' : 'DATABASE()') . ' AND table_name = ? ORDER BY ordinal_position', [$table]),
        BooleanDomainSchema::catalog($connection)['checks'][$table] ?? [], $rawLedger()];
};
$createdRows = [];
$fixtures = new FixtureRecords($DB, static function (string $parent, int $id) use (&$createdRows): void {
    $createdRows[] = [$parent, $id];
});
$created = [];
$historicalStarted = false;
$primary = null;
$cleanup = [];
$rebuild = static function (string $mode = 'generated') use ($connection, $manager, $table, $required, $booleans, $migration): void {
    foreach ([$migration::VERSION, ExactDiscriminators20261010::VERSION, $migration::GENERAL_RECEIPT] as $version) {
        $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    }
    $manager->dropTable($table);
    $old = clone $required;
    $old->removeForeignKey('fk_documents_items_domains_id');
    $old->dropIndex($table . '_domains_id');
    $old->dropColumn('domains_id');
    DocumentSubjects::configureTable($old);
    if ($mode === 'ordinary') {
        $old->getColumn('items_id')->setColumnDefinition(null);
    } elseif ($mode === 'old-column drift') {
        $old->removeForeignKey('fk_documents_items_budgets_id');
        $old->dropIndex($table . '_budgets_id');
        $old->dropColumn('budgets_id');
        $old->getColumn('items_id')->setColumnDefinition("BIGINT GENERATED ALWAYS AS (CASE WHEN itemtype = 'Computer' THEN computers_id ELSE NULL END) STORED");
    }
    $manager->createTable($old);
    $booleans->restore();
    if ($mode !== 'old-column drift') {
        $connection->executeStatement(DocumentSubjects::checkSql($table));
    }
};
$refuse = static function (string $fragment) use ($connection, $history, $facts): void {
    $before = $facts();
    $rejected = false;
    try {
        $history->upgrade($connection);
    } catch (RuntimeException $error) {
        $rejected = $error::class === RuntimeException::class
            && str_starts_with($error->getMessage(), "Exact subject preflight failed before DDL or receipt:\n")
            && str_contains($error->getMessage(), $fragment);
    }
    verify($rejected && $facts() === $before, 'Exact refusal preserves rows, native schema/checks and entire ledger: ' . $fragment);
};

try {
    $nativeExact->beginOwnedAlteration();
    $historicalStarted = true;
    foreach (['glpi_domains', 'glpi_documents', 'glpi_computers'] as $parent) {
        $created[$parent] = $fixtures->create($parent);
    }
    $document = $created['glpi_documents'];
    $computer = $created['glpi_computers'];
    $domain = $created['glpi_domains'];
    $linkId = 4294991201;
    $legacyRow = ['id' => $linkId, 'documents_id' => $document, 'itemtype' => 'Computer', 'computers_id' => $computer, 'timeline_position' => 1];
    $deferredId = $linkId + 1;
    $original = ['id' => (string)$deferredId, 'documents_id' => (string)$document,
        'items_id' => (string)$domain, 'itemtype' => 'Domain', 'entities_id' => '0',
        'is_recursive' => '0', 'users_id' => null, 'timeline_position' => '2',
        'date_mod' => '2026-01-02 03:04:05', 'date_creation' => null, 'date' => null];
    $receipt = ['complete' => true, 'format' => $migration::GENERAL_FORMAT, 'timestamp_timezone' => '+00:00',
        'deferred_documents' => [['id' => $deferredId, 'domain_id' => $domain, 'original' => $original]]];

    $rebuild();
    $connection->insert($table, $legacyRow);
    $before = $facts();
    $plan = $history->plan($connection);
    verify(
        in_array($table, $plan['exact_subject_discriminators']['deferred'], true)
        && $plan['domain_documents'][$table]['columns'] !== [] && $facts() === $before,
        'Read-only canonical plan admits the genuine generated predecessor and plans its owning expansion'
    );

    $rebuild('old-column drift');
    $connection->insert($table, $legacyRow);
    $refuse('Incomplete canonical owning subject columns/projection: ' . $table);

    $rebuild();
    $connection->insert($table, $legacyRow);
    Ledger::save($connection, $migration::VERSION, $saved[$migration::VERSION]);
    $connection->delete(LegacyToOrm::LEDGER, ['version' => OperatingSystemSubjects20261006::VERSION]);
    try {
        $refuse('Incomplete canonical owning subject columns/projection: ' . $table);
    } finally {
        Ledger::save($connection, OperatingSystemSubjects20261006::VERSION, $saved[OperatingSystemSubjects20261006::VERSION]);
    }

    $rebuild();
    $connection->insert($table, $legacyRow);
    Ledger::save($connection, $migration::VERSION, ['complete' => false, 'phase' => 'projection', 'items_comment' => '', 'projection_expanded' => true]);
    $refuse('Incomplete canonical owning subject columns/projection: ' . $table);

    foreach (['columns', 'copy', 'projection', 'constraints'] as $impossiblePhase) {
        $rebuild();
        $connection->insert($table, $legacyRow);
        Ledger::save($connection, $migration::VERSION, ['complete' => false, 'phase' => $impossiblePhase,
            'items_comment' => '', 'projection_expanded' => false]);
        $refuse('Incomplete canonical owning subject columns/projection: ' . $table);
    }
    $rebuild();
    $connection->insert($table, $legacyRow);
    Ledger::save($connection, $migration::VERSION, ['complete' => false, 'phase' => 'audited',
        'items_comment' => '', 'projection_expanded' => null]);
    $refuse('Incomplete canonical owning subject columns/projection: ' . $table);

    foreach (['missing selection', 'two owners', 'future unowned kind'] as $invalid) {
        $rebuild();
        $connection->insert($table, $legacyRow);
        $connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table) . ' DROP '
            . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ')
            . $platform->quoteIdentifier($table . '_typed_item_kind'));
        $bad = ['id' => $linkId + 2, 'documents_id' => $document, 'timeline_position' => 3];
        $bad += match ($invalid) {
            'missing selection' => ['itemtype' => 'Computer'],
            'two owners' => ['itemtype' => 'Computer', 'computers_id' => $computer, 'subject_entities_id' => 0],
            'future unowned kind' => ['itemtype' => 'Domain'],
        };
        $connection->insert($table, $bad);
        $refuse('Invalid exact subject data: ' . $table . ' (1 rows)');
    }

    // A manual DROP is not evidence for adopting a different compatibility index
    // vector: Maria's supported expansion uses one MODIFY, PG is transactional.
    $rebuild();
    $connection->insert($table, $legacyRow);
    $without = $manager->introspectTable($table);
    foreach ($without->getIndexes() as $index) {
        if (in_array('items_id', $index->getColumns(), true)) {
            $without->dropIndex($index->getName());
        }
    }
    $without->dropColumn('items_id');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($manager->introspectTable($table), $without)) as $sql) {
        $connection->executeStatement($sql);
    }
    $refuse('Missing subject identity columns: ' . $table);

    foreach (['columns', 'copy', 'projection'] as $phase) {
        $ordinary = $phase === 'copy';
        $rebuild($ordinary ? 'ordinary' : 'generated');
        $row = $legacyRow;
        if ($ordinary) {
            $row['items_id'] = $computer;
        }
        $connection->insert($table, $row);
        Ledger::save($connection, $migration::GENERAL_RECEIPT, $receipt);
        $before = $facts();
        $seen = false;
        $actualOwnerState = null;
        try {
            $history->upgrade($connection, static function (string $step) use ($phase, $connection, &$seen, &$actualOwnerState): void {
                if ($step === 'DomainDocuments20261006: ' . $phase) {
                    $seen = true;
                    $actualOwnerState = Ledger::state($connection, DomainDocuments20261006::VERSION);
                    throw new RuntimeException('Actual owning document interruption: ' . $phase);
                }
            });
            throw new LogicException('Required actual producer phase did not interrupt');
        } catch (RuntimeException $error) {
            verify($seen && $error->getMessage() === 'Actual owning document interruption: ' . $phase, 'Actual History executes the selected producer statement before interruption');
        }
        $previousPhase = ['columns' => 'audited', 'copy' => 'columns', 'projection' => 'copy'][$phase];
        verify(
            ($actualOwnerState['complete'] ?? null) === false && ($actualOwnerState['phase'] ?? null) === $previousPhase,
            'Actual producer receipt identifies the last completed phase before the selected native statement'
        );
        if ($postgres) {
            verify($facts() === $before, 'PostgreSQL canonical transaction rolls back the actual interrupted phase');
        } else {
            $state = Ledger::state($connection, $migration::VERSION);
            verify(
                ($state['complete'] ?? null) === false && ($state['phase'] ?? null) === $previousPhase
                && !(Ledger::state($connection, $migration::GENERAL_RECEIPT)['documents_restored'] ?? false),
                'Nontransactional retry retains actual owner journal and defers data restoration'
            );
        }
        if ($phase === 'columns') {
            $history->install($DB, 'en_GB');
        } else {
            $history->upgrade($connection);
        }
        verify(
            History::pendingVersions($connection) === [] && (new SchemaCheck())->differences($connection) === [],
            'Actual installation and upgrade retries converge through complete canonical history'
        );
        $rows = $connection->fetchAllAssociative('SELECT id, documents_id, itemtype, items_id, computers_id, domains_id, timeline_position FROM ' . $table . ' ORDER BY id');
        verify(
            count($rows) === 2 && (int)$rows[0]['id'] === $linkId && (int)$rows[0]['items_id'] === $computer
            && (int)$rows[0]['computers_id'] === $computer && $rows[0]['domains_id'] === null
            && (int)$rows[1]['id'] === $deferredId && (int)$rows[1]['documents_id'] === $document
            && (int)$rows[1]['items_id'] === $domain && (int)$rows[1]['domains_id'] === $domain,
            'Canonical retry preserves populated old/new independent binding identities and containing document'
        );
        $after = $facts();
        $history->upgrade($connection);
        verify($facts() === $after, 'Completed retry preserves every later stored row and receipt');
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        if ($historicalStarted) {
        $manager->dropTable($table);
        $manager->createTable($required);
        $booleans->restore();
        $connection->executeStatement($migration::checkSql($table));
        foreach ([DomainDocuments20261006::VERSION, OperatingSystemSubjects20261006::VERSION] as $version) {
            Ledger::save($connection, $version, $saved[$version]);
        }
        $connection->delete(LegacyToOrm::LEDGER, ['version' => $migration::GENERAL_RECEIPT]);
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        $nativeExact->restore();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    foreach (array_reverse($createdRows) as [$parent, $id]) {
        try {
            $connection->delete($parent, ['id' => $id]);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    try {
        $DB->clearSchemaCache();
        verify(
            (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0
            && $rawLedger() === $originalLedger && (new SchemaCheck())->differences($connection) === [],
            'Fixture restores original rows, entire canonical ledger and required schema'
        );
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Additional fixture cleanup failure: ' . $error::class . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
echo $DB->getProvider() . ": $assertions assertions; producer-owned predecessor audits and actual canonical phase retry controls passed.\n";
