<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/reporting.php /path/to/test-config\n");
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
function fixture(string $table, array $values): int
{
    global $DB;
    $DB->insertOrDie($table, $values);
    return $DB->insertId();
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $entity = (new Entity())->add(['name' => 'Report scope']);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $computer = fixture('glpi_computers', ['name' => 'Visible report computer', 'entities_id' => $entity]);
    $other = fixture('glpi_computers', ['name' => 'Hidden report computer', 'entities_id' => 0]);
    fixture('glpi_computers', ['name' => 'Deleted report computer', 'entities_id' => $entity, 'is_deleted' => 1]);
    fixture('glpi_computers', ['name' => 'Template report computer', 'entities_id' => $entity, 'is_template' => 1]);
    $printer = fixture('glpi_printers', ['name' => 'Shared printer', 'entities_id' => $entity]);
    foreach ([$computer, $other] as $id) {
        fixture('glpi_computers_items', ['computers_id' => $id, 'itemtype' => 'Printer', 'items_id' => $printer]);
    }
    $assets = new \itsmng\Database\Repository\AssetRepository(\itsmng\Database\Orm::create($DB));
    verify($assets->count('Computer', [$entity]) === 1, 'Scoped asset count excludes deleted/templates');
    verify($assets->count('Printer', [$entity]) === 1, 'A shared asset is counted once');
    verify($assets->count('Computer', []) === 0, 'Empty authorization scope matches nothing');
    foreach (['Visible report OS' => $computer, 'Hidden report OS' => $other] as $name => $id) {
        $os = fixture('glpi_operatingsystems', ['name' => $name]);
        fixture('glpi_items_operatingsystems', ['operatingsystems_id' => $os, 'items_id' => $id, 'itemtype' => 'Computer']);
    }
    ob_start();
    Report::showDefaultReport();
    $html = ob_get_clean();
    verify(str_contains($html, 'Visible report OS') && !str_contains($html, 'Hidden report OS'), 'OS report obeys computer entity scope');

    $switch = fixture('glpi_networkequipments', ['name' => 'Report switch', 'entities_id' => $entity]);
    $ports = [];
    foreach ([1, 2] as $number) {
        $port = fixture('glpi_networkports', ['name' => 'Report port ' . $number, 'itemtype' => 'NetworkEquipment', 'items_id' => $switch, 'entities_id' => $entity]);
        $ports[] = $port;
        $networkname = fixture('glpi_networknames', ['name' => 'Report name', 'items_id' => $port, 'itemtype' => 'NetworkPort']);
        foreach ([1, 2] as $address) {
            fixture('glpi_ipaddresses', ['name' => "192.0.$number.$address", 'items_id' => $networkname, 'itemtype' => 'NetworkName']);
        }
    }
    fixture('glpi_networkports_networkports', ['networkports_id_1' => $ports[0], 'networkports_id_2' => $ports[1]]);
    $renderNetwork = static function () use ($switch): string {
        ob_start();
        Report::reportForNetworkInformations('glpi_networkequipments AS ITEM', ['PORT_1' => 'items_id', 'ITEM' => 'id', ['AND' => ['PORT_1.itemtype' => 'NetworkEquipment']]], ['ITEM.id' => $switch]);
        return ob_get_clean();
    };
    $html = $renderNetwork();
    verify(str_contains($html, '192.0.1.1,192.0.1.2') && str_contains($html, '192.0.2.1,192.0.2.2'), 'Both endpoints aggregate distinct ordered IP addresses without Cartesian duplication');
    $DB->update('glpi_networkports', ['entities_id' => 0], ['id' => $ports[1]]);
    $html = $renderNetwork();
    verify(!str_contains($html, '192.0.2.'), 'Opposite endpoint cannot leak addresses from another entity');

    $reservable = fixture('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $computer, 'entities_id' => $entity]);
    $hidden = fixture('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $other, 'entities_id' => 0]);
    $storage = new \itsmng\Database\MappedStorage($DB);
    foreach ([$reservable, $hidden] as $id) {
        $storage->insert('glpi_reservations', ['reservationitems_id' => $id, 'users_id' => 2, 'begin' => '2025-02-01 12:00:00', 'end' => '2025-02-01 13:00:00']);
    }
    $reservations = new \itsmng\Database\Repository\ReservationRepository(\itsmng\Database\Orm::create($DB));
    $rows = $reservations->forUser(2, '2025-01-01 00:00:00', false, [$entity]);
    verify(count($rows) === 1 && (int)$rows[0]['items_id'] === $computer && $rows[0]['begin'] === '2025-02-01 12:00:00', 'Mapped reservations preserve dates and entity scope');
    verify(count($reservations->forUser(2, '2025-03-01 00:00:00', true, [$entity])) === 1, 'Past reservations');
    verify($reservations->forUser(2, '2025-03-01 00:00:00', false, [$entity]) === [], 'Past and future do not overlap');

    fixture('glpi_useremails', ['users_id' => 2, 'email' => 'report-a@example.invalid']);
    fixture('glpi_useremails', ['users_id' => 2, 'email' => 'report-b@example.invalid']);
    $DB->getDoctrineConnection()->update('glpi_users', ['access_custom_shortcuts' => '{"example":true}'], ['id' => 2]);
    $users = iterator_to_array(User::getSqlSearchResult(false, 'reservation', $entity));
    $ids = array_column($users, 'id');
    verify(count($ids) === count(array_unique($ids)), 'User selector deduplicates identity including JSON rows');
    $count = User::getSqlSearchResult(true, 'reservation', $entity)->next();
    verify((int)$count['CPT'] === count($ids), 'User count excludes email and profile fan-out');
    $user = \itsmng\Database\Orm::create($DB)->find(\itsmng\Database\Entity\User::class, 2);
    verify($user->access_custom_shortcuts === ['example' => true], 'Mapped JSON hydrates as an array');

    $infocom = fixture('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $computer, 'buy_date' => '2024-12-01', 'use_date' => '2025-02-01']);
    $criteria = \itsmng\Reporting\Criteria::financialDates('2025-01-01', '2025-01-31');
    verify($DB->request(['FROM' => 'glpi_infocoms', 'WHERE' => ['id' => $infocom, $criteria]])->count() === 0, 'Dates straddling an interval do not falsely match');
    $DB->update('glpi_infocoms', ['use_date' => '2025-01-31'], ['id' => $infocom]);
    verify($DB->request(['FROM' => 'glpi_infocoms', 'WHERE' => ['id' => $infocom, $criteria]])->count() === 1, 'Either complete date interval includes its boundary');
    verify($DB->request(['FROM' => 'glpi_infocoms', 'WHERE' => ['id' => $infocom, \itsmng\Reporting\Criteria::year('glpi_infocoms.use_date', '2025')]])->count() === 1, 'Portable year range');

    $ticket = fixture('glpi_tickets', ['name' => 'Report ticket', 'entities_id' => $entity, 'date' => '2025-01-04 12:00:00', 'solvedate' => '2025-01-07 12:00:00', 'closedate' => '2025-01-08 12:00:00', 'status' => Ticket::CLOSED]);
    $types = ['inter_total', 'inter_solved', 'inter_solved_late', 'inter_closed', 'inter_solved_with_actiontime', 'inter_avgsolvedtime', 'inter_avgclosedtime', 'inter_avgactiontime', 'inter_avgtakeaccount', 'inter_opensatisfaction', 'inter_answersatisfaction', 'inter_avgsatisfaction'];
    foreach ($types as $type) {
        $values = Stat::constructEntryValues('Ticket', $type, '2025-01-01', '2025-02-28');
        verify(array_keys($values) === ['2025-01', '2025-02'], 'Portable monthly statistics: ' . $type);
        if ($type === 'inter_total') {
            verify((int)$values['2025-01'] === 1 && (int)$values['2025-02'] === 0, 'Monthly statistics values');
        }
    }

} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": scoped reports, network aggregation, financial dates and monthly statistics passed.\n";
