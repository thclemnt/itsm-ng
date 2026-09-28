<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Real application workflow against a dedicated installation. No browser mocks. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/search-benchmark.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
class GlpitestSQLError extends RuntimeException
{
}
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Use a dedicated itsm_port_* database.');
$_SESSION['glpiextauth'] = 0;
$auth = new Auth();
verify($auth->login('itsm', 'itsm', true), 'Seeded administrator login.');

// Optional reproducible benchmark, deliberately separate from timing assertions in CI.
verify($DB->getProvider() === 'mysql', 'Legacy comparison benchmark requires MySQL/MariaDB.');
$prefix = 'Search benchmark ' . bin2hex(random_bytes(5));
$DB->beginTransaction();
try {
    $groups = [];
    foreach (range(1, 8) as $i) {
        $DB->insertOrDie('glpi_groups', ['name' => $prefix . $i, 'completename' => $prefix . $i, 'entities_id' => 0]);
        $groups[] = $DB->insertId();
    }
    foreach (range(1, 300) as $i) {
        $DB->insertOrDie('glpi_tickets', ['name' => $prefix . sprintf(' %04d', $i), 'entities_id' => 0, 'status' => 1]);
        $ticket = $DB->insertId();
        foreach ($groups as $group) {
            $DB->insertOrDie('glpi_groups_tickets', ['tickets_id' => $ticket, 'groups_id' => $group, 'type' => CommonITILActor::ASSIGN]);
        }
        foreach (range(1, 12) as $j) {
            $DB->insertOrDie('glpi_itilfollowups', ['items_id' => $ticket, 'itemtype' => 'Ticket', 'content' => 'Followup ' . $j, 'date' => '2024-01-01 12:00:00']);
        }
    }
    foreach (Search::getOptions('Ticket') as $id => $option) {
        if (!is_array($option) || ($option['table'] ?? '') !== 'glpi_itilfollowups') {
            continue;
        }
        if ($option['field'] === 'content') {
            $content = $id;
        }
        if (($option['datatype'] ?? '') === 'count') {
            $count = $id;
        }
    }
    $params = [
        'criteria' => [
            ['field' => 1, 'searchtype' => 'contains', 'value' => $prefix],
            ['link' => 'AND', 'field' => $count, 'searchtype' => 'contains', 'value' => '>4'],
            ['link' => 'AND', 'field' => 8, 'searchtype' => 'contains', 'value' => $prefix],
        ], 'sort' => 1, 'order' => 'ASC', 'list_limit' => 20, 'reset' => 'reset',
    ];
    $samples = ['legacy' => [], 'two_phase' => []];
    $ids = [];
    // Alternate order to reduce cache-warming bias; discard the first pair.
    foreach (range(0, 3) as $round) {
        foreach ($round % 2 ? ['two_phase', 'legacy'] : ['legacy', 'two_phase'] as $mode) {
            $start = microtime(true);
            $data = Search::getDatas('Ticket', $params + ['disable_two_phase_search' => $mode === 'legacy'], [8, $content]);
            $elapsed = microtime(true) - $start;
            verify((int)$data['data']['totalcount'] === 300, 'Benchmark total count.');
            $page = array_map('intval', array_column($data['data']['rows'], 'id'));
            verify(count($page) === 20 && (!$ids || $page === $ids), 'Benchmark plans return identical page IDs.');
            $ids = $page;
            if ($round) {
                $samples[$mode][] = $elapsed;
            }
        }
    }
    foreach ($samples as $mode => $times) {
        sort($times);
        printf("%s: %.3f s median (300 tickets, 8 groups and 12 followups each, 20-row page)\n", $mode, $times[1]);
    }
} finally {
    $DB->rollBack();
}
