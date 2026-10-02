<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use itsmng\Database\InitialData;
use itsmng\Database\InstallationConnection;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/initial-data.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
verify(InstallationConnection::hasApplicationTables($DB->getDoctrineConnection()), 'Installation guard finds core tables through DBAL');
foreach ([
    'localhost' => ['host' => 'localhost'],
    '127.0.0.1:3307' => ['host' => '127.0.0.1', 'port' => 3307],
    'localhost:/tmp/itsm-port.sock' => ['host' => 'localhost', 'unix_socket' => '/tmp/itsm-port.sock'],
    '[::1]:3307' => ['host' => '::1', 'port' => 3307],
] as $endpoint => $expected) {
    $connection = InstallationConnection::mysqlServer($endpoint, 'fixture', '');
    verify(array_intersect_key($connection->getParams(), $expected) === $expected, 'Installation endpoint parsing');
    $connection->close();
}
if ($DB->getProvider() === 'mysql') {
    $server = InstallationConnection::mysqlServer($DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword));
    try {
        verify(!InstallationConnection::ensureMysqlDatabase($server, $DB->dbdefault), 'Existing database is selected without creating it again');
        $databases = array_column(InstallationConnection::mysqlDatabases($server), null, 'name');
        verify(isset($databases[$DB->dbdefault]) && (int)$databases[$DB->dbdefault]['table_count'] === count($DB->listTables('%')), 'Installer catalogue includes the selected database and its tables');
        verify(!array_intersect(['information_schema', 'mysql', 'performance_schema', 'sys'], array_keys($databases)), 'System databases are excluded from installation choices');
        $selected = InstallationConnection::mysqlDatabase($DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $DB->dbdefault);
        try {
            verify($selected->getDatabase() === $DB->dbdefault && $selected->getServerVersion() !== '', 'Named installation connection uses the selected database');
        } finally {
            $selected->close();
        }
    } finally {
        $server->close();
    }
    // The last native mysqli constructor was in this historical upgrade helper.
    require_once GLPI_ROOT . '/install/update_068_0681.php';
    $legacyConnection = $DB->getDoctrineConnection();
    $legacyConnection->executeStatement('CREATE TEMPORARY TABLE glpi_ocs_config (ocs_db_host VARCHAR(255), ocs_db_user VARCHAR(255), ocs_db_passwd VARCHAR(255), ocs_db_name VARCHAR(255))');
    $savedDb = $GLOBALS['db'] ?? null;
    $savedConfig = $GLOBALS['cfg_glpi'] ?? null;
    $ocs = null;
    try {
        $GLOBALS['db'] = $DB;
        $GLOBALS['cfg_glpi'] = ['ocs_mode' => false];
        verify(!(new DBocs())->connected, 'Disabled historical OCS connection remains disconnected');
        $GLOBALS['cfg_glpi']['ocs_mode'] = true;
        verify((new DBocs())->error === 1, 'Missing OCS configuration fails without opening a connection');
        $legacyConnection->insert('glpi_ocs_config', [
            'ocs_db_host' => $DB->dbhost, 'ocs_db_user' => $DB->dbuser,
            'ocs_db_passwd' => rawurldecode($DB->dbpassword), 'ocs_db_name' => $DB->dbdefault,
        ]);
        $ocs = new DBocs();
        verify($ocs->connected && $ocs->getDoctrineConnection()->getDatabase() === $DB->dbdefault, 'Historical OCS helper owns a DBAL connection to the configured database');
        $result = $ocs->query('SELECT 1 AS result');
        verify($ocs->fetchAssoc($result) === ['result' => 1], 'Historical migration query compatibility uses the DBAL transport');
        $ocs->freeResult($result);
    } finally {
        $ocs?->close();
        $GLOBALS['db'] = $savedDb;
        $GLOBALS['cfg_glpi'] = $savedConfig;
        $legacyConnection->executeStatement('DROP TEMPORARY TABLE glpi_ocs_config');
    }
}
$repository = static fn () => new RecordRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $context = "Initial data NULL O'Reilly \\path 日本語";
    $progress = 0;
    $cron = [];
    foreach ([0, 1, 2, 3] as $allow) {
        $cron[] = ['itemtype' => 'InitialDataFixture', 'name' => 'initial_fixture_' . $allow, 'mode' => 1, 'allowmode' => $allow];
    }
    $cron[] = ['itemtype' => 'InitialDataFixture', 'name' => 'watcher', 'mode' => 1, 'allowmode' => 3];
    InitialData::load($DB, [
        'glpi_configs' => [
            ['context' => $context, 'name' => 'NULL', 'value' => 'NULL'],
            ['context' => $context, 'name' => 'nullable', 'value' => null],
            ['context' => $context, 'name' => 'quoted', 'value' => "O'Reilly \\path 日本語"],
        ],
        'glpi_crontasks' => $cron,
    ], static function () use (&$progress): void {
        ++$progress;
    });
    verify($progress === 8, 'Progress runs for every inserted seed row');
    verify(Config::getConfigurationValues($context) === ['NULL' => 'NULL', 'nullable' => null, 'quoted' => "O'Reilly \\path 日本語"], 'Raw ORM seed values preserve literal NULL, null and escaping');
    InitialData::enableSystemCron($DB);
    $modes = array_column($repository()->matching('glpi_crontasks', ['itemtype' => 'InitialDataFixture'], 'id'), 'mode', 'name');
    verify($modes === ['initial_fixture_0' => 1, 'initial_fixture_1' => 1, 'initial_fixture_2' => 2, 'initial_fixture_3' => 2, 'watcher' => 1], 'System cron selects the allowed mode bit and excludes watcher');
    try {
        InitialData::load($DB, [
            'glpi_configs' => [['context' => $context, 'name' => 'rolled_back', 'value' => 'must disappear']],
            'glpi_useremails' => [['users_id' => 2147483647, 'email' => 'invalid@example.invalid']],
        ]);
        throw new RuntimeException('Invalid seed graph was accepted');
    } catch (ForeignKeyConstraintViolationException) {
    }
    verify(Config::getConfigurationValues($context, ['rolled_back']) === [], 'Seed failure rolls back earlier rows across tables');
    try {
        InitialData::load($DB, [
            'glpi_configs' => [['context' => $context, 'name' => 'unmapped_plan', 'value' => 'must not be written']],
            'unmapped_initial_table' => [],
        ]);
        throw new RuntimeException('Unmapped seed table was accepted');
    } catch (InvalidArgumentException) {
    }
    verify(Config::getConfigurationValues($context, ['unmapped_plan']) === [], 'Unmapped seed table fails before writes');
    verify($SQL_TOTAL_REQUEST === 0, 'Initial data and system cron configuration use ORM without adapter execution');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": ORM initial data, literal values, rollback, system cron and installation catalogue guards passed.\n";
