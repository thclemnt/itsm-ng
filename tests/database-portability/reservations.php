<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ReservationUserReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ReservationRepository;
use itsmng\Database\Repository\ReservationItemRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/reservations.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $owner = $fixtures->create('glpi_users', ['name' => 'Booking owner']);
    $replacement = $fixtures->create('glpi_users', ['name' => 'Booking replacement']);
    $computer = $fixtures->create('glpi_computers', ['name' => 'Booking computer']);
    $item = $fixtures->create('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $computer]);
    $otherComputer = $fixtures->create('glpi_computers', ['name' => 'Other booking computer']);
    $other = $fixtures->create('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $otherComputer]);
    $book = static fn (array $values) => $fixtures->create('glpi_reservations', $values + [
        'reservationitems_id' => $item, 'users_id' => $owner, 'group' => 77,
        'begin' => '2026-10-01 10:00:00', 'end' => '2026-10-01 11:00:00', 'comment' => 'Booking comment',
    ]);
    $first = $book([]);
    $next = $book(['begin' => '2026-10-01 11:00:00', 'end' => '2026-10-01 12:00:00']);
    $unrelated = $book(['reservationitems_id' => $other, 'begin' => '2026-10-02 10:00:00', 'end' => '2026-10-02 11:00:00']);
    $repo = new ReservationRepository(Orm::create($DB));
    $items = new ReservationItemRepository(Orm::create($DB));
    verify($repo->conflicts($item, '2026-10-01 10:30:00', '2026-10-01 11:30:00'), 'Overlap detected');
    verify(!$repo->conflicts($item, '2026-10-01 09:00:00', '2026-10-01 10:00:00'), 'Adjacent earlier interval allowed');
    verify(!$repo->conflicts($item, '2026-10-01 12:00:00', '2026-10-01 13:00:00'), 'Adjacent later interval allowed');
    verify(!$repo->conflicts($item, '2026-10-01 10:00:00', '2026-10-01 11:00:00', $first), 'Update excludes itself');
    verify($repo->groupIds($item, 77) === [$first, $next] && $repo->groupExists($item, 77), 'Group belongs to one item');
    verify(array_column($repo->during($item, '2026-10-01 10:00:00', '2026-10-01 12:00:00'), 'id') === [$first, $next], 'Day listing is ordered');
    verify(array_column($repo->forItem($item, '2026-10-01 11:00:00', true), 'id') === [$first], 'End equality is past');
    verify(array_column($repo->forItem($item, '2026-10-01 11:00:00', false), 'id') === [$next], 'Current list excludes finished booking');
    verify($repo->activeItemIds('2026-10-01 00:00:00', '2026-10-02 00:00:00') === [$item], 'Calendar returns one row per item despite multiple bookings');
    $scope = ['entities_id' => 0];
    verify(array_column($items->available('Computer', 'name', $scope, '2026-10-01 10:30:00', '2026-10-01 11:00:00'), 'id') === [$other], 'Availability excludes occupied item');
    verify(count($items->available('Computer', 'name', $scope, '2026-10-01 12:00:00', '2026-10-01 13:00:00')) === 2, 'Availability agrees with adjacent booking validation');
    verify($items->types([]) === [] && $items->peripheralTypes([]) === [], 'Empty entity scope exposes nothing');
    $type = $fixtures->create('glpi_peripheraltypes', ['name' => 'Booking peripheral type']);
    $p = $fixtures->create('glpi_peripherals', ['name' => 'Booking peripheral', 'peripheraltypes_id' => $type]);
    $pi = $fixtures->create('glpi_reservationitems', ['itemtype' => 'Peripheral', 'items_id' => $p]);
    verify(array_column($items->peripheralTypes([0]), 'id') === [$type], 'Peripheral category query');
    verify(array_column($items->available('Peripheral', 'name', $scope, null, null, $type), 'id') === [$pi], 'Peripheral type selection');
    verify($items->available('Peripheral', 'name', $scope, null, null, $type + 1) === [], 'Different peripheral type excluded');
    foreach ($CFG_GLPI['reservation_types'] as $coreType) {
        $model = getItemForItemtype($coreType);
        $items->available($coreType, $model->getNameField(), $scope, null, null);
    }
    $foreignEntity = (new Entity())->add(['name' => 'Foreign booking entity', 'entities_id' => 0]);
    $foreignComputer = $fixtures->create('glpi_computers', ['name' => 'Foreign booking computer', 'entities_id' => $foreignEntity]);
    $foreignItem = $fixtures->create('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $foreignComputer, 'entities_id' => $foreignEntity]);
    $book(['reservationitems_id' => $foreignItem]);
    $inactive = $fixtures->create('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers', ['name' => 'Inactive booking computer']), 'is_active' => false]);
    $deleted = $fixtures->create('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers', ['name' => 'Disabled booking computer']), 'is_deleted' => true]);
    $deadComputer = $fixtures->create('glpi_computers', ['name' => 'Deleted booking computer', 'is_deleted' => true]);
    $fixtures->create('glpi_reservationitems', ['itemtype' => 'Computer', 'items_id' => $deadComputer]);
    verify(count($items->available('Computer', 'name', $scope, null, null)) === 2, 'Availability filters entities and deleted/inactive items');
    $now = new DateTimeImmutable('2026-10-01 10:30:00');
    verify($repo->expiring(0, 1800, $now) === [], 'Exact alert threshold excluded');
    verify(array_column($repo->expiring(0, 1801, $now), 'resaid') === [$first], 'Alert selects begun bookings below threshold within entity');
    $fixtures->create('glpi_alerts', ['itemtype' => 'Reservation', 'items_id' => $first, 'type' => Alert::END, 'date' => '2026-10-01 10:30:00']);
    verify($repo->expiring(0, 1801, $now) === [], 'Already alerted reservation excluded');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $_POST = ['submit' => 1, 'reservation_types' => 'Computer', 'reserve' => ['begin' => '2026-10-01 12:00:00', 'end' => '2026-10-01 13:00:00']];
    ob_start();
    ReservationItem::showListSimple();
    $html = ob_get_clean();
    verify(str_contains($html, 'Booking computer') && !str_contains($html, 'Foreign booking computer') && !str_contains($html, 'Deleted booking computer'), 'Availability view respects scope');
    $reservation = new Reservation();
    verify($reservation->getFromDB($first), 'Load reservation');
    verify(!$reservation->is_reserved(), 'Model overlap excludes itself');
    $originalFields = $reservation->fields;
    ob_start();
    $rejected = $reservation->prepareInputForUpdate(['id' => $first, 'reservationitems_id' => $other, 'begin' => '2026-10-02 10:00:00', 'end' => '2026-10-02 11:00:00']);
    ob_end_clean();
    verify($rejected === false && $reservation->fields === $originalFields, 'Moving to occupied item is rejected without corrupting model fields');
    ob_start();
    $rejected = $reservation->prepareInputForUpdate(['id' => $first, 'end' => '2026-10-01 09:00:00']);
    ob_end_clean();
    verify($rejected === false && $reservation->fields === $originalFields, 'Invalid dates rejected without corrupting model fields');
    verify($reservation->getUniqueGroupFor($item) > 0, 'Group allocation uses repository');
    $invalid = $book(['begin' => null, 'end' => null, 'group' => 78]);
    $late = $book(['begin' => '2026-10-01 23:59:59', 'end' => '2026-10-02 00:00:00', 'group' => 78]);
    $asset = new Computer();
    verify($asset->getFromDB($computer), 'Load reservable computer');
    ob_start();
    Reservation::showForItem($asset);
    $html = ob_get_clean();
    verify(str_contains($html, 'Booking owner') && str_contains($html, 'Booking comment'), 'Item history renders joined booking owner and comments');
    $user = new User();
    verify($user->delete(['id' => $owner, '_replace_by' => $replacement], true), 'Replace booking owner');
    verify((int)(new Reservation())->find(['id' => $first])[$first]['users_id'] === $replacement, 'Replacement preserves booking');
    verify($user->delete(['id' => $replacement], true), 'Purge booking owner');
    verify($reservation->getFromDB($first) && $reservation->fields['users_id'] === null, 'Purge retains booking with nullable owner');
    verify((new Reservation())->find(['id' => $invalid])[$invalid]['users_id'] === null, 'Owner cleanup is not blocked by a legacy booking with missing dates');
    ob_start();
    Reservation::displayReservationsForAnItem($item, '2026-10-01');
    $html = ob_get_clean();
    verify(!str_contains($html, 'Booking owner') && str_contains($html, '10:00') && str_contains($html, '23:59'), 'Calendar renders unowned reservations including the last second of the day');
    verify($reservation->delete(['id' => $first, '_delete_group' => true, '_disablenotif' => true]), 'Delete repeated reservation group');
    verify(!(new Reservation())->getFromDB($next) && (new Reservation())->getFromDB($unrelated), 'Group purge keeps other item with same group number');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->conflicts($other, '2026-10-01 12:00:00', '2026-10-01 13:00:00');
    $items->available('Computer', 'name', $scope, null, null);
    $repo->expiring(0, 3600, $now);
    $repo->during($other, '2026-10-02 00:00:00', '2026-10-03 00:00:00');
    $repo->forItem($other, '2026-10-01 12:00:00', false);
    $repo->activeItemIds('2026-10-02 00:00:00', '2026-10-03 00:00:00');
    verify($SQL_TOTAL_REQUEST === 0, 'Reservation queries bypass legacy database requests');
    verify((new ForeignKeys())->audit($connection) === [], 'Reservation graph remains valid');
} finally {
    $DB->rollBack();
}

// Recreate an old optional owner column and verify preflight before any DDL.
$platform = $connection->getDatabasePlatform();
$migration = new ReservationUserReferences();
$parent = $fixtures->create('glpi_reservationitems');
$legacy = null;
try {
    $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_reservations', 'users_id'), 'glpi_reservations'));
    $connection->executeStatement('UPDATE glpi_reservations SET users_id = 0 WHERE users_id IS NULL');
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable('glpi_reservations');
    $after = clone $before;
    $after->getColumn('users_id')->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_reservations');
    $connection->insert('glpi_reservations', ['id' => $legacy, 'reservationitems_id' => $parent]);
    verify($migration->plan($connection)['sql'] !== [], 'Old owner column has migration plan');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_reservations WHERE id = ?', [$legacy]) === 0, 'Plan does not normalize data');
    $connection->update('glpi_reservations', ['users_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned reservation user');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_reservations')['users_id']->getNotnull(), 'Orphan rejection precedes DDL');
    $connection->update('glpi_reservations', ['users_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_reservations WHERE id = ?', [$legacy]) === null, 'Empty owner becomes NULL');
    verify($migration->apply($connection) === [], 'Migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_reservations', ['id' => $legacy]);
    }
    $connection->delete('glpi_reservationitems', ['id' => $parent]);
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": reservation ownership, overlap, availability, calendar, alert selection and migration passed.\n";
