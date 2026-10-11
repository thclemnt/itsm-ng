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

use Glpi\Features\Clonable;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\SoftwareAssignmentRepository;
use itsmng\Database\Repository\SoftwareRepository;
use itsmng\Domain\SoftwareAssignmentCancelled;
use itsmng\Domain\SoftwareAssignmentService;
use itsmng\Domain\SoftwareLifecycleAdmission;
use itsmng\Domain\SoftwareMutation;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/** Software Class
**/
class Software extends CommonDBTM
{
    use SoftwareLifecycleAdmission;

    use Clonable;

    protected function executePreparedAdd(callable $operation, array $priorState): mixed
    {
        global $DB;

        return (new SoftwareAssignmentService($DB))->mutateSoftware(
            $this,
            $priorState,
            fn () => parent::executePreparedAdd($operation, $priorState),
            'add'
        );
    }

    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        global $DB;

        $checkpoint = LifecycleModelJournal::state($this);
        $checkpoint['fields'] = $storedFields;
        $checkpoint['updates'] = [];
        $checkpoint['oldvalues'] = [];
        return (new SoftwareAssignmentService($DB))->mutateSoftware(
            $this,
            $checkpoint,
            fn () => parent::executePreparedUpdate($operation, $storedFields),
            'update'
        );
    }

    protected function executePreparedRestore(callable $operation, array $storedFields): bool
    {
        global $DB;

        $checkpoint = LifecycleModelJournal::state($this);
        $checkpoint['fields'] = $storedFields;
        $checkpoint['updates'] = [];
        $checkpoint['oldvalues'] = [];
        return (new SoftwareAssignmentService($DB))->mutateSoftware(
            $this,
            $checkpoint,
            fn () => parent::executePreparedRestore($operation, $storedFields),
            'restore'
        );
    }

    public function delete(array $input, $force = 0, $history = 1)
    {
        global $DB;

        $database = $DB;
        if (!array_key_exists(static::getIndexName(), $input)
            || !SoftwareMutation::loadForMutation(
                $database,
                $this,
                $input[static::getIndexName()],
                fn () => $this->admitSoftwareLifecycle()
            )) {
            return false;
        }
        return (new SoftwareAssignmentService($database))->mutateSoftware(
            $this,
            LifecycleModelJournal::state($this),
            fn () => parent::delete($input, $force, $history),
            'delete'
        );
    }

    // From CommonDBTM
    public $dohistory                   = true;

    protected static $forward_entity_to = ['Infocom', 'ReservationItem', 'SoftwareVersion'];

    public static $rightname                   = 'software';
    protected $usenotepad               = true;

    public function getCloneRelations(): array
    {
        return [
           Infocom::class,
           Contract_Item::class,
           Document_Item::class,
           KnowbaseItem_Item::class
        ];
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Software', 'Software', $nb);
    }


    /**
     * @see CommonGLPI::getMenuShorcut()
     *
     *  @since 0.85
    **/
    public static function getMenuShorcut()
    {
        return 's';
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {

        if (!$withtemplate) {
            switch ($item->getType()) {
                case __CLASS__:
                    if (
                        $item->isRecursive()
                        && $item->can($item->fields['id'], UPDATE)
                    ) {
                        return __('Merging');
                    }
                    break;
            }
        }
        return '';
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        if ($item->getType() == __CLASS__) {
            $item->showMergeCandidates();
        }
        return true;
    }


    public function defineTabs($options = [])
    {

        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addImpactTab($ong, $options);
        $this->addStandardTab('SoftwareVersion', $ong, $options);
        $this->addStandardTab('SoftwareLicense', $ong, $options);
        $this->addStandardTab('Item_SoftwareVersion', $ong, $options);
        $this->addStandardTab('Infocom', $ong, $options);
        $this->addStandardTab('Contract_Item', $ong, $options);
        $this->addStandardTab('Document_Item', $ong, $options);
        $this->addStandardTab('KnowbaseItem_Item', $ong, $options);
        $this->addStandardTab('Ticket', $ong, $options);
        $this->addStandardTab('Item_Problem', $ong, $options);
        $this->addStandardTab('Change_Item', $ong, $options);
        $this->addStandardTab('Link', $ong, $options);
        $this->addStandardTab('Notepad', $ong, $options);
        $this->addStandardTab('Reservation', $ong, $options);
        $this->addStandardTab('Domain_Item', $ong, $options);
        $this->addStandardTab('Appliance_Item', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);
        $this->addStandardTab(__CLASS__, $ong, $options);

        return $ong;
    }


    public function prepareInputForUpdate($input)
    {

        if (isset($input['is_update']) && !$input['is_update']) {
            $input['softwares_id'] = 0;
        }
        return $input;
    }


    public function prepareInputForAdd($input)
    {

        if (isset($input['is_update']) && !$input['is_update']) {
            $input['softwares_id'] = 0;
        }

        if (isset($input["id"]) && ($input["id"] > 0)) {
            $input["_oldID"] = $input["id"];
        }
        unset($input['id']);
        unset($input['withtemplate']);

        //If category was not set by user (when manually adding a user)
        if (!isset($input["softwarecategories_id"]) || !$input["softwarecategories_id"]) {
            $softcatrule = new RuleSoftwareCategoryCollection();
            $result      = $softcatrule->processAllRules(null, null, Toolbox::stripslashes_deep($input));

            if (!empty($result)) {
                if (isset($result['_ignore_import'])) {
                    $input["softwarecategories_id"] = 0;
                } elseif (isset($result["softwarecategories_id"])) {
                    $input["softwarecategories_id"] = $result["softwarecategories_id"];
                } elseif (isset($result["_import_category"])) {
                    $softCat = new SoftwareCategory();
                    $input["softwarecategories_id"]
                       = $softCat->importExternal($input["_system_category"]);
                }
            } else {
                $input["softwarecategories_id"] = 0;
            }
        }
        return $input;
    }


    public function cleanDBonPurge()
    {

        // SoftwareLicense does not extends CommonDBConnexity
        $sl = new SoftwareLicense();
        $sl->deleteByCriteria(['softwares_id' => $this->fields['id']], true);

        $this->deleteChildrenAndRelationsFromDb(
            [
              Item_Project::class,
              SoftwareVersion::class,
            ]
        );
    }


    /**
     * Update validity indicator of a specific software
     *
     * @param $ID ID of the licence
     *
     * @since 0.85
     *
     * @return bool required aggregate refresh accepted
    **/
    public static function updateValidityIndicator($ID): bool
    {
        global $DB;

        return (new SoftwareAssignmentService($DB))->refreshSoftwareValidity((int)$ID);
    }


    /**
     * Print the Software form
     *
     * @param $ID        integer  ID of the item
     * @param $options   array    of possible options:
     *     - target filename : where to go when done.
     *     - withtemplate boolean : template or basic item
     *
     *@return boolean item found
    **/
    public function showForm($ID, $options = [])
    {
        $title = __('New item') . ' - ' . self::getTypeName(1);
        $isNew = $this->isNewID($ID) || (isset($options['withtemplate']) && $options['withtemplate'] == 2);

        $form = [
           'action' => $this->getFormURL(),
           'itemtype' => $this::class,
           'content' => [
              $title => [
                 'visible' => true,
                 'inputs' => [
                    __('Name') => [
                       'name' => 'name',
                       'type' => 'text',
                       'value' => $this->fields['name'],
                    ],
                    __('Publisher') => [
                       'name' => 'manufacturers_id',
                       'type' => 'select',
                       'value' => $this->fields['manufacturers_id'],
                       'values' => getOptionForItems("Manufacturer"),
                       'actions' => getItemActionButtons(['info', 'add'], "Manufacturer"),
                    ],
                     __('Location') => [
                        'name' => 'locations_id',
                        'type' => 'select',
                        'itemtype' => Location::class,
                        'value' => $this->fields['locations_id'],
                        'actions' => getItemActionButtons(['info', 'add'], "Location"),
                     ],
                    __('Category') => [
                       'name' => 'softwarecategories_id',
                       'type' => 'select',
                       'value' => $this->fields['softwarecategories_id'],
                       'values' => getOptionForItems("SoftwareCategory"),
                       'actions' => getItemActionButtons(['info', 'add'], "SoftwareCategory"),
                    ],
                    __("Technician in charge of the software") => [
                       'name' => 'users_id_tech',
                       'type' => 'select',
                       'value' => $this->fields['users_id_tech'],
                       'values' => getOptionsForUsers('own_ticket', ['entities_id' => $this->fields['entities_id']]),
                       'actions' => getItemActionButtons(['info'], "User"),
                    ],
                    __("Associable to a ticket") => [
                       'name' => 'is_helpdesk_visible',
                       'type' => 'checkbox',
                       'value' => $this->fields['is_helpdesk_visible'],
                    ],
                     __("Group in charge of the software") => [
                        'name' => 'groups_id_tech',
                        'type' => 'select',
                        'itemtype' => Group::class,
                        'conditions' => ['is_assign' => 1],
                        'value' => $this->fields['groups_id_tech'],
                        'actions' => getItemActionButtons(['info', 'add'], "Group"),
                     ],
                    __("User") => [
                       'name' => 'users_id',
                       'type' => 'select',
                       'value' => $this->fields['users_id'],
                       'values' => getOptionForItems("User", ['entities_id' => $this->fields['entities_id']]), // NEED right => all
                       'actions' => getItemActionButtons(['info'], "User"),
                    ],
                     __("Group") => [
                        'name' => 'groups_id',
                        'type' => 'select',
                        'itemtype' => Group::class,
                        'conditions' => ['is_itemgroup' => 1],
                        'value' => $this->fields['groups_id'],
                        'actions' => getItemActionButtons(['info', 'add'], "Group"),
                     ],
                 ]
              ],
              __('Upgrade') => [
                 'visible' => true,
                 'inputs' => [
                    __("Upgrade") => [
                       'name' => 'is_update',
                       'type' => 'checkbox',
                       'value' => $this->fields['is_update'],
                       'id' => 'is_update_checkbox',
                       'hooks' => [
                          'click' => <<<JS
                        console.log('click !')
                        JS
                       ],
                    ],
                    __('from') => [
                       'name' => 'softwares_id',
                       'type' => 'select',
                       'value' => $this->fields['softwares_id'],
                       'values' => getOptionForItems("Software"),
                    ],
                 ]
              ]
           ]
        ];

        renderTwigForm($form, '', $this->fields);
        return true;
    }


    public function getEmpty()
    {
        global $CFG_GLPI;
        parent::getEmpty();

        $this->fields["is_helpdesk_visible"] = $CFG_GLPI["default_software_helpdesk_visible"];
    }


    /**
     * @see CommonDBTM::getSpecificMassiveActions()
    **/
    public function getSpecificMassiveActions($checkitem = null)
    {

        $isadmin = static::canUpdate();
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (
            $isadmin
            && (countElementsInTable("glpi_rules", ['sub_type' => 'RuleSoftwareCategory']) > 0)
        ) {
            $actions[__CLASS__ . MassiveAction::CLASS_ACTION_SEPARATOR . 'compute_software_category']
               = "<i class='ma-icon fas fa-calculator' aria-hidden='true'></i>" .
                 __('Recalculate the category');
        }

        if (
            Session::haveRightsOr("rule_dictionnary_software", [CREATE, UPDATE])
             && (countElementsInTable("glpi_rules", ['sub_type' => 'RuleDictionnarySoftware']) > 0)
        ) {
            $actions[__CLASS__ . MassiveAction::CLASS_ACTION_SEPARATOR . 'replay_dictionnary']
               = "<i class='ma-icon fas fa-undo' aria-hidden='true'></i>" .
                 __('Replay the dictionary rules');
        }

        if ($isadmin) {
            KnowbaseItem_Item::getMassiveActionsForItemtype($actions, __CLASS__, 0, $checkitem);
        }

        return $actions;
    }


    /**
     * @since 0.85
     *
     * @see CommonDBTM::processMassiveActionsForOneItemtype()
    **/
    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ) {

        switch ($ma->getAction()) {
            case 'merge':
                $input = $ma->getInput();
                if (isset($input['item_items_id'])) {
                    $items = [];
                    foreach ($ids as $id) {
                        $items[$id] = 1;
                    }
                    if ($item->can($input['item_items_id'], UPDATE)) {
                        if ($item->merge($items)) {
                            $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_OK);
                        } else {
                            $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_KO);
                            $ma->addMessage($item->getErrorMessage(ERROR_ON_ACTION));
                        }
                    } else {
                        $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_NORIGHT);
                        $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    }
                } else {
                    $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_KO);
                }
                return;

            case 'compute_software_category':
                $softcatrule = new RuleSoftwareCategoryCollection();
                foreach ($ids as $id) {
                    $params = [];
                    //Get software name and manufacturer
                    if ($item->can($id, UPDATE)) {
                        $params["name"]             = $item->fields["name"];
                        $params["manufacturers_id"] = $item->fields["manufacturers_id"];
                        $params["comment"]          = $item->fields["comment"];
                        $output = [];
                        $output = $softcatrule->processAllRules(null, $output, $params);
                        //Process rules
                        if (
                            isset($output['softwarecategories_id'])
                            && $item->update(['id' => $id,
                                                   'softwarecategories_id'
                                                        => $output['softwarecategories_id']])
                        ) {
                            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                        } else {
                            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                            $ma->addMessage($item->getErrorMessage(ERROR_ON_ACTION));
                        }
                    } else {
                        $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_NORIGHT);
                        $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    }
                }
                return;

            case 'replay_dictionnary':
                $softdictionnayrule = new RuleDictionnarySoftwareCollection();
                $allowed_ids        = [];
                foreach ($ids as $id) {
                    if ($item->can($id, UPDATE)) {
                        $allowed_ids[] = $id;
                    } else {
                        $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_NORIGHT);
                        $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    }
                }
                if ($softdictionnayrule->replayRulesOnExistingDB(0, 0, $allowed_ids) > 0) {
                    $ma->itemDone($item->getType(), $allowed_ids, MassiveAction::ACTION_OK);
                } else {
                    $ma->itemDone($item->getType(), $allowed_ids, MassiveAction::ACTION_KO);
                }

                return;
        }
        parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
    }


    public function rawSearchOptions()
    {
        // Only use for History (not by search Engine)
        $tab = parent::rawSearchOptions();

        $tab[] = [
           'id'                 => '2',
           'table'              => $this->getTable(),
           'field'              => 'id',
           'name'               => __('ID'),
           'massiveaction'      => false,
           'datatype'           => 'number'
        ];

        $tab = array_merge($tab, Location::rawSearchOptionsToAdd());

        $tab[] = [
           'id'                 => '16',
           'table'              => $this->getTable(),
           'field'              => 'comment',
           'name'               => __('Comments'),
           'datatype'           => 'text'
        ];

        $tab[] = [
           'id'                 => '62',
           'table'              => 'glpi_softwarecategories',
           'field'              => 'completename',
           'name'               => __('Category'),
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '19',
           'table'              => $this->getTable(),
           'field'              => 'date_mod',
           'name'               => __('Last update'),
           'datatype'           => 'datetime',
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '121',
           'table'              => $this->getTable(),
           'field'              => 'date_creation',
           'name'               => __('Creation date'),
           'datatype'           => 'datetime',
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '23',
           'table'              => 'glpi_manufacturers',
           'field'              => 'name',
           'name'               => __('Publisher'),
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '24',
           'table'              => 'glpi_users',
           'field'              => 'name',
           'linkfield'          => 'users_id_tech',
           'name'               => __('Technician in charge of the software'),
           'datatype'           => 'dropdown',
           'right'              => 'own_ticket'
        ];

        $tab[] = [
           'id'                 => '49',
           'table'              => 'glpi_groups',
           'field'              => 'completename',
           'linkfield'          => 'groups_id_tech',
           'name'               => __('Group in charge of the software'),
           'condition'          => ['is_assign' => 1],
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '64',
           'table'              => $this->getTable(),
           'field'              => 'template_name',
           'name'               => __('Template name'),
           'datatype'           => 'text',
           'massiveaction'      => false,
           'nosearch'           => true,
           'nodisplay'          => true,
           'autocomplete'       => true,
        ];

        $tab[] = [
           'id'                 => '70',
           'table'              => 'glpi_users',
           'field'              => 'name',
           'name'               => User::getTypeName(1),
           'datatype'           => 'dropdown',
           'right'              => 'all'
        ];

        $tab[] = [
           'id'                 => '71',
           'table'              => 'glpi_groups',
           'field'              => 'completename',
           'name'               => Group::getTypeName(1),
           'condition'          => ['is_itemgroup' => 1],
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '61',
           'table'              => $this->getTable(),
           'field'              => 'is_helpdesk_visible',
           'name'               => __('Associable to a ticket'),
           'datatype'           => 'bool'
        ];

        $tab[] = [
           'id'                 => '63',
           'table'              => $this->getTable(),
           'field'              => 'is_valid',
                                //TRANS: Indicator to know is all licenses of the software are valids
           'name'               => __('Valid licenses'),
           'datatype'           => 'bool'
        ];

        $tab[] = [
           'id'                 => '80',
           'table'              => 'glpi_entities',
           'field'              => 'completename',
           'name'               => Entity::getTypeName(1),
           'massiveaction'      => false,
           'datatype'           => 'dropdown'
        ];

        $newtab = [
           'id'                 => '72',
           'table'              => 'glpi_items_softwareversions',
           'field'              => 'id',
           'name'               => _x('quantity', 'Number of installations'),
           'forcegroupby'       => true,
           'usehaving'          => true,
           'datatype'           => 'count',
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'   => 'child',
              'beforejoin' => [
                 'table'      => 'glpi_softwareversions',
                 'joinparams' => ['jointype' => 'child'],
              ],
              'condition'  => "AND NEWTABLE.`is_deleted_item` = '0'
                             AND NEWTABLE.`is_deleted` = '0'
                             AND NEWTABLE.`is_template_item` = '0'",
           ]
        ];

        if (Session::getLoginUserID()) {
            $newtab['joinparams']['condition'] .= getEntitiesRestrictRequest(' AND', 'NEWTABLE');
        }
        $tab[] = $newtab;

        $tab[] = [
           'id'                 => '73',
           'table'              => 'glpi_items_softwareversions',
           'field'              => 'date_install',
           'name'               => __('Installation date'),
           'datatype'           => 'date',
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'   => 'child',
              'beforejoin' => [
                 'table'      => 'glpi_softwareversions',
                 'joinparams' => ['jointype' => 'child'],
              ],
              'condition'  => "AND NEWTABLE.`is_deleted_item` = '0'
                             AND NEWTABLE.`is_deleted` = '0'
                             AND NEWTABLE.`is_template_item` = '0'",
           ]
        ];

        $tab = array_merge($tab, SoftwareLicense::rawSearchOptionsToAdd());

        $name = _n('Version', 'Versions', Session::getPluralNumber());
        $tab[] = [
           'id'                 => 'versions',
           'name'               => $name
        ];

        $tab[] = [
           'id'                 => '5',
           'table'              => 'glpi_softwareversions',
           'field'              => 'name',
           'name'               => __('Name'),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'displaywith'        => ['softwares_id'],
           'joinparams'         => [
              'jointype'           => 'child'
           ],
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '31',
           'table'              => 'glpi_states',
           'field'              => 'completename',
           'name'               => __('Status'),
           'datatype'           => 'dropdown',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'beforejoin'         => [
                 'table'              => 'glpi_softwareversions',
                 'joinparams'         => [
                    'jointype'           => 'child'
                 ]
              ]
           ],
        ];

        $tab[] = [
           'id'                 => '170',
           'table'              => 'glpi_softwareversions',
           'field'              => 'comment',
           'name'               => __('Comments'),
           'forcegroupby'       => true,
           'datatype'           => 'text',
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ]
        ];

        $tab[] = [
           'id'                 => '4',
           'table'              => 'glpi_operatingsystems',
           'field'              => 'name',
           'datatype'           => 'dropdown',
           'name'               => OperatingSystem::getTypeName(1),
           'forcegroupby'       => true,
           'joinparams'         => [
              'beforejoin'         => [
                 'table'              => 'glpi_softwareversions',
                 'joinparams'         => [
                    'jointype'           => 'child'
                 ]
              ]
           ],
        ];

        $tab = array_merge($tab, Notepad::rawSearchOptionsToAdd());
        $tab = array_merge($tab, Certificate::rawSearchOptionsToAdd());

        return $tab;
    }


    /**
     * Make a select box for  software to install
     *
     * @param $myname          select name
     * @param $entity_restrict restrict to a defined entity
     *
     * @return integer random part of elements id
    **/
    public static function dropdownSoftwareToInstall($myname, $entity_restrict)
    {
        global $CFG_GLPI;

        // Make a select box
        $rand  = mt_rand();
        $where = getEntitiesRestrictCriteria(
            'glpi_softwares',
            'entities_id',
            $entity_restrict,
            true
        );
        $rand = Dropdown::show('Software', ['condition' => $where]);

        $paramsselsoft = ['softwares_id' => '__VALUE__',
                               'myname'       => $myname];

        Ajax::updateItemOnSelectEvent(
            "dropdown_softwares_id$rand",
            "show_" . $myname . $rand,
            $CFG_GLPI["root_doc"] . "/ajax/dropdownInstallVersion.php",
            $paramsselsoft
        );

        echo "<span id='show_" . $myname . $rand . "'>&nbsp;</span>\n";

        return $rand;
    }


    /**
     * Make a select box for license software to associate
     *
     * @param $myname          select name
     * @param $entity_restrict restrict to a defined entity
     *
     * @return integer random part of elements id
    **/
    public static function dropdownLicenseToInstall($myname, $entity_restrict)
    {
        global $CFG_GLPI, $DB;

        $rows = (new SoftwareRepository(Orm::create($DB)))
            ->withLicenses(getEntitiesRestrictCriteria('glpi_softwarelicenses', 'entities_id', $entity_restrict, true));
        $values = array_column($rows, 'name', 'id');
        $rand = Dropdown::showFromArray('softwares_id', $values, ['display_emptychoice' => true]);

        $paramsselsoft = ['softwares_id'    => '__VALUE__',
                               'entity_restrict' => $entity_restrict,
                               'myname'          => $myname];

        Ajax::updateItemOnSelectEvent(
            "dropdown_softwares_id$rand",
            "show_" . $myname . $rand,
            $CFG_GLPI["root_doc"] . "/ajax/dropdownSoftwareLicense.php",
            $paramsselsoft
        );

        echo "<span id='show_" . $myname . $rand . "'>&nbsp;</span>\n";

        return $rand;
    }


    /**
     * Create a new software
     *
     * @param name                          the software's name (need to be addslashes)
     * @param manufacturer_id               id of the software's manufacturer
     * @param entity                        the entity in which the software must be added
     * @param comment                       (default '')
     * @param is_recursive         boolean  must the software be recursive (false by default)
     * @param is_helpdesk_visible           show in helpdesk, default : from config (false by default)
     *
     * @return the software's ID
    **/
    public function addSoftware(
        $name,
        $manufacturer_id,
        $entity,
        $comment = '',
        $is_recursive = false,
        $is_helpdesk_visible = null
    ) {
        global $CFG_GLPI;

        $input["name"]                = $name;
        $input["manufacturers_id"]    = $manufacturer_id;
        $input["entities_id"]         = $entity;
        $input["is_recursive"]        = ($is_recursive ? 1 : 0);
        // No comment
        if (is_null($is_helpdesk_visible)) {
            $input["is_helpdesk_visible"] = $CFG_GLPI["default_software_helpdesk_visible"];
        } else {
            $input["is_helpdesk_visible"] = $is_helpdesk_visible;
        }

        //Process software's category rules
        $softcatrule = new RuleSoftwareCategoryCollection();
        $result      = $softcatrule->processAllRules(null, null, Toolbox::stripslashes_deep($input));

        if (!empty($result)) {
            if (isset($result['_ignore_import'])) {
                $input["softwarecategories_id"] = 0;
            } elseif (isset($result["softwarecategories_id"])) {
                $input["softwarecategories_id"] = $result["softwarecategories_id"];
            } elseif (isset($result["_import_category"])) {
                $softCat = new SoftwareCategory();
                $input["softwarecategories_id"]
                   = $softCat->importExternal($input["_system_category"]);
            }
        } else {
            $input["softwarecategories_id"] = 0;
        }

        return $this->add($input);
    }


    /**
     * Add a software. If already exist in trashbin restore it
     *
     * @param name                            the software's name
     * @param manufacturer                    the software's manufacturer
     * @param entity                          the entity in which the software must be added
     * @param comment                         comment (default '')
     * @param is_recursive           boolean  must the software be recursive (false by default)
     * @param is_helpdesk_visible             show in helpdesk, default = config value (false by default)
    */
    public function addOrRestoreFromTrash(
        $name,
        $manufacturer,
        $entity,
        $comment = '',
        $is_recursive = false,
        $is_helpdesk_visible = null
    ) {
        global $DB;

        //Look for the software by his name in GLPI for a specific entity
        $manufacturer_id = 0;
        if ($manufacturer != '') {
            $manufacturer_id = Dropdown::import('Manufacturer', ['name' => $manufacturer]);
        }

        $rows = (new RecordRepository(Orm::create($DB)))
            ->matching('glpi_softwares', [
                'name' => stripslashes((string)$name),
                'manufacturers_id' => $manufacturer_id ?: null,
                'is_template' => false,
            ] + getEntitiesRestrictCriteria('glpi_softwares', 'entities_id', $entity, true), ['id'], 1, legacyValues: false);

        if ($rows) {
            // Software already exists for this entity; restore it if necessary.
            $data = $rows[0];
            $ID   = $data["id"];

            // restore software
            if ($data['is_deleted']) {
                $this->removeFromTrash($ID);
            }
        } else {
            $ID = 0;
        }

        if (!$ID) {
            $ID = $this->addSoftware(
                $name,
                $manufacturer_id,
                $entity,
                $comment,
                $is_recursive,
                $is_helpdesk_visible
            );
        }
        return $ID;
    }


    /**
     * Put software in trashbin because it's been removed by GLPI software dictionnary
     *
     * @param $ID        the ID of the software to put in trashbin
     * @param $comment   the comment to add to the already existing software's comment (default '')
     *
     * @return boolean (success)
    **/
    public function putInTrash($ID, $comment = '')
    {
        global $CFG_GLPI;

        $this->getFromDB($ID);
        $input["id"]         = $ID;
        $input["is_deleted"] = 1;

        //change category of the software on deletion (if defined in glpi_configs)
        if (
            isset($CFG_GLPI["softwarecategories_id_ondelete"])
            && ($CFG_GLPI["softwarecategories_id_ondelete"] != 0)
        ) {
            $input["softwarecategories_id"] = $CFG_GLPI["softwarecategories_id_ondelete"];
        }

        //Add dictionnary comment to the current comment
        $input["comment"] = (($this->fields["comment"] != '') ? "\n" : '') . $comment;

        return $this->update($input);
    }


    /** Merge source removal owns the real delete lifecycle and its follow-up intents. */
    public function removeMergedSource(int $ID, string $comment = ''): bool
    {
        global $DB, $CFG_GLPI;

        $database = $DB;
        if ($database->isSlave()) {
            return false;
        }
        $loaded = SoftwareMutation::loadForMutation($database, $this, $ID);
        if (!$loaded || (int)$this->getID() !== $ID || $this->isTemplate()) {
            return false;
        }
        // Preserve the existing merge comment/category input, including its
        // historical conditional newline. Dictionary putInTrash stays separate.
        $input = ['id' => $ID, 'is_deleted' => 1];
        if (isset($CFG_GLPI['softwarecategories_id_ondelete']) && $CFG_GLPI['softwarecategories_id_ondelete'] != 0) {
            $input['softwarecategories_id'] = $CFG_GLPI['softwarecategories_id_ondelete'];
        }
        $input['comment'] = (($this->fields['comment'] != '') ? "\n" : '') . $comment;
        return (new SoftwareAssignmentService($database))->mutateSoftware(
            $this,
            LifecycleModelJournal::state($this),
            function () use ($database, $ID, $input): bool {
                $assertOwner = SoftwareMutation::writerContinuity($database);
                $manager = Orm::create($database);
                try {
                    $repository = new SoftwareAssignmentRepository($manager);
                    $source = $repository->software($ID);
                    if ($source === null || $source->is_template) {
                        return false;
                    }
                } finally {
                    $manager->clear();
                }
                $deleted = $this->delete(['id' => $ID]);
                $assertOwner();
                if (!$deleted) {
                    return false;
                }
                if ((int)$this->getID() !== $ID) {
                    throw new SoftwareAssignmentCancelled('Merged source delete changed its selected identity.');
                }
                $updated = $this->update($input);
                $assertOwner();
                if (!$updated) {
                    return false;
                }
                if ((int)$this->getID() !== $ID) {
                    throw new SoftwareAssignmentCancelled('Merged source update changed its selected identity.');
                }
                // Completion hooks may write on this same owner. Observe the
                // actual selected source, never a callback-mutated model ID.
                $manager = Orm::create($database);
                try {
                    $source = (new SoftwareAssignmentRepository($manager))->software($ID);
                    if ($source === null || !$source->is_deleted || $source->is_template) {
                        throw new SoftwareAssignmentCancelled('Merged source removal did not retain the deleted source.');
                    }
                } finally {
                    $manager->clear();
                }
                return true;
            },
            'delete'
        );
    }


    /**
     * Restore a software from trashbin
     *
     * @param $ID  the ID of the software to put in trashbin
     *
     * @return boolean (success)
    **/
    public function removeFromTrash($ID)
    {

        $res         = $this->restore(["id" => $ID]);
        $softcatrule = new RuleSoftwareCategoryCollection();
        $result      = $softcatrule->processAllRules(null, null, $this->fields);

        if (
            !empty($result)
            && isset($result['softwarecategories_id'])
            && ($result['softwarecategories_id'] != $this->fields['softwarecategories_id'])
        ) {
            $this->update(['id'                    => $ID,
                                'softwarecategories_id' => $result['softwarecategories_id']]);
        }

        return $res;
    }


    /**
     * Show softwares candidates to be merged with the current
     *
     * @return void
    **/
    public function showMergeCandidates()
    {
        global $DB;

        $ID   = $this->getField('id');
        $this->check($ID, UPDATE);
        $rand = mt_rand();

        echo "<div class='center'>";
        $rows = (new SoftwareRepository(Orm::create($DB)))
            ->mergeCandidates((int)$ID, (string)$this->fields['name'], getEntitiesRestrictCriteria(
                'glpi_softwares',
                'entities_id',
                getSonsOf('glpi_entities', $this->fields['entities_id']),
                false
            ));
        $nb = count($rows);

        if ($nb) {
            $link = Toolbox::getItemTypeFormURL('Software');
            Html::openMassiveActionsForm('mass' . __CLASS__ . $rand);
            $massiveactionparams
               = ['num_displayed' => min($_SESSION['glpilist_limit'], $nb),
                       'container'     => 'mass' . __CLASS__ . $rand,
                       'deprecated'      => true,
                       'specific_actions'
                                       => [__CLASS__ . MassiveAction::CLASS_ACTION_SEPARATOR . 'merge'
                                                   => __('Merge')],
                                       'item'          => $this];
            Html::showMassiveActions($massiveactionparams);

            echo "<table class='tab_cadre_fixehov' aria-label='Installations'>";
            echo "<tr><th width='10'>";
            echo Html::getCheckAllAsCheckbox('mass' . __CLASS__ . $rand);
            echo "</th>";
            echo "<th>" . __('Name') . "</th>";
            echo "<th>" . Entity::getTypeName(1) . "</th>";
            echo "<th>" . _n('Installation', 'Installations', Session::getPluralNumber()) . "</th>";
            echo "<th>" . SoftwareLicense::getTypeName(Session::getPluralNumber()) . "</th></tr>";

            foreach ($rows as $data) {
                echo "<tr class='tab_bg_2'>";
                echo "<td>" . Html::getMassiveActionCheckBox(__CLASS__, $data["id"]) . "</td>";
                echo "<td><a href='" . $link . "?id=" . $data["id"] . "'>" . $data["name"] . "</a></td>";
                echo "<td>" . $data["entity"] . "</td>";
                echo "<td class='right'>" . Item_SoftwareVersion::countForSoftware($data["id"]) . "</td>";
                echo "<td class='right'>" . SoftwareLicense::countForSoftware($data["id"]) . "</td></tr>\n";
            }
            echo "</table>\n";
            $massiveactionparams['ontop'] = false;
            Html::showMassiveActions($massiveactionparams);
            Html::closeForm();
        } else {
            echo __('No item found');
        }

        echo "</div>";
    }


    /**
     * Merge softwares with current
     *
     * @param $item array of software ID to be merged
     * @param boolean display html progress bar
     *
     * @return boolean about success
    **/
    public function merge($item, $html = true)
    {
        global $DB;

        $ID = $this->getField('id');

        if ($html) {
            echo "<div class='center'>";
            echo "<table class='tab_cadrehov' aria-label='Merging'><tr><th>" . __('Merging') . "</th></tr>";
            echo "<tr class='tab_bg_2'><td>";
            Html::createProgressBar(__('Work in progress...'));
            echo "</td></tr></table></div>\n";
        }

        $accepted = SoftwareMutation::run(
            $DB,
            $this,
            LifecycleModelJournal::state($this),
            function () use ($DB, $ID, $item, $html): bool {
                (new SoftwareRepository(Orm::create($DB)))->merge(
                    (int)$ID,
                    (int)$this->getField('entities_id'),
                    array_keys($item),
                    static fn (int $source): bool => (new self())->removeMergedSource($source, __('Software deleted after merging')),
                    $html ? static fn (int $done, int $total) => Html::changeProgressBarPosition($done, $total) : null
                );
                return true;
            }
        );
        if (!$accepted) {
            return false;
        }
        if ($html) {
            Html::changeProgressBarPosition(1, 1, __('Task completed.'));
        }
        return true;
    }


    public static function getDefaultSearchRequest()
    {
        return [
           'sort' => 0
        ];
    }

    public static function getIcon()
    {
        return "fas fa-cube";
    }
}
