<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\ConsumableRecipients;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/consumable-recipients.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
require_once __DIR__ . '/fixtures/ExactSubjectHistoricalFixture.php';
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_consumables'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    $migration = new ConsumableRecipients();
    $migration->apply($connection);
    $DB->clearSchemaCache();
    foreach ($migration->plan($connection) as $entry) {
        verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Idempotent recipient migration');
    }
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Login');
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $storage = new MappedStorage($DB);
    $table = 'glpi_consumables';
    $read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_consumables', 'id', $id);
    $reject = static function (callable $operation, string $message) use ($connection): void {
        $connection->beginTransaction();
        try {
            try {
                $operation();
                throw new RuntimeException($message);
            } catch (DriverException) {
            }
        } finally {
            $connection->rollBack();
        }
    };
    $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
    verify(array_keys($branches) === ['User', 'Group'] && array_diff($CFG_GLPI['consumables_types'], array_keys($branches)) === [], 'All core recipient kinds are owning associations');
    $DB->beginTransaction();
    try {
        $sameId = 900000201;
        $user = $fixtures->create('glpi_users', ['id' => $sameId, 'name' => 'Typed consumable user']);
        $group = $fixtures->create('glpi_groups', ['id' => $sameId, 'name' => 'Typed consumable group']);
        $replacement = $fixtures->create('glpi_groups', ['name' => 'Replacement consumable group']);
        $model = $fixtures->create('glpi_consumableitems', ['name' => 'Typed consumable stock']);
        $stock = (new Consumable())->add(['consumableitems_id' => $model]);
        verify($stock > 0 && $read($stock)['items_id'] === 0 && $read($stock)['itemtype'] === null, 'New stock has no recipient and projects legacy zero');
        $public = new Consumable();
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $CFG_GLPI['debug_sql'] = true;
        $DEBUG_SQL = [];
        $SQL_TOTAL_REQUEST = 0;
        verify(!$public->out($stock, 'UnknownPlugin', $sameId) && !$public->out($stock, 'User', 999999999), 'Unknown and missing recipients are rejected');
        verify($public->out($stock, 'User', $sameId) && $public->out($stock, 'User', $sameId), 'Repeated public assignment succeeds');
        verify((int)$read($stock)['users_id'] === $sameId && $read($stock)['groups_id'] === null && (int)$read($stock)['items_id'] === $sameId, 'Assignment selects only the user association');
        verify($public->backToStock(['id' => $stock]), 'Return to stock');
        verify($read($stock)['date_out'] === null && (int)$read($stock)['users_id'] === $sameId, 'Return preserves the last recipient association');
        $storage->update($table, $stock, ['date_in' => '2026-10-01']);
        verify((int)$read($stock)['users_id'] === $sameId, 'Partial updates preserve recipient history');
        verify($public->out($stock, 'Group', $sameId), 'Retarget to overlapping group ID');
        verify($read($stock)['users_id'] === null && (int)$read($stock)['groups_id'] === $sameId, 'Retarget clears the other branch');
        verify($SQL_TOTAL_REQUEST === 0, 'Recipient assignment, return, retarget and reads bypass adapter SQL');
        $otherStock = $fixtures->create($table, ['consumableitems_id' => $model, 'itemtype' => 'User', 'items_id' => $sameId, 'date_out' => '2026-10-01']);
        foreach ($branches as $kind => $selection) {
            $reject(static fn () => $connection->insert('glpi_consumables', ['consumableitems_id' => $model, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Physical recipient FK accepted an orphan');
            $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Physical FK allowed its recipient to disappear');
        }
        foreach ([['itemtype' => 'Unknown'], ['itemtype' => 'User'], ['users_id' => $sameId],
            ['itemtype' => 'Group', 'users_id' => $sameId], ['itemtype' => 'User', 'users_id' => $sameId, 'groups_id' => $sameId],
            ['itemtype' => 'User', 'users_id' => 0], ['date_out' => '2026-10-01']] as $invalid) {
            $reject(static fn () => $connection->insert('glpi_consumables', ['consumableitems_id' => $model] + $invalid), 'Exact recipient/stock CHECK accepted invalid data');
        }
        $em = Orm::create($DB);
        $nativeUser = new Record\User();
        $nativeUser->name = 'Native consumable recipient';
        $nativeUser->entities = $em->getReference(Record\Entity::class, 0);
        $native = new Record\Consumable();
        $native->consumableitems = $em->getReference(Record\ConsumableItem::class, $model);
        $native->entities = $em->getReference(Record\Entity::class, 0);
        $native->itemtype = 'User';
        $native->recipientUser = $nativeUser;
        $native->date_out = new DateTime('2026-10-01');
        $em->persist($nativeUser);
        $em->persist($native);
        $em->flush();
        $em->refresh($native);
        verify($native->items_id === $nativeUser->id, 'Native recipient and consumable persist in one unit of work');
        foreach (['both', 'unassigned_used'] as $case) {
            $invalidEm = Orm::create($DB);
            $invalid = new Record\Consumable();
            $invalid->consumableitems = $invalidEm->getReference(Record\ConsumableItem::class, $model);
            $invalid->entities = $invalidEm->getReference(Record\Entity::class, 0);
            $invalid->date_out = new DateTime('2026-10-01');
            if ($case === 'both') {
                $invalid->itemtype = 'User';
                $invalid->recipientUser = $invalidEm->getReference(Record\User::class, $sameId);
                $invalid->recipientGroup = $invalidEm->getReference(Record\Group::class, $sameId);
            }
            try {
                $invalidEm->persist($invalid);
                $invalidEm->flush();
                throw new RuntimeException('Native invalid recipient accepted');
            } catch (InvalidArgumentException) {
            }
        }
        $date = $read($stock)['date_out'];
        verify((new Group())->delete(['id' => $sameId, '_replace_by' => $replacement], true), 'Public recipient group replacement');
        verify((int)$read($stock)['groups_id'] === $replacement && $read($stock)['date_out'] === $date, 'Group replacement preserves usage date');
        verify((int)$read($otherStock)['users_id'] === $sameId, 'Group replacement preserves equal-ID user stock');
        verify((new Group())->delete(['id' => $replacement], true), 'Public group recipient purge');
        verify($read($stock)['itemtype'] === null && $read($stock)['items_id'] === 0 && $read($stock)['date_out'] === null, 'Group purge returns stock and clears its recipient');
        verify((new User())->delete(['id' => $sameId], true), 'Public user recipient purge');
        verify($read($otherStock)['users_id'] === null && $read($otherStock)['items_id'] === 0 && $read($otherStock)['date_out'] === null, 'User purge clears only user recipient stock');
        verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references after public lifecycle');
    } finally {
        $DB->rollBack();
    }

    // Rebuild only this disposable table's pre-upgrade identity, preserving its indexes.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    $legacyModel = $fixtures->create('glpi_consumableitems', ['name' => 'Legacy recipient stock']);
    $legacyUser = $fixtures->create('glpi_users', ['id' => 950000142, 'name' => 'Legacy stock recipient']);
    $legacyIds = [];
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
            if (array_intersect($foreign->getLocalColumns(), ['users_id', 'groups_id'])) {
                $legacy->removeForeignKey($foreign->getName());
            }
        }
        foreach ($legacy->getIndexes() as $index) {
            if (array_intersect($index->getColumns(), ['users_id', 'groups_id'])) {
                $legacy->dropIndex($index->getName());
            }
        }
        $legacy->dropColumn('users_id');
        $legacy->dropColumn('groups_id');
        $legacy->addColumn('items_id', 'integer', ['default' => 0]);
        foreach ($indexes as $index) {
            $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        foreach ([['itemtype' => null, 'items_id' => 0], ['itemtype' => '', 'items_id' => 0],
            ['itemtype' => 'User', 'items_id' => $legacyUser, 'date_out' => '2026-10-01'],
            ['itemtype' => 'User', 'items_id' => $legacyUser, 'date_out' => null]] as $values) {
            $connection->insert($table, ['consumableitems_id' => $legacyModel, 'date_in' => '2026-09-01'] + $values);
            $legacyIds[] = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
        }
        foreach ([['itemtype' => 'PluginRemoved', 'items_id' => 1], ['itemtype' => 'User', 'items_id' => 999999999],
            ['itemtype' => 'User', 'items_id' => 0], ['itemtype' => null, 'items_id' => $legacyUser],
            ['itemtype' => null, 'items_id' => 0, 'date_out' => '2026-10-01']] as $values) {
            $connection->insert($table, ['consumableitems_id' => $legacyModel] + $values);
            $bad = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
            try {
                $failed = false;
                try {
                    $migration->apply($connection);
                } catch (RuntimeException $error) {
                    $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
                }
                verify($failed && !$manager->introspectTable($table)->hasColumn('users_id'), 'Invalid legacy stock refuses before DDL');
            } finally {
                $connection->delete($table, ['id' => $bad]);
            }
        }
        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD users_id BIGINT NULL');
        $connection->update($table, ['users_id' => $legacyUser + 1], ['id' => $legacyIds[2]]);
        try {
            $migration->apply($connection);
            throw new RuntimeException('Conflicting canonical recipient was accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'disagree') && !$manager->introspectTable($table)->hasColumn('groups_id'), 'Conflicting recipient refuses before DDL');
        }
        $connection->update($table, ['users_id' => $legacyUser], ['id' => $legacyIds[2]]);
        $migration->apply($connection);
        $DB->clearSchemaCache();
        foreach (array_slice($legacyIds, 0, 2) as $id) {
            verify($read($id)['items_id'] === 0 && $read($id)['itemtype'] === null && $read($id)['date_in'] === '2026-09-01', 'Upgrade normalizes unassigned stock without changing its entry date');
        }
        foreach (array_slice($legacyIds, 2) as $index => $id) {
            verify((int)$read($id)['users_id'] === $legacyUser && (int)$read($id)['items_id'] === $legacyUser && $read($id)['date_out'] === ($index === 0 ? '2026-10-01' : null), 'Upgrade preserves assigned and returned recipient history');
        }
        foreach ($indexes as $index) {
            verify($manager->introspectTable($table)->hasIndex($index->getName()), 'Upgrade preserves identity selection indexes');
        }
        foreach ($migration->apply($connection) as $entry) {
            verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Recipient upgrade retry is idempotent');
        }
        echo $DB->getProvider() . ": consumable recipient FKs, exact selection, native/public stock lifecycle, overlapping IDs and audited upgrade passed.\n";
    } finally {
        foreach ($legacyIds as $id) {
            $connection->delete($table, ['id' => $id]);
        }
        $migration->apply($connection);
        $DB->clearSchemaCache();
        $connection->delete('glpi_users', ['id' => $legacyUser]);
        $connection->delete('glpi_consumableitems', ['id' => $legacyModel]);
    }

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
