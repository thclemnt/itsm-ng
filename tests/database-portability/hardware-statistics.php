<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketAssetStatisticsRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/hardware-statistics.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$create = (new FixtureRecords($DB))->create(...);
$repository = new TicketAssetStatisticsRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $entity = (int)(new Entity())->add(['name' => 'Hardware statistics entity', 'entities_id' => 0]);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpi_multientitiesmode'] = 1;
    $_SESSION['glpilist_limit'] = 1;
    $id = 100 + (int)$DB->getDoctrineConnection()->fetchOne('SELECT GREATEST(COALESCE((SELECT MAX(id) FROM glpi_computers), 0), COALESCE((SELECT MAX(id) FROM glpi_printers), 0))');
    $first = $create('glpi_computers', ['id' => $id, 'entities_id' => $entity, 'name' => 'Hardware first page']);
    $second = $create('glpi_computers', ['entities_id' => $entity, 'name' => 'Hardware second page']);
    $printer = $create('glpi_printers', ['id' => $id, 'entities_id' => $entity, 'name' => 'Hardware printer page']);
    $link = static function (int $asset, string $type, string $date, int $scope, bool $deleted = false) use ($create): void {
        $ticket = $create('glpi_tickets', ['date' => $date, 'entities_id' => $scope, 'is_deleted' => $deleted]);
        $create('glpi_items_tickets', ['tickets_id' => $ticket, 'itemtype' => $type, 'items_id' => $asset]);
    };
    foreach (['2025-01-01 00:00:00', '2025-01-15 12:00:00', '2025-01-31 23:59:59'] as $date) {
        $link($first, 'Computer', $date, $entity, $date === '2025-01-31 23:59:59');
    }
    foreach ([$second => 'Computer', $printer => 'Printer'] as $asset => $type) {
        foreach (['2025-01-05 12:00:00', '2025-01-20 12:00:00'] as $date) {
            $link($asset, $type, $date, $entity);
        }
    }
    $link($second, 'Computer', '2025-01-10 12:00:00', 0);
    $link($second, 'Computer', '2025-02-01 00:00:00', $entity);
    $link($second, 'Computer', '2024-12-31 23:59:59', $entity);
    $link($first, '', '2025-01-10 12:00:00', $entity);
    $link(0, 'Computer', '2025-01-10 12:00:00', $entity);
    $expected = [
        ['itemtype' => 'Computer', 'items_id' => $first, 'NB' => 3],
        ['itemtype' => 'Computer', 'items_id' => $second, 'NB' => 2],
        ['itemtype' => 'Printer', 'items_id' => $printer, 'NB' => 2],
    ];
    verify($repository->page('2025-01-01', '2025-01-31', [$entity]) === ['total' => 3, 'rows' => $expected], 'Grouped counts include end day, retain deleted-ticket semantics and distinguish item types sharing an ID');
    foreach ($expected as $offset => $row) {
        verify($repository->page('2025-01-01', '2025-01-31', [$entity], $offset, 1) === ['total' => 3, 'rows' => [$row]], 'SQL pagination has deterministic aggregate ties');
    }
    verify($repository->page('2025-01-01', '2025-01-31', [$entity], 3, 1) === ['total' => 3, 'rows' => []], 'Offset beyond the last row retains total');
    verify($repository->page('2025-01-01', '2025-01-31', [$entity], 0, 0) === ['total' => 3, 'rows' => []], 'Zero limit does not load all groups');
    verify($repository->page('2025-01-01', '2025-01-31', []) === ['total' => 0, 'rows' => []], 'Empty scope grants no rows');
    verify($repository->page('2025-01-01', '2025-01-31', [0]) === ['total' => 1, 'rows' => [['itemtype' => 'Computer', 'items_id' => $second, 'NB' => 1]]], 'Root entity remains a real scope');
    verify($repository->page('2025-01-01', '2025-01-31', null)['rows'][1]['NB'] === 3, 'Explicit unrestricted scope includes other entities');
    verify($repository->page('2025-02-01', '2025-01-31', [$entity]) === ['total' => 0, 'rows' => []], 'Reversed dates match nothing');
    verify($repository->page('2025-01-31 23:59:58', '2025-01-31 23:59:59', [$entity])['rows'][0]['NB'] === 1, 'Timestamp bounds are exact and inclusive');
    try {
        $repository->page('2025-02-30', '2025-03-01', [$entity]);
        throw new RuntimeException('Invalid date was accepted');
    } catch (InvalidArgumentException) {
    }
    foreach (['glpi_tickets', 'glpi_items_tickets', 'glpi_computers', 'glpi_printers', 'glpi_entities'] as $table) {
        $DB->listFields($table);
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $render = static function (int $offset): string {
        ob_start();
        Stat::showItems('/front/stat.item.php', '2025-01-01', '2025-01-31', $offset);
        return ob_get_clean();
    };
    $html = $render(1);
    verify(str_contains($html, 'Hardware second page') && !str_contains($html, 'Hardware first page') && !str_contains($html, 'Hardware printer page'), 'Rendered second page advances beyond the first aggregate');
    verify(str_contains($html, 'Hardware statistics entity'), 'Rendered page retains entity labels');
    $_GET['export_all'] = 1;
    $html = $render(2);
    verify(str_contains($html, 'Hardware first page') && str_contains($html, 'Hardware second page') && str_contains($html, 'Hardware printer page'), 'Export-all resets offset and removes the page limit');
    $_GET['display_type'] = Search::CSV_OUTPUT;
    $csv = $render(2);
    verify(str_contains($csv, 'Hardware first page') && str_contains($csv, 'Hardware second page') && str_contains($csv, 'Hardware printer page'), 'CSV export-all includes every grouped asset');
    unset($_GET['export_all']);
    $csv = $render(1);
    verify(str_contains($csv, 'Hardware second page') && !str_contains($csv, 'Hardware first page') && !str_contains($csv, 'Hardware printer page'), 'CSV page export obeys offset and limit');
    unset($_GET['display_type']);
    verify($SQL_TOTAL_REQUEST === 0, 'Hardware report bypasses legacy adapter execution: ' . json_encode($DEBUG_SQL['queries'] ?? []));
    $link($first, 'Computer ', '2025-01-10 12:00:00', $entity);
    $groups = $repository->page('2025-01-01', '2025-01-31', [$entity]);
    verify($groups['total'] === count($groups['rows']), 'Group totals follow each provider collation, including trailing whitespace');
} finally {
    unset($_GET['export_all'], $_GET['display_type']);
    $DB->rollBack();
}
echo $DB->getProvider() . ": hardware statistics counts, scopes, SQL pagination and rendered pages passed.\n";
