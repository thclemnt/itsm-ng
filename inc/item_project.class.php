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
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\ItemProject;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProjectAssetRepository;
use itsmng\Database\RowIterator;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}


/**
 * Item_Project Class
 *
 *  Relation between Projects and Items
 *
 *  @since 0.85
**/
class Item_Project extends CommonDBRelation
{
    // From CommonDBRelation
    public static $itemtype_1          = 'Project';
    public static $items_id_1          = 'projects_id';

    public static $itemtype_2          = 'itemtype';
    public static $items_id_2          = 'items_id';
    public static $checkItem_2_Rights  = self::HAVE_VIEW_RIGHT_ON_ITEM;



    public function getForbiddenStandardMassiveAction()
    {

        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        return $forbidden;
    }


    public function prepareInputForAdd($input)
    {
        global $DB;
        try {
            $normalized = (new ItemProject())->normalizeInput($input);
        } catch (InvalidArgumentException) {
            return false;
        }
        $kind = $normalized['itemtype'];
        $column = EntityRegistry::discriminatedReferences(static::getTable())['items_id']['selections'][$kind]['column'];
        $input = $normalized + ['items_id' => $normalized[$column]];

        // Avoid duplicate entry
        if (
            (new ProjectAssetRepository(Orm::create($DB)))
                ->hasBinding((int)($input['projects_id'] ?? 0), $kind, (int)$input['items_id'])
        ) {
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $selections = EntityRegistry::discriminatedReferences(static::getTable())['items_id']['selections'];
        if (array_intersect(array_keys($input), ['itemtype', 'items_id', ...array_column($selections, 'column')])) {
            $input += ['itemtype' => $this->fields['itemtype']];
            $column = $selections[$input['itemtype']]['column'] ?? null;
            if ($column === null) {
                return false;
            }
            if (!array_key_exists($column, $input) && !array_key_exists('items_id', $input)) {
                $input['items_id'] = $this->fields['items_id'];
            }
            try {
                $normalized = (new ItemProject())->normalizeInput($input);
            } catch (InvalidArgumentException) {
                return false;
            }
            $input = $normalized + ['items_id' => $normalized[$column]];
        }
        return parent::prepareInputForUpdate($input);
    }

    /** Both Project roles are explicit; unrelated subject IDs never select another kind. */
    public static function getSQLCriteriaToSearchForItem($itemtype, $items_id)
    {
        $selection = EntityRegistry::discriminatedReferences(static::getTable())['items_id']['selections'][$itemtype] ?? null;
        $conditions = [];
        if ($itemtype === Project::class) {
            $conditions[] = ['projects_id' => $items_id];
        }
        if ($selection !== null) {
            $conditions[] = [$selection['column'] => $items_id];
        }
        return $conditions ? ['SELECT' => 'id', 'FROM' => static::getTable(), 'WHERE' => ['OR' => $conditions]] : null;
    }

    public static function getDistinctTypes($items_id, $extra_where = [])
    {
        global $DB;
        return new RowIterator(
            (new ProjectAssetRepository(Orm::create($DB)))->kinds((int)$items_id, $extra_where)
        );
    }

    public static function getItemsAssociationRequest($itemtype, $items_id)
    {
        global $DB;
        return new RowIterator(
            (new ProjectAssetRepository(Orm::create($DB)))
                ->relationshipsForItem($itemtype, (int)$items_id)
        );
    }

    public static function getOppositeByTypeAndID($itemtype, $items_id, &$relations_id = null)
    {
        $rows = static::getItemsAssociationRequest($itemtype, $items_id);
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows->next();
        if ($row['is_1'] === $row['is_2']) {
            return false;
        }
        $role = $row['is_1'] ? 2 : 1;
        $opposite = getItemForItemtype($row['itemtype_' . $role]);
        if (!$opposite || !$opposite->getFromDB($row['items_id_' . $role])) {
            return false;
        }
        if ($relations_id !== null) {
            $relations_id = $row['id'];
        }
        return $opposite;
    }

    private static function subjectCriteria(CommonDBTM $item): array
    {
        $criteria = $item->maybeTemplate() ? ['is_template' => false] : [];
        if ($item->isEntityAssign()) {
            $criteria += getEntitiesRestrictCriteria($item->getTable(), '', '', 'auto');
        }
        return $criteria;
    }

    public static function getTypeItems($items_id, $itemtype)
    {
        global $DB;
        $item = getItemForItemtype($itemtype);
        $rows = [];
        if ($item && $item->canView()) {
            $component = $item instanceof Item_Devices;
            $rows = (new ProjectAssetRepository(Orm::create($DB)))
                ->subjects((int)$items_id, $itemtype, self::subjectCriteria($item), $component ? 'itemtype' : $item::getNameField(), $component ? $itemtype::$items_id_2 : null);
        }
        return new RowIterator($rows);
    }

    public static function countForMainItem(CommonDBTM $item, $extra_types_where = [])
    {
        global $DB;
        if (!$item->can($item->getID(), READ)) {
            return 0;
        }
        $repository = new ProjectAssetRepository(Orm::create($DB));
        $count = 0;
        foreach ($repository->kinds((int)$item->getID(), $extra_types_where) as $row) {
            $subject = getItemForItemtype($row['itemtype']);
            if ($subject && $subject->canView()) {
                $count += $repository->subjectCount((int)$item->getID(), $row['itemtype'], self::subjectCriteria($subject));
            }
        }
        return $count;
    }

    public static function countForItem(CommonDBTM $item)
    {
        global $DB;
        $criteria = Session::isCron() ? [] : getEntitiesRestrictCriteria(Project::getTable(), '', '', 'auto');
        return (new ProjectAssetRepository(Orm::create($DB)))
            ->ownerCount($item->getType(), (int)$item->getID(), $criteria);
    }


    /**
     * Print the HTML array for Items linked to a project
     *
     * @param $project Project object
     *
     * @return void
    **/
    public static function showForProject(Project $project)
    {
        global $CFG_GLPI;

        $instID = $project->fields['id'];

        if (!$project->can($instID, READ)) {
            return false;
        }
        $canedit = $project->canEdit($instID);
        $rand    = mt_rand();

        $types_iterator = self::getDistinctTypes($instID);
        $number = count($types_iterator);

        if ($canedit) {
            $itemtypes = $CFG_GLPI['contract_types'];
            $options = [];
            foreach ($itemtypes as $itemtype) {
                $options[$itemtype] = $itemtype::getTypeName(1);
            };

            $dropdownChoiceTokens = [];
            foreach (array_keys(array_unique($options)) as $kind) {
                $dropdownChoiceTokens[$kind] = DropdownChoiceContext::token($kind, []);
            }
            $dropdownChoiceTokens = json_encode($dropdownChoiceTokens, JSON_THROW_ON_ERROR);

            $form = [
               'action' => Toolbox::getItemTypeFormURL(__CLASS__),
               'buttons' => [
                  [
                     'type' => 'submit',
                     'name' => 'add',
                     'value' => _sx('button', 'Add an item'),
                     'class' => 'btn btn-secondary'
                  ]
               ],
               'content' => [
                  __('Add an item') => [
                     'visible' => true,
                     'inputs' => [
                        [
                           'type' => 'hidden',
                           'name' => 'projects_id',
                           'value' => $instID
                        ],
                        __('Type') => [
                           'type' => 'select',
                           'id' => 'dropdown_itemtypeForProject',
                           'name' => 'itemtype',
                           'values' => [Dropdown::EMPTY_VALUE] + array_unique($options),
                           'col_lg' => 6,
                           'hooks' => [
                              'change' => <<<JS
                                 const choiceToken = ({$dropdownChoiceTokens})[this.value];
                                 if (!choiceToken) {
                                     $('#dropdown_items_idForProject').empty();
                                     return;
                                 }
                              $.ajax({
                                    method: "POST",
                                    url: "$CFG_GLPI[root_doc]/ajax/getDropdownValue.php",
                                    data: {
                                       itemtype: this.value,
                                       _idor_token: choiceToken,
                                       display_emptychoice: 1,
                                    },
                                    success: function(response) {
                                       const data = response.results;
                                       $('#dropdown_items_idForProject').empty();
                                       for (let i = 0; i < data.length; i++) {
                                          if (data[i].children) {
                                             const group = $('#dropdown_items_idForProject')
                                                .append("<optgroup label='" + data[i].text + "'></optgroup>");
                                             for (let j = 0; j < data[i].children.length; j++) {
                                                group.append("<option value='" + data[i].children[j].id + "'>" + data[i].children[j].text + "</option>");
                                             }
                                          } else {
                                             $('#dropdown_items_idForProject').append("<option value='" + data[i].id + "'>" + data[i].text + "</option>");
                                          }
                                       }
                                    }
                                 });
                           JS,
                           ]
                        ],
                        __('Item') => [
                           'type' => 'select',
                           'id' => 'dropdown_items_idForProject',
                           'name' => 'items_id',
                           'values' => [],
                           'col_lg' => 6,
                        ],
                     ]
                  ]
               ]
            ];
            renderTwigForm($form);
        }

        if ($canedit && $number) {
            $massiveactionparams = [
               'container' => 'tableForProjectItem',
               'specific_actions' => [
                  'MassiveAction:purge' => _x('button', 'Delete permanently the relation with selected elements'),
               ],
               'display_arrow' => false,
            ];
            Html::showMassiveActions($massiveactionparams);
        }

        $fields = [
           _n('Type', 'Types', 1),
           Entity::getTypeName(1),
           __('Name'),
           __('Serial number'),
           __('Inventory number'),
        ];
        $values = [];
        $massiveactionValues = [];
        while ($row = $types_iterator->next()) {
            $itemtype = $row['itemtype'];
            if (!($item = getItemForItemtype($itemtype))) {
                continue;
            }

            if ($item->canView()) {
                $iterator = self::getTypeItems($instID, $itemtype);
                $nb = count($iterator);

                while ($data = $iterator->next()) {
                    $name = $data[$itemtype::getNameField()];
                    if (
                        $_SESSION["glpiis_ids_visible"]
                        || empty($data[$itemtype::getNameField()])
                    ) {
                        $name = sprintf(__('%1$s (%2$s)'), $name, $data["id"]);
                    }
                    $link     = $item::getFormURLWithID($data['id']);
                    $namelink = "<a href=\"" . $link . "\">" . $name . "</a>";

                    $values[] = [
                       $item->getTypeName(),
                       Dropdown::getDropdownName("glpi_entities", $data['entity']),
                       $namelink,
                       (isset($data["serial"]) ? "" . $data["serial"] . "" : "-"),
                       (isset($data["otherserial"]) ? "" . $data["otherserial"] . "" : "-"),
                    ];
                    $massiveactionValues[] = sprintf('item[%s][%s]', self::class, $data['linkid']);
                }
            }
        }
        renderTwigTemplate('table.twig', [
           'id' => 'tableForProjectItem',
           'fields' => $fields,
           'values' => $values,
           'massive_action' => $massiveactionValues,
        ]);
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {

        if (!$withtemplate) {
            $nb = 0;
            switch ($item->getType()) {
                case 'Project':
                    if ($_SESSION['glpishow_count_on_tabs']) {
                        $nb = self::countForMainItem($item);
                    }
                    return self::createTabEntry(_n('Item', 'Items', Session::getPluralNumber()), $nb);

                default:
                    // Not used now
                    if (Session::haveRight("project", Project::READALL)) {
                        if ($_SESSION['glpishow_count_on_tabs']) {
                            // Direct one
                            $nb = self::countForItem($item);

                            // Linked items
                            $linkeditems = $item->getLinkedItems();

                            if (count($linkeditems)) {
                                foreach ($linkeditems as $type => $tab) {
                                    $typeitem = new $type();
                                    foreach ($tab as $ID) {
                                        $typeitem->getFromDB($ID);
                                        $nb += self::countForItem($typeitem);
                                    }
                                }
                            }
                        }
                        return self::createTabEntry(Project::getTypeName(Session::getPluralNumber()), $nb);
                    }
            }
        }
        return '';
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        switch ($item->getType()) {
            case 'Project':
                self::showForProject($item);
                break;

            default:
                // Not defined and used now
                // Project::showListForItem($item);
        }
        return true;
    }
}
