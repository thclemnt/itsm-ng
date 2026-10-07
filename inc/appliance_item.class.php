<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

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
 **/
use itsmng\Database\ApplianceOwnerReadOperation;

class Appliance_Item extends CommonDBRelation
{
    use Glpi\Features\Clonable;

    public static $itemtype_1 = 'Appliance';
    public static $items_id_1 = 'appliances_id';
    public static $take_entity_1 = false;

    public static $itemtype_2 = 'itemtype';
    public static $items_id_2 = 'items_id';
    public static $take_entity_2 = true;

    public function getCloneRelations(): array
    {
        return [
            Appliance_Item_Relation::class
        ];
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Items', $nb);
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!Appliance::canView()) {
            return '';
        }

        $nb = 0;
        if ($item->getType() == Appliance::class) {
            if ($_SESSION['glpishow_count_on_tabs']) {
                if (!$item->isNewItem()) {
                    $nb = self::countForMainItem($item);
                }
            }
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
        } elseif (in_array($item->getType(), Appliance::getTypes(true))) {
            if ($_SESSION['glpishow_count_on_tabs']) {
                $nb = self::countForItem($item);
            }
            return self::createTabEntry(Appliance::getTypeName(Session::getPluralNumber()), $nb);
        }
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        switch ($item->getType()) {
            case Appliance::class:
                self::showItems($item);
                break;
            default:
                if (in_array($item->getType(), Appliance::getTypes())) {
                    self::showForItem($item, $withtemplate);
                }
        }
        return true;
    }

    /**
     * Print enclosure items
     *
     * @param Appliance $appliance  Appliance object wanted
     *
     * @return void|boolean (display) Returns false if there is a rights error.
     **/
    public static function showItems(Appliance $appliance)
    {
        global $DB, $CFG_GLPI;

        $ID = $appliance->fields['id'];
        $rand = mt_rand();

        if (
            !$appliance->getFromDB($ID)
            || !$appliance->can($ID, READ)
        ) {
            return false;
        }
        $canedit = $appliance->canEdit($ID);
        $entity_restrict = [(int) $appliance->getEntityID()];
        if ((int) ($appliance->fields['is_recursive'] ?? 0)) {
            $entity_restrict = getSonsOf('glpi_entities', $appliance->getEntityID());
        }
        $entity_restrict = array_unique(array_map(intval(...), (array) $entity_restrict));
        if (empty($entity_restrict)) {
            $entity_restrict = [(int) $appliance->getEntityID()];
        }
        $entity_restrict_js = json_encode(array_values($entity_restrict));

        $items = [];
        foreach (Appliance::getTypes() as $kind) {
            foreach (self::getTypeItems($ID, $kind) as $row) {
                $items[$row['linkid']] = ['id' => $row['linkid'], 'itemtype' => $kind, 'items_id' => $row['id']];
            }
        }
        ksort($items);

        Session::initNavigateListItems(
            self::getType(),
            //TRANS : %1$s is the itemtype name,
            //        %2$s is the name of the item (used for headings of a list)
            sprintf(
                __('%1$s = %2$s'),
                $appliance->getTypeName(1),
                $appliance->getName()
            )
        );

        if ($appliance->canAddItem('itemtype')) {
            $itemtypes = $CFG_GLPI['appliance_types'];
            $options = [];
            foreach ($itemtypes as $itemtype) {
                $options[$itemtype] = $itemtype::getTypeName(1);
            }

            $dropdownChoiceTokens = [];
            foreach (array_keys(array_unique($options)) as $kind) {
                $dropdownChoiceTokens[$kind] = \itsmng\Database\DropdownChoiceContext::token($kind, ['entity_restrict' => array_values($entity_restrict)]);
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
                                'name' => 'appliances_id',
                                'value' => $ID
                            ],
                            __('Type') => [
                                'type' => 'select',
                                'id' => 'dropdown_itemtype',
                                'name' => 'itemtype',
                                'values' => [Dropdown::EMPTY_VALUE] + array_unique($options),
                                'col_lg' => 6,
                                'hooks' => [
                                    'change' => <<<JS
                                        const entityRestrict = $entity_restrict_js;
                                        const target = $('#dropdown_items_id');
                                        const selectedType = this.value;
                                        target.empty();
                                        if (!selectedType) {
                                            return;
                                        }
                              $.ajax({
                                    method: "POST",
                                    url: "$CFG_GLPI[root_doc]/ajax/getDropdownValue.php",
                                    data: {
                                                    itemtype: selectedType,
                                       _idor_token: ({$dropdownChoiceTokens})[selectedType],
                                       display_emptychoice: 1,
                                                    entity_restrict: entityRestrict,
                                    },
                                    success: function(response) {
                                       const data = response.results;
                                       for (let i = 0; i < data.length; i++) {
                                          if (data[i].children) {
                                                            const group = target
                                                .append("<optgroup label='" + data[i].text + "'></optgroup>");
                                             for (let j = 0; j < data[i].children.length; j++) {
                                                group.append("<option value='" + data[i].children[j].id + "'>" + data[i].children[j].text + "</option>");
                                             }
                                          } else {
                                                            target.append("<option value='" + data[i].id + "'>" + data[i].text + "</option>");
                                          }
                                       }
                                    }
                                 });
                           JS,
                                ]
                            ],
                            __('Item') => [
                                'type' => 'select',
                                'id' => 'dropdown_items_id',
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

        $fields = [
            __('Itemtype'),
            _n('Item', 'Items', 1),
            __("Serial"),
            __("Inventory number"),
            Appliance_Item_Relation::getTypeName(Session::getPluralNumber()),
        ];

        if ($canedit) {
            $massiveactionparams = [
                'container' => 'tableForApplianceItem',
                'specific_actions' => [
                    'MassiveAction:purge' => _x('button', 'Delete permanently the relation with selected elements'),
                ],
                'is_deleted' => 0,
                'display_arrow' => false,
            ];
            Html::showMassiveActions($massiveactionparams);
        }

        $values = [];
        $massive_action = [];
        foreach ($items as $row) {
            $item = new $row['itemtype']();
            $item->getFromDB($row['items_id']);
            $values[] = [
                $item->getTypeName(1),
                $item->getLink(),
                ($item->fields['serial'] ?? ""),
                ($item->fields['otherserial'] ?? ""),
                Appliance_Item_Relation::showListForApplianceItem($row['id'], $canedit),
            ];
            $massive_action[] = sprintf('item[%s][%s]', self::class, $row['id']);
        }
        renderTwigTemplate('table.twig', [
            'id' => 'tableForApplianceItem',
            'fields' => $fields,
            'values' => $values,
            'massive_action' => $massive_action,
        ]);
        echo Appliance_Item_Relation::getListJSForApplianceItem($appliance, $canedit);
    }

    /**
     * Print an HTML array of appliances associated to an object
     *
     * @since 9.5.2
     *
     * @param CommonDBTM $item         CommonDBTM object wanted
     * @param boolean    $withtemplate not used (to be deleted)
     *
     * @return void
     **/
    public static function showForItem(CommonDBTM $item, $withtemplate = 0)
    {

        $itemtype = $item->getType();
        $ID = $item->fields['id'];

        if (
            !Appliance::canView()
            || !$item->can($ID, READ)
        ) {
            return;
        }

        $canedit = $item->can($ID, UPDATE);
        $rand = mt_rand();

        $iterator = self::getListForItem($item);
        $number = count($iterator);

        $appliances = [];
        $used = [];
        while ($data = $iterator->next()) {
            $appliances[$data['id']] = $data;
            $used[$data['id']] = $data['id'];
        }
        if ($canedit && ($withtemplate != 2)) {
            $form = [
                'action' => Toolbox::getItemTypeFormURL(__CLASS__),
                'buttons' => [
                    [
                        'name' => 'add',
                        'value' => _x('button', 'Associate'),
                        'class' => 'btn btn-secondary',
                    ]
                ],
                'content' => [
                    '' => [
                        'visible' => true,
                        'inputs' => [
                            [
                                'type' => 'hidden',
                                'name' => 'items_id',
                                'value' => $ID,
                            ],
                            [
                                'type' => 'hidden',
                                'name' => 'itemtype',
                                'value' => $itemtype,
                            ],
                            __('Add to an appliance') => [
                                'type' => 'select',
                                'name' => 'appliances_id',
                                'itemtype' => Appliance::class,
                                'actions' => getItemActionButtons(['info'], Appliance::class),
                                'col_lg' => 12,
                                'col_md' => 12,
                            ]
                        ]
                    ]
                ]
            ];
            renderTwigForm($form);
        }

        if ($withtemplate != 2) {
            if ($canedit && $number) {
                $massiveactionparams = [
                    'container' => 'tableForApplianceItem',
                    'display_arrow' => false,
                    'specific_actions' => [
                        'MassiveAction:purge' => _x('button', 'Delete permanently the relation with selected elements'),
                    ],
                ];
                Html::showMassiveActions($massiveactionparams);
            }
        }

        $fields = [
            __('Name'),
            Appliance_Item_Relation::getTypeName(Session::getPluralNumber()),
        ];
        $values = [];
        $massive_action = [];
        foreach ($appliances as $data) {
            $cID = $data["id"];
            Session::addToNavigateListItems(__CLASS__, $cID);
            $assocID = $data["linkid"];
            $app = new Appliance();
            $app->getFromResultSet($data);
            $name = $app->fields["name"];
            if (
                $_SESSION["glpiis_ids_visible"]
                || empty($app->fields["name"])
            ) {
                $name = sprintf(__('%1$s (%2$s)'), $name, $app->fields["id"]);
            }
            $values[] = [
                $name,
                Appliance_Item_Relation::showListForApplianceItem($assocID, $canedit),
            ];
            $massive_action[] = sprintf('item[%s][%s]', self::class, $data['linkid']);
        }

        renderTwigTemplate('table.twig', [
            'id' => 'tableForApplianceItem',
            'fields' => $fields,
            'values' => $values,
            'massive_action' => $massive_action,
        ]);
        echo Appliance_Item_Relation::getListJSForApplianceItem($item, $canedit);
    }


    public function prepareInputForAdd($input)
    {
        global $DB;
        $input = $this->validateLifecycleEndpoints($input);
        if ($input !== false && (new \itsmng\Database\Repository\ApplianceAssetRepository(\itsmng\Database\Orm::create($DB)))
            ->hasAsset((int)$input['appliances_id'], $input['itemtype'], (int)$input['items_id'])) {
            return false;
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validateLifecycleEndpoints($input);
    }

    public static function getSQLCriteriaToSearchForItem($itemtype, $items_id)
    {
        $selection = \itsmng\Database\EntityRegistry::discriminatedReferences(static::getTable())['items_id']['selections'][$itemtype] ?? null;
        $conditions = [];
        if ($itemtype === static::$itemtype_1) {
            $conditions[] = [static::$items_id_1 => $items_id];
        }
        if ($selection !== null) {
            $conditions[] = [$selection['column'] => $items_id];
        }
        return $conditions ? ['SELECT' => 'id', 'FROM' => static::getTable(), 'WHERE' => ['OR' => $conditions]] : null;
    }

    public static function getItemsAssociationRequest($itemtype, $items_id)
    {
        global $DB;
        return new \itsmng\Database\RowIterator(
            (new \itsmng\Database\Repository\ApplianceAssetRepository(\itsmng\Database\Orm::create($DB)))->assetRelationships($itemtype, (int)$items_id)
        );
    }

    public static function getOppositeByTypeAndID($itemtype, $items_id, &$relations_id = null)
    {
        $rows = static::getItemsAssociationRequest($itemtype, $items_id);
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows->next();
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

    /**
     * Prepares input (for update and add)
     *
     * @param array $input Input data
     *
     * @return array
     */
    protected function validateLifecycleEndpoints(array $input): array|false
    {
        $error_detected = [];

        //check for requirements
        if (
            ($this->isNewItem() && (!isset($input['itemtype']) || empty($input['itemtype'])))
            || (isset($input['itemtype']) && empty($input['itemtype']))
        ) {
            $error_detected[] = __('An item type is required');
        }
        if (
            ($this->isNewItem() && (!isset($input['items_id']) || empty($input['items_id'])))
            || (isset($input['items_id']) && empty($input['items_id']))
        ) {
            $error_detected[] = __('An item is required');
        }
        if (
            ($this->isNewItem() && (!isset($input[self::$items_id_1]) || empty($input[self::$items_id_1])))
            || (array_key_exists(self::$items_id_1, $input) && empty($input[self::$items_id_1]))
        ) {
            $error_detected[] = __('An appliance is required');
        }

        if (count($error_detected)) {
            foreach ($error_detected as $error) {
                Session::addMessageAfterRedirect(
                    $error,
                    true,
                    ERROR
                );
            }
            return false;
        }

        return $input;
    }

    /** Count session-visible subjects; the actual tab/view caller guards appliance access. */
    public static function countForMainItem(CommonDBTM $item, $extra_types_where = [])
    {
        global $DB;
        $repository = new \itsmng\Database\Repository\ApplianceAssetRepository(\itsmng\Database\Orm::create($DB));
        $types = Appliance::getTypes();
        $count = 0;
        foreach ($repository->assetKinds((int)$item->getID(), $extra_types_where) as $row) {
            if (!in_array($row['itemtype'], $types, true)) {
                continue;
            }
            $subject = getItemForItemtype($row['itemtype']);
            $count += $repository->assetCount((int)$item->getID(), $row['itemtype'], self::subjectCriteria($subject));
        }
        return $count;
    }

    public static function getTypeItems($items_id, $itemtype)
    {
        global $DB;
        $subject = getItemForItemtype($itemtype);
        $rows = [];
        if ($subject && $subject->canView()) {
            $rows = (new \itsmng\Database\Repository\ApplianceAssetRepository(\itsmng\Database\Orm::create($DB)))
                ->assets((int)$items_id, $itemtype, self::subjectCriteria($subject), $subject::getNameField());
        }
        return new \itsmng\Database\RowIterator($rows);
    }

    public static function getDistinctTypes($items_id, $extra_where = [])
    {
        global $DB;
        return new \itsmng\Database\RowIterator(
            (new \itsmng\Database\Repository\ApplianceAssetRepository(\itsmng\Database\Orm::create($DB)))->assetKinds((int)$items_id, $extra_where)
        );
    }



    public static function getListForItem(CommonDBTM $item)
    {
        global $DB;
        $criteria = Session::isCron() ? [] : getEntitiesRestrictCriteria(Appliance::getTable(), '', '', 'auto');
        return new \itsmng\Database\RowIterator(
            (new \itsmng\Database\Repository\ApplianceAssetRepository(\itsmng\Database\Orm::create($DB)))
                ->owners($item->getType(), (int)$item->getID(), $criteria)
        );
    }

    public static function countForItem(CommonDBTM $item)
    {
        global $DB;
        $criteria = Session::isCron() ? [] : getEntitiesRestrictCriteria(Appliance::getTable(), '', '', 'auto');
        return (new ApplianceOwnerReadOperation($DB->getDoctrineConnection()))
            ->ownerCount($item->getType(), (int)$item->getID(), $criteria);
    }

    public function getForbiddenStandardMassiveAction()
    {
        $forbidden = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        $forbidden[] = 'CommonDBConnexity:unaffect';
        $forbidden[] = 'CommonDBConnexity:affect';
        return $forbidden;
    }

    public static function getRelationMassiveActionsSpecificities()
    {
        global $CFG_GLPI;

        $specificities = parent::getRelationMassiveActionsSpecificities();
        $specificities['itemtypes'] = Appliance::getTypes();

        return $specificities;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb(
            [
                Appliance_Item_Relation::class,
            ]
        );
    }
}
