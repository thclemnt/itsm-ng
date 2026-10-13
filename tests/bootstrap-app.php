<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

ini_set('display_errors', 'On');
error_reporting(E_ALL);

define('GLPI_ROOT', __DIR__ . '/../');
define('GLPI_CONFIG_DIR', getenv('GLPI_CONFIG_DIR') ?: __DIR__ . '/config');
define('GLPI_VAR_DIR', getenv('GLPI_VAR_DIR') ?: __DIR__ . '/files');
define('GLPI_URI', getenv('GLPI_URI') ?: 'http://localhost:8088');

define(
    'PLUGINS_DIRECTORIES',
    [
      GLPI_ROOT . '/plugins',
      GLPI_ROOT . '/tests/fixtures/plugins',
   ]
);

define('TU_USER', '_test_user');
define('TU_PASS', 'PhpUnit_4');

global $CFG_GLPI, $GLPI_CACHE;

include(GLPI_ROOT . "/inc/based_config.php");

if (!file_exists(GLPI_CONFIG_DIR . '/config_db.php')) {
    die("\nConfiguration file for tests not found\n\nrun: bin/console itsmng:database:install --config-dir=" . GLPI_CONFIG_DIR . " ...\n\n");
}

// Create subdirectories of GLPI_VAR_DIR based on defined constants
foreach (get_defined_constants() as $constant_name => $constant_value) {
    if (preg_match('/^GLPI_[\w]+_DIR$/', $constant_name)
        && preg_match('/^' . preg_quote(GLPI_VAR_DIR, '/') . '\//', $constant_value)) {
        is_dir($constant_value) or mkdir($constant_value, 0755, true);
    }
}

//init cache
$GLPI_CACHE = Config::getCache('cache_db');

include_once __DIR__ . '/../inc/includes.php';
include_once __DIR__ . '/GLPITestCase.php';
include_once __DIR__ . '/DbTestCase.php';
include_once __DIR__ . '/APIBaseClass.php';

// check folder exists instead of class_exists('\GuzzleHttp\Client'), to prevent global includes
if (file_exists(__DIR__ . '/../vendor/autoload.php') && !file_exists(__DIR__ . '/../vendor/guzzlehttp/guzzle')) {
    die("\nDevelopment dependencies not found\n\nrun: composer install -o\n\n");
}

class GlpitestPHPerror extends Exception
{
}
class GlpitestPHPwarning extends Exception
{
}
class GlpitestPHPnotice extends Exception
{
}
class GlpitestSQLError extends Exception
{
}

/**
 * Test helper, search an item from its type and name
 *
 * @param string  $type
 * @param string  $name
 * @param boolean $onlyid
 * @return CommonDBTM|false the item, or its id
 */
function getItemByTypeName($type, $name, $onlyid = false)
{

    $item = getItemForItemtype($type);
    $nameField = $type::getNameField();
    if ($item->getFromDBByCrit([$nameField => $name])) {
        return ($onlyid ? $item->getField('id') : $item);
    }
    return false;
}
