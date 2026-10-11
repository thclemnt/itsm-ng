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

namespace itsmng\Search;

use Appliance;
use CommonDBTM;
use CommonITILObject;
use CommonTreeDropdown;
use Contract;
use Document;
use Domain;
use Entity;
use Group;
use Infocom;
use Link;
use Location;
use Manufacturer;
use NetworkPort;
use Plugin;
use Search;
use Session;
use Ticket;
use User;

use function getEntitiesRestrictRequest;
use function getForeignKeyFieldForTable;
use function getItemForItemtype;
use function getItemTypeForTable;
use function isPluginItemType;

use const READ;
use const READNOTE;
use const UPDATE;

final class SearchOption
{
    /**
     * Get meta types available for search engine
     *
     * @param string $itemtype Type to display the form
     *
     * @return array Array of available itemtype
     **/
    public static function getMetaItemtypeAvailable($itemtype)
    {
        global $CFG_GLPI;
        $itemtype = SearchOption::getMetaReferenceItemtype($itemtype);
        if (!($item = getItemForItemtype($itemtype)) instanceof CommonDBTM) {
            return [];
        }
        $linked = [];
        foreach ($CFG_GLPI as $key => $values) {
            if ($key === 'link_types') {
                // Links are associated to all items of a type, it does not make any sense to use them in meta search
                continue;
            }
            if ($key === 'ticket_types' && $item instanceof CommonITILObject) {
                // Linked are filtered by CommonITILObject::getAllTypesForHelpdesk()
                $linked = array_merge($linked, array_keys($item::getAllTypesForHelpdesk()));
                continue;
            }
            foreach (SearchOption::getMetaParentItemtypesForTypesConfig($key) as $config_itemtype) {
                if ($itemtype === $config_itemtype::getType()) {
                    // List is related to source itemtype, all types of list are so linked
                    $linked = array_merge($linked, $values);
                } elseif (in_array($itemtype, $values)) {
                    // Source itemtype is inside list, type corresponding to list is so linked
                    $linked[] = $config_itemtype::getType();
                }
            }
        }
        return array_unique($linked);
    }
    /**
     * Returns parents itemtypes having subitems defined in given config key.
     * This list is filtered and is only valid in a "meta" search context.
     *
     * @param string $config_key
     *
     * @return string[]
     */
    private static function getMetaParentItemtypesForTypesConfig(string $config_key): array
    {
        $matches = [];
        if (preg_match('/^(.+)_types$/', $config_key, $matches) === 0) {
            return [];
        }
        $key_to_itemtypes = [
            'directconnect_types' => ['Computer'],
            'infocom_types' => ['Budget', 'Infocom'],
            'linkgroup_types' => ['Group'],
            // 'linkgroup_tech_types' => ['Group'], // Cannot handle ambiguity with 'Group' from 'linkgroup_types'
            'linkuser_types' => ['User'],
            // 'linkuser_tech_types'  => ['User'], // Cannot handle ambiguity with 'User' from 'linkuser_types'
            'project_asset_types' => ['Project'],
            'rackable_types' => ['Enclosure', 'Rack'],
            'ticket_types' => ['Change', 'Problem', 'Ticket'],
        ];
        if (array_key_exists($config_key, $key_to_itemtypes)) {
            return $key_to_itemtypes[$config_key];
        }
        $itemclass = $matches[1];
        if (is_a($itemclass, CommonDBTM::class, true)) {
            return [$itemclass::getType()];
        }
        return [];
    }
    /**
     * Check if an itemtype is a possible subitem of another itemtype in a "meta" search context.
     *
     * @param string $parent_itemtype
     * @param string $child_itemtype
     *
     * @return boolean
     */
    public static function isPossibleMetaSubitemOf(string $parent_itemtype, string $child_itemtype)
    {
        global $CFG_GLPI;
        if (is_a($parent_itemtype, CommonITILObject::class, true) && in_array($child_itemtype, array_keys($parent_itemtype::getAllTypesForHelpdesk()))) {
            return true;
        }
        foreach ($CFG_GLPI as $key => $values) {
            if (in_array($parent_itemtype, SearchOption::getMetaParentItemtypesForTypesConfig($key)) && in_array($child_itemtype, $values)) {
                return true;
            }
        }
        return false;
    }
    /**
     * @since 0.85
     *
     * @param $itemtype
     **/
    public static function getMetaReferenceItemtype($itemtype)
    {
        if (!isPluginItemType($itemtype)) {
            return $itemtype;
        }
        // Use reference type if given itemtype extends a reference type.
        $types = ['Computer', 'Problem', 'Change', 'Ticket', 'Printer', 'Monitor', 'Peripheral', 'Software', 'Phone'];
        foreach ($types as $type) {
            if (is_a($itemtype, $type, true)) {
                return $type;
            }
        }
        return false;
    }
    /**
     * Generic Function to add default columns to view
     *
     * @param string $itemtype device type
     * @param array  $params   array of parameters
     *
     * @return select string
     **/
    public static function addDefaultToView($itemtype, $params)
    {
        global $CFG_GLPI;
        $toview = [];
        $item = null;
        $entity_check = true;
        if ($itemtype != 'AllAssets') {
            $item = getItemForItemtype($itemtype);
            $entity_check = $item->isEntityAssign();
        }
        // Add first element (name)
        array_push($toview, 1);
        if (isset($params['as_map']) && $params['as_map'] == 1) {
            // Add location name when map mode
            array_push($toview, $itemtype == 'Location' ? 1 : ($itemtype == 'Ticket' ? 83 : 3));
        }
        // Add entity view :
        if (Session::isMultiEntitiesMode() && $entity_check && (isset($CFG_GLPI["union_search_type"][$itemtype]) || $item && $item->maybeRecursive() || isset($_SESSION['glpiactiveentities']) && count($_SESSION["glpiactiveentities"]) > 1)) {
            array_push($toview, 80);
        }
        return $toview;
    }
    /**
     * Clean search options depending of user active profile
     *
     * @param string  $itemtype     Item type to manage
     * @param integer $action       Action which is used to manupulate searchoption
     *                               (default READ)
     * @param boolean $withplugins  Get plugins options (true by default)
     *
     * @return array Clean $SEARCH_OPTION array
     **/
    public static function getCleanedOptions($itemtype, $action = READ, $withplugins = true)
    {
        global $CFG_GLPI;
        $options = & SearchOption::getOptions($itemtype, $withplugins);
        $todel = [];
        if (!Session::haveRight('infocom', $action) && Infocom::canApplyOn($itemtype)) {
            $itemstodel = Infocom::getSearchOptionsToAdd($itemtype);
            $todel = array_merge($todel, array_keys($itemstodel));
        }
        if (!Session::haveRight('contract', $action) && in_array($itemtype, $CFG_GLPI["contract_types"])) {
            $itemstodel = Contract::getSearchOptionsToAdd();
            $todel = array_merge($todel, array_keys($itemstodel));
        }
        if (!Session::haveRight('document', $action) && Document::canApplyOn($itemtype)) {
            $itemstodel = Document::getSearchOptionsToAdd();
            $todel = array_merge($todel, array_keys($itemstodel));
        }
        // do not show priority if you don't have right in profile
        if ($itemtype == 'Ticket' && $action == UPDATE && !Session::haveRight('ticket', Ticket::CHANGEPRIORITY)) {
            $todel[] = 3;
        }
        if ($itemtype == 'Computer') {
            if (!Session::haveRight('networking', $action)) {
                $itemstodel = NetworkPort::getSearchOptionsToAdd($itemtype);
                $todel = array_merge($todel, array_keys($itemstodel));
            }
        }
        if (!Session::haveRight(strtolower($itemtype), READNOTE)) {
            $todel[] = 90;
        }
        if (count($todel)) {
            foreach ($todel as $ID) {
                if (isset($options[$ID])) {
                    unset($options[$ID]);
                }
            }
        }
        return $options;
    }
    /**
     *
     * Get an option number in the SEARCH_OPTION array
     *
     * @param string $itemtype  Item type
     * @param string $field     Name
     *
     * @return integer
     **/
    public static function getOptionNumber($itemtype, $field)
    {
        $table = $itemtype::getTable();
        $opts = & SearchOption::getOptions($itemtype);
        foreach ($opts as $num => $opt) {
            if (is_array($opt) && isset($opt['table']) && $opt['table'] == $table && $opt['field'] == $field) {
                return $num;
            }
        }
        return 0;
    }
    /**
     * Get the SEARCH_OPTION array
     *
     * @param string  $itemtype     Item type
     * @param boolean $withplugins  Get search options from plugins (true by default)
     *
     * @return array The reference to the array of search options for the given item type
     **/
    public static function &getOptions($itemtype, $withplugins = true)
    {
        global $CFG_GLPI;
        $item = null;
        if (!isset(Search::$search[$itemtype])) {
            // standard type first
            switch ($itemtype) {
                case 'Internet':
                    Search::$search[$itemtype]['common'] = __('Characteristics');
                    Search::$search[$itemtype][1]['table'] = 'networkport_types';
                    Search::$search[$itemtype][1]['field'] = 'name';
                    Search::$search[$itemtype][1]['name'] = __('Name');
                    Search::$search[$itemtype][1]['datatype'] = 'itemlink';
                    Search::$search[$itemtype][1]['searchtype'] = 'contains';
                    Search::$search[$itemtype][2]['table'] = 'networkport_types';
                    Search::$search[$itemtype][2]['field'] = 'id';
                    Search::$search[$itemtype][2]['name'] = __('ID');
                    Search::$search[$itemtype][2]['searchtype'] = 'contains';
                    Search::$search[$itemtype][31]['table'] = 'glpi_states';
                    Search::$search[$itemtype][31]['field'] = 'completename';
                    Search::$search[$itemtype][31]['name'] = __('Status');
                    Search::$search[$itemtype] += NetworkPort::getSearchOptionsToAdd('networkport_types');
                    break;
                case 'AllAssets':
                    Search::$search[$itemtype]['common'] = __('Characteristics');
                    Search::$search[$itemtype][1]['table'] = 'asset_types';
                    Search::$search[$itemtype][1]['field'] = 'name';
                    Search::$search[$itemtype][1]['name'] = __('Name');
                    Search::$search[$itemtype][1]['datatype'] = 'itemlink';
                    Search::$search[$itemtype][1]['searchtype'] = 'contains';
                    Search::$search[$itemtype][2]['table'] = 'asset_types';
                    Search::$search[$itemtype][2]['field'] = 'id';
                    Search::$search[$itemtype][2]['name'] = __('ID');
                    Search::$search[$itemtype][2]['searchtype'] = 'contains';
                    Search::$search[$itemtype][31]['table'] = 'glpi_states';
                    Search::$search[$itemtype][31]['field'] = 'completename';
                    Search::$search[$itemtype][31]['name'] = __('Status');
                    Search::$search[$itemtype] += Location::getSearchOptionsToAdd();
                    Search::$search[$itemtype][5]['table'] = 'asset_types';
                    Search::$search[$itemtype][5]['field'] = 'serial';
                    Search::$search[$itemtype][5]['name'] = __('Serial number');
                    Search::$search[$itemtype][6]['table'] = 'asset_types';
                    Search::$search[$itemtype][6]['field'] = 'otherserial';
                    Search::$search[$itemtype][6]['name'] = __('Inventory number');
                    Search::$search[$itemtype][16]['table'] = 'asset_types';
                    Search::$search[$itemtype][16]['field'] = 'comment';
                    Search::$search[$itemtype][16]['name'] = __('Comments');
                    Search::$search[$itemtype][16]['datatype'] = 'text';
                    Search::$search[$itemtype][70]['table'] = 'glpi_users';
                    Search::$search[$itemtype][70]['field'] = 'name';
                    Search::$search[$itemtype][70]['name'] = User::getTypeName(1);
                    Search::$search[$itemtype][7]['table'] = 'asset_types';
                    Search::$search[$itemtype][7]['field'] = 'contact';
                    Search::$search[$itemtype][7]['name'] = __('Alternate username');
                    Search::$search[$itemtype][7]['datatype'] = 'string';
                    Search::$search[$itemtype][8]['table'] = 'asset_types';
                    Search::$search[$itemtype][8]['field'] = 'contact_num';
                    Search::$search[$itemtype][8]['name'] = __('Alternate username number');
                    Search::$search[$itemtype][8]['datatype'] = 'string';
                    Search::$search[$itemtype][71]['table'] = 'glpi_groups';
                    Search::$search[$itemtype][71]['field'] = 'completename';
                    Search::$search[$itemtype][71]['name'] = Group::getTypeName(1);
                    Search::$search[$itemtype][19]['table'] = 'asset_types';
                    Search::$search[$itemtype][19]['field'] = 'date_mod';
                    Search::$search[$itemtype][19]['name'] = __('Last update');
                    Search::$search[$itemtype][19]['datatype'] = 'datetime';
                    Search::$search[$itemtype][19]['massiveaction'] = false;
                    Search::$search[$itemtype][23]['table'] = 'glpi_manufacturers';
                    Search::$search[$itemtype][23]['field'] = 'name';
                    Search::$search[$itemtype][23]['name'] = Manufacturer::getTypeName(1);
                    Search::$search[$itemtype][24]['table'] = 'glpi_users';
                    Search::$search[$itemtype][24]['field'] = 'name';
                    Search::$search[$itemtype][24]['linkfield'] = 'users_id_tech';
                    Search::$search[$itemtype][24]['name'] = __('Technician in charge of the hardware');
                    Search::$search[$itemtype][24]['condition'] = ['is_assign' => 1];
                    Search::$search[$itemtype][49]['table'] = 'glpi_groups';
                    Search::$search[$itemtype][49]['field'] = 'completename';
                    Search::$search[$itemtype][49]['linkfield'] = 'groups_id_tech';
                    Search::$search[$itemtype][49]['name'] = __('Group in charge of the hardware');
                    Search::$search[$itemtype][49]['condition'] = ['is_assign' => 1];
                    Search::$search[$itemtype][49]['datatype'] = 'dropdown';
                    Search::$search[$itemtype][80]['table'] = 'glpi_entities';
                    Search::$search[$itemtype][80]['field'] = 'completename';
                    Search::$search[$itemtype][80]['name'] = Entity::getTypeName(1);
                    break;
                default:
                    if ($item = getItemForItemtype($itemtype)) {
                        Search::$search[$itemtype] = $item->searchOptions();
                    }
                    break;
            }
            if (Session::getLoginUserID() && in_array($itemtype, $CFG_GLPI["ticket_types"])) {
                Search::$search[$itemtype]['tracking'] = __('Assistance');
                Search::$search[$itemtype][60]['table'] = 'glpi_tickets';
                Search::$search[$itemtype][60]['field'] = 'id';
                Search::$search[$itemtype][60]['datatype'] = 'count';
                Search::$search[$itemtype][60]['name'] = _x('quantity', 'Number of tickets');
                Search::$search[$itemtype][60]['forcegroupby'] = true;
                Search::$search[$itemtype][60]['usehaving'] = true;
                Search::$search[$itemtype][60]['massiveaction'] = false;
                Search::$search[$itemtype][60]['joinparams'] = ['beforejoin' => ['table' => 'glpi_items_tickets', 'joinparams' => ['jointype' => 'itemtype_item']], 'condition' => getEntitiesRestrictRequest('AND', 'NEWTABLE')];
                Search::$search[$itemtype][140]['table'] = 'glpi_problems';
                Search::$search[$itemtype][140]['field'] = 'id';
                Search::$search[$itemtype][140]['datatype'] = 'count';
                Search::$search[$itemtype][140]['name'] = _x('quantity', 'Number of problems');
                Search::$search[$itemtype][140]['forcegroupby'] = true;
                Search::$search[$itemtype][140]['usehaving'] = true;
                Search::$search[$itemtype][140]['massiveaction'] = false;
                Search::$search[$itemtype][140]['joinparams'] = ['beforejoin' => ['table' => 'glpi_items_problems', 'joinparams' => ['jointype' => 'itemtype_item']], 'condition' => getEntitiesRestrictRequest('AND', 'NEWTABLE')];
            }
            if (in_array($itemtype, $CFG_GLPI["networkport_types"]) || $itemtype == 'AllAssets') {
                Search::$search[$itemtype] += NetworkPort::getSearchOptionsToAdd($itemtype);
            }
            if (in_array($itemtype, $CFG_GLPI["contract_types"]) || $itemtype == 'AllAssets') {
                Search::$search[$itemtype] += Contract::getSearchOptionsToAdd();
            }
            if (Document::canApplyOn($itemtype) || $itemtype == 'AllAssets') {
                Search::$search[$itemtype] += Document::getSearchOptionsToAdd();
            }
            if (Infocom::canApplyOn($itemtype) || $itemtype == 'AllAssets') {
                Search::$search[$itemtype] += Infocom::getSearchOptionsToAdd($itemtype);
            }
            if (in_array($itemtype, $CFG_GLPI["domain_types"]) || $itemtype == 'AllAssets') {
                Search::$search[$itemtype] += Domain::getSearchOptionsToAdd($itemtype);
            }
            if (in_array($itemtype, $CFG_GLPI["appliance_types"]) || $itemtype == 'AllAssets') {
                Search::$search[$itemtype] += Appliance::getSearchOptionsToAdd($itemtype);
            }
            if (in_array($itemtype, $CFG_GLPI["link_types"])) {
                Search::$search[$itemtype]['link'] = _n('External link', 'External links', Session::getPluralNumber());
                Search::$search[$itemtype] += Link::getSearchOptionsToAdd($itemtype);
            }
            if ($withplugins) {
                // Search options added by plugins
                $plugsearch = Plugin::getAddSearchOptions($itemtype);
                $plugsearch = $plugsearch + Plugin::getAddSearchOptionsNew($itemtype);
                if (count($plugsearch)) {
                    Search::$search[$itemtype] += ['plugins' => _n('Plugin', 'Plugins', Session::getPluralNumber())];
                    Search::$search[$itemtype] += $plugsearch;
                }
            }
            // Complete linkfield if not define
            if (is_null($item)) {
                // Special union type
                $itemtable = $CFG_GLPI['union_search_type'][$itemtype];
            } else {
                if ($item = getItemForItemtype($itemtype)) {
                    $itemtable = $item->getTable();
                }
            }
            foreach (Search::$search[$itemtype] as $key => $val) {
                if (!is_array($val) || count($val) == 1) {
                    // skip sub-menu
                    continue;
                }
                // Compatibility before 0.80 : Force massive action to false if linkfield is empty :
                if (isset($val['linkfield']) && empty($val['linkfield'])) {
                    Search::$search[$itemtype][$key]['massiveaction'] = false;
                }
                // Set default linkfield
                if (!isset($val['linkfield']) || empty($val['linkfield'])) {
                    if (strcmp((string) $itemtable, (string) $val['table']) == 0 && (!isset($val['joinparams']) || count($val['joinparams']) == 0)) {
                        Search::$search[$itemtype][$key]['linkfield'] = $val['field'];
                    } else {
                        Search::$search[$itemtype][$key]['linkfield'] = getForeignKeyFieldForTable($val['table']);
                    }
                }
                // Add default joinparams
                if (!isset($val['joinparams'])) {
                    Search::$search[$itemtype][$key]['joinparams'] = [];
                }
            }
        }
        return Search::$search[$itemtype];
    }
    /**
     * Is the search item related to infocoms
     *
     * @param string  $itemtype  Item type
     * @param integer $searchID  ID of the element in $SEARCHOPTION
     *
     * @return boolean
     **/
    public static function isInfocomOption($itemtype, $searchID)
    {
        if (!Infocom::canApplyOn($itemtype)) {
            return false;
        }
        $infocom_options = Infocom::rawSearchOptionsToAdd($itemtype);
        $found_infocoms = array_filter($infocom_options, function ($option) use ($searchID) {
            return isset($option['id']) && $searchID == $option['id'];
        });
        return count($found_infocoms) > 0;
    }
    /**
     * @param string  $itemtype
     * @param integer $field_num
     **/
    public static function getActionsFor($itemtype, $field_num)
    {
        $searchopt = & SearchOption::getOptions($itemtype);
        $actions = ['contains' => __('contains'), 'notcontains' => __('not contains'), 'searchopt' => []];
        if (isset($searchopt[$field_num]) && isset($searchopt[$field_num]['table'])) {
            $actions['searchopt'] = $searchopt[$field_num];
            // Force search type
            if (isset($actions['searchopt']['searchtype'])) {
                // Reset search option
                $actions = [];
                $actions['searchopt'] = $searchopt[$field_num];
                if (!is_array($actions['searchopt']['searchtype'])) {
                    $actions['searchopt']['searchtype'] = [$actions['searchopt']['searchtype']];
                }
                foreach ($actions['searchopt']['searchtype'] as $searchtype) {
                    switch ($searchtype) {
                        case "equals":
                            $actions['equals'] = __('is');
                            break;
                        case "notequals":
                            $actions['notequals'] = __('is not');
                            break;
                        case "contains":
                            $actions['contains'] = __('contains');
                            $actions['notcontains'] = __('not contains');
                            break;
                        case "notcontains":
                            $actions['notcontains'] = __('not contains');
                            break;
                        case "under":
                            $actions['under'] = __('under');
                            break;
                        case "notunder":
                            $actions['notunder'] = __('not under');
                            break;
                        case "lessthan":
                            $actions['lessthan'] = __('before');
                            break;
                        case "morethan":
                            $actions['morethan'] = __('after');
                            break;
                    }
                }
                return $actions;
            }
            if (isset($searchopt[$field_num]['datatype'])) {
                switch ($searchopt[$field_num]['datatype']) {
                    case 'count':
                    case 'number':
                        $opt = ['contains' => __('contains'), 'notcontains' => __('not contains'), 'equals' => __('is'), 'notequals' => __('is not'), 'searchopt' => $searchopt[$field_num]];
                        // No is / isnot if no limits defined
                        if (!isset($searchopt[$field_num]['min']) && !isset($searchopt[$field_num]['max'])) {
                            unset($opt['equals']);
                            unset($opt['notequals']);
                            // https://github.com/glpi-project/glpi/issues/6917
                            // change filter wording for numeric values to be more
                            // obvious if the number dropdown will not be used
                            $opt['contains'] = __('is');
                            $opt['notcontains'] = __('is not');
                        }
                        return $opt;
                    case 'bool':
                        return ['equals' => __('is'), 'notequals' => __('is not'), 'contains' => __('contains'), 'notcontains' => __('not contains'), 'searchopt' => $searchopt[$field_num]];
                    case 'right':
                        return ['equals' => __('is'), 'notequals' => __('is not'), 'searchopt' => $searchopt[$field_num]];
                    case 'itemtypename':
                        return ['equals' => __('is'), 'notequals' => __('is not'), 'searchopt' => $searchopt[$field_num]];
                    case 'date':
                    case 'datetime':
                    case 'date_delay':
                        return ['equals' => __('is'), 'notequals' => __('is not'), 'lessthan' => __('before'), 'morethan' => __('after'), 'contains' => __('contains'), 'notcontains' => __('not contains'), 'searchopt' => $searchopt[$field_num]];
                }
            }
            // switch ($searchopt[$field_num]['table']) {
            //    case 'glpi_users_validation' :
            //       return array('equals'    => __('is'),
            //                    'notequals' => __('is not'),
            //                    'searchopt' => $searchopt[$field_num]);
            // }
            switch ($searchopt[$field_num]['field']) {
                case 'id':
                    return ['equals' => __('is'), 'notequals' => __('is not'), 'searchopt' => $searchopt[$field_num]];
                case 'name':
                case 'completename':
                    $actions = ['contains' => __('contains'), 'notcontains' => __('not contains'), 'equals' => __('is'), 'notequals' => __('is not'), 'searchopt' => $searchopt[$field_num]];
                    // Specific case of TreeDropdown : add under
                    $itemtype_linked = getItemTypeForTable($searchopt[$field_num]['table']);
                    if ($itemlinked = getItemForItemtype($itemtype_linked)) {
                        if ($itemlinked instanceof CommonTreeDropdown) {
                            $actions['under'] = __('under');
                            $actions['notunder'] = __('not under');
                        }
                        return $actions;
                    }
            }
        }
        return $actions;
    }
}
