<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\ExactDiscriminators20261010;
use itsmng\Database\Migration\BooleanDomains20261008;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/exact-subject-discriminators-upgrade.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}

verify($DB instanceof DBAdapter && !$DB->isSlave() && str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated configured writer');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0 && History::pendingVersions($connection) === [], 'Idle complete canonical history');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before historical fixture');
$platform = $connection->getDatabasePlatform();
$mysql = $platform instanceof AbstractMySQLPlatform;
$quote = $platform->quoteIdentifier(...);
$scope = ExactDiscriminators20261010::definitions()['tables'];
$rawLedger = static fn (): array => $connection->fetchAllAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' ORDER BY version');
$ledgerBefore = $rawLedger();
$sessionBefore = $_SESSION;
$configurationBefore = $CFG_GLPI;
$physicalBefore = $connection;
$created = [];
$fixtures = new FixtureRecords($DB, static function (string $table, int $id) use (&$created): void {
    $created[] = [$table, $id];
});
$historical = new ExactSubjectHistoricalFixture($connection);
$migration = new ExactDiscriminators20261010();
$preservationMethod = new ReflectionMethod(ExactDiscriminators20261010::class, 'preservation');
$facts = static function () use ($connection, $scope, $preservationMethod, $quote, $mysql): array {
    $result = [];
    $catalog = $mysql ? BooleanDomainSchema::catalog($connection) : null;
    foreach ($scope as $table => $definition) {
        $result[$table] = $preservationMethod->invoke(null, $connection, $connection->createSchemaManager()->introspectTable($table), $catalog['checks'] ?? null);
        if ($mysql) {
            $result[$table]['subject_check'] = $catalog['checks'][$table][$definition['constraint']] ?? null;
            $result[$table]['projection'] = $connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'items_id']);
        } else {
            $result[$table]['native_constraints'] = $connection->fetchAllAssociative('SELECT conname, contype, convalidated, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname, contype', [$quote($table)]);
            $result[$table]['native_columns'] = $connection->fetchAllAssociative('SELECT a.attname, a.attnum, a.attnotnull, a.attidentity, a.attgenerated, format_type(a.atttypid,a.atttypmod) AS type, pg_get_expr(d.adbin,d.adrelid) AS expression, col_description(a.attrelid,a.attnum) AS comment FROM pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum', [$quote($table)]);
        }
    }
    return $result;
};
$rowHashes = static function () use ($connection, $scope, $quote): array {
    $result = [];
    foreach (array_keys($scope) as $table) {
        $hash = hash_init('sha256');
        $count = 0;
        foreach ($connection->iterateAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id') as $row) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            ++$count;
        }
        $result[$table] = ['count' => $count, 'sha256' => hash_final($hash)];
    }
    return $result;
};
$rowsBefore = $rowHashes();
$nativeBefore = $facts();
$catalogBefore = $mysql ? BooleanDomainSchema::catalog($connection) : null;
if ($mysql) {
    foreach (array_keys($scope) as $table) {
        verify(BooleanDomainSchema::checks($connection, $table) === array_intersect_key($catalogBefore['checks'], [$table => true]), 'Fresh selected native CHECK scope equals the authoritative full catalogue subset');
    }
}
unset($catalogBefore);
$prefix = 'itsm_port_exact_' . bin2hex(random_bytes(5));
$child = $prefix . '_incoming';
$index = $prefix . '_identity';
$childCreated = $indexCreated = false;
$primaryError = null;
$cleanupErrors = [];
try {
    // CHECK(TRUE) is deliberately permissive only in this owned corruption
    // fixture; this does not claim to reproduce every supported thirteen-version schema.
    $historical->detach();
    $owned = [];
    foreach ($scope as $table => $definition) {
        $kind = array_key_first($definition['branches']);
        $branch = $definition['branches'][$kind];
        $wide = max(4294969000, 10000 + (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $quote($branch['target'])));
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($branch['target']) . ' WHERE id=?', [$wide]) === 0, 'Unused wide subject identity');
        $target = $fixtures->create($branch['target'], ['id' => $wide]);
        $values = ['itemtype' => $kind, $branch['column'] => $target];
        if ($table === 'glpi_objectlocks') {
            // A real historical lock makes automatic timestamp changes deterministic.
            $values['date_mod'] = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));
        }
        $id = $fixtures->create($table, $values);
        $owned[$table] = ['id' => $id, 'kind' => $kind, 'target' => $target];
        if ($table === 'glpi_objectlocks') {
            $owned[$table]['date_mod'] = $connection->fetchOne('SELECT date_mod FROM ' . $quote($table) . ' WHERE id=?', [$id]);
            verify(is_string($owned[$table]['date_mod']) && $owned[$table]['date_mod'] !== '', 'Capture the actual owned historical lock timestamp');
        }
        verify((int)$connection->fetchOne('SELECT items_id FROM ' . $quote($table) . ' WHERE id=?', [$id]) === $target, 'Populated legacy compatibility projection preserves wide IDs');
    }
    $populatedBefore = $rowHashes();
    $fixtureNative = $facts();
    $fixtureLedger = $rawLedger();
    foreach ($owned as $table => $row) {
        $connection->update($quote($table), ['itemtype' => strtolower($row['kind'])], ['id' => $row['id']]);
    }
    verify($connection->fetchOne('SELECT date_mod FROM ' . $quote('glpi_objectlocks') . ' WHERE id=?', [$owned['glpi_objectlocks']['id']])
        !== $owned['glpi_objectlocks']['date_mod'], 'Owned discriminator corruption exercises the native lock timestamp touch');
    $invalidRows = $rowHashes();
    foreach (['plan', 'apply'] as $method) {
        $diagnostic = null;
        try {
            $migration->$method($connection);
        } catch (RuntimeException $error) {
            $diagnostic = $error->getMessage();
        }
        verify(is_string($diagnostic) && str_contains($diagnostic, 'before DDL or receipt'), 'Invalid data refuses before all migration writes');
        foreach (array_keys($scope) as $table) {
            verify(str_contains($diagnostic, 'Invalid exact subject data: ' . $table . ' ('), 'All frozen tables are audited before the first DDL');
        }
        verify($facts() === $fixtureNative && $rawLedger() === $fixtureLedger && $rowHashes() === $invalidRows, 'Refusal retains exact spelling, data, native schema and raw ledger');
    }
    $priorReceipt = $connection->fetchAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' WHERE version=?', [BooleanDomains20261008::VERSION]);
    verify(is_array($priorReceipt), 'Capture the exact existing prerequisite receipt');
    $priorDetached = false;
    $prerequisiteError = $prerequisiteRestoreError = null;
    try {
        $connection->delete($quote(LegacyToOrm::LEDGER), ['version' => BooleanDomains20261008::VERSION]);
        $priorDetached = true;
        $diagnostic = null;
        try {
            $migration->plan($connection, true);
        } catch (RuntimeException $error) {
            $diagnostic = $error->getMessage();
        }
        verify(is_string($diagnostic) && str_contains($diagnostic, 'before DDL or receipt'), 'Pending historical adoption cannot hide existing invalid canonical subjects');
        foreach (array_keys($scope) as $table) {
            verify(str_contains($diagnostic, 'Invalid exact subject data: ' . $table . ' ('), 'Pre-adoption still audits every exact spelling and existing owner');
        }
        verify($facts() === $fixtureNative && $rowHashes() === $invalidRows, 'Pre-adoption refusal remains read-only');
    } catch (Throwable $error) {
        $prerequisiteError = $error;
    } finally {
        if ($priorDetached) {
            try {
                $connection->insert($quote(LegacyToOrm::LEDGER), $priorReceipt);
            } catch (Throwable $error) {
                $prerequisiteRestoreError = $error;
                $cleanupErrors[] = $error;
            }
        }
    }
    if ($prerequisiteError !== null) {
        throw $prerequisiteError;
    }
    if ($prerequisiteRestoreError !== null) {
        throw new RuntimeException('Prerequisite fixture receipt restoration failed.', previous: $prerequisiteRestoreError);
    }
    verify($rawLedger() === $fixtureLedger, 'Restore the exact raw prerequisite receipt after the deferral control');
    foreach ($owned as $table => $row) {
        // Explicit fixture correction, never an application migration repair.
        $values = ['itemtype' => $row['kind']];
        if ($table === 'glpi_objectlocks') {
            // Restore the fixture's own native value, including its provider serialization.
            $values['date_mod'] = $row['date_mod'];
        }
        $connection->update($quote($table), $values, ['id' => $row['id']]);
    }
    verify($rowHashes() === $populatedBefore, 'Explicit fixture repair restored its own rows exactly');

    $link = $owned['glpi_items_tickets'];
    $comment = "Exact O'Reilly \\ compatibility identité";
    if ($mysql) {
        $projection = $connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['glpi_items_tickets', 'items_id']);
        $connection->executeStatement('ALTER TABLE ' . $quote('glpi_items_tickets') . ' MODIFY COLUMN items_id BIGINT GENERATED ALWAYS AS (' . $projection . ') STORED ' . $platform->getInlineColumnCommentSQL($comment));
    } else {
        $connection->executeStatement('COMMENT ON COLUMN ' . $quote('glpi_items_tickets') . '.items_id IS ' . $platform->quoteStringLiteral($comment));
    }
    // The additional unique composite cannot reject legitimate repeated
    // items_id values: id already has the canonical primary-key uniqueness.
    $connection->executeStatement('CREATE UNIQUE INDEX ' . $quote($index) . ' ON ' . $quote('glpi_items_tickets') . ' (' . $quote('items_id') . ', ' . $quote('id') . ')');
    $indexCreated = true;
    $connection->executeStatement('CREATE TABLE ' . $quote($child) . ' (id BIGINT NOT NULL PRIMARY KEY, subject_id BIGINT NOT NULL, link_id BIGINT NOT NULL, CONSTRAINT '
        . $quote($prefix . '_fk') . ' FOREIGN KEY (subject_id, link_id) REFERENCES ' . $quote('glpi_items_tickets') . ' (items_id, id))' . ($mysql ? ' ENGINE=InnoDB' : ''));
    $childCreated = true;
    $connection->insert($quote($child), ['id' => 1, 'subject_id' => $link['target'], 'link_id' => $link['id']]);
    $withIncoming = $migration->plan($connection);
    verify($withIncoming['tables']['glpi_items_tickets']['incoming_projection_references'] === true, 'Supported custom incoming generated-identity FK remains attached to its real target');
    $preserved = [];
    $preservedCatalog = $mysql ? BooleanDomainSchema::catalog($connection) : null;
    foreach (array_keys($scope) as $table) {
        $preserved[$table] = $preservationMethod->invoke(null, $connection, $connection->createSchemaManager()->introspectTable($table), $preservedCatalog['checks'] ?? null);
    }
    unset($preservedCatalog);
    if ($mysql) {
        $incomingRows = array_values(array_filter($preserved['glpi_items_tickets']['native']['incoming'], static fn (array $row): bool => $row['CONSTRAINT_NAME'] === $prefix . '_fk'));
        verify(array_map('intval', array_column($incomingRows, 'ORDINAL_POSITION')) === [1, 2]
            && array_column($incomingRows, 'COLUMN_NAME') === ['subject_id', 'link_id']
            && array_column($incomingRows, 'REFERENCED_COLUMN_NAME') === ['items_id', 'id'], 'Incoming preservation includes every ordered composite FK column');
    } else {
        $incomingRows = array_values(array_filter($preserved['glpi_items_tickets']['native']['incoming'], static fn (array $row): bool => $row['constraint_name'] === $prefix . '_fk'));
        verify(count($incomingRows) === 1 && str_contains($incomingRows[0]['definition'], 'subject_id, link_id')
            && str_contains($incomingRows[0]['definition'], 'items_id, id'), 'PostgreSQL incoming preservation captures the whole OID-bound composite definition');
    }
    $beforeFault = $facts();
    $beforeFaultLedger = $rawLedger();
    $faultTable = getenv('PORT_EXACT_FAULT_TABLE') ?: 'glpi_items_tickets';
    verify(isset($scope[$faultTable]), 'Fault injection selects an actual frozen table phase');
    $fault = 'Exact subject: ' . $faultTable;
    $interrupted = false;
    try {
        $migration->apply($connection, static function (string $phase) use ($fault): void {
            if ($phase === $fault) {
                throw new RuntimeException('Owned post-DDL interruption before checkpoint');
            }
        });
    } catch (RuntimeException $error) {
        $interrupted = $error->getMessage() === 'Owned post-DDL interruption before checkpoint';
        if (!$interrupted) {
            throw $error; // A native incoming-FK/ALTER limitation is a failure, not accepted evidence.
        }
    }
    verify($interrupted, 'Actual table DDL reached the deliberate interrupted checkpoint');
    verify($rowHashes() === $populatedBefore && array_map('intval', $connection->fetchAssociative('SELECT * FROM ' . $quote($child))) === ['id' => 1, 'subject_id' => $link['target'], 'link_id' => $link['id']], 'Interrupted schema work retained all populated application rows and incoming ownership');
    // A NULL processed snapshot must not bypass the exact key-set guards on
    // either provider. PostgreSQL rolled its genuine journal back; its owned
    // malformed receipt below is incomplete diagnostic input, never completion.
    $retryReceipt = $connection->fetchAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' WHERE version=?', [ExactDiscriminators20261010::VERSION]);
    $retryLedger = $rawLedger();
    $retryNative = $facts();
    $retryRows = $rowHashes();
    $retryTemplate = Ledger::state($connection, ExactDiscriminators20261010::VERSION);
    if (($retryTemplate['next'] ?? 0) === 0) {
        $first = array_key_first($scope);
        $nativePolicyMethod = new ReflectionMethod(ExactDiscriminators20261010::class, 'nativePolicy');
        $retryTemplate = ['complete' => false, 'next' => 1, 'preservation' => $preserved,
            'policy' => [$first => $nativePolicyMethod->invoke(null, $connection, $first, $scope[$first])]];
    }
    foreach (['policy', 'preservation'] as $cache) {
        $malformedError = $malformedRestoreError = null;
        try {
            $malformed = $retryTemplate;
            $entry = array_key_first($malformed['policy']);
            $malformed[$cache][$entry] = null;
            verify(array_keys($malformed['preservation']) === array_keys($scope)
                && array_keys($malformed['policy']) === array_slice(array_keys($scope), 0, $malformed['next']), 'Malformed NULL fixture retains otherwise valid full and processed-prefix key sets');
            Ledger::save($connection, ExactDiscriminators20261010::VERSION, $malformed);
            $malformedLedger = $rawLedger();
            foreach (['plan', 'apply'] as $method) {
                $refused = false;
                try {
                    $migration->$method($connection);
                } catch (RuntimeException $error) {
                    $refused = $error->getMessage() === 'Invalid exact subject journal; inspect the original receipt and native schema before retrying.';
                }
                verify($refused && $rawLedger() === $malformedLedger, 'Malformed NULL checkpoint cache refuses before DDL or receipt overwrite');
            }
            verify($facts() === $retryNative && $rowHashes() === $retryRows, 'Both malformed-cache public paths preserve exact native schema and populated data');
        } catch (Throwable $error) {
            $malformedError = $error;
        } finally {
            try {
                if ($retryReceipt === false) {
                    $connection->delete($quote(LegacyToOrm::LEDGER), ['version' => ExactDiscriminators20261010::VERSION]);
                } else {
                    $connection->update($quote(LegacyToOrm::LEDGER), ['state' => $retryReceipt['state']], ['version' => $retryReceipt['version']]);
                }
                verify($rawLedger() === $retryLedger, 'Restore exact raw retry receipt or its original absence after owned malformed-cache control');
            } catch (Throwable $error) {
                $malformedRestoreError = $error;
                $cleanupErrors[] = $error;
            }
        }
        if ($malformedError !== null) {
            throw $malformedError;
        }
        if ($malformedRestoreError !== null) {
            throw new RuntimeException('Malformed-checkpoint receipt restoration failed.', previous: $malformedRestoreError);
        }
    }
    if ($mysql) {
        $journal = Ledger::state($connection, ExactDiscriminators20261010::VERSION);
        verify(($journal['complete'] ?? null) === false && $journal['preservation'] === $preserved, 'Nontransactional DDL has an exact preservation journal');
        verify(array_keys($journal['policy']) === array_slice(array_keys($scope), 0, $journal['next']), 'Native policy snapshots exactly cover successful processed checkpoints');
        $journalReceipt = $connection->fetchAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' WHERE version=?', [ExactDiscriminators20261010::VERSION]);
        verify(is_array($journalReceipt), 'Capture the actual post-DDL raw retry receipt');
        $processed = array_key_first($journal['policy']);
        if ($processed !== null) {
            $checkpointPolicy = $journal['policy'][$processed];
            $checkpointNative = $facts();
            $checkpointRows = $rowHashes();
            $checkpointLedger = $rawLedger();
            $replace = new ReflectionMethod(ExactSubjectHistoricalFixture::class, 'replace');
            foreach (['check', 'projection'] as $divergence) {
                $tamperError = $tamperRestoreError = null;
                try {
                    if ($divergence === 'check') {
                        $replace->invoke($historical, $processed, null, 'CHECK (TRUE)', $preserved[$processed]['comment']);
                    } else {
                        $cases = [];
                        foreach ($scope[$processed]['branches'] as $kind => $branch) {
                            $cases[] = 'WHEN ' . $quote('itemtype') . ' = ' . $platform->quoteStringLiteral($kind) . ' THEN ' . $quote($branch['column']);
                        }
                        $ordinaryProjection = 'CASE ' . implode(' ', $cases) . ' ELSE ' . ($scope[$processed]['empty_value'] ?? 'NULL') . ' END';
                        $replace->invoke($historical, $processed, $ordinaryProjection, 'CHECK (' . $checkpointPolicy['check']['clause'] . ')', $preserved[$processed]['comment']);
                    }
                    $alteredNative = $facts();
                    verify($alteredNative !== $checkpointNative && $rowHashes() === $checkpointRows, 'Actual processed native policy changed while canonical rows remain valid');
                    foreach (['plan', 'apply'] as $method) {
                        $diagnostic = null;
                        try {
                            $migration->$method($connection);
                        } catch (RuntimeException $error) {
                            $diagnostic = $error->getMessage();
                        }
                        verify(is_string($diagnostic) && str_contains($diagnostic, 'Exact subject checkpoint native policy changed: ' . $processed), 'Processed policy divergence fails closed before skip or completion');
                        verify($facts() === $alteredNative && $rawLedger() === $checkpointLedger && $rowHashes() === $checkpointRows, 'Divergent retry neither overwrites native policy nor changes data or receipts');
                    }
                } catch (Throwable $error) {
                    $tamperError = $error;
                } finally {
                    try {
                        $replace->invoke($historical, $processed, $checkpointPolicy['projection'], 'CHECK (' . $checkpointPolicy['check']['clause'] . ')', $preserved[$processed]['comment']);
                        verify($facts() === $checkpointNative && $rawLedger() === $checkpointLedger && $rowHashes() === $checkpointRows, 'Owned tamper cleanup restores the exact checkpoint native policy and data');
                    } catch (Throwable $error) {
                        $tamperRestoreError = $error;
                        $cleanupErrors[] = $error;
                    }
                }
                if ($tamperError !== null) {
                    throw $tamperError;
                }
                if ($tamperRestoreError !== null) {
                    throw new RuntimeException('Processed-policy fixture restoration failed.', previous: $tamperRestoreError);
                }
            }
        } else {
            verify($journal['next'] === 0, 'A first-phase interruption has no processed policy to skip');
        }
        $connection->beginTransaction();
        try {
            $refused = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $refused = $error->getMessage() === 'MySQL exact subject DDL must run outside an application transaction.';
            }
            verify($refused && $connection->getTransactionNestingLevel() === 1, 'Application transaction refuses DDL without consuming the caller transaction');
        } finally {
            $connection->rollBack();
        }
    } else {
        verify($facts() === $beforeFault && $rawLedger() === $beforeFaultLedger, 'PostgreSQL interruption rolls back all CHECK changes and receipt');
        $connection->beginTransaction();
        try {
            $migration->apply($connection);
            verify($connection->getTransactionNestingLevel() === 1 && (Ledger::state($connection, ExactDiscriminators20261010::VERSION)['complete'] ?? false) === true, 'Nested PostgreSQL migration leaves caller transaction active');
        } finally {
            $connection->rollBack();
        }
        verify($facts() === $beforeFault && $rawLedger() === $beforeFaultLedger, 'Caller rollback restores the full pre-migration native schema and receipt');
    }
    (new ExactDiscriminators20261010())->apply($connection);
    verify((Ledger::state($connection, ExactDiscriminators20261010::VERSION)['complete'] ?? false) === true, 'Fresh migration instance resumes and records actual completion');
    foreach (array_keys($scope) as $table) {
        verify($preservationMethod->invoke(null, $connection, $connection->createSchemaManager()->introspectTable($table)) === $preserved[$table], 'Final indexes, comments, TIMESTAMP/native storage, incoming/outgoing FKs and auto-increment facts preserved');
    }
    $complete = $facts();
    $completedLedger = $rawLedger();
    $migration->apply($connection);
    verify($facts() === $complete && $rawLedger() === $completedLedger && $rowHashes() === $populatedBefore, 'Exact replay and populated-data retention are idempotent');
    // This owned comment intentionally differs from the core declaration.
    // The unchanged full SchemaCheck runs after exact original-comment restoration.
} catch (Throwable $error) {
    $primaryError = $error;
} finally {
    while ($connection->getTransactionNestingLevel() > 0) {
        try {
            $connection->rollBack();
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
            break;
        }
    }
    if ($childCreated) {
        try {
            $connection->executeStatement('DROP TABLE ' . $quote($child));
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    if ($indexCreated) {
        try {
            $connection->executeStatement('DROP INDEX ' . $quote($index) . ($mysql ? ' ON ' . $quote('glpi_items_tickets') : ''));
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    foreach (array_reverse($created) as [$table, $id]) {
        try {
            $connection->delete($quote($table), ['id' => $id]);
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    try {
        $historical->restore();
    } catch (Throwable $error) {
        $cleanupErrors[] = $error;
    }
    $_SESSION = $sessionBefore;
    $CFG_GLPI = $configurationBefore;
    if ($cleanupErrors) {
        fwrite(STDERR, 'Owned fixture cleanup failures: ' . count($cleanupErrors) . "; inspect the preserved primary exception.\n");
    }
}
if ($primaryError !== null) {
    throw $primaryError;
}
if ($cleanupErrors) {
    throw new RuntimeException('Exact historical fixture cleanup did not restore all owned state.', previous: $cleanupErrors[0]);
}
// MySQL auto-increment and PostgreSQL sequences can legitimately advance while
// owned rows are removed. Never reset counters or claim their rollback.
$nativeAfter = $facts();
if ($mysql) {
    foreach ($nativeBefore as $table => &$definition) {
        unset($definition['native']['table']['AUTO_INCREMENT']);
        unset($nativeAfter[$table]['native']['table']['AUTO_INCREMENT']);
    }
    unset($definition);
}
verify($nativeAfter === $nativeBefore && $rowHashes() === $rowsBefore && $rawLedger() === $ledgerBefore, 'Every original scoped row, native definition and raw receipt restored');
verify($connection === $physicalBefore && $DB->getDoctrineConnection() === $connection && $connection->getTransactionNestingLevel() === 0, 'Supplied writer and idle physical ownership retained');
verify((new SchemaCheck())->differences($connection) === [], 'Schema remains canonical after owned historical fixture cleanup');
echo 'Exact subject populated upgrade/retry: ' . $assertions . " contracts passed\n";
