<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\V220\IPNetworkParentReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\IPNetworkRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\TreeRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/network-tree.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$records = static fn () => new RecordRepository(Orm::create($DB));
$read = static fn (int $id) => $records()->find('glpi_ipnetworks', 'id', $id);
$reject = static function (callable $operation, string $exception, ?string $message = null) use ($connection): bool {
    try {
        $connection->transactional($operation);
        return false;
    } catch (Throwable $error) {
        if (!$error instanceof $exception || ($message !== null && !str_contains($error->getMessage(), $message))) {
            throw $error;
        }
        return true;
    }
};
$DB->beginTransaction();
try {
    $entity = (int)(new Entity())->add(['name' => 'Implicit network tree', 'entities_id' => 0]);
    $outside = (int)(new Entity())->add(['name' => 'Outside network tree', 'entities_id' => 0]);
    // Catalogue discovery is separate from runtime domain-query execution.
    foreach (['glpi_ipnetworks', 'glpi_ipaddresses', 'glpi_ipaddresses_ipnetworks', 'glpi_ipnetworks_vlans'] as $table) {
        $DB->listFields($table);
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    foreach (['NULL', "Network config O'Reilly \\path 日本語"] as $context) {
        $values = ['NULL' => 'NULL', 'nullable' => null, 'quoted' => "O'Reilly \\path 日本語"];
        foreach ($values as $name => $value) {
            $fixtures->create('glpi_configs', ['context' => $context, 'name' => $name, 'value' => $value]);
        }
        verify(Config::getConfigurationValues($context) === $values, 'Configuration contexts and values are literal and nullable');
        verify(Config::getConfigurationValues($context, ['missing', 'NULL']) === ['NULL' => 'NULL'], 'Configuration name NULL is a literal selector');
    }
    verify(Config::getConfigurationValues('Missing network config context') === [], 'Missing configuration context');
    foreach ([
        ['10.229.0.0/16', '10.229.4.0/24', '10.229.4.0/25', '10.233.1.0/24', '10.229.4.8', '10.233.1.8'],
        ['2001:db8:229::/48', '2001:db8:229:4::/64', '2001:db8:229:4::/80', '2001:db8:233:1::/64', '2001:db8:229:4::8', '2001:db8:233:1::8'],
    ] as $version => [$broadRange, $middleRange, $leafRange, $movedRange, $insideAddress, $outsideAddress]) {
        $add = static fn (string $range, string $name) => (int)(new IPNetwork())->add(['network' => $range, 'name' => $name, 'entities_id' => $entity]);
        $address = static function (string $value, int $scope) use ($fixtures): int {
            $ip = new IPAddress($value);
            return $fixtures->create('glpi_ipaddresses', $ip->setArrayFromAddress(['entities_id' => $scope, 'name' => $value], 'version', 'name', 'binary'));
        };
        $inside = $address($insideAddress, $entity);
        $crossScope = $address($insideAddress, $outside);
        $out = $address($outsideAddress, $entity);
        // Build from narrowest to widest to exercise root adoption with SQL NULL.
        $leaf = $add($leafRange, "Leaf O'Reilly " . $version);
        verify($leaf > 0 && $read($leaf)['ipnetworks_id'] === null, 'Standalone network uses NULL parent');
        getSonsOf('glpi_ipnetworks', 0);
        getAncestorsOf('glpi_ipnetworks', $leaf);
        $middle = $add($middleRange, 'Middle ' . $version);
        $broad = $add($broadRange, 'Broad ' . $version);
        verify($middle > 0 && $broad > 0 && $read($middle)['ipnetworks_id'] === $broad && $read($leaf)['ipnetworks_id'] === $middle, 'Implicit adoption chooses nearest containing network');
        verify($read($leaf)['level'] === 3 && str_contains($read($leaf)['completename'], "Broad $version > Middle $version > Leaf O'Reilly"), 'Adoption updates depth and quoted complete names');
        verify(array_values(array_map('intval', getAncestorsOf('glpi_ipnetworks', $leaf))) === [$broad, $middle], 'Warm ancestor cache follows adoption');
        verify(isset(getSonsOf('glpi_ipnetworks', $broad)[$leaf]), 'Warm descendant cache follows adoption');
        $linked = $records()->matching('glpi_ipaddresses_ipnetworks', ['ipnetworks_id' => $leaf]);
        verify(array_column($linked, 'ipaddresses_id') === [$inside, $crossScope], 'Address membership preserves bit matching without visibility filtering');
        verify($reject(fn () => $fixtures->create('glpi_ipnetworks', ['ipnetworks_id' => 2147483647]), ForeignKeyConstraintViolationException::class), 'FK rejects orphan parent');
        $protectedParent = $fixtures->create('glpi_ipnetworks', ['name' => 'Protected parent']);
        $protectedChild = $fixtures->create('glpi_ipnetworks', ['name' => 'Protected child', 'ipnetworks_id' => $protectedParent]);
        // PostgreSQL reports a RESTRICT deletion as 23001, which DBAL keeps as DriverException.
        verify($reject(fn () => $connection->delete('glpi_ipnetworks', ['id' => $protectedParent]), DriverException::class, ForeignKeys::name('glpi_ipnetworks', 'ipnetworks_id')), 'Parent FK protects lifecycle hooks');
        $protectedGrandchild = $fixtures->create('glpi_ipnetworks', ['name' => 'Protected grandchild', 'ipnetworks_id' => $protectedChild, 'level' => 3]);
        verify((new IPNetwork())->delete(['id' => $protectedParent], true), 'Malformed legacy parent can be purged');
        verify($read($protectedChild)['ipnetworks_id'] === null && $read($protectedChild)['level'] === 1, 'Malformed child is promoted without CIDR revalidation');
        verify($read($protectedGrandchild)['ipnetworks_id'] === $protectedChild && $read($protectedGrandchild)['level'] === 2, 'Structural promotion preserves and regenerates descendants');
        verify((new IPNetwork())->delete(['id' => $protectedChild], true), 'Malformed intermediate node can be purged');
        verify($read($protectedGrandchild)['ipnetworks_id'] === null && $read($protectedGrandchild)['level'] === 1, 'Malformed descendant reaches the root');
        verify((new IPNetwork())->delete(['id' => $protectedGrandchild], true), 'Malformed fixture cleanup');
        verify($reject(fn () => $connection->update('glpi_ipnetworks', ['ipnetworks_id' => 0], ['id' => $leaf]), ForeignKeyConstraintViolationException::class), 'Raw zero is not a real network parent');
        $rootMatches = $records()->matching('glpi_ipnetworks', ['id' => $broad, 'ipnetworks_id' => 0]);
        verify(count($rootMatches) === 1, 'Legacy root criteria use NULL');
        verify((new IPNetwork())->delete(['id' => $middle], true), 'Purge containing network');
        verify($read($leaf)['ipnetworks_id'] === $broad && $read($leaf)['level'] === 2, 'Purge promotes children to a surviving ancestor');
        $middle = $add($middleRange, 'Middle again ' . $version);
        verify($read($leaf)['ipnetworks_id'] === $middle, 'Recreated intermediate node adopts children');
        verify((new IPNetwork())->update(['id' => $middle, 'network' => $movedRange]), 'Move subnet away from all former children');
        verify($read($middle)['ipnetworks_id'] === null && $read($leaf)['ipnetworks_id'] === $broad, 'Empty potential-child set still detaches former children');
        verify($read($leaf)['level'] === 2 && !isset(getSonsOf('glpi_ipnetworks', $middle)[$leaf]), 'Subnet move regenerates depth and descendant caches');
        $linked = $records()->matching('glpi_ipaddresses_ipnetworks', ['ipnetworks_id' => $middle]);
        verify(array_column($linked, 'ipaddresses_id') === [$out], 'Subnet change rebuilds address memberships');
        verify((new IPNetwork())->update(['id' => $middle, 'network' => $middleRange]), 'Move subnet back');
        verify($read($middle)['ipnetworks_id'] === $broad && $read($leaf)['ipnetworks_id'] === $middle, 'Returning subnet restores nearest parent and children');
        verify((new IPNetwork())->delete(['id' => $broad], true), 'Purge root network');
        verify($read($middle)['ipnetworks_id'] === null && $read($middle)['level'] === 1 && $read($leaf)['level'] === 2, 'Root purge promotes children with native NULL root');
        verify((new IPNetwork())->delete(['id' => $middle], true), 'Purge remaining parent');
        verify($read($leaf)['ipnetworks_id'] === null && $read($leaf)['level'] === 1, 'Last parent purge promotes leaf');
        $broad = $add($broadRange, 'Rebuild broad ' . $version);
        $middle = $add($middleRange, 'Rebuild middle ' . $version);
        getSonsOf('glpi_ipnetworks', $broad);
        getAncestorsOf('glpi_ipnetworks', $leaf);
        (new TreeRepository(Orm::create($DB)))->reparent('glpi_ipnetworks', 'ipnetworks_id', [$leaf], null);
        (new TreeRepository(Orm::create($DB)))->updateDerived('glpi_ipnetworks', [$leaf], ['level' => 99, 'completename' => 'Corrupt derived name']);
        IPNetwork::recreateTree();
        verify($read($middle)['ipnetworks_id'] === $broad && $read($leaf)['ipnetworks_id'] === $middle && $read($leaf)['level'] === 3, 'Rebuild repairs hierarchy and depth');
        verify(str_contains($read($leaf)['completename'], "Rebuild broad $version > Rebuild middle $version > Leaf O'Reilly"), 'Rebuild repairs derived names');
        verify(array_values(array_map('intval', getAncestorsOf('glpi_ipnetworks', $leaf))) === [$broad, $middle], 'Rebuild invalidates warm caches');
        $before = [$read($broad), $read($middle), $read($leaf)];
        $invalid = $fixtures->create('glpi_ipnetworks', ['name' => 'Invalid network rebuild fixture']);
        verify($reject(IPNetwork::recreateTree(...), RuntimeException::class, 'Unable to rebuild IP network tree'), 'Invalid node aborts rebuild');
        verify([$read($broad), $read($middle), $read($leaf)] === $before, 'Failed rebuild rolls back parent and derived changes');
        (new RecordWriter(Orm::create($DB)))->delete('glpi_ipnetworks', $invalid);
        verify(isset(getSonsOf('glpi_ipnetworks', $broad)[$leaf]), 'Failed rebuild discards rolled-back cache values');
        $other = (int)(new IPNetwork())->add(['network' => $broadRange, 'name' => 'Other entity network', 'entities_id' => $outside]);
        verify($other > 0 && $read($other)['ipnetworks_id'] === null, 'Sibling entities do not become network parents');
        verify((new IPNetworkRepository(Orm::create($DB)))->containedAddresses($leaf) === [$inside, $crossScope], 'Mapped address membership matches the model');
    }
    verify($SQL_TOTAL_REQUEST === 0, 'Implicit network lifecycle, memberships and rebuild use ORM: ' . json_encode($DEBUG_SQL['queries'] ?? []));
    verify((new ForeignKeys())->audit($connection) === [], 'Network lifecycle leaves no dangling references');
} finally {
    $DB->rollBack();
}

// Restore the legacy parent column in this disposable installation and exercise upgrade audits.
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new IPNetworkParentReferences();
$created = [];
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_ipnetworks', 'ipnetworks_id'), 'glpi_ipnetworks'));
    $connection->executeStatement('UPDATE glpi_ipnetworks SET ipnetworks_id = 0 WHERE ipnetworks_id IS NULL');
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable('glpi_ipnetworks');
    $after = clone $before;
    $after->getColumn('ipnetworks_id')->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $root = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_ipnetworks');
    $child = $root + 1;
    foreach ([$root => 0, $child => $root] as $id => $parent) {
        $connection->insert('glpi_ipnetworks', ['id' => $id, 'name' => 'Legacy network migration ' . $id, 'ipnetworks_id' => $parent]);
        $created[] = $id;
    }
    $connection->update('glpi_ipnetworks', ['ipnetworks_id' => 2147483647], ['id' => $child]);
    verify($reject(fn () => $migration->apply($connection), RuntimeException::class, 'Nonzero orphaned IP network parent'), 'Orphan aborts migration');
    verify($connection->createSchemaManager()->introspectTable('glpi_ipnetworks')->getColumn('ipnetworks_id')->getNotnull(), 'Orphan audit precedes DDL');
    $connection->update('glpi_ipnetworks', ['ipnetworks_id' => $root], ['id' => $child]);
    $connection->update('glpi_ipnetworks', ['ipnetworks_id' => $child], ['id' => $root]);
    verify($reject(fn () => $migration->apply($connection), RuntimeException::class, 'Cyclic tree parents'), 'Parent cycle aborts migration');
    verify($connection->createSchemaManager()->introspectTable('glpi_ipnetworks')->getColumn('ipnetworks_id')->getNotnull(), 'Cycle audit precedes DDL');
    $connection->update('glpi_ipnetworks', ['ipnetworks_id' => $root], ['id' => $root]);
    verify($reject(fn () => $migration->apply($connection), RuntimeException::class, 'Cyclic tree parents'), 'Self cycle aborts migration');
    $connection->update('glpi_ipnetworks', ['ipnetworks_id' => 0], ['id' => $root]);
    $plan = $migration->plan($connection);
    verify($plan['sql'] && $plan['counts']['glpi_ipnetworks.ipnetworks_id'] > 0, 'Legacy plan records root conversion');
    verify($connection->fetchOne('SELECT ipnetworks_id FROM glpi_ipnetworks WHERE id = ?', [$root]) == 0, 'Plan does not mutate data');
    $migration->apply($connection);
    verify($read($root)['ipnetworks_id'] === null && $read($child)['ipnetworks_id'] === $root, 'Migration normalizes roots and preserves parents');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []], 'Migration retry is empty');
} finally {
    foreach (array_reverse($created) as $id) {
        $connection->delete('glpi_ipnetworks', ['id' => $id]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": implicit network hierarchy, ORM lifecycle, membership, rebuild rollback and migration passed.\n";
