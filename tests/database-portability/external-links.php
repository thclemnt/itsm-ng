<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/external-links.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$repo = static fn () => new \itsmng\Database\Repository\LinkRepository(Orm::create($DB));
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'Link scope', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [0, $entity];
    $_SESSION['glpiactiveentities_string'] = '0,' . $entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = 0;
    $outside = $fixtures->create('glpi_entities', ['id' => $entityId + 1, 'name' => 'Outside link scope', 'entities_id' => 0]);
    $computer = $fixtures->create('glpi_computers', ['entities_id' => $entity, 'name' => 'Linked computer']);
    $item = new Computer();
    $item->getFromDB($computer);
    $links = [];
    foreach (['scoped' => [$entity, false], 'recursive' => [0, true], 'denied' => [$outside, true], 'root-only' => [0, false]] as $name => [$scopeEntity, $recursive]) {
        $links[$name] = (new Link())->add(['name' => $name . ' link', 'entities_id' => $scopeEntity, 'is_recursive' => $recursive, 'link' => 'https://example.test/[ID]', 'data' => '', 'open_window' => true]);
        verify($links[$name] > 0, 'Link lifecycle creates definition');
        verify((new Link_Itemtype())->add(['links_id' => $links[$name], 'itemtype' => 'Computer']) > 0, 'Link association lifecycle persists parent');
    }
    $otherType = $fixtures->create('glpi_links', ['name' => 'Different type link', 'entities_id' => $entity, 'data' => '']);
    $fixtures->create('glpi_links_itemtypes', ['links_id' => $otherType, 'itemtype' => 'Printer']);
    $scope = getEntitiesRestrictCriteria('glpi_links', 'entities_id', $entity, true);
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $visible = Link::getLinksDataForItem($item);
    verify(array_values(array_intersect(array_column($visible, 'id'), array_values($links))) === [$links['recursive'], $links['scoped']], 'Item links honor type, entity and recursive ancestry');
    verify($repo()->countForItem('Computer', $scope) === count($visible), 'Count and rendered link list share visibility predicates');
    verify($repo()->countItemtypes($links['scoped']) === 1 && $repo()->itemtypes($links['scoped'])[0]['itemtype'] === 'Computer', 'Association list and count use mapped link');
    verify($SQL_TOTAL_REQUEST === 0, 'Link definition queries bypass adapter SQL');
    $link = new Link();
    $link->getFromDB($links['scoped']);
    ob_start();
    Link_Itemtype::showForLink($link);
    $form = ob_get_clean();
    verify(str_contains($form, 'tab_associated_itemtypes'), 'Association configuration form renders');
    ob_start();
    Link::showForItem($item);
    $html = ob_get_clean();
    verify(str_contains($html, 'scoped link') && str_contains($html, 'recursive link') && !str_contains($html, 'denied link'), 'Real external-link rendering retains visibility');
    $oldCount = $_SESSION['glpishow_count_on_tabs'];
    $_SESSION['glpishow_count_on_tabs'] = true;
    try {
        $tab = (new Link())->getTabNameForItem($item);
        verify(str_contains($tab, (string)count($visible)), 'Link tab uses mapped count');
    } finally {
        $_SESSION['glpishow_count_on_tabs'] = $oldCount;
    }
    $domain = $fixtures->create('glpi_domains', ['name' => 'first.example.test']);
    $laterDomain = $fixtures->create('glpi_domains', ['name' => 'later.example.test']);
    $fixtures->create('glpi_domains_items', ['domains_id' => $laterDomain, 'itemtype' => 'Computer', 'items_id' => $computer]);
    $fixtures->create('glpi_domains_items', ['domains_id' => $domain, 'itemtype' => 'Computer', 'items_id' => $computer]);
    verify(Link::generateLinkContents('https://[DOMAIN]/[ID]', $item, false) === ['https://first.example.test/' . $computer], 'Domain tag selects a deterministic mapped domain');
    verify($repo()->domainName('Printer', $computer) === null, 'Domain association respects polymorphic item type');
    $ports = [];
    foreach (['first' => 'aa:bb:cc:00:00:01', 'duplicate' => 'aa:bb:cc:00:00:01', 'second' => 'aa:bb:cc:00:00:02', 'empty' => ''] as $name => $mac) {
        $ports[$name] = $fixtures->create('glpi_networkports', ['itemtype' => 'Computer', 'items_id' => $computer, 'name' => $name, 'mac' => $mac]);
    }
    $networkName = $fixtures->create('glpi_networknames', ['itemtype' => 'NetworkPort', 'items_id' => $ports['first'], 'name' => 'mapped-host']);
    $ip1 = $fixtures->create('glpi_ipaddresses', ['itemtype' => 'NetworkName', 'items_id' => $networkName, 'name' => '192.0.2.10']);
    $ip2 = $fixtures->create('glpi_ipaddresses', ['itemtype' => 'NetworkName', 'items_id' => $networkName, 'name' => '192.0.2.11']);
    $fixtures->create('glpi_networknames', ['itemtype' => 'Computer', 'items_id' => $ports['second'], 'name' => 'wrong-polymorphic-type']);
    $SQL_TOTAL_REQUEST = 0;
    $addresses = $repo()->portAddresses('Computer', $computer);
    verify(array_column($addresses, 'id') === [$ip1, $ip2], 'Port address join uses both polymorphic discriminators');
    $macs = $repo()->portMacs('Computer', $computer, false);
    verify(array_column($macs, 'id') === [$ports['first'], $ports['second'], $ports['empty']], 'MAC grouping deterministically chooses the minimum port ID');
    verify(array_column($repo()->portMacs('Computer', $computer, true), 'id') === [$ports['duplicate'], $ports['second'], $ports['empty']], 'Unnamed-port selection excludes only actual network-name associations');
    $generated = Link::generateLinkContents('https://example.test/[IP]/[MAC]', $item, false);
    verify(array_values($generated) === ['https://example.test/192.0.2.10/aa:bb:cc:00:00:01', 'https://example.test/192.0.2.11/aa:bb:cc:00:00:01'], 'IP/MAC tags retain every address and suppress missing IPs');
    verify(array_values(Link::generateLinkContents('[MAC]', $item, false)) === ['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02'], 'MAC-only tag deduplicates ports and skips blank MACs');
    verify($SQL_TOTAL_REQUEST === 0, 'Network tag queries bypass adapter SQL');
    $equipment = $fixtures->create('glpi_networkequipments', ['name' => 'Linked router']);
    $directName = $fixtures->create('glpi_networknames', ['itemtype' => 'NetworkEquipment', 'items_id' => $equipment]);
    $fixtures->create('glpi_ipaddresses', ['itemtype' => 'NetworkName', 'items_id' => $directName, 'name' => '192.0.2.20']);
    $router = new NetworkEquipment();
    $router->getFromDB($equipment);
    verify(array_values(Link::generateLinkContents('[IP]', $router, false)) === ['192.0.2.20'], 'Equipment-level network names work without a removed equipment MAC field');
    $fixtures->create('glpi_networkports', ['itemtype' => 'NetworkEquipment', 'items_id' => $equipment, 'mac' => 'aa:bb:cc:00:00:03']);
    verify(array_values(Link::generateLinkContents('[MAC]', $router, false)) === ['aa:bb:cc:00:00:03'], 'Equipment MAC tags use its network ports');
    $pluginBinding = $fixtures->create('glpi_links_itemtypes', ['links_id' => $links['scoped'], 'itemtype' => 'PluginSampleAsset']);
    $otherBinding = $fixtures->create('glpi_links_itemtypes', ['links_id' => $links['scoped'], 'itemtype' => 'PluginOtherAsset']);
    Link_Itemtype::deleteForItemtype('Sample');
    verify($read('glpi_links_itemtypes', $pluginBinding) === null && $read('glpi_links_itemtypes', $otherBinding) !== null, 'Plugin association cleanup retains unrelated item types');
    verify((new Link())->delete(['id' => $links['scoped']], true), 'Link purge deletes required child associations');
    verify($repo()->itemtypes($links['scoped']) === [] && $repo()->countItemtypes($links['recursive']) === 1, 'Link purge is scoped');
    verify((new ForeignKeys())->audit($connection) === [], 'Link graph remains valid');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}
echo $DB->getProvider() . ": External link ownership, entity visibility, tags, rendering and purge passed.\n";
