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

use itsmng\Csrf;
use itsmng\Database\Upgrade;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__));
}
require_once GLPI_ROOT . '/inc/based_config.php';
require_once GLPI_ROOT . '/inc/db.function.php';
require_once GLPI_CONFIG_DIR . '/config_db.php';

global $DB, $CFG_GLPI, $GLPI, $GLPI_CACHE;
$GLPI = new GLPI();
$GLPI->initLogger();
$GLPI->initErrorHandler();
$GLPI_CACHE = Config::getCache('cache_db');
Config::detectRootDoc();
Session::setPath();
Session::start();
Session::loadLanguage('en_GB', false);
$DB = new DB();

// The existing install/update authorization is session-owned. A POST also
// requires the application's one-use CSRF token before any migration work.
if (($_SESSION['can_process_update'] ?? false) !== true || Session::getLoginUserID() <= 0 || !Config::canUpdate()) {
    http_response_code(403);
    Html::maintenanceHeader(__('Upgrade'));
    echo '<p>' . __('Impossible to accomplish an update by this way!') . '</p>';
    Html::maintenanceFooter();
    exit;
}
if ($_POST && GLPI_USE_CSRF_CHECK && !Csrf::verify()) {
    http_response_code(403);
    Html::maintenanceHeader(__('Upgrade'));
    echo '<p>' . __('CSRF token is invalid') . '</p>';
    Html::maintenanceFooter();
    exit;
}
Csrf::generate();
$DB->disableTableCaching();
Config::loadLegacyConfiguration(false, false);
$upgrade = new Upgrade($DB);
$completed = false;
$plan = null;
$errorMessage = null;
$messages = [];
try {
    if (isset($_POST['continuer'])) {
        if (defined('ITSM_PREVER') && empty($_POST['agree_dev'])) {
            throw new RuntimeException('Confirm the development release before applying its canonical history.');
        }
        // Retain response headers until the outcome is known. A failed upgrade
        // must return an error status, even after nontransactional DDL progress.
        $upgrade->apply(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $completed = true;
        unset($_SESSION['can_process_update']);
    } else {
        $plan = $upgrade->plan();
        if ($plan['security_key_missing']) {
            $errorMessage = 'Restore the original encryption key before upgrading: ' . $plan['security_key'];
        }
    }
} catch (Throwable $error) {
    $errorMessage = $error->getMessage();
}
if ($errorMessage !== null) {
    http_response_code(409);
}
Html::maintenanceHeader(__('Upgrade'));
echo '<div class="container"><h2>' . __('Upgrade') . '</h2>';
foreach ($messages as $message) {
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}
if ($errorMessage !== null) {
    echo '<pre>' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</pre>';
} elseif ($completed) {
    echo '<p>' . __('Update successful, your database is up to date') . '</p>';
    echo '<p><a href="../index.php">' . __('Use ITSM-NG') . '</a></p>';
} else {
    echo '<p>' . __('Stop application writers before applying the database upgrade.') . '</p><ul>';
    foreach ($plan['pending'] as $version) {
        echo '<li>' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul><form method="post" action="update.php">';
    if (defined('ITSM_PREVER')) {
        echo Config::agreeDevMessage();
    }
    echo '<button type="submit" name="continuer" value="1">' . __('Continue') . '</button>';
    Html::closeForm();
}
echo '</div>';
Html::maintenanceFooter();
