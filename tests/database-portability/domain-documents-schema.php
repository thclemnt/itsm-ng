<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity;
use itsmng\Database\Migration\DocumentSubjects;
use itsmng\Database\Migration\DomainDocuments20261006;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-documents-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
require __DIR__ . '/fixtures/NativeBooleanFixture.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable core database required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$migration = new DomainDocuments20261006();
$table = 'glpi_documents_items';
verify(Ledger::state($connection, $migration::VERSION)['complete'] ?? false, 'Canonical history includes the appended document stage');
verify((new SchemaCheck())->differences($connection) === [], 'Starting core schema converges');
$reject = static function (callable $operation, string $message, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $rejected = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $rejected = in_array($error->getSQLState(), ['23502', '23503', '23514', '23505', '23001', '23000'], true)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($rejected, $message);
    } finally {
        $connection->rollBack();
    }
};
$fixtures = new FixtureRecords($DB);
$connection->beginTransaction();
try {
    $domain = $fixtures->create('glpi_domains', ['id' => 4294976001]);
    $next = $fixtures->create('glpi_domains', ['id' => 4294976002]);
    $document = $fixtures->create('glpi_documents', ['id' => 4294976003]);
    $em = Orm::create($DB);
    $link = new Entity\DocumentItem();
    $link->id = null;
    $link->documents = $em->getReference(Entity\Document::class, $document);
    $link->entities = $em->getReference(Entity\Entity::class, 0);
    $link->itemtype = 'Domain';
    $link->domain = $em->getReference(Entity\Domain::class, $domain);
    $em->persist($link);
    $em->flush();
    verify($link->items_id === $domain, 'Native owning Domain generates the wide compatibility identity');
    $base = ['documents_id' => $document, 'itemtype' => 'Domain', 'domains_id' => $domain];
    $reject(static fn () => $connection->insert($table, $base), 'Duplicate document/domain/timeline binding is rejected');
    $reject(static fn () => $connection->insert($table, array_replace($base, ['domains_id' => 999999999])), 'Missing Domain target is rejected');
    $reject(static fn () => $connection->insert($table, array_replace($base, ['domains_id' => 0])), 'Zero Domain subject is rejected', 'glpi_documents_items_typed_item_kind');
    $reject(static fn () => $connection->insert($table, array_replace($base, ['domains_id' => null])), 'Missing owning selection is rejected', 'glpi_documents_items_typed_item_kind');
    $reject(static fn () => $connection->insert($table, array_replace($base, ['itemtype' => 'Computer'])), 'Discriminator mismatch is rejected', 'glpi_documents_items_typed_item_kind');
    $reject(static fn () => $connection->insert($table, $base + ['subject_entities_id' => 0]), 'Multiple owning subjects are rejected', 'glpi_documents_items_typed_item_kind');
    $reject(static fn () => $connection->insert($table, array_replace($base, ['documents_id' => 999999999])), 'Missing containing document is rejected');
    $reject(static fn () => $connection->delete('glpi_domains', ['id' => $domain]), 'Domain deletion is restrictive');
    $reject(static fn () => $connection->delete('glpi_documents', ['id' => $document]), 'Document deletion is restrictive');
    $connection->insert($table, $base + ['timeline_position' => 1]);
    verify((int)$connection->fetchOne("SELECT COUNT(*) FROM $table WHERE documents_id = ? AND itemtype = 'Domain' AND items_id = ?", [$document, $domain]) === 2, 'Distinct timeline links retain independent binding rows');
    $link->domain = $em->getReference(Entity\Domain::class, $next);
    $em->flush();
    verify($link->items_id === $next && $link->documents->id === $document, 'Native retarget preserves the containing document');
    $linkId = $link->id;
    $em->remove($link);
    $em->flush();
    verify(!$connection->fetchOne("SELECT 1 FROM $table WHERE id = ?", [$linkId]), 'Native link removal succeeds');
} finally {
    $connection->rollBack();
}

verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Never reconstruct a populated unrelated document table');
verify(Ledger::state($connection, $migration::GENERAL_RECEIPT) === null, 'Never overwrite an existing deferred receipt');
$required = (new \itsmng\Database\BaselineSchema())->build($platform)->getTable($table);
$savedStage = Ledger::state($connection, $migration::VERSION);
$nativeBooleans = new NativeBooleanFixture($connection, $table);
$nativeExact = new ExactSubjectHistoricalFixture($connection, [$table]);
$domain = $fixtures->create('glpi_domains', ['id' => 4294976101]);
$document = $fixtures->create('glpi_documents', ['id' => 4294976102]);
$computer = $fixtures->create('glpi_computers', ['id' => 4294976103]);
$original = [
    'id' => '4294976201', 'documents_id' => (string)$document,
    'items_id' => (string)$domain, 'itemtype' => 'PluginDomainsDomain',
    'entities_id' => '0', 'is_recursive' => '1', 'users_id' => '0',
    'timeline_position' => '2', 'date_mod' => '2026-01-02 03:04:05',
    'date_creation' => null, 'date' => '2026-01-01 00:00:00',
];
$receipt = ['complete' => true, 'format' => $migration::GENERAL_FORMAT, 'timestamp_timezone' => '+00:00',
    'deferred_documents' => [['id' => 4294976201, 'domain_id' => $domain, 'original' => $original]]];
$timezone = $postgres ? null : $connection->fetchOne('SELECT @@session.time_zone');
$historicalStarted = false;
$historicalPrimary = null;
$historicalCleanup = [];
try {
    $nativeExact->beginOwnedAlteration();
    $historicalStarted = true;
    foreach (['columns', 'projection', 'constraints'] as $interrupt) {
        $manager->dropTable($table);
        $old = clone $required;
        $old->removeForeignKey('fk_documents_items_domains_id');
        $old->dropIndex($table . '_domains_id');
        $old->dropColumn('domains_id');
        DocumentSubjects::configureTable($old);
        $manager->createTable($old);
        $nativeBooleans->restore();
        $connection->executeStatement(DocumentSubjects::checkSql($table));
        $connection->insert($table, ['id' => 4294976202, 'documents_id' => $document, 'itemtype' => 'Computer', 'computers_id' => $computer, 'timeline_position' => 1]);
        $connection->delete('itsmng_migrations', ['version' => $migration::VERSION]);
        Ledger::save($connection, $migration::GENERAL_RECEIPT, $receipt);
        $before = $connection->fetchAllAssociative('SELECT version, state FROM itsmng_migrations ORDER BY version');
        $plan = $migration->plan($connection);
        verify($plan[$table]['projection'] !== [], 'Generated expression expansion has actual provider DDL');
        verify($connection->fetchAllAssociative('SELECT version, state FROM itsmng_migrations ORDER BY version') === $before
            && !$manager->introspectTable($table)->hasColumn('domains_id'), 'Preview leaves schema and ledger unchanged');
        if ($interrupt === 'columns') {
            $problems = ['missing context', 'missing parent', 'duplicate identity', 'zero date', 'foreign subject'];
            if (!$postgres) {
                $problems[] = 'native overflow';
            }
            foreach ($problems as $problem) {
                $invalid = $receipt;
                match ($problem) {
                    'missing context' => $invalid['timestamp_timezone'] = null,
                    'missing parent' => $invalid['deferred_documents'][0]['original']['documents_id'] = '999999999',
                    'duplicate identity' => $invalid['deferred_documents'][] = $invalid['deferred_documents'][0],
                    'zero date' => $invalid['deferred_documents'][0]['original']['date'] = '0000-00-00 00:00:00',
                    'foreign subject' => $invalid['deferred_documents'][0]['original']['computers_id'] = (string)$computer,
                    'native overflow' => $invalid['deferred_documents'][0]['original']['date'] = '2200-01-01 00:00:00',
                };
                Ledger::save($connection, $migration::GENERAL_RECEIPT, $invalid);
                try {
                    $migration->apply($connection);
                    throw new LogicException('Invalid deferred rows accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Domain document'), 'Invalid snapshot receives concrete preflight diagnostics: ' . $problem);
                }
                verify(Ledger::state($connection, $migration::VERSION) === null && !$manager->introspectTable($table)->hasColumn('domains_id'), 'Invalid deferred rows change neither stage journal nor DDL');
            }
            Ledger::save($connection, $migration::GENERAL_RECEIPT, $receipt);
        }
        if (!$postgres) {
            $connection->executeStatement("SET SESSION time_zone = '+02:00'");
        }
        try {
            $migration->apply($connection, static function (string $phase) use ($interrupt): void {
                if ($phase === $interrupt) {
                    throw new RuntimeException('Injected document phase interruption');
                }
            });
            throw new LogicException('Document interruption did not execute');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Injected document phase interruption', 'Real expansion surfaces interrupted DDL phase');
        }
        verify(!(Ledger::state($connection, $migration::GENERAL_RECEIPT)['documents_restored'] ?? false), 'Failed DDL never restores pending rows');
        verify(!$connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE id = 4294976201'), 'Deferred row stays outside incomplete history');
        $migration->apply($connection);
        $rows = $connection->fetchAllAssociative('SELECT id, itemtype, items_id, domains_id, documents_id, users_id, entities_id, is_recursive, timeline_position FROM ' . $table . ' ORDER BY id');
        verify(count($rows) === 2 && (int)$rows[0]['id'] === 4294976201 && (int)$rows[1]['id'] === 4294976202, 'Retry preserves both original and deferred binding IDs');
        verify((int)$rows[0]['items_id'] === $domain && (int)$rows[0]['domains_id'] === $domain && (int)$rows[0]['documents_id'] === $document, 'Restored owning Domain and containing document remain separate');
        verify($rows[0]['users_id'] === null && (int)$rows[0]['entities_id'] === 0 && (bool)$rows[0]['is_recursive'] && (int)$rows[0]['timeline_position'] === 2, 'Nullable actor sentinel, root ownership, recursion and timeline role converge');
        verify((int)$rows[1]['items_id'] === $computer && $rows[1]['domains_id'] === null, 'Earlier frozen subjects retain their generated identities');
        if (!$postgres) {
            verify($connection->fetchOne('SELECT @@session.time_zone') === '+02:00', 'Restoration preserves caller timezone');
            verify((int)$connection->fetchOne('SELECT UNIX_TIMESTAMP(date_mod) FROM ' . $table . ' WHERE id = 4294976201') === (new DateTimeImmutable($original['date_mod'], new DateTimeZone('UTC')))->getTimestamp(), 'Deferred native TIMESTAMP retains its original UTC instant under a different retry timezone');
        }
        verify(Ledger::state($connection, $migration::GENERAL_RECEIPT)['documents_restored'] === true && Ledger::state($connection, $migration::VERSION)['complete'] === true, 'Restored rows and both completion records converge');
        $connection->executeStatement("UPDATE $table SET timeline_position = 3 WHERE id = 4294976201");
        verify($migration->apply($connection) === [] && (int)$connection->fetchOne("SELECT timeline_position FROM $table WHERE id = 4294976201") === 3, 'Completed retry preserves later edits instead of replaying frozen rows');
    }
    // Real late database rejection proves restoration and completion records
    // share a physical transaction, after all nontransactional DDL has succeeded.
    $connection->executeStatement('DELETE FROM ' . $table . ' WHERE id = 4294976201');
    $second = $receipt['deferred_documents'][0];
    $second['id'] = 4294976203;
    $second['original']['id'] = '4294976203';
    $second['original']['timeline_position'] = '4';
    $late = $receipt;
    $late['deferred_documents'][] = $second;
    Ledger::save($connection, $migration::GENERAL_RECEIPT, $late);
    Ledger::save($connection, $migration::VERSION, ['complete' => false, 'phase' => 'constraints', 'projection_expanded' => true, 'items_comment' => $manager->introspectTable($table)->getColumn('items_id')->getComment()]);
    if ($postgres) {
        $connection->executeStatement("CREATE FUNCTION itsm_domain_document_restore_probe() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.id = 4294976203 THEN RAISE EXCEPTION ''Injected deferred restore failure''; END IF; RETURN NEW; END'");
        $connection->executeStatement('CREATE TRIGGER itsm_domain_document_restore_probe BEFORE INSERT ON ' . $table . ' FOR EACH ROW EXECUTE FUNCTION itsm_domain_document_restore_probe()');
    } else {
        $connection->executeStatement("CREATE TRIGGER itsm_domain_document_restore_probe BEFORE INSERT ON $table FOR EACH ROW BEGIN IF NEW.id = 4294976203 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected deferred restore failure'; END IF; END");
    }
    try {
        $migration->apply($connection);
        throw new LogicException('Late restoration failure did not execute');
    } catch (DriverException $error) {
        verify(str_contains($error->getMessage(), 'Injected deferred restore failure'), 'Real second-row trigger rejection remains observable');
    }
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE id IN (4294976201, 4294976203)') === 0, 'Late failure rolls back the earlier restored row');
    verify(!(Ledger::state($connection, $migration::GENERAL_RECEIPT)['documents_restored'] ?? false)
        && Ledger::state($connection, $migration::VERSION)['complete'] === false, 'Late failure rolls back both completion records');
    if (!$postgres) {
        verify($connection->fetchOne('SELECT @@session.time_zone') === '+02:00', 'Failed restoration also restores caller timezone');
    }
    $connection->executeStatement('DROP TRIGGER itsm_domain_document_restore_probe' . ($postgres ? ' ON ' . $table : ''));
    if ($postgres) {
        $connection->executeStatement('DROP FUNCTION itsm_domain_document_restore_probe()');
    }
    $migration->apply($connection);
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE id IN (4294976201, 4294976203)') === 2, 'Retry after late rejection restores every retained row once');
    if (!$postgres) {
        $payload = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        $manager->dropTable($table);
        $unsafe = clone $required;
        foreach ($unsafe->getForeignKeys() as $foreign) {
            $unsafe->removeForeignKey($foreign->getName());
        }
        $unsafe->addOption('engine', 'MyISAM');
        $manager->createTable($unsafe);
        $nativeBooleans->restore();
        foreach ($payload as $row) {
            unset($row['items_id']);
            $connection->insert($table, $row);
        }
        $beforeRows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        $connection->delete('itsmng_migrations', ['version' => $migration::VERSION]);
        try {
            $migration->apply($connection);
            throw new LogicException('Nontransactional document table accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'InnoDB'), 'Nontransactional storage receives a concrete refusal');
        }
        verify(Ledger::state($connection, $migration::VERSION) === null
            && $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $beforeRows
            && $connection->fetchOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'") === 'MyISAM', 'Storage refusal preserves every existing row and changes neither engine nor stage receipt');
    }
} catch (Throwable $error) {
    $historicalPrimary = $error;
} finally {
    try {
        if (!$postgres) {
            $connection->executeStatement('SET SESSION time_zone = ?', [$timezone]);
        }
        if ($historicalStarted) {
            $manager->dropTable($table);
            if ($postgres) {
                $connection->executeStatement('DROP FUNCTION IF EXISTS itsm_domain_document_restore_probe()');
            }
            $manager->createTable($nativeExact->restorationTable($table));
            $nativeBooleans->restore();
            $connection->executeStatement($migration::checkSql($table));
            Ledger::save($connection, $migration::VERSION, $savedStage);
            $connection->delete('itsmng_migrations', ['version' => $migration::GENERAL_RECEIPT]);
        }
        foreach (['glpi_domains' => $domain, 'glpi_documents' => $document, 'glpi_computers' => $computer] as $parent => $id) {
            $connection->delete($parent, ['id' => $id]);
        }
        $DB->clearSchemaCache();
    } catch (Throwable $error) {
        $historicalCleanup[] = $error;
    }
    try {
        $nativeExact->restore();
    } catch (Throwable $error) {
        $historicalCleanup[] = $error;
    }
}
foreach ($historicalCleanup as $error) {
    try {
        fwrite(STDERR, 'Additional historical fixture cleanup failure: ' . $error::class . "\n");
    } catch (Throwable) {
    }
}
if ($historicalPrimary !== null) {
    throw $historicalPrimary;
}
if ($historicalCleanup !== []) {
    throw new RuntimeException('Historical Domain document fixture cleanup failed.', previous: $historicalCleanup[0]);
}
verify((new SchemaCheck())->differences($connection) === [], 'Fixture cleanup restores the complete required schema');
// Exercise the restored native policy independently of the completed receipt.
$connection->beginTransaction();
try {
    $policyDocument = $fixtures->create('glpi_documents');
    $policyBudget = $fixtures->create('glpi_budgets');
    $policyLink = ['documents_id' => $policyDocument, 'itemtype' => 'Budget', 'budgets_id' => $policyBudget];
    $reject(static fn () => $connection->insert($table, array_replace($policyLink, ['itemtype' => 'budget'])), 'Restored document CHECK rejects a lowercase Budget INSERT', $table . '_typed_item_kind');
    $connection->insert($table, $policyLink);
    $policyId = (int)$connection->fetchOne('SELECT id FROM ' . $table . ' WHERE documents_id=? AND budgets_id=?', [$policyDocument, $policyBudget]);
    verify($policyId > 0 && (int)$connection->fetchOne('SELECT items_id FROM ' . $table . ' WHERE id=?', [$policyId]) === $policyBudget, 'Restored canonical Budget link keeps its actual generated owning identity');
    $reject(static fn () => $connection->update($table, ['itemtype' => 'budget'], ['id' => $policyId]), 'Restored document CHECK rejects a lowercase Budget UPDATE', $table . '_typed_item_kind');
} finally {
    $connection->rollBack();
}

echo $DB->getProvider() . ": $assertions assertions; owning Domain documents, native projection/uniqueness, frozen expansion, invalid-data preflight, preserved timestamps and populated retry passed.\n";
