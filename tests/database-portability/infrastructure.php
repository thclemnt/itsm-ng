<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/infrastructure.php /path/to/test-config\n");
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
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $model = $fixtures->create('glpi_computermodels', ['name' => 'Infrastructure computer model', 'required_units' => 1]);
    $asset = $fixtures->create('glpi_computers', ['name' => 'Infrastructure asset', 'computermodels_id' => $model]);
    $tables = ['glpi_appliances_items', 'glpi_appliances_items_relations', 'glpi_certificates_items', 'glpi_domainrecords', 'glpi_domains_items', 'glpi_items_clusters', 'glpi_items_enclosures', 'glpi_items_racks', 'glpi_pdus_plugs', 'glpi_pdus_racks'];
    $tested = 0;
    foreach ($tables as $table) {
        $metadata = Orm::create($DB)->getClassMetadata(EntityRegistry::tables()[$table]);
        $values = $metadata->hasField('itemtype') ? ['itemtype' => 'Computer', 'items_id' => $asset] : [];
        if ($table === 'glpi_appliances_items_relations') {
            $values = ['itemtype' => 'Location', 'items_id' => $fixtures->create('glpi_locations')];
        }
        $containerRelations = ForeignKeys::relations()[$table];
        foreach ($metadata->associationMappings as $property => $mapping) {
            if ($mapping->isToOneOwningSide()
                && (new ReflectionProperty($metadata->name, $property))->getAttributes(\itsmng\Database\Mapping\DiscriminatedBy::class)) {
                // Typed asset branches have their own complete placement lifecycle contract.
                unset($containerRelations[$mapping->joinColumns[0]->name]);
            }
        }
        foreach ($containerRelations as $column => $parentTable) {
            if (isset(ReferenceHistory::get('optional', 'RELATIONS')[$table][$column])) {
                continue;
            }
            // Entity ownership reassigns children; its lifecycle has a separate contract.
            if (isset(ReferenceHistory::get('ownership', 'RELATIONS')[$table][$column])) {
                continue;
            }
            $parentValues = $parentTable === 'glpi_appliances_items' ? ['itemtype' => 'Computer', 'items_id' => $asset] : [];
            $parent = $fixtures->create($parentTable, $parentValues);
            $child = $fixtures->create($table, [$column => $parent] + $values);
            $otherValues = $values;
            if (isset($values['items_id'])) {
                $otherValues['items_id'] = $fixtures->create($table === 'glpi_appliances_items_relations' ? 'glpi_locations' : 'glpi_computers', ['name' => 'Unrelated infrastructure asset']);
            }
            $unrelated = $fixtures->create($table, $otherValues);
            $nested = null;
            if ($table === 'glpi_appliances_items') {
                $nested = $fixtures->create('glpi_appliances_items_relations', ['appliances_items_id' => $child, 'itemtype' => 'Location', 'items_id' => $fixtures->create('glpi_locations')]);
            }
            $type = getItemTypeForTable($parentTable);
            $object = new $type();
            verify($object->getFromDB($parent), 'Load parent ' . $type);
            verify($object->delete(['id' => $parent], true), 'Purge parent under restrictive FK: ' . $table . '.' . $column);
            verify($read($table, $child) === null, 'Purge removes dependent record: ' . $table);
            verify($read($table, $unrelated) !== null, 'Purge preserves unrelated record: ' . $table);
            if ($nested !== null) {
                verify($read('glpi_appliances_items_relations', $nested) === null, 'Appliance purge reaches nested relation hooks');
            }
            ++$tested;
        }
    }
    verify($tested === 12, 'All twelve new FK purge paths exercised');

    $domainId = $fixtures->create('glpi_domains', ['name' => 'infrastructure.example']);
    $typeA = $fixtures->create('glpi_domainrecordtypes', ['name' => 'A-type']);
    $typeZ = $fixtures->create('glpi_domainrecordtypes', ['name' => 'Z-type']);
    $recordZ = $fixtures->create('glpi_domainrecords', ['domains_id' => $domainId, 'domainrecordtypes_id' => $typeZ, 'name' => 'aaa', 'data' => 'target-z', 'ttl' => 3600]);
    $recordB = $fixtures->create('glpi_domainrecords', ['domains_id' => $domainId, 'domainrecordtypes_id' => $typeA, 'name' => 'bbb', 'data' => 'target-b', 'ttl' => 3600]);
    $recordA = $fixtures->create('glpi_domainrecords', ['domains_id' => $domainId, 'domainrecordtypes_id' => $typeA, 'name' => 'aaa', 'data' => 'target-a', 'ttl' => 3600]);
    $untypedRecord = $fixtures->create('glpi_domainrecords', ['domains_id' => $domainId, 'name' => 'No type', 'ttl' => 3600]);
    $unnamedRecord = $fixtures->create('glpi_domainrecords', ['domains_id' => $domainId, 'domainrecordtypes_id' => $typeA, 'name' => null, 'ttl' => 3600]);
    $repository = new \itsmng\Database\Repository\DomainRepository(Orm::create($DB));
    verify(array_column($repository->records($domainId), 'id') === [$untypedRecord, $unnamedRecord, $recordA, $recordB, $recordZ], 'Domain records order by mapped type then name');
    $domain = new Domain();
    verify($domain->getFromDB($domainId), 'Load domain');
    ob_start();
    DomainRecord::showForDomain($domain);
    $html = ob_get_clean();
    verify(str_contains($html, 'target-a') && str_contains($html, 'target-z'), 'Public domain record view');

    $rackId = $fixtures->create('glpi_racks', ['name' => 'Infrastructure rack', 'number_units' => 42]);
    $rack = new Rack();
    verify($rack->getFromDB($rackId), 'Load rack');
    $first = $fixtures->create('glpi_pdus_racks', ['racks_id' => $rackId, 'side' => PDU_Rack::SIDE_LEFT, 'position' => 2]);
    $second = $fixtures->create('glpi_pdus_racks', ['racks_id' => $rackId, 'side' => PDU_Rack::SIDE_LEFT, 'position' => 8]);
    $opposite = $fixtures->create('glpi_pdus_racks', ['racks_id' => $rackId, 'side' => PDU_Rack::SIDE_RIGHT, 'position' => 5]);
    verify(array_column(iterator_to_array(PDU_Rack::getForRackSide($rack, PDU_Rack::SIDE_LEFT)), 'id') === [$first, $second], 'Rack side query preserves ordered iterator contract');
    verify(array_column(iterator_to_array(PDU_Rack::getForRackSide($rack, [PDU_Rack::SIDE_LEFT, PDU_Rack::SIDE_RIGHT])), 'id') === [$first, $opposite, $second], 'Multiple rack sides use a mapped IN predicate');
    verify(in_array($first, array_column(iterator_to_array(PDU_Rack::getUsed()), 'id'), true), 'Used PDU relations remain iterable');

    $enclosureId = $fixtures->create('glpi_enclosures', ['name' => 'Infrastructure enclosure']);
    $fixtures->create('glpi_items_enclosures', ['enclosures_id' => $enclosureId, 'itemtype' => 'Computer', 'items_id' => $asset, 'position' => 3]);
    $enclosure = new Enclosure();
    verify($enclosure->getFromDB($enclosureId), 'Load enclosure');
    verify($enclosure->getFilled() === [3 => 3] && $enclosure->getFilled('Computer', $asset) === [], 'Enclosure placement excludes the current asset when requested');
    foreach ([['Cluster', 'glpi_clusters', 'glpi_items_clusters', 'clusters_id', Item_Cluster::class], ['Appliance', 'glpi_appliances', 'glpi_appliances_items', 'appliances_id', Appliance_Item::class]] as [$type, $parentTable, $table, $column, $linkType]) {
        $parent = $fixtures->create($parentTable, ['name' => 'Rendered ' . $type]);
        $link = $fixtures->create($table, [$column => $parent, 'itemtype' => 'Computer', 'items_id' => $asset]);
        $object = new $type();
        verify($object->getFromDB($parent), 'Load view parent');
        ob_start();
        $linkType::showItems($object);
        $html = ob_get_clean();
        verify(str_contains($html, 'Infrastructure asset'), 'Mapped ' . $type . ' assignment view');
    }
    ob_start();
    Item_Enclosure::showItems($enclosure);
    $html = ob_get_clean();
    verify(str_contains($html, 'Infrastructure asset'), 'Mapped enclosure assignment view');

    $rackAsset = $fixtures->create('glpi_computers', ['name' => 'Rendered rack asset', 'computermodels_id' => $model]);
    $fixtures->create('glpi_items_racks', ['racks_id' => $rackId, 'itemtype' => 'Computer', 'items_id' => $rackAsset, 'position' => 4]);
    ob_start();
    Item_Rack::showItems($rack);
    $html = ob_get_clean();
    verify(str_contains($html, 'Rendered rack asset'), 'Mapped rack assignment view');
    $pduId = $fixtures->create('glpi_pdus', ['name' => 'Rendered PDU']);
    $plugId = $fixtures->create('glpi_plugs', ['name' => 'Rendered plug']);
    $fixtures->create('glpi_pdus_plugs', ['pdus_id' => $pduId, 'plugs_id' => $plugId]);
    $pdu = new PDU();
    verify($pdu->getFromDB($pduId), 'Load PDU');
    ob_start();
    Pdu_Plug::showItems($pdu);
    $html = ob_get_clean();
    verify(str_contains($html, 'Rendered plug'), 'Mapped PDU plug view');
    $appLink = $fixtures->create('glpi_appliances_items', ['itemtype' => 'Computer', 'items_id' => $asset]);
    $location = $fixtures->create('glpi_locations', ['name' => 'Appliance relation location']);
    $fixtures->create('glpi_appliances_items_relations', ['appliances_items_id' => $appLink, 'itemtype' => 'Location', 'items_id' => $location]);
    verify(str_contains(implode('', Appliance_Item_Relation::getForApplianceItem($appLink)), 'Appliance relation location'), 'Mapped nested appliance relation labels');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->records($domainId);
    PDU_Rack::getForRackSide($rack, PDU_Rack::SIDE_LEFT);
    PDU_Rack::getUsed();
    $enclosure->getFilled();
    verify($SQL_TOTAL_REQUEST === 0, 'Mapped domain records and placement queries bypass the adapter');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphaned relationships');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": twelve infrastructure FK lifecycles, domain records, placement queries and assignment views passed.\n";
