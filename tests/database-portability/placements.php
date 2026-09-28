<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/placements.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
use itsmng\Database\Orm;
use itsmng\Database\Repository\PlacementRepository;

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $foreignEntity = (new Entity())->add(['name' => 'Foreign placement entity', 'entities_id' => 0]);
    verify((bool)$foreignEntity, 'Create foreign entity');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [0];
    $roomId = $fixtures->create('glpi_dcrooms', ['name' => 'Placement room', 'vis_rows' => 3, 'vis_cols' => 3]);
    $rackId = $fixtures->create('glpi_racks', ['name' => 'Visible placement rack', 'dcrooms_id' => $roomId, 'number_units' => 42, 'position' => '1,1', 'bgcolor' => '#ffffff', 'max_weight' => 100, 'max_power' => 1000]);
    $foreignRack = $fixtures->create('glpi_racks', ['name' => 'Foreign placement rack', 'entities_id' => $foreignEntity, 'number_units' => 42]);
    $fixtures->create('glpi_racks', ['name' => 'Deleted placement rack', 'dcrooms_id' => $roomId, 'is_deleted' => true]);
    $fullModel = $fixtures->create('glpi_computermodels', ['name' => 'Full rack model', 'required_units' => 2, 'depth' => 1, 'is_half_rack' => false, 'weight' => 10, 'power_consumption' => 100]);
    $halfModel = $fixtures->create('glpi_computermodels', ['name' => 'Half rack model', 'required_units' => 1, 'depth' => 0.5, 'is_half_rack' => true, 'weight' => 5, 'power_consumption' => 50]);
    $full = $fixtures->create('glpi_computers', ['name' => 'Full rack asset', 'computermodels_id' => $fullModel]);
    $reserved = $fixtures->create('glpi_computers', ['name' => 'Reserved half asset', 'computermodels_id' => $halfModel]);
    $foreignAsset = $fixtures->create('glpi_computers', ['name' => 'Foreign rack asset', 'entities_id' => $foreignEntity]);
    $enclosed = $fixtures->create('glpi_computers', ['name' => 'Enclosed asset']);
    $free = $fixtures->create('glpi_computers', ['name' => 'Free placement asset']);
    $fullAssignment = $fixtures->create('glpi_items_racks', ['racks_id' => $rackId, 'itemtype' => 'Computer', 'items_id' => $full, 'position' => 1, 'orientation' => Rack::FRONT, 'hpos' => Rack::POS_NONE]);
    $reservedAssignment = $fixtures->create('glpi_items_racks', ['racks_id' => $rackId, 'itemtype' => 'Computer', 'items_id' => $reserved, 'position' => 5, 'orientation' => Rack::REAR, 'hpos' => Rack::POS_LEFT, 'is_reserved' => true]);
    $fixtures->create('glpi_items_racks', ['racks_id' => $foreignRack, 'itemtype' => 'Computer', 'items_id' => $foreignAsset]);
    $enclosure = $fixtures->create('glpi_enclosures', ['name' => 'Placement enclosure']);
    $fixtures->create('glpi_items_enclosures', ['enclosures_id' => $enclosure, 'itemtype' => 'Computer', 'items_id' => $enclosed]);
    $cluster = $fixtures->create('glpi_clusters', ['name' => 'Placement cluster']);
    $fixtures->create('glpi_items_clusters', ['clusters_id' => $cluster, 'itemtype' => 'Computer', 'items_id' => $foreignAsset]);
    $sidePdu = $fixtures->create('glpi_pdus', ['name' => 'Side PDU']);
    $rackedPdu = $fixtures->create('glpi_pdus', ['name' => 'Racked PDU']);
    $fixtures->create('glpi_pdus_racks', ['racks_id' => $rackId, 'pdus_id' => $sidePdu, 'position' => 1, 'side' => PDU_Rack::SIDE_LEFT]);
    $fixtures->create('glpi_items_racks', ['racks_id' => $foreignRack, 'itemtype' => 'PDU', 'items_id' => $rackedPdu]);
    $repo = new PlacementRepository(Orm::create($DB));
    $selection = $repo->rackSelection();
    foreach ([$full, $reserved, $foreignAsset, $enclosed] as $id) {
        verify(in_array($id, $selection['used']['Computer'], true), 'Rack exclusion includes all physical placements');
    }
    verify(!in_array($free, $selection['used']['Computer'], true), 'Unassigned asset remains available');
    verify($selection['reserved']['Computer'] === [$reserved], 'Reserved subset uses the typed boolean');
    verify(in_array($sidePdu, $selection['used']['PDU'], true) && in_array($rackedPdu, $selection['used']['PDU'], true), 'Rack selector excludes both PDU placement types');
    $enclosureUsed = $repo->enclosureSelection();
    verify(in_array($full, $enclosureUsed['Computer'], true) && in_array($foreignAsset, $enclosureUsed['Computer'], true) && in_array($enclosed, $enclosureUsed['Computer'], true), 'Enclosure selector excludes installed rack and enclosure assets globally');
    verify(!in_array($reserved, $enclosureUsed['Computer'], true), 'Enclosure selection preserves reservation exception');
    verify($repo->clusterSelection()['Computer'] === [$foreignAsset], 'Cluster selector uses logical memberships only');
    verify(in_array($sidePdu, $repo->pduSelection(), true) && in_array($rackedPdu, $repo->pduSelection(), true), 'PDU selector combines side and normal rack assignments');

    $link = new Item_Rack();
    verify($link->getFromDB($fullAssignment), 'Load rack assignment form');
    ob_start();
    $link->showForm($fullAssignment);
    $formHtml = ob_get_clean();
    $document = new DOMDocument();
    $oldErrors = libxml_use_internal_errors(true);
    $document->loadHTML($formHtml);
    libxml_clear_errors();
    libxml_use_internal_errors($oldErrors);
    $input = (new DOMXPath($document))->query('//input[@id="used_input"]')->item(0);
    verify($input !== null && json_decode($input->getAttribute('value'), true) === $selection['used'], 'Rack form embeds the ORM placement exclusions');
    $xpath = new DOMXPath($document);
    verify($xpath->query('//select[@id="dropdown_items_id"]//option[@value="' . $foreignAsset . '"]')->length === 0, 'Rack form asset choices respect the rack entity');
    verify($xpath->query('//input[@type="checkbox"][@id="dropdown_is_reserved"]')->length === 1, 'Reservation handler has an explicit checkbox target');
    verify(str_contains($formHtml, 'toggleUsed(this.checked ? 1 : 0);') && !str_contains($formHtml, "$('#used_')"), 'Rendered checkbox handler updates the real exclusion input');
    verify($link->getFromDB($reservedAssignment), 'Load reserved assignment form');
    ob_start();
    $link->showForm($reservedAssignment);
    $reservedHtml = ob_get_clean();
    $oldErrors = libxml_use_internal_errors(true);
    $document->loadHTML($reservedHtml);
    libxml_clear_errors();
    libxml_use_internal_errors($oldErrors);
    $xpath = new DOMXPath($document);
    $reservedInput = $xpath->query('//input[@id="used_input"]')->item(0);
    verify($reservedInput !== null && json_decode($reservedInput->getAttribute('value'), true) === $selection['reserved'], 'Reserved edit form starts with the reserved exclusion set');
    verify($xpath->query('//input[@id="dropdown_is_reserved"][@checked]')->length === 1, 'Reserved edit form preserves checkbox state');


    $rack = new Rack();
    verify($rack->getFromDB($rackId), 'Load rack');
    $filled = $rack->getFilled();
    foreach ([1, 2] as $position) {
        verify($filled[$position][Rack::POS_LEFT] === [1, 1, 1, 1] && $filled[$position][Rack::POS_RIGHT] === [1, 1, 1, 1], 'Full depth two-unit placement');
    }
    verify($filled[5][Rack::POS_LEFT] === [0, 0, 1, 1] && $filled[5][Rack::POS_RIGHT] === [0, 0, 0, 0], 'Rear half-depth half-width placement');
    verify(array_keys($rack->getFilled('Computer', $full)) === [5], 'Placement checks exclude the asset being moved');
    ob_start();
    Item_Rack::showStats($rack);
    $html = ob_get_clean();
    verify(str_contains($html, '15 / 100') && str_contains($html, '150 / 1000'), 'Mapped rack stats preserve weight and power totals');
    ob_start();
    PDU_Rack::showListForRack($rack);
    PDU_Rack::showStatsForRack($rack);
    $html = ob_get_clean();
    verify(str_contains($html, 'Side PDU') && !str_contains($html, 'Racked PDU'), 'PDU summaries select the current rack');
    $room = new DCRoom();
    verify($room->getFromDB($roomId), 'Load room');
    ob_start();
    Rack::showForRoom($room);
    $html = ob_get_clean();
    verify(str_contains($html, 'Visible placement rack') && !str_contains($html, 'Deleted placement rack') && !str_contains($html, 'Foreign placement rack'), 'Room view filters by room and deletion status');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repo->rackSelection();
    $repo->enclosureSelection();
    $repo->clusterSelection();
    $repo->pduSelection();
    $rack->getFilled();
    verify($SQL_TOTAL_REQUEST === 0, 'Placement selectors and occupancy use ORM execution');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": global placement exclusions, reservations, rack geometry, statistics and room/PDU views passed.\n";
