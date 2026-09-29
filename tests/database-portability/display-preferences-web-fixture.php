<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Companion for web-display-preferences.py; only use a disposable installation.
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($argv[1]));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable test database required');
}
$CFG_GLPI['use_notifications'] = false;
$_SESSION['glpiextauth'] = 0;
if (!(new Auth())->login('itsm', 'itsm', true)) {
    throw new RuntimeException('Fixture login failed');
}
$user = (int)Session::getLoginUserID();
$records = new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
$repository = new \itsmng\Database\Repository\DisplayPreferenceRepository(\itsmng\Database\Orm::create($DB));
$others = $records->matching('glpi_users', ['name' => 'DisplayPreferenceHTTPFixture']);
$other = $others[0]['id'] ?? null;
if ($argv[2] === 'seed') {
    if ($other !== null || $repository->rows('Computer', $user) || $repository->rows('Ticket', $user)) {
        throw new RuntimeException('Use an installation without existing fixture or personal Computer/Ticket preferences');
    }
    $fixtures = new FixtureRecords($DB);
    $other = $fixtures->create('glpi_users', ['name' => 'DisplayPreferenceHTTPFixture']);
    foreach ([$user, $other] as $owner) {
        foreach ([2, 3] as $rank => $num) {
            $fixtures->create('glpi_displaypreferences', ['itemtype' => 'Computer', 'users_id' => $owner, 'num' => $num, 'rank' => $rank + 1]);
        }
    }
    $fixtures->create('glpi_displaypreferences', ['itemtype' => 'Ticket', 'users_id' => $user, 'num' => 2, 'rank' => 1]);
} elseif ($argv[2] === 'clean') {
    (new DisplayPreference())->deleteByCriteria(['users_id' => $user, 'itemtype' => ['Computer', 'Ticket']]);
    if ($other !== null) {
        (new User())->delete(['id' => $other], true);
    }
    exit;
} elseif ($argv[2] !== 'read' || $other === null) {
    throw new RuntimeException('Unknown fixture action or missing seed');
}
echo json_encode([
    'user' => $user, 'other' => $other,
    'personal' => $repository->rows('Computer', $user),
    'foreign' => $repository->rows('Computer', $other),
    'ticket' => $repository->rows('Ticket', $user),
    'defaults' => $repository->rows('Computer', 0),
], JSON_THROW_ON_ERROR);
