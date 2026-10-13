<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\History;
use itsmng\Database\SchemaCheck;
use itsmng\Database\Upgrade;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$directory = realpath($argv[1] ?? '');
if ($directory === false || !is_file($directory . '/config_db.php')) {
    throw new RuntimeException('An installed test configuration is required.');
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', $directory);
define('GLPI_VAR_DIR', getenv('GLPI_VAR_DIR') ?: GLPI_ROOT . '/tests/files');

// Bootstrap only configuration/autoload and the explicit configured writer.
// Ordinary application startup can exit before these readiness assertions.
require GLPI_ROOT . '/inc/based_config.php';
require GLPI_ROOT . '/inc/db.function.php';
require GLPI_CONFIG_DIR . '/config_db.php';
$database = new DB();
if (!$database->connected || $database->isSlave()) {
    throw new RuntimeException('The configured installation writer is required.');
}
$connection = $database->getDoctrineConnection();
if (($argv[2] ?? null) === '--without-application-fixtures') {
    if ((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_configs WHERE context = ? AND name = ?', ['phpunit', 'dataset']) !== 0
        || (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_plugins WHERE directory = ?', ['tester']) !== 0) {
        throw new RuntimeException('Migration tests require a public installation before ordinary application fixtures; preserve this database.');
    }
}
$pending = History::pendingVersions($connection);
if ($pending || History::isInstalling($connection)) {
    throw new RuntimeException('Canonical installation history is incomplete: ' . implode(', ', $pending));
}
$differences = (new SchemaCheck())->differences($connection);
if ($differences) {
    throw new RuntimeException('Installed core schema differs: ' . implode('; ', $differences));
}
$published = (new Upgrade($database))->release();
foreach (['version' => ITSM_VERSION, 'itsmversion' => ITSM_VERSION,
    'dbversion' => ITSM_SCHEMA_VERSION, 'itsmdbversion' => ITSM_SCHEMA_VERSION] as $name => $expected) {
    if (($published[$name] ?? null) !== $expected) {
        throw new RuntimeException('Canonical release publication is incomplete for ' . $name . '.');
    }
}
echo "Installed canonical history, core schema and release publication verified.\n";
