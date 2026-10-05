<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDbPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\V220\CertificateAssets;
use itsmng\Database\Migration\V220\DomainAssets;
use itsmng\Database\Migration\V220\ClusterAssets;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DomainAssetRepository;
use itsmng\Database\Repository\LinkRepository;
use itsmng\Database\Repository\PlacementRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/infrastructure-assets.php /path/to/test-config\n");
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
$nativeExact = new ExactSubjectHistoricalFixture($connection, ['glpi_certificates_items', 'glpi_domains_items', 'glpi_items_clusters'], captureTableDeclarations: false, preserveLedger: true);
$nativeExactFailure = null;
try {
    $nativeExact->beginOwnedAlteration();
    foreach ([new CertificateAssets(), new DomainAssets(), new ClusterAssets()] as $migration) {
        $migration->apply($connection);
    }
    $DB->clearSchemaCache();
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Login');
    $_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
    $configuration = $CFG_GLPI;
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $storage = new MappedStorage($DB);
    $read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
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
    $scopes = [
        ['Certificate', 'glpi_certificates_items', 'certificates_id', Record\CertificateItem::class, Certificate_Item::class, new CertificateAssets(), 'certificate_types'],
        ['Domain', 'glpi_domains_items', 'domains_id', Record\DomainItem::class, Domain_Item::class, new DomainAssets(), 'domain_types'],
        ['Cluster', 'glpi_items_clusters', 'clusters_id', Record\ItemCluster::class, Item_Cluster::class, new ClusterAssets(), 'cluster_types'],
    ];
    $DB->beginTransaction();
    try {
        $sameId = 4294968751;
        $subjects = $links = $parents = [];
        $owner = $fixtures->create('glpi_entities');
        $category = $fixtures->create('glpi_domainrelations', ['name' => 'Selected relation']);
        $total = 0;
        foreach ($scopes as [$parentType, $table, $parentColumn, $class, $modelClass, $migration, $configurationKey]) {
            $parentTable = (new $parentType())->getTable();
            $parent = $fixtures->create($parentTable, ['name' => "Linked $parentType O'Reilly", 'entities_id' => $owner]);
            $parents[$parentType] = $parent;
            $parentAssociation = Orm::create($DB)->getClassMetadata($class)->getAssociationMapping($parentColumn === 'clusters_id' ? 'clusters' : ($parentColumn === 'domains_id' ? 'domains' : 'certificates'));
            $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
            $expected = $CFG_GLPI[$configurationKey];
            $actual = array_keys($branches);
            sort($expected);
            sort($actual);
            verify($actual === $expected, 'All configured owning branches: ' . $parentType);
            foreach ($branches as $kind => $selection) {
                if (!isset($subjects[$kind])) {
                    $subjects[$kind] = $fixtures->create($selection['target'], ['id' => $sameId, 'name' => "Asset $kind O'Reilly", 'entities_id' => $owner]);
                }
                $values = [$parentColumn => $parent, 'itemtype' => $kind, 'items_id' => $sameId];
                if ($class === Record\DomainItem::class) {
                    $values['domainrelations_id'] = $category;
                }
                $model = new $modelClass();
                $id = $model->add($values);
                verify($id > 0 && $model->fields[$selection['column']] === $sameId && $model->fields['items_id'] === $sameId, 'Public owning subject: ' . $parentType . '/' . $kind);
                $links[$table][$kind] = $id;
                $reject(static fn () => $connection->insert($table, [$parentColumn => $parent, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Orphan rejected: ' . $kind);
                $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Subject delete restricted: ' . $kind);
                $reject(static fn () => $connection->insert($table, [$parentColumn => $parent, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate rejected: ' . $kind);
                $manager = Orm::create($DB);
                $native = new $class();
                $native->{$parentAssociation->fieldName} = $manager->getReference($parentAssociation->targetEntity, $parent);
                $native->itemtype = $kind;
                $property = $class::referenceAssociation($kind);
                $target = $manager->getClassMetadata($class)->getAssociationTargetClass($property);
                $other = $fixtures->create($selection['target']);
                $native->{$property} = $manager->getReference($target, $other);
                $manager->persist($native);
                $manager->flush();
                verify($native->items_id === $other, 'Native owning subject projection: ' . $parentType . '/' . $kind);
                $manager->remove($native);
                $manager->flush();
                $manager->clear();
                ++$total;
            }
            foreach ([[], ['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'computers_id' => -1], ['itemtype' => 'Computer', 'networkequipments_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'networkequipments_id' => $sameId]] as $invalid) {
                $reject(static fn () => $connection->insert($table, $invalid + [$parentColumn => $parent]), 'Missing/unknown/negative/wrong/multiple branch rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null, $table . '_typed_item_kind');
            }
            $otherComputer = $fixtures->create('glpi_computers');
            $retarget = $fixtures->create($table, [$parentColumn => $parent, 'itemtype' => 'Computer', 'items_id' => $otherComputer]);
            $otherEquipment = $fixtures->create('glpi_networkequipments');
            $storage->update($table, $retarget, $class::withReference([], 'NetworkEquipment', $otherEquipment));
            verify($read($table, $retarget)['computers_id'] === null && $read($table, $retarget)['networkequipments_id'] === $otherEquipment, 'Retarget clears old subject branch');
            $storage->delete($table, $retarget);
        }
        verify($total === 20, 'Twenty native and public subject graphs');
        $repo = new DomainAssetRepository(Orm::create($DB));
        $scope = ['entities_id' => $owner];
        verify(count($repo->types($parents['Domain'], 9)) === 9 && count($repo->types($parents['Domain'], 2)) === 2, 'Domain kind selection remains bounded');
        foreach (array_keys($links['glpi_domains_items']) as $kind) {
            $rows = $repo->assets($parents['Domain'], $kind, $scope);
            verify(count($rows) === 1 && $rows[0]['id'] === $sameId && $rows[0]['items_id'] === $links['glpi_domains_items'][$kind]
                && $rows[0]['domainrelations_id'] === $category && $rows[0]['entity'] === $owner, 'Owning asset list keeps subject and association/category IDs separate: ' . $kind);
            verify(array_column($repo->domains($kind, $sameId, $scope), 'id') === [$parents['Domain']], 'Owning domain list: ' . $kind);
        }
        verify(count($repo->domains('DomainRelation', $category, $scope, true)) === 9, 'Relation-category listing selects its separate association');
        verify($repo->domains('DomainRelation', $category, $scope) === [] && $repo->assets($parents['Domain'], 'User', $scope) === [], 'Unsupported subjects do not become a category or another branch');
        verify($repo->assets($parents['Domain'], 'Computer', ['entities_id' => 0]) === [] && $repo->domains('Computer', $sameId, ['entities_id' => 0]) === [], 'Asset and parent domain scopes are independent');
        $template = $fixtures->create('glpi_computers', ['entities_id' => $owner, 'is_template' => true]);
        $fixtures->create('glpi_domains_items', ['domains_id' => $parents['Domain'], 'itemtype' => 'Computer', 'items_id' => $template]);
        verify(count($repo->assets($parents['Domain'], 'Computer', $scope)) === 2 && count($repo->assets($parents['Domain'], 'Computer', $scope + ['is_template' => false])) === 1, 'Domain asset list honors template criteria');
        $SQL_TOTAL_REQUEST = 0;
        verify((new LinkRepository(Orm::create($DB)))->domainName('Computer', $sameId) === "Linked Domain O'Reilly", 'External domain lookup uses owning subject');
        $selection = (new PlacementRepository(Orm::create($DB)))->clusterSelection();
        verify(in_array($sameId, $selection['Computer'], true) && in_array($sameId, $selection['NetworkEquipment'], true) && $SQL_TOTAL_REQUEST === 0, 'Cluster selection keeps overlapping wide IDs and uses ORM');
        $domain = new Domain();
        verify($domain->getFromDB($parents['Domain']), 'Load domain view');
        $_SESSION['glpiactiveentities'] = [$owner];
        $_SESSION['glpiactive_entity'] = $owner;
        ob_start();
        Domain_Item::showForDomain($domain);
        $html = ob_get_clean();
        verify(str_contains($html, "Asset Computer O'Reilly") && str_contains($html, 'Selected relation'), 'Public domain asset rendering');
        $computer = new Computer();
        verify($computer->getFromDB($sameId), 'Load subject view');
        ob_start();
        Domain_Item::showForItem($computer);
        $html = ob_get_clean();
        verify(str_contains($html, "Linked Domain O'Reilly"), 'Public subject domain rendering');
        foreach ($subjects as $kind => $id) {
            $subject = new $kind();
            verify($subject->delete(['id' => $id], true), 'Public subject purge: ' . $kind);
            foreach ($links as $table => $byKind) {
                if (isset($byKind[$kind])) {
                    verify($read($table, $byKind[$kind]) === null, 'Purge removes selected subject links: ' . $kind);
                }
            }
        }
        foreach ($scopes as [$parentType, $table, $parentColumn]) {
            $parent = $fixtures->create((new $parentType())->getTable());
            $child = $fixtures->create($table, [$parentColumn => $parent, 'itemtype' => 'Computer']);
            verify((new $parentType())->delete(['id' => $parent], true) && $read($table, $child) === null, 'Public parent purge: ' . $parentType);
        }
        verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references');
    } finally {
        $DB->rollBack();
        $CFG_GLPI = $configuration;
    }
    // Frozen upgrade checks reconstruct only these owned disposable fixture tables.
    $manager = $connection->createSchemaManager();
    $platform = $connection->getDatabasePlatform();
    foreach ($scopes as [$type, $table, $parentColumn, $class, $modelClass, $migration]) {
        $computer = $fixtures->create('glpi_computers', ['id' => 950000202]);
        $parentTable = (new $type())->getTable();
        $parent = $fixtures->create($parentTable);
        $id = null;
        try {
            $drop = $platform instanceof PostgreSQLPlatform || $platform instanceof MariaDbPlatform ? ' DROP CONSTRAINT ' : ' DROP CHECK ';
            $connection->executeStatement('ALTER TABLE ' . $table . $drop . $table . '_typed_item_kind');
            $before = $manager->introspectTable($table);
            $identityComment = $before->getColumn('items_id')->getComment();
            $indexes = array_filter($before->getIndexes(), static fn ($index) => in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true));
            // MariaDB may use the composite unique index to support the parent FK.
            // Keep that FK enforceable while reconstructing the old scalar identity.
            $support = new \Doctrine\DBAL\Schema\Index($table . '_fixture_parent', [$parentColumn]);
            $connection->executeStatement($platform->getCreateIndexSQL($support, $table));
            foreach ($indexes as $index) {
                $connection->executeStatement($platform->getDropIndexSQL($index->getName(), $table));
            }
            $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN items_id');
            $before = $manager->introspectTable($table);
            $legacy = clone $before;
            $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
            $columns = array_column($branches, 'column');
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
            $legacy->addColumn('items_id', 'integer', ['default' => 0, 'comment' => $identityComment]);
            foreach ($indexes as $index) {
                if ($index->isUnique()) {
                    $legacy->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
                } else {
                    $legacy->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                }
            }
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $legacy)) as $sql) {
                $connection->executeStatement($sql);
            }
            $connection->executeStatement($platform->getDropIndexSQL($support->getName(), $table));
            $connection->insert($table, [$parentColumn => $parent, 'itemtype' => 'Computer', 'items_id' => $computer]);
            $id = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
            foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => -1]] as $bad) {
                $connection->insert($table, $bad + [$parentColumn => $parent]);
                $badId = (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $table);
                $failed = false;
                try {
                    $migration->apply($connection);
                } catch (RuntimeException $error) {
                    $failed = str_contains($error->getMessage(), 'Invalid or unsupported');
                }
                verify($failed && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Invalid legacy asset refuses before DDL: ' . $type);
                $connection->delete($table, ['id' => $badId]);
            }
            $connection->executeStatement('ALTER TABLE ' . $table . ' ADD computers_id BIGINT NULL');
            $connection->update($table, ['computers_id' => $computer + 1], ['id' => $id]);
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'disagree');
            }
            verify($failed && !$manager->introspectTable($table)->hasColumn('monitors_id'), 'Conflicting canonical/legacy asset refuses before DDL: ' . $type);
            $connection->update($table, ['computers_id' => $computer], ['id' => $id]);
            $migration->apply($connection);
            $DB->clearSchemaCache();
            $row = $read($table, $id);
            verify($row['computers_id'] === $computer && $row['items_id'] === $computer && $row[$parentColumn] === $parent, 'Upgrade preserves parent/asset/relation identifiers: ' . $type);
            verify($manager->introspectTable($table)->getColumn('items_id')->getComment() === $identityComment, 'Upgrade preserves the legacy identity comment: ' . $type);
            foreach ($indexes as $index) {
                verify($manager->introspectTable($table)->getIndex($index->getName())->isUnique() === $index->isUnique(), 'Upgrade preserves index uniqueness: ' . $type);
            }
            foreach ($migration->apply($connection) as $entry) {
                verify(!$entry['sql'] && !$entry['key_sql'] && !$entry['constraint_sql'], 'Upgrade retry is idempotent: ' . $type);
            }
        } finally {
            if ($id !== null) {
                $connection->delete($table, ['id' => $id]);
            }
            $connection->delete($parentTable, ['id' => $parent]);
            $connection->delete('glpi_computers', ['id' => $computer]);
        }
    }

} catch (Throwable $error) {
    $nativeExactFailure = $error;
} finally {
    $nativeExact->restorePreservingFailure($nativeExactFailure);
}
echo "PASS: twenty infrastructure asset FKs, native/public graphs, scoped owning queries, category roles, purge and frozen upgrades\n";
