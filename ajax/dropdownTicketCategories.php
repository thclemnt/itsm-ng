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

if (strpos($_SERVER['PHP_SELF'], "dropdownTicketCategories.php")) {
    include('../inc/includes.php');
    header("Content-Type: text/html; charset=UTF-8");
    Html::header_nocache();
} elseif (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

Session::checkLoginUser();
global $DB;
$active = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
$requested = array_filter((array)($_POST['entity_restrict'] ?? $active), static fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false);
$requested = array_map('intval', $requested);
$entities = array_values(array_intersect($active, $requested));
$em = \itsmng\Database\Orm::create($DB);
try {
    $values = (new \itsmng\Database\Repository\TicketCategoryRepository($em))->choices(
        (int)($_POST['type'] ?? 0),
        $entities,
        Session::getCurrentInterface() === 'helpdesk'
    );
} finally {
    $em->clear();
}
$values[0] = Dropdown::EMPTY_VALUE;
echo json_encode($values);
