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

namespace Glpi\Features;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use CommonDBConnexity;
use Session;
use Toolbox;

/**
 * Clonable objects
 **/
trait Clonable
{
    /**
     * Get relations class to clone along with current eleemnt
     *
     * @return CommonDBTM::class[]
     */
    abstract public function getCloneRelations(): array;

    public function post_clone($source, $history)
    {
        parent::post_clone($source, $history);

        $clone_relations = $this->getCloneRelations();
        foreach ($clone_relations as $classname) {
            $override_input = [];

            if (!is_a($classname, CommonDBConnexity::class, true)) {
                Toolbox::logWarning(
                    sprintf(
                        'Unable to clone elements of class %s as it does not extends "CommonDBConnexity"',
                        $classname
                    )
                );
                continue;
            }

            $item_field = $classname::getItemField($this->getType());
            $override_input[$item_field] = $this->getID();

            // Force entity / recursivity based on cloned parent, with fallback on session values
            $override_input['entities_id'] = $this->isEntityAssign() ? $this->getEntityID() : Session::getActiveEntity();
            $override_input['is_recursive'] = $this->maybeRecursive() ? $this->isRecursive() : Session::getIsActiveEntityRecursive();

            $relation_items = $classname::getItemsAssociatedTo($this->getType(), $source->getID());
            if (!$relation_items) {
                continue;
            }

            foreach ($relation_items as $relation_item) {
                $relation_override = $override_input;
                // Abstract families such as Item_Devices return concrete models;
                // their owning subject columns belong to each concrete entity.
                $entity = \itsmng\Database\EntityRegistry::tables()[$relation_item::getTable()] ?? null;
                if ($item_field === 'items_id' && $entity !== null && is_a($entity, \itsmng\Database\Mapping\LegacyInput::class, true) && method_exists($entity, 'withReference')) {
                    $relation_override = array_replace($relation_override, (new $entity())->normalizeInput(
                        $entity::withReference($relation_override, $this->getType(), (int)$this->getID())
                    ));
                }
                $relation_item->clone($relation_override, $history);
            }
        }
    }
}
