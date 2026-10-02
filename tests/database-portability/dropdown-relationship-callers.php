<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/dropdown-relationship-callers.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable caller database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$savedPost = $_POST;
$savedServer = $_SERVER;
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    foreach (['contract', 'domain', 'software', 'appliance', 'document', 'certificate', 'ticket'] as $prefix) {
        $CFG_GLPI[$prefix . '_types'] = ['Computer', 'Monitor'];
    }
    $CFG_GLPI['use_notifications'] = false;
    $child = (new Entity())->add(['name' => 'Dropdown caller child', 'entities_id' => 0]);
    verify(is_int($child) && $child > 0, 'Real child entity');
    // Entity creation refreshes the administrator's scope; use root only for default requests.
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveprofile']['helpdesk_item_type'] = ['Computer', 'Monitor'];
    $computer = $fixtures->create('glpi_computers', ['name' => 'Caller visible computer']);
    $childComputer = $fixtures->create('glpi_computers', ['name' => 'Caller child computer', 'entities_id' => $child]);
    $fixtures->create('glpi_monitors', ['name' => 'Caller monitor']);
    $request = static function (array $payload): array {
        $_POST = $payload;
        $_SERVER['PHP_SELF'] = '/portability/caller.php';
        http_response_code(200);
        ob_start();
        try {
            require GLPI_ROOT . '/ajax/getDropdownValue.php';
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return [http_response_code(), json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
    };
    $case = static function (string $class, string $method, array $fields = [], array $context = [], bool $expectChild = false) use ($fixtures, $request, $computer, $childComputer): string {
        $id = $fixtures->create($class::getTable(), ['name' => 'Dropdown caller ' . $class] + $fields);
        $item = new $class();
        verify($item->getFromDB($id), 'Loaded ' . $class);
        ob_start();
        try {
            $method($item);
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        verify(str_contains($html, '/ajax/getDropdownValue.php'), $class . ' renders its real AJAX caller');
        preg_match('/\((\{"Computer"[^\n]*?\})\)\[/', $html, $matches);
        verify(isset($matches[1]), $class . ' renders a per-kind token map');
        $tokens = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        verify(isset($tokens['Computer'], $tokens['Monitor']) && !isset($tokens['']), $class . ' binds rendered kinds without an empty-kind token');
        foreach (['Computer', 'Monitor'] as $kind) {
            $payload = ['itemtype' => $kind, '_idor_token' => $tokens[$kind]] + $context;
            [$status, $choices] = $request($payload);
            verify($status === 200 && isset($choices['results']), $class . ' token accepts its actual payload for ' . $kind);
            $payload += ['searchText' => 'Caller', 'page' => 1, 'page_limit' => 10, 'used' => []];
            [$status] = $request($payload);
            verify($status === 200, $class . ' mutable search/page retains context');
            foreach (['entity_restrict' => [0, 999999], 'condition' => 'tampered', 'displaywith' => ['comment'], 'permit_select_parent' => true, 'right' => READ, 'inactive_deleted' => 1, 'with_no_right' => 1] as $option => $value) {
                [$status] = $request(array_replace($payload, [$option => $value]));
                verify($status === 403, $class . ' token refuses changed ' . $option);
            }
        }
        [$status] = $request(['itemtype' => 'Monitor', '_idor_token' => $tokens['Computer']] + $context);
        verify($status === 403, $class . ' token cannot change kind');
        [$status] = $request(['itemtype' => 'Computer'] + $context);
        verify($status === 403, $class . ' type-only request is rejected');
        [$status, $choices] = $request(['itemtype' => 'Computer', '_idor_token' => $tokens['Computer']] + $context);
        $ids = [];
        foreach ($choices['results'] as $row) {
            foreach ($row['children'] ?? [$row] as $choice) {
                $ids[] = (int)$choice['id'];
            }
        }
        verify(in_array($computer, $ids, true), $class . ' retains the root asset');
        verify(in_array($childComputer, $ids, true) === $expectChild, $class . ' retains its caller and active entity scope');
        $profile = $_SESSION['glpiactiveprofile'];
        try {
            $_SESSION['glpiactiveprofile'][$class::$rightname] = defined($class . '::READALL') ? $class::READALL : READ;
            if ($class === SoftwareLicense::class) {
                $_SESSION['glpiactiveprofile']['software'] = READ;
            }
            $readonly = new $class();
            verify($readonly->getFromDB($id), 'Loaded read-only ' . $class);
            ob_start();
            try {
                $method($readonly);
                $readonlyHtml = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            verify(!str_contains($readonlyHtml, '/ajax/getDropdownValue.php'), $class . ' read-only form grants no relationship choice context');
        } finally {
            $_SESSION['glpiactiveprofile'] = $profile;
        }
        return $html;
    };
    $case(Contract::class, Contract_Item::class . '::showForContract');
    $case(Domain::class, Domain_Item::class . '::showForDomain');
    $software = $fixtures->create('glpi_softwares', ['name' => 'Caller software']);
    $licenseHtml = $case(SoftwareLicense::class, Item_SoftwareLicense::class . '::showForLicense', ['softwares_id' => $software, 'number' => -1]);
    verify(substr_count($licenseHtml, '/ajax/getDropdownValue.php') === 2, 'License init and change both render their actual POSTs');
    verify(str_contains($licenseHtml, "_idor_token:") && str_contains($licenseHtml, "[$('#dropdown_itemtype').val()]"), 'License initialization selects its current-kind token');
    $case(Problem::class, Item_Problem::class . '::showForProblem');
    $case(Change::class, Change_Item::class . '::showForChange');
    $applianceScope = array_values(array_map('intval', getSonsOf('glpi_entities', 0)));
    verify(in_array($child, $applianceScope, true), 'Recursive appliance context includes its real child');
    $case(Appliance::class, Appliance_Item::class . '::showItems', ['is_recursive' => true], ['entity_restrict' => $applianceScope]);
    $_SESSION['glpiactiveentities'] = [0, $child];
    $_SESSION['glpiactiveentities_string'] = '0,' . $child;
    $case(Appliance::class, Appliance_Item::class . '::showItems', ['is_recursive' => false], ['entity_restrict' => [0]]);
    $case(Appliance::class, Appliance_Item::class . '::showItems', ['is_recursive' => true], ['entity_restrict' => $applianceScope], true);
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $case(Project::class, Item_Project::class . '::showForProject');
    $case(Document::class, Document_Item::class . '::showForDocument');
    $case(Certificate::class, Certificate_Item::class . '::showForCertificate');
    echo "Nine relationship forms, ten AJAX callers, per-kind context, mutable search and immutable authorization/scope passed.\n";
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
    $_POST = $savedPost;
    $_SERVER = $savedServer;
    $GLPI_CACHE = $savedCache;
    http_response_code(200);
}
