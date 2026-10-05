<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\V220\ObjectLockSubjects;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/object-lock-subjects.php /path/to/test-config\n");
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
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_objectlocks'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $migration = new ObjectLockSubjects();
    $migration->apply($connection);
    $DB->clearSchemaCache();
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Login');
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $table = 'glpi_objectlocks';
    $read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
    verify(array_diff($CFG_GLPI['lock_lockable_objects'], array_keys($branches)) === [] && count($branches) === 30, 'Every configured core lockable object has an owning subject');
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
        $sameId = 4294968120;
        $owner = Session::getLoginUserID();
        $links = [];
        foreach ($branches as $kind => $selection) {
            $fixtures->create($selection['target'], ['id' => $sameId]);
            if ($kind === 'Profile') {
                $fixtures->create('glpi_profilerights', ['profiles_id' => $sameId, 'name' => 'profile', 'rights' => 0]);
            }
            $id = $links[$kind] = (new ObjectLock())->add(['itemtype' => $kind, 'items_id' => $sameId, 'users_id' => $owner, 'date_mod' => '2030-01-01 12:00:00']);
            $row = $read($table, $id);
            verify($id > 0 && (int)$row['items_id'] === $sameId && (int)$row[$selection['column']] === $sameId && (int)$row['users_id'] === $owner, 'Public lock stores its canonical wide subject independently of its owner: ' . $kind);
            $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => 999999999, 'users_id' => $owner]), 'Native orphan subject rejected: ' . $kind);
            $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Native parent deletion restricted: ' . $kind);
            $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => $sameId, 'users_id' => $owner]), 'Native subject uniqueness enforced: ' . $kind);
        }
        foreach ([[], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'User'], ['itemtype' => 'User', 'subject_computers_id' => $sameId],
            ['itemtype' => 'Entity', 'subject_entities_id' => 0], ['itemtype' => 'User', 'subject_users_id' => $sameId, 'subject_computers_id' => $sameId]] as $invalid) {
            $reject(static fn () => $connection->insert($table, $invalid + ['users_id' => $owner]), 'Native null/unknown/missing/wrong/zero/multiple selection rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, 'glpi_objectlocks_typed_item_kind');
        }
        $oldConfiguration = $CFG_GLPI;
        try {
            $CFG_GLPI['lock_use_lock_item'] = 1;
            $CFG_GLPI['lock_lockprofile_id'] = $_SESSION['glpiactiveprofile']['id'];
            $CFG_GLPI['lock_item_list'] = array_keys($branches);
            $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
            $SQL_TOTAL_REQUEST = 0;
            foreach ($links as $kind => $id) {
                $lock = ObjectLock::isLocked($kind, $sameId);
                verify($lock instanceof ObjectLock && (int)$lock->fields['id'] === $id && (int)$lock->fields['users_id'] === $owner, 'Public lock status isolates each subject type: ' . $kind);
            }
            verify($SQL_TOTAL_REQUEST === 0, 'Public lock status bypasses adapter SQL');
        } finally {
            $CFG_GLPI = $oldConfiguration;
        }
        $locks = new \itsmng\Database\Repository\ObjectLockRepository(Orm::create($DB));
        $expired = array_column($locks->expired(new DateTimeImmutable('2030-01-01 12:00:00')), 'id');
        verify(!array_intersect($links, $expired), 'Expiry excludes the strict timestamp boundary');
        $expired = array_column($locks->expired(new DateTimeImmutable('2030-01-01 12:00:01')), 'id');
        verify(array_diff($links, $expired) === [], 'Expiry selects every typed lock after the boundary');

        $em = Orm::create($DB);
        $subject = new Record\Computer();
        $subject->entities = $em->getReference(Record\Entity::class, 0);
        $native = new Record\ObjectLock();
        $native->itemtype = 'Computer';
        $native->subjectComputer = $subject;
        $native->users = $em->getReference(Record\User::class, $owner);
        $em->persist($subject);
        $em->persist($native);
        $em->flush();
        $em->refresh($native);
        verify($native->items_id === $subject->id && $native->date_mod !== null, 'Native lock and subject persist together with a required lock timestamp');
        $replacement = $fixtures->create('glpi_tickets');
        $storage = new MappedStorage($DB);
        $changes = $storage->update($table, $native->id, ['itemtype' => 'Ticket', 'items_id' => $replacement]);
        $row = $read($table, $native->id);
        verify($row['subject_computers_id'] === null && (int)$row['subject_tickets_id'] === $replacement && in_array('items_id', $changes, true), 'Retarget clears the previous owning subject and reports the logical identity');
        verify((new ObjectLock())->delete(['id' => $native->id], true) && $read('glpi_computers', $subject->id) !== null && $read('glpi_tickets', $replacement) !== null, 'Unlock removes only the lock');
        $badEm = Orm::create($DB);
        $invalid = new Record\ObjectLock();
        $invalid->itemtype = 'User';
        $invalid->subjectComputer = $badEm->getReference(Record\Computer::class, $sameId);
        $invalid->users = $badEm->getReference(Record\User::class, $owner);
        try {
            $badEm->persist($invalid);
            $badEm->flush();
            throw new RuntimeException('Native mismatched lock accepted');
        } catch (InvalidArgumentException) {
        }
        foreach ($branches as $kind => $selection) {
            verify((new $kind())->delete(['id' => $sameId], true) && $read($table, $links[$kind]) === null, 'Public subject purge removes its lock: ' . $kind);
            foreach (array_diff_key($links, [$kind => true]) as $otherKind => $link) {
                if ($read($branches[$otherKind]['target'], $sameId) !== null) {
                    verify($read($table, $link) !== null, 'Same-ID subjects remain isolated during purge');
                }
            }
        }
        verify((new ForeignKeys())->audit($connection) === [], 'Lock lifecycle leaves no orphaned references');
    } finally {
        $DB->rollBack();
    }

    // Reconstruct this disposable table's nullable legacy discriminator and INT identity.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    $parent = $fixtures->create('glpi_computers', ['id' => 950000151]);
    $otherParent = $fixtures->create('glpi_computers', ['id' => 950000158]);
    $legacyId = null;
    $legacyUntouched = null;
    $columns = array_column($branches, 'column');
    try {
        $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
        $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
        $before = $manager->introspectTable($table);
        $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', $index->getColumns(), true));
        foreach ($indexes as $index) {
            $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
        }
        $identityComment = (new \itsmng\Database\BaselineSchema())->build($platform)->getTable($table)->getColumn('items_id')->getComment();
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
        $legacy->addColumn('items_id', 'integer', ['default' => 0, 'comment' => $identityComment]);
        foreach ($indexes as $index) {
            $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        $data = ['itemtype' => 'Computer', 'items_id' => $parent, 'users_id' => Session::getLoginUserID(), 'date_mod' => '2026-01-02 12:00:00'];
        $connection->insert($table, $data);
        $legacyId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        $connection->insert($table, array_replace($data, ['items_id' => $otherParent]));
        $legacyUntouched = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        foreach ([['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['items_id' => 999999999], ['items_id' => 0]] as $invalid) {
            $connection->update($table, $invalid, ['id' => $legacyId]);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('subject_tickets_id'), 'Invalid legacy lock subjects refuse before DDL');
            $connection->update($table, $data, ['id' => $legacyId]);
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD subject_computers_id BIGINT NULL');
        $connection->update($table, ['subject_computers_id' => $parent + 1], ['id' => $legacyId]);
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'disagree');
        }
        verify($failed && !$manager->introspectTable($table)->hasColumn('subject_tickets_id'), 'Conflicting canonical lock subjects refuse before DDL');
        $connection->update($table, ['subject_computers_id' => $parent], ['id' => $legacyId]);
        $connection->update($table, ['date_mod' => $data['date_mod']], ['id' => $legacyId]);
        verify((new DateTimeImmutable($connection->fetchOne('SELECT date_mod FROM glpi_objectlocks WHERE id = ?', [$legacyId])))->format('Y-m-d H:i:s') === $data['date_mod'], 'Legacy timestamp fixture is restored before migration');
        $migration->apply($connection);
        $DB->clearSchemaCache();
        verify($manager->introspectTable($table)->getColumn('items_id')->getComment() === $identityComment, 'Generated identity preserves the legacy column comment');
        $row = $read($table, $legacyId);
        verify((int)$row['items_id'] === $parent && (int)$row['subject_computers_id'] === $parent && (int)$row['users_id'] === $data['users_id']
            && $row['date_mod'] === $data['date_mod'], 'Upgrade preserves lock subject, owner and timestamp: ' . json_encode([$row, $data]));
        $row = $read($table, $legacyUntouched);
        verify((int)$row['items_id'] === $otherParent && (int)$row['subject_computers_id'] === $otherParent
            && $row['date_mod'] === $data['date_mod'], 'Copying a previously absent canonical subject preserves the original timestamp');
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Completed lock upgrade retry makes no changes');
        }
    } finally {
        if ($legacyId !== null) {
            $connection->delete($table, ['id' => $legacyId]);
        }
        if ($legacyUntouched !== null) {
            $connection->delete($table, ['id' => $legacyUntouched]);
        }
        $migration->apply($connection);
        (new ForeignKeys())->apply($connection);
        $connection->delete('glpi_computers', ['id' => $parent]);
        $connection->delete('glpi_computers', ['id' => $otherParent]);
    }

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
echo $DB->getProvider() . ": thirty object lock subject FKs, public lock status, native persistence, wide overlapping IDs, subject purges and frozen upgrade passed.\n";
