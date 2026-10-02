<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/item-selection.php /path/to/test-config\n");
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
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$savedSession = $_SESSION;
$savedPost = $_POST;
$savedCache = $GLPI_CACHE;
$savedDropdownMax = $CFG_GLPI['dropdown_max'];
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$endpoint = static function (array $post): array {
    $_POST = $post;
    ob_start();
    try {
        include GLPI_ROOT . '/ajax/dropdownAllItems.php';
        return [http_response_code(), json_decode(ob_get_contents(), true, flags: JSON_THROW_ON_ERROR)];
    } finally {
        ob_end_clean();
    }
};
$flatten = static function (array $values): array {
    $flat = [];
    foreach ($values as $id => $label) {
        $flat += is_array($label) ? $label : [$id => $label];
    }
    return $flat;
};
$render = static fn (array $options = []): string => Dropdown::showSelectItemFromItemtypes($options + ['display' => false, 'itemtypes' => Appliance_Item_Relation::getTypes(true), 'checkright' => true]);
$tokens = static function (): array {
    $requests = [];
    foreach ($_SESSION['glpiidortokens'] ?? [] as $token => $context) {
        if (isset($context['_select_itemtypes'])) {
            unset($context['expires']);
            $kind = $context['itemtype'];
            unset($context['itemtype']);
            $requests[$kind] = ['idtable' => $kind, '_idor_token' => $token] + $context;
        }
    }
    return $requests;
};
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $parent = $fixtures->create('glpi_entities', ['name' => 'Selector parent', 'completename' => 'Selector parent', 'entities_id' => 0, 'level' => 1]);
    $child = $fixtures->create('glpi_entities', ['name' => 'Selector child', 'completename' => 'Selector parent > Selector child', 'entities_id' => $parent, 'level' => 2]);
    $foreign = $fixtures->create('glpi_entities', ['name' => 'Selector foreign', 'completename' => 'Selector foreign', 'entities_id' => 0, 'level' => 1]);
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpiactiveentities_string'] = (string)$child;
    $_SESSION['glpiactive_entity'] = $child;
    $_SESSION['glpishowallentities'] = false;
    $literal = '<img src=x onerror=alert(1)> O\'Reilly 東京';
    $locations = [];
    foreach ([['local', $child, false], ['recursive', $parent, true], ['ancestor', $parent, false], ['foreign', $foreign, false]] as [$name, $entity, $recursive]) {
        $locations[$name] = $fixtures->create('glpi_locations', ['name' => $name === 'local' ? $literal : $name, 'completename' => $name === 'local' ? $literal : $name, 'entities_id' => $entity, 'is_recursive' => $recursive]);
    }
    $network = $fixtures->create('glpi_networks', ['name' => 'Global selector network']);
    $domain = $fixtures->create('glpi_domains', ['name' => 'Local selector domain', 'entities_id' => $child]);
    $_SESSION['glpiidortokens'] = [];
    $html = $render(['entity_restrict' => [$child], 'default_itemtype' => 'Location', 'rand' => 1701]);
    foreach (['Location', 'Network', 'Domain'] as $kind) {
        verify(str_contains($html, 'value="' . $kind . '"'), 'The actual nested selector offers its declared context: ' . $kind);
    }
    verify(!str_contains($html, 'value="Computer"') && str_contains($html, 'select_itemtype_itemtype_1701') && str_contains($html, 'select_item_items_id_1701'), 'Domain kinds and widget IDs are independent of ticket selector defaults');
    verify(str_contains($html, 'value="Location" selected') || str_contains($html, 'selected value="Location"'), 'Requested default type is selected');
    verify(str_contains($html, 'new Option(') && !str_contains($html, "append('<option"), 'Labels and identifiers enter DOM option text without HTML concatenation');
    verify(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x') && !str_contains($html, 'return markup;'), 'Initial option labels are escaped and Select2 retains its default text escaping');
    $requests = $tokens();
    foreach (['Location', 'Network', 'Domain'] as $kind) {
        [$status, $values] = $endpoint($requests[$kind]);
        verify($status === 200, 'The real endpoint accepts the rendered capability: ' . $kind);
        $flat = $flatten($values);
        if ($kind === 'Location') {
            verify(isset($flat[$locations['local']], $flat[$locations['recursive']]) && !isset($flat[$locations['ancestor']]) && !isset($flat[$locations['foreign']]), 'Requested scope admits recursive ancestors and excludes local ancestors/foreign context');
            verify(str_contains($flat[$locations['local']], "O'Reilly") && str_contains($flat[$locations['local']], '東京'), 'Literal labels retain text across endpoint serialization');
        } else {
            verify(isset($flat[$kind === 'Network' ? $network : $domain]), 'Global Network and owned Domain are selectable through the same endpoint');
        }
    }
    [$status, $values] = $endpoint($requests['Location'] + ['condition' => ['entities_id' => $foreign]]);
    verify($status === 200 && !isset($flatten($values)[$locations['foreign']]), 'Added client conditions cannot widen session scope');
    foreach ([['idtable' => 'Computer'], ['entity_restrict' => json_encode([$child, $foreign])], ['checkright' => 0], ['used' => '[999]'], ['onlyglobal' => 1], ['_idor_token' => 'forged']] as $tamper) {
        [$status, $values] = $endpoint(array_replace($requests['Location'], $tamper));
        verify($status === 403 && $values === [], 'The actual endpoint rejects changed type/scope/rights/filter capability');
    }
    [$status] = $endpoint(['idtable' => 'ReflectionClass']);
    verify($status === 403, 'Arbitrary PHP classes are not item selections');
    [$status, $values] = $endpoint(['idtable' => 'Location', 'entity_restrict' => [$foreign]]);
    verify($status === 200 && !isset($flatten($values)[$locations['foreign']]), 'Legacy endpoint clients also intersect requested and active scope');
    $rights = $_SESSION['glpiactiveprofile'];
    $_SESSION['glpiactiveprofile']['location'] = 0;
    $denied = $render(['entity_restrict' => [$child], 'rand' => 1702]);
    verify(!str_contains($denied, 'value="Location"') && str_contains($denied, 'value="Network"') && str_contains($denied, 'value="Domain"'), 'View checks filter the actual rendered kinds');
    verify($endpoint($requests['Location'])[0] === 403 && $endpoint(['idtable' => 'Location'])[0] === 403, 'Current rights are checked for rendered and legacy endpoint requests');
    $_SESSION['glpiidortokens'] = [];
    $unchecked = $render(['itemtypes' => ['Location'], 'checkright' => false, 'entity_restrict' => [$child]]);
    verify(str_contains($unchecked, 'value="Location"') && $endpoint($tokens()['Location'])[0] === 200, 'Explicit unchecked domain selections retain their scoped server-issued capability');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiidortokens'] = [];
    $render(['entity_restrict' => [], 'rand' => 1703]);
    $empty = $tokens();
    verify($flatten($endpoint($empty['Location'])[1]) === [0 => Dropdown::EMPTY_VALUE] && isset($flatten($endpoint($empty['Network'])[1])[$network]), 'Empty entity scope excludes owned contexts while preserving genuinely global Networks');
    $_SESSION['glpiidortokens'] = [];
    $render(['entity_restrict' => [$child], 'used' => ['Location' => [$locations['local']]], 'rand' => 1704]);
    verify(!isset($flatten($endpoint($tokens()['Location'])[1])[$locations['local']]), 'Already-used identifiers are excluded per kind');
    $localMonitor = $fixtures->create('glpi_monitors', ['name' => 'Local monitor', 'entities_id' => $child, 'is_global' => false]);
    $globalMonitor = $fixtures->create('glpi_monitors', ['name' => 'Global monitor', 'entities_id' => $child, 'is_global' => true]);
    $_SESSION['glpiidortokens'] = [];
    $render(['itemtypes' => ['Monitor'], 'onlyglobal' => true, 'entity_restrict' => [$child]]);
    $monitorValues = $flatten($endpoint($tokens()['Monitor'])[1]);
    verify(isset($monitorValues[$globalMonitor]) && !isset($monitorValues[$localMonitor]), 'Only-global selection follows the model boolean field');
    // A native select must remain complete even when searchable widgets page.
    $CFG_GLPI['dropdown_max'] = 2;
    $beyondLimit = [];
    for ($i = 0; $i < 4; ++$i) {
        $beyondLimit[] = $fixtures->create('glpi_networks', ['name' => 'After limit ' . $i]);
    }
    $render(['itemtypes' => ['Network'], 'rand' => 1799]);
    $allNetworks = $flatten($endpoint($tokens()['Network'])[1]);
    verify(count(array_intersect($beyondLimit, array_keys($allNetworks))) === 4, 'Native selector includes candidates beyond the configured searchable dropdown limit');
    $device = $fixtures->create('glpi_deviceprocessors', ['designation' => "Processor O'Reilly 東京", 'entities_id' => $child]);
    $deviceValues = $flatten($endpoint(['idtable' => 'DeviceProcessor', 'entity_restrict' => [$child]])[1]);
    $legacyDeviceValues = $flatten(getOptionForItems('DeviceProcessor', [], true, true));
    verify(isset($deviceValues[$device]) && $deviceValues[$device] === $legacyDeviceValues[$device], 'Legacy device clients retain designation labels and grouped identifier shape');
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Recipient selector profile', 'interface' => 'central']);
    $recipients = [];
    foreach (['local' => $child, 'foreign' => $foreign] as $label => $entity) {
        $recipients[$label] = $fixtures->create('glpi_users', ['name' => 'Recipient selector ' . $label, 'is_active' => true]);
        $fixtures->create('glpi_profiles_users', ['users_id' => $recipients[$label], 'profiles_id' => $profile, 'entities_id' => $entity]);
    }
    $groups = [];
    foreach (['local' => $child, 'foreign' => $foreign] as $label => $entity) {
        $groups[$label] = $fixtures->create('glpi_groups', ['name' => 'Recipient group ' . $label, 'completename' => 'Recipient group ' . $label, 'entities_id' => $entity]);
    }
    $_SESSION['glpiactiveprofile']['user'] = 0;
    $_SESSION['glpiactiveprofile']['group'] = 0;
    $_SESSION['glpiidortokens'] = [];
    $recipientHtml = $render(['itemtypes' => $CFG_GLPI['consumables_types'], 'checkright' => false, 'entity_restrict' => [$child], 'itemtype_name' => 'give_itemtype', 'items_id_name' => 'give_items_id']);
    verify(str_contains($recipientHtml, 'value="User"') && str_contains($recipientHtml, 'value="Group"'), 'Actual Consumable kinds remain available without directory-wide view rights');
    $recipientRequests = $tokens();
    $userValues = $flatten($endpoint($recipientRequests['User'])[1]);
    $groupValues = $flatten($endpoint($recipientRequests['Group'])[1]);
    verify(isset($userValues[$recipients['local']], $groupValues[$groups['local']]) && !isset($userValues[$recipients['foreign']]) && !isset($groupValues[$groups['foreign']]), 'Recipient users use profile-grant scope and groups use their owning entity scope');
    $render(['itemtypes' => ['User'], 'checkright' => false, 'entity_restrict' => [$foreign]]);
    $outsideUsers = $flatten($endpoint($tokens()['User'])[1]);
    verify(!isset($outsideUsers[$recipients['foreign']]) && !isset($outsideUsers[$recipients['local']]), 'Requested User grant scope cannot widen the current session grant scope');
    $_SESSION['glpiactiveentities'] = [$child, $foreign];
    $render(['itemtypes' => ['User'], 'checkright' => false, 'entity_restrict' => [$child]]);
    $narrowUsers = $flatten($endpoint($tokens()['User'])[1]);
    verify(isset($narrowUsers[$recipients['local']]) && !isset($narrowUsers[$recipients['foreign']]), 'Requested User recipient scope remains narrower than the session');
    $render(['itemtypes' => ['User'], 'checkright' => false, 'entity_restrict' => []]);
    verify($flatten($endpoint($tokens()['User'])[1]) === [0 => Dropdown::EMPTY_VALUE], 'Explicit empty User recipient grants stay empty');
    $_SESSION['glpiactiveentities'] = [];
    $render(['itemtypes' => ['User'], 'checkright' => false, 'entity_restrict' => -1]);
    verify($flatten($endpoint($tokens()['User'])[1]) === [0 => Dropdown::EMPTY_VALUE], 'Empty active User grants cannot fall back to root recipients');
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpiactiveprofile'] = $rights;
    $second = $render(['rand' => 1801, 'itemtype_name' => 'peer_type', 'items_id_name' => 'peer_id']);
    verify(str_contains($second, 'select_itemtype_peer_type_1801') && !str_contains($second, 'select_itemtype_itemtype_1701'), 'Multiple widget field names/random IDs remain isolated');
    ob_start();
    $returned = Dropdown::showSelectItemFromItemtypes(['itemtypes' => ['Network'], 'rand' => 1802]);
    $displayed = ob_get_clean();
    verify($returned === 1802 && str_contains($displayed, 'select_itemtype_itemtype_1802'), 'Display mode echoes once and returns the documented random ID');
    $default = Dropdown::showSelectItemFromItemtypes(['display' => false, 'rand' => 1803]);
    verify(!str_contains($default, 'selectItemTypeForTicketMassiveAction'), 'Default selector uses state kinds without ticket-specific DOM IDs');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $_POST = $savedPost;
    $GLPI_CACHE = $savedCache;
    $CFG_GLPI['dropdown_max'] = $savedDropdownMax;
    http_response_code(200);
    restore_error_handler();
}
echo $DB->getProvider() . ": shared item selector kinds/defaults, isolated widgets, session-bound endpoint permissions/scopes, used/global filters and safe option rendering passed.\n";
