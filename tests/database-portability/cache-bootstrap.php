<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Tools\SchemaTool;
use Glpi\Cache\SimpleCache;
use itsmng\Database\Entity\Config as Configuration;
use itsmng\Database\Orm;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\Memory;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/cache-bootstrap.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$installed = $DB;
verify(str_starts_with($installed->dbdefault, 'itsm_port_'), 'Dedicated installed parent database required');
$name = getenv('PORT_CACHE_BOOTSTRAP_DB') ?: 'itsm_port_cache_bootstrap';
verify(str_starts_with($name, 'itsm_port_') && str_ends_with($name, '_cache_bootstrap') && $name !== $installed->dbdefault, 'Cache bootstrap requires a separate disposable database');
$database = DBConnection::createConnection(
    $installed->getProvider(),
    is_array($installed->dbhost) ? reset($installed->dbhost) : $installed->dbhost,
    $installed->dbuser,
    rawurldecode($installed->dbpassword),
    $name
);
verify($database->connected, 'Provision the empty cache bootstrap database and grant the test role access');
$connection = $database->getDoctrineConnection();
$manager = $connection->createSchemaManager();
verify($manager->listTableNames() === [], 'Refuse a cache bootstrap fixture containing existing tables');
$created = false;
$em = null;
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
try {
    // Neither an absent nor a disconnected adapter may require schema inspection.
    $disconnected = (new ReflectionClass($installed))->newInstanceWithoutConstructor();
    $disconnected->connected = false;
    foreach ([null, $disconnected, $database] as $state) {
        $DB = $state;
        foreach (['cache_db', 'cache_trans'] as $option) {
            $storage = Config::getCache($option, 'core', false);
            verify($storage instanceof Filesystem, $option . ': default cache works before configuration exists');
            verify((float)$storage->getOptions()->getTtl() === 600.0, $option . ': default TTL retained');
            $cache = Config::getCache($option);
            verify($cache instanceof SimpleCache && $cache->set('bootstrap-probe', $option) && $cache->get('bootstrap-probe') === $option, $option . ': default PSR16 cache is usable');
            verify($cache->delete('bootstrap-probe'), $option . ': remove probe from the default cache');
        }
    }
    verify($manager->listTableNames() === [], 'Default cache lookup does not create application tables');

    $DB = $database;
    try {
        $database->fieldExists('glpi_cache_missing_configuration', 'context');
        throw new LogicException('General field inspection no longer warns about an absent table');
    } catch (ErrorException $error) {
        verify($error->getSeverity() === E_USER_WARNING && str_contains($error->getMessage(), 'glpi_cache_missing_configuration'), 'The general absent-table warning remains observable');
    }

    // Use the same adapter, without clearing its schema caches or reconnecting.
    // The fixture shape comes from the Config entity, not another runtime declaration.
    $DB = $database;
    $em = Orm::create($database);
    $table = (new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(Configuration::class)])->getTable('glpi_configs');
    $manager->createTable($table);
    $created = true;
    foreach (['cache_db', 'cache_trans'] as $index => $option) {
        $connection->insert('glpi_configs', [
            'id' => $index + 1, 'context' => 'core', 'name' => $option,
            'value' => json_encode(['adapter' => 'memory', 'options' => ['namespace' => 'bootstrap_' . $option, 'ttl' => 37]], JSON_THROW_ON_ERROR),
        ]);
    }
    foreach (['cache_db', 'cache_trans'] as $option) {
        $storage = Config::getCache($option, 'core', false);
        verify($storage instanceof Memory, $option . ': same adapter discovers newly seeded configuration');
        verify($storage->getOptions()->getNamespace() === 'bootstrap_' . $option && (float)$storage->getOptions()->getTtl() === 37.0, $option . ': configured options are retained');
        $cache = Config::getCache($option);
        verify($cache instanceof SimpleCache && $cache->set('configured-probe', $option) && $cache->get('configured-probe') === $option, $option . ': configured PSR16 cache is usable');
    }

    // A present current configuration table with a missing mapped column must
    // still surface its real query error, rather than fall back silently.
    $malformed = clone $table;
    $malformed->dropColumn('value');
    foreach ($connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($table, $malformed)) as $sql) {
        $connection->executeStatement($sql);
    }
    try {
        Config::getCache('cache_db');
        throw new LogicException('Malformed current configuration was silently accepted');
    } catch (DriverException $error) {
        verify(str_contains(strtolower($error->getMessage()), 'value'), 'Missing mapped configuration column remains a database diagnostic');
    }
} finally {
    restore_error_handler();
    $DB = $installed;
    $em?->close();
    if ($created) {
        $manager->dropTable('glpi_configs');
    }
    $database->close();
}
echo $installed->getProvider() . ': ' . $assertions . " cache bootstrap assertions passed: strict warnings, empty schema, same-adapter seeded transition and query diagnostics.\n";
