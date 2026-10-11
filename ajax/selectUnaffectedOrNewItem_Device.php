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

use itsmng\Database\EntityRegistry;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ComponentRepository;

/**
 * @since 0.85
 */

include(__DIR__ . '/../inc/includes.php');

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

Session::checkCentralAccess();

// Make a select box
if (
    $_POST['items_id']
    && $_POST['itemtype'] && class_exists($_POST['itemtype'])
) {
    $devicetype = $_POST['itemtype'];
    $linktype   = $devicetype::getItem_DeviceType();

    if (isset(EntityRegistry::tables()[$linktype::getTable()])) {
        $manager = Orm::create($DB);
        try {
            $result = (new ComponentRepository($manager))->stock(
                $linktype::getTable(),
                $devicetype::getForeignKeyField(),
                (int)$_POST['items_id']
            );
        } finally {
            $manager->clear();
        }
        foreach ($result as &$row) {
            $values = [];
            foreach (array_keys($linktype::getSpecificities()) as $field) {
                if (($row[$field] ?? null) !== null) {
                    $values[] = $row[$field];
                }
            }
            $row['name'] = $values ? implode(' - ', $values) : $row['id'];
        }
        unset($row);
    } else {
        // Unmapped plugin components retain their existing selection boundary.
        if (count($linktype::getSpecificities())) {
            $keys = array_keys($linktype::getSpecificities());
            array_walk($keys, function (&$val) use ($DB) {
                return $DB->quoteName($val);
            });
            $name_field = new QueryExpression(
                "CONCAT_WS(' - ', " . implode(', ', $keys) . ")"
                . "AS " . $DB->quoteName("name")
            );
        } else {
            $name_field = 'id AS name';
        }
        $result = $DB->request(
            [
          'SELECT' => ['id', $name_field],
          'FROM'   => $linktype::getTable(),
          'WHERE'  => [
             $devicetype::getForeignKeyField() => $_POST['items_id'],
             'itemtype'                        => '',
          ]
        ]
        );
    }
    $devices = [];
    foreach ($result as $row) {
        $name = $row['name'];
        if (empty($name)) {
            $name = $row['id'];
        }
        $devices[$row['id']] = $name;
    }
    echo json_encode(['name' => $devicetype::getForeignKeyField(), 'options' => $devices]);
}
