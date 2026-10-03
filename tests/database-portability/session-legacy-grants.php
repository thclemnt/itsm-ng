<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php session-legacy-grants.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$installed = $DB;
verify(str_starts_with($installed->dbdefault, 'itsm_port_'), 'Dedicated installed parent database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual login supplies the stale grant snapshot');
$savedSession = $_SESSION;
verify(!empty($_SESSION['glpiprofiles']), 'Existing current-schema grant snapshot is nonempty');
$name = getenv('PORT_SESSION_LEGACY_DB') ?: 'itsm_port_session_legacy';
verify(str_starts_with($name, 'itsm_port_') && str_ends_with($name, '_session_legacy') && $name !== $installed->dbdefault, 'Missing-table control requires a separate disposable empty database');
$legacy = (new ReflectionClass(DBConnection::getAdapterClass($installed->getProvider())))->newInstanceWithoutConstructor();
$legacy->dbhost = is_array($installed->dbhost) ? reset($installed->dbhost) : $installed->dbhost;
$legacy->dbuser = $installed->dbuser;
$legacy->dbpassword = $installed->dbpassword;
$legacy->dbdefault = $name;
// The auxiliary adapter owns a separate physical handle. Preserve explicit
// PostgreSQL endpoint settings instead of falling back to its default port/SSL.
foreach (['dbport', 'dbsslmode'] as $property) {
    if (property_exists($installed, $property) && property_exists($legacy, $property)) {
        $legacy->$property = $installed->$property;
    }
}
$legacy->connect();
verify($legacy->connected, 'Provision the empty legacy-session database and grant the test role access');
$legacyConnection = $legacy->getDoctrineConnection();
verify($legacyConnection->createSchemaManager()->listTableNames() === [], 'Refuse a legacy fixture containing existing tables');
$writer = $installed->getDoctrineConnection();
$depth = $writer->getTransactionNestingLevel();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
try {
    $DB = $legacy;
    Session::initEntityProfiles((int)$savedSession['glpiID']);
    verify($_SESSION['glpiprofiles'] === [], 'Actual absent-grant-table path clears the prior current-schema snapshot');
    $expected = $savedSession;
    $expected['glpiprofiles'] = [];
    verify($_SESSION === $expected, 'Read-only snapshot initialization changes no other session/profile/entity/token state');
    Session::initEntityProfiles((int)$savedSession['glpiID']);
    verify($_SESSION === $expected, 'Repeated legacy snapshot clearing is idempotent');
    verify($legacyConnection->createSchemaManager()->listTableNames() === [], 'Missing-table initialization creates no tables');
    $DB = $installed;
    Session::initEntityProfiles((int)$savedSession['glpiID']);
    verify($_SESSION['glpiprofiles'] === $savedSession['glpiprofiles'], 'Returning to the same installed adapter refreshes its real grants without a global negative cache');
    verify($writer === $installed->getDoctrineConnection() && $writer->getTransactionNestingLevel() === $depth, 'Read-only legacy inspection preserves the original supplied writer and its transaction depth');
} finally {
    $DB = $installed;
    $_SESSION = $savedSession;
    $legacy->close();
    restore_error_handler();
}
echo $installed->getProvider() . ": Actual missing legacy grant-table snapshot clearing, refresh and read-only isolation passed.\n";
