<?php

// SPDX-License-Identifier: GPL-2.0-or-later

define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($argv[1]));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable test database required');
}
$CFG_GLPI['use_notifications'] = false;
$em = \itsmng\Database\Orm::create($DB);
$records = new \itsmng\Database\Repository\RecordRepository($em);
$history = new \itsmng\Database\Repository\HistoryRepository($em);
$existing = $records->matching('glpi_computers', ['name' => 'HistoryHTTPFixture']);
if ($argv[2] === 'clean') {
    foreach ($existing as $row) {
        $history->deleteForItem('Printer', $row['id']);
        (new Computer())->delete(['id' => $row['id']], true);
    }
    exit;
}
if ($argv[2] !== 'seed' || $existing) {
    throw new RuntimeException('Unknown action or existing fixture');
}
$result = $DB->getDoctrineConnection()->transactional(static function () use ($DB, $history): array {
    $id = (new FixtureRecords($DB))->create('glpi_computers', ['name' => 'HistoryHTTPFixture']);
    $ids = [];
    $actor = "O'Reilly \\path 日本語";
    for ($i = 0; $i < 3; $i++) {
        $ids[] = $history->append([
            'itemtype' => 'Computer', 'items_id' => $id,
            'date_mod' => $i === 2 ? null : '2024-02-29 12:34:56',
            'user_name' => $i === 2 ? null : $actor,
            'linked_action' => Log::HISTORY_LOG_SIMPLE_MESSAGE,
            'new_value' => 'History <script>fixture</script> ' . $i,
        ]);
    }
    $history->append(['itemtype' => 'Printer', 'items_id' => $id, 'user_name' => $actor]);
    return ['id' => $id, 'logs' => $ids, 'actor' => $actor];
});
echo json_encode($result, JSON_THROW_ON_ERROR);
