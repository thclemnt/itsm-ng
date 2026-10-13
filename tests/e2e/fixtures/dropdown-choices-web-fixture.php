<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DropdownChoiceContext;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

// HTTP actions are available only through an explicitly enabled private test router.
define('GLPI_ROOT', dirname(__DIR__, 3));
if (!defined('PLUGINS_DIRECTORIES')) {
    define('PLUGINS_DIRECTORIES', [GLPI_ROOT . '/plugins', GLPI_ROOT . '/tests/fixtures/plugins']);
}
if (PHP_SAPI === 'cli') {
    define('GLPI_CONFIG_DIR', realpath($argv[1]));
} elseif (!defined('GLPI_DROPDOWN_TEST_ROUTE')) {
    http_response_code(404);
    exit;
}
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable test database required');
}
$CFG_GLPI['use_notifications'] = false;
$records = new RecordRepository(Orm::create($DB));
$writer = new RecordWriter(Orm::create($DB));
$prefix = 'DropdownHTTPFixture';
$find = static function (string $table, string $suffix) use ($records, $prefix): ?int {
    return $records->matching($table, ['name' => $prefix . $suffix])[0]['id'] ?? null;
};
$state = static function () use ($find): array {
    return [
        'entityA' => $find('glpi_entities', 'A'), 'entityB' => $find('glpi_entities', 'B'),
        'computerA' => $find('glpi_computers', 'ComputerA'), 'computerB' => $find('glpi_computers', 'ComputerB'),
        'location' => $find('glpi_locations', 'Location'), 'project' => $find('glpi_projects', 'Project'),
        'excludedLocation' => $find('glpi_locations', 'ExcludedLocation'),
        'projectType' => $find('glpi_projecttypes', 'Type'),
    ];
};
$action = PHP_SAPI === 'cli' ? ($argv[2] ?? '') : ($_POST['action'] ?? 'render');
if (PHP_SAPI === 'cli') {
    if ($action === 'seed') {
        if ($find('glpi_entities', 'A') !== null) {
            throw new RuntimeException('Existing fixture; use an isolated installation');
        }
        $fixtures = new FixtureRecords($DB);
        $a = $fixtures->create('glpi_entities', ['name' => $prefix . 'A', 'completename' => $prefix . 'A', 'entities_id' => 0]);
        $b = $fixtures->create('glpi_entities', ['name' => $prefix . 'B', 'completename' => $prefix . 'B', 'entities_id' => 0]);
        $user = $records->matching('glpi_users', ['name' => 'itsm'])[0];
        $profile = $user['profiles_id'] ?? $records->matching('glpi_profiles_users', ['users_id' => $user['id']])[0]['profiles_id'];
        foreach ([$a, $b] as $entity) {
            $fixtures->create('glpi_profiles_users', ['users_id' => $user['id'], 'profiles_id' => $profile, 'entities_id' => $entity, 'is_recursive' => false]);
        }
        $location = $fixtures->create('glpi_locations', ['name' => $prefix . 'Location', 'completename' => $prefix . 'Location', 'entities_id' => $a]);
        $fixtures->create('glpi_locations', ['name' => $prefix . 'ExcludedLocation', 'completename' => $prefix . 'ExcludedLocation', 'entities_id' => $a]);
        $fixtures->create('glpi_computers', ['name' => $prefix . 'ComputerA', 'entities_id' => $a, 'locations_id' => $location]);
        $fixtures->create('glpi_computers', ['name' => $prefix . 'ComputerB', 'entities_id' => $b]);
        $type = $fixtures->create('glpi_projecttypes', ['name' => $prefix . 'Type']);
        $fixtures->create('glpi_projects', ['name' => $prefix . 'Parent', 'entities_id' => $a, 'projecttypes_id' => $type]);
        $fixtures->create('glpi_projects', ['name' => $prefix . 'Project', 'entities_id' => $a, 'projecttypes_id' => $type]);
    } elseif ($action === 'clean') {
        foreach ([['glpi_projects', 'Project'], ['glpi_projects', 'Parent'], ['glpi_computers', 'ComputerA'], ['glpi_locations', 'Location'], ['glpi_locations', 'ExcludedLocation'], ['glpi_projecttypes', 'Type']] as [$table, $suffix]) {
            if (($id = $find($table, $suffix)) !== null) {
                $writer->delete($table, $id);
            }
        }
        if (($id = $find('glpi_computers', 'ComputerB')) !== null) {
            $writer->delete('glpi_computers', $id);
        }
        foreach (['B', 'A'] as $suffix) {
            if (($id = $find('glpi_entities', $suffix)) !== null) {
                foreach ($records->matching('glpi_profiles_users', ['entities_id' => $id]) as $grant) {
                    $writer->delete('glpi_profiles_users', $grant['id']);
                }
                $writer->delete('glpi_entities', $id);
            }
        }
        exit;
    } elseif ($action !== 'read') {
        throw new RuntimeException('Unknown fixture action');
    }
    echo json_encode($state(), JSON_THROW_ON_ERROR);
    exit;
}
Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');
if ($action === 'restrict' || $action === 'revoke' || $action === 'restore') {
    if (!isset($_SESSION['dropdown_http_saved'])) {
        $_SESSION['dropdown_http_saved'] = array_intersect_key($_SESSION, array_flip(['glpiactiveentities', 'glpiactive_entity', 'glpiactiveprofile', 'glpishowallentities']));
    }
    if ($action === 'restrict') {
        $_SESSION['glpiactiveentities'] = [(int)$state()['entityA']];
        $_SESSION['glpiactive_entity'] = (int)$state()['entityA'];
        $_SESSION['glpishowallentities'] = false;
    } elseif ($action === 'revoke') {
        $_SESSION['glpiactiveprofile']['project'] = 0;
    } else {
        $_SESSION = array_replace($_SESSION, $_SESSION['dropdown_http_saved']);
        unset($_SESSION['dropdown_http_saved']);
    }
    echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'render') {
    throw new RuntimeException('Unknown private HTTP fixture action');
}
$_SESSION['glpiis_ids_visible'] = 1;
$kind = $_POST['kind'] ?? 'Computer';
if (!in_array($kind, ['Computer', 'Location', 'Project', 'User'], true)) {
    throw new RuntimeException('Unknown fixture kind');
}
$options = ['display' => false, 'comments' => false, 'addicon' => false, 'entity' => [$state()['entityA'], $state()['entityB']], 'permit_select_parent' => false];
if ($kind === 'Computer') {
    $options['condition'] = ['name' => ['LIKE', $prefix . '%']];
} elseif ($kind === 'Location') {
    $options['condition'] = ['glpi_locations.id' => $state()['location']];
}
$html = Dropdown::show($kind, $options);
// A real caller passes numeric User grants through the same component-issued factory.
$user = ['itemtype' => 'User', 'entity_restrict' => [$state()['entityA'], $state()['entityB']], 'right' => READ, 'permit_select_parent' => false];
$user['_idor_token'] = DropdownChoiceContext::token('User', $user);
echo json_encode(['html' => $html, 'user' => $user, 'fixture' => $state(), 'scope' => $_SESSION['glpiactiveentities']], JSON_THROW_ON_ERROR);
