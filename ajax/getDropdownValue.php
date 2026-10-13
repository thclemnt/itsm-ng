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

use itsmng\Database\DropdownChoiceContext;

/**
 * @since 0.85
 */

// Direct access to file
if (strpos($_SERVER['PHP_SELF'], "getDropdownValue.php")) {
    include('../inc/includes.php');
    header("Content-Type: application/json; charset=UTF-8");
    Html::header_nocache();
} elseif (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

Session::checkLoginUser();
$request = $_POST;
$request['_dropdown_choice_context'] = DropdownChoiceContext::encode($_POST);
$token = $request['_idor_token'] ?? null;
if (!is_string($token) || !isset($_SESSION['glpiidortokens'][$token]['_dropdown_choice_context']) || !Session::validateIDOR($request)) {
    http_response_code(403);
    echo json_encode(['error' => __('Invalid dropdown request.')]);
    return;
}
echo Dropdown::getDropdownValue($_POST);
