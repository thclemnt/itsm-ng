<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/timestamps.php /path/to/test-config\n");
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
$connection = $DB->getDoctrineConnection();
$postgres = $DB->getProvider() === 'pgsql';
$timezone = $connection->fetchOne($postgres ? 'SHOW TIME ZONE' : 'SELECT @@session.time_zone');
$setTimezone = static function (string $zone) use ($connection, $postgres): void {
    $connection->executeStatement($postgres ? "SELECT set_config('TimeZone', ?, false)" : 'SET SESSION time_zone = ?', [$zone]);
};
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    foreach (['2019-03-04 10:00:00' => '2019-03-04 11:00:00', '2019-07-04 10:00:00' => '2019-07-04 12:00:00'] as $utc => $local) {
        $setTimezone('UTC');
        $id = $fixtures->create('glpi_computers', ['name' => 'Timestamp conversion', 'date_creation' => new DateTime($utc, new DateTimeZone('UTC')), 'date_mod' => null]);
        $setTimezone('Europe/Paris');
        $row = $connection->fetchAssociative('SELECT date_creation, date_mod FROM glpi_computers WHERE id = ?', [$id]);
        verify(substr($row['date_creation'], 0, 19) === $local, 'Stored timestamp follows the session timezone, including DST');
        verify($row['date_mod'] === null, 'Nullable timestamp stays null');
        $setTimezone('UTC');
        verify(substr($connection->fetchOne('SELECT date_creation FROM glpi_computers WHERE id = ?', [$id]), 0, 19) === $utc, 'Timezone change does not alter the stored instant');
    }
} finally {
    $connection->rollBack();
    $setTimezone($timezone);
}
echo $DB->getProvider() . ": fresh-install timestamp timezone conversion, DST and null preservation passed.\n";
