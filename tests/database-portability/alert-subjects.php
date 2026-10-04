<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\AlertSubjects;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/alert-subjects.php /path/to/test-config\n");
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
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_alerts'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
$migration = new AlertSubjects();
$migration->apply($connection);
$DB->clearSchemaCache();
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$table = 'glpi_alerts';
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
verify(count($branches) === 10, 'Every audited core alert producer has an owning subject');
$reject = static function (callable $operation, string $message, ?string $omittedRequiredColumn = null, ?string $expectedCheck = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $failed = NativeConstraintRefusal::matches($error, $omittedRequiredColumn)
                || NativeConstraintRefusal::matchesSelectedCheck($error, $expectedCheck);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$DB->beginTransaction();
try {
    $sameId = 4294967990;
    $links = [];
    foreach ($branches as $kind => $selection) {
        $values = ['id' => $sameId];
        if ($kind === 'PlanningRecall') {
            $values['users_id'] = Session::getLoginUserID();
        }
        if ($kind === 'Infocom') {
            $values += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers')];
        }
        $fixtures->create($selection['target'], $values);
        $id = $links[$kind] = (new Alert())->add(['itemtype' => $kind, 'items_id' => $sameId, 'type' => Alert::END, 'date' => '2030-01-01 12:00:00']);
        $row = $read($table, $id);
        verify($id > 0 && (int)$row['items_id'] === $sameId && (int)$row[$selection['column']] === $sameId, 'Public alert stores its canonical wide subject: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => 999999999, 'type' => Alert::END]), 'Native orphan subject rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Native parent deletion restricted: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => $sameId, 'type' => Alert::END]), 'Native subject/event uniqueness enforced: ' . $kind);
    }
    foreach ([[], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'User'], ['itemtype' => 'User', 'contracts_id' => $sameId],
        ['itemtype' => 'Contract', 'contracts_id' => 0], ['itemtype' => 'User', 'users_id' => $sameId, 'contracts_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert($table, $invalid + ['type' => Alert::NOTICE]), 'Native null/unknown/missing/wrong/zero/multiple selection rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, $table . '_typed_item_kind');
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    foreach ($links as $kind => $id) {
        verify((int)Alert::alertExists($kind, $sameId, Alert::END) === $id && Alert::getAlertDate($kind, $sameId, Alert::END) === '2030-01-01 12:00:00', 'Public existence/date reads isolate each producer');
        verify(Alert::alertExists($kind, $sameId, Alert::NOTICE) === false && Alert::getAlertDate($kind, $sameId, Alert::NOTICE) === false, 'Missing events retain false');
    }
    verify(Alert::alertExists('Contract', 0, Alert::END) === false && Alert::getAlertDate('Contract', $sameId, 0) === false, 'Invalid legacy lookup inputs retain false');
    $later = (new Alert())->add(['itemtype' => 'Contract', 'items_id' => $sameId, 'type' => Alert::NOTICE, 'date' => '2030-01-02 13:14:15']);
    ob_start();
    Alert::displayLastAlert('Contract', $sameId);
    $html = ob_get_clean();
    verify(str_contains($html, Html::convDateTime('2030-01-02 13:14:15')), 'Last-alert display orders across event types');
    verify($SQL_TOTAL_REQUEST === 0, 'Public alert reads and creation bypass adapter SQL: ' . json_encode($DEBUG_SQL['queries'] ?? []));
    verify((new Alert())->clear('Contract', $sameId, Alert::NOTICE) && $read($table, $later) === null && $read($table, $links['Contract']) !== null, 'Clear removes only the selected event');
    $storage = new MappedStorage($DB);
    $changes = $storage->update($table, $links['Contract'], ['itemtype' => 'User', 'items_id' => $sameId, 'type' => Alert::NOTICE]);
    verify($read($table, $links['Contract'])['contracts_id'] === null && (int)$read($table, $links['Contract'])['users_id'] === $sameId
        && in_array('itemtype', $changes, true), 'Retarget clears the former canonical subject');
    $storage->update($table, $links['Contract'], ['itemtype' => 'Contract', 'items_id' => $sameId, 'type' => Alert::END]);

    $em = Orm::create($DB);
    $subject = new Record\Contract();
    $subject->entities = $em->getReference(Record\Entity::class, 0);
    $native = new Record\Alert();
    $native->itemtype = 'Contract';
    $native->contract = $subject;
    $native->type = Alert::END;
    $em->persist($subject);
    $em->persist($native);
    $em->flush();
    $em->refresh($native);
    verify($native->items_id === $subject->id && $native->date !== null, 'Native alert and parent persist in one unit of work');
    $invalid = new Record\Alert();
    $invalid->itemtype = 'User';
    $invalid->contract = $subject;
    try {
        $em->persist($invalid);
        $em->flush();
        throw new RuntimeException('Native mismatched alert accepted');
    } catch (InvalidArgumentException) {
    }
    verify((new Alert())->delete(['id' => $native->id], true) && $read('glpi_contracts', $subject->id) !== null, 'Alert purge preserves its parent');
    foreach ($branches as $kind => $selection) {
        verify((new $kind())->delete(['id' => $sameId], true) && $read($table, $links[$kind]) === null, 'Public parent purge removes its alerts: ' . $kind);
        foreach (array_diff_key($links, [$kind => true]) as $otherKind => $link) {
            if ($read($branches[$otherKind]['target'], $sameId) !== null) {
                verify($read($table, $link) !== null, 'Same-ID producer alerts stay isolated during purge');
            }
        }
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Alert lifecycle leaves no orphaned references');
} finally {
    $DB->rollBack();
}

// Reconstruct this disposable table's nullable legacy discriminator and INT identity.
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$parent = $fixtures->create('glpi_cartridgeitems', ['id' => 950000132]);
$legacyId = null;
$columns = array_column($branches, 'column');
try {
    $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
    $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
    $before = $manager->introspectTable($table);
    $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', $index->getColumns(), true));
    foreach ($indexes as $index) {
        $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
    }
    $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
    $before = $manager->introspectTable($table);
    $legacy = clone $before;
    foreach ($legacy->getForeignKeys() as $foreign) {
        if (array_intersect($foreign->getLocalColumns(), $columns)) {
            $legacy->removeForeignKey($foreign->getName());
        }
    }
    foreach ($legacy->getIndexes() as $index) {
        if (array_intersect($index->getColumns(), $columns)) {
            $legacy->dropIndex($index->getName());
        }
    }
    foreach ($columns as $column) {
        $legacy->dropColumn($column);
    }
    $legacy->getColumn('itemtype')->setNotnull(false);
    $legacy->addColumn('items_id', 'integer', ['default' => 0]);
    foreach ($indexes as $index) {
        $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
    }
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
        $connection->executeStatement($sql);
    }
    $data = ['itemtype' => 'CartridgeItem', 'items_id' => $parent, 'type' => Alert::THRESHOLD, 'date' => '2026-01-02 12:00:00'];
    $connection->insert($table, $data);
    $legacyId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
    foreach ([['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['items_id' => 999999999], ['items_id' => 0]] as $invalid) {
        $connection->update($table, $invalid, ['id' => $legacyId]);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('projecttasks_id'), 'Invalid legacy alert subjects refuse before DDL');
        $connection->update($table, $data, ['id' => $legacyId]);
    }
    $connection->executeStatement('ALTER TABLE ' . $table . ' ADD cartridgeitems_id BIGINT NULL');
    $connection->update($table, ['cartridgeitems_id' => $parent + 1], ['id' => $legacyId]);
    $failed = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'disagree');
    }
    verify($failed && !$manager->introspectTable($table)->hasColumn('projecttasks_id'), 'Conflicting canonical alert subjects refuse before DDL');
    $connection->update($table, ['cartridgeitems_id' => $parent], ['id' => $legacyId]);
    $migration->apply($connection);
    $DB->clearSchemaCache();
    $row = $read($table, $legacyId);
    verify((int)$row['items_id'] === $parent && (int)$row['cartridgeitems_id'] === $parent && (int)$row['type'] === Alert::THRESHOLD
        && $row['date'] === $data['date'], 'Upgrade preserves alert subject, event and delivery date');
    foreach ($migration->apply($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Completed alert upgrade retry makes no changes');
    }
} finally {
    if ($legacyId !== null) {
        $connection->delete($table, ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    $connection->delete('glpi_cartridgeitems', ['id' => $parent]);
}

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
echo $DB->getProvider() . ": ten alert subject FKs, ORM public lookups, native persistence, wide overlapping IDs, public purges and frozen upgrade passed.\n";
