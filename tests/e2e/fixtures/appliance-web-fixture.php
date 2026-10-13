<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SequenceSynchronizer;

// CLI companion to appliance.spec.mts; never expose a fixture HTTP endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$directory = $argv[1] ?? '';
$action = $argv[2] ?? '';
if (!is_file($directory . '/config_db.php')) {
    throw new RuntimeException('Disposable application configuration required');
}
define('GLPI_ROOT', dirname(__DIR__, 3));
define('GLPI_CONFIG_DIR', realpath($directory));
define('GLPI_VAR_DIR', getenv('GLPI_VAR_DIR') ?: GLPI_ROOT . '/files');
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable test database required');
}
$CFG_GLPI['use_notifications'] = false;
$_SESSION['glpiextauth'] = 0;
if (!(new Auth())->login('itsm', 'itsm', true)) {
    throw new RuntimeException('Fixture administrator login failed');
}
$connection = $DB->getDoctrineConnection();
$records = new RecordRepository(Orm::create($DB));
if ($action === 'seed') {
    $result = $connection->transactional(static function () use ($DB): array {
        $fixtures = new FixtureRecords($DB);
        $tag = bin2hex(random_bytes(4));
        $base = 5000000000 + random_int(1, 999999) * 100;
        $owned = [];
        $create = static function (string $table, array $values) use ($fixtures, &$owned): int {
            $id = $fixtures->create($table, $values);
            $owned[$table][] = $id;
            return $id;
        };
        $applianceName = 'Browser appliance ' . $tag;
        $appliance = $create('glpi_appliances', ['id' => $base, 'name' => $applianceName, 'entities_id' => 0]);
        $computers = $bindings = $relations = [];
        for ($index = 0; $index < 2; ++$index) {
            $computer = $create('glpi_computers', ['id' => $base + 1 + $index, 'name' => 'Browser computer ' . $tag . '-' . $index, 'entities_id' => 0]);
            $computers[] = $computer;
            $binding = $create('glpi_appliances_items', ['id' => $base + 11 + $index, 'appliances_id' => $appliance, 'itemtype' => 'Computer', 'items_id' => $computer]);
            $bindings[] = $binding;
            $location = $create('glpi_locations', ['id' => $base + 31 + $index, 'name' => 'Browser location ' . $tag . '-' . $index, 'completename' => 'Browser location ' . $tag . '-' . $index, 'entities_id' => 0]);
            $relations[] = $create('glpi_appliances_items_relations', ['id' => $base + 21 + $index, 'appliances_items_id' => $binding, 'itemtype' => 'Location', 'items_id' => $location]);
        }
        $newLocationName = 'New browser location <img src=x data-appliance-fixture> ' . $tag;
        $newLocation = $create('glpi_locations', ['id' => $base + 33, 'name' => $newLocationName, 'completename' => $newLocationName, 'entities_id' => 0]);
        $domain = $create('glpi_domains', ['id' => $base + 41, 'name' => 'Browser domain ' . $tag, 'entities_id' => 0]);
        $profile = $create('glpi_profiles', ['name' => 'Browser appliance reader ' . $tag, 'interface' => 'central']);
        foreach (['appliance', 'computer', 'location'] as $right) {
            $create('glpi_profilerights', ['profiles_id' => $profile, 'name' => $right, 'rights' => READ]);
        }
        $readerName = 'e2e-appliance-reader-' . $tag;
        $reader = $create('glpi_users', ['name' => $readerName, 'password' => Auth::getPasswordHash('E2EAppliance1!'), 'authtype' => Auth::DB_GLPI,
            'profiles_id' => $profile, 'entities_id' => 0, 'language' => 'en_GB', 'use_mode' => Session::NORMAL_MODE]);
        $create('glpi_profiles_users', ['profiles_id' => $profile, 'users_id' => $reader, 'entities_id' => 0, 'is_recursive' => false, 'is_default_profile' => true]);
        SequenceSynchronizer::synchronize($DB->getDoctrineConnection());
        return compact('appliance', 'applianceName', 'computers', 'bindings', 'relations', 'newLocation', 'newLocationName', 'domain', 'readerName', 'owned');
    });
} else {
    $fixture = json_decode($argv[3] ?? '', true, flags: JSON_THROW_ON_ERROR);
    if (!isset($fixture['owned'], $fixture['bindings']) || count($fixture['bindings']) !== 2) {
        throw new RuntimeException('Owned fixture manifest required');
    }
    $rows = $records->matching('glpi_appliances_items_relations', ['appliances_items_id' => $fixture['bindings']], 'id ASC');
    if ($action === 'read') {
        $result = $rows;
    } elseif ($action === 'clean') {
        $connection->transactional(static function () use ($connection, $fixture, $rows): void {
            foreach ($rows as $row) {
                $connection->delete('glpi_appliances_items_relations', ['id' => $row['id']]);
            }
            foreach (array_reverse($fixture['owned'], true) as $table => $ids) {
                foreach ($ids as $id) {
                    $connection->delete($table, ['id' => $id]);
                }
            }
        });
        $result = ['cleaned' => true];
    } else {
        throw new RuntimeException('Unknown fixture action');
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
