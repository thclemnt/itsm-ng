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

namespace itsmng\Search\Provider;

use itsmng\Search\SearchOption;

final class JoinBuilder
{
    /**
     * Generic Function to add Default left join to a request
     *
     * @param string $itemtype             Reference item type
     * @param string $ref_table            Reference table
     * @param array &$already_link_tables  Array of tables already joined
     *
     * @return string Left join string
     **/
    public static function addDefaultJoin($itemtype, $ref_table, array &$already_link_tables)
    {
        switch ($itemtype) {
            // No link
            case 'User':
                return JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_profiles_users", "profiles_users_id", 0, 0, ['jointype' => 'child']);
            case 'Reminder':
                return \Reminder::addVisibilityJoins();
            case 'RSSFeed':
                return \RSSFeed::addVisibilityJoins();
            case 'ProjectTask':
                // Same structure in addDefaultWhere
                $out = '';
                $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_projects", "projects_id");
                $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_projecttaskteams", "projecttaskteams_id", 0, 0, ['jointype' => 'child']);
                return $out;
            case 'Project':
                // Same structure in addDefaultWhere
                $out = '';
                if (!\Session::haveRight("project", \Project::READALL)) {
                    $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_projectteams", "projectteams_id", 0, 0, ['jointype' => 'child']);
                }
                return $out;
            case 'Ticket':
                // Same structure in addDefaultWhere
                $out = '';
                if (!\Session::haveRight("ticket", \Ticket::READALL)) {
                    $searchopt = & SearchOption::getOptions($itemtype);
                    // show mine : requester
                    $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_tickets_users", "tickets_users_id", 0, 0, $searchopt[4]['joinparams']['beforejoin']['joinparams']);
                    if (\Session::haveRight("ticket", \Ticket::READGROUP)) {
                        if (count($_SESSION['glpigroups'])) {
                            $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_groups_tickets", "groups_tickets_id", 0, 0, $searchopt[71]['joinparams']['beforejoin']['joinparams']);
                        }
                    }
                    // show mine : observer
                    $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_tickets_users", "tickets_users_id", 0, 0, $searchopt[66]['joinparams']['beforejoin']['joinparams']);
                    if (count($_SESSION['glpigroups'])) {
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_groups_tickets", "groups_tickets_id", 0, 0, $searchopt[65]['joinparams']['beforejoin']['joinparams']);
                    }
                    if (\Session::haveRight("ticket", \Ticket::OWN)) {
                        // Can own ticket : show assign to me
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_tickets_users", "tickets_users_id", 0, 0, $searchopt[5]['joinparams']['beforejoin']['joinparams']);
                    }
                    if (\Session::haveRightsOr("ticket", [\Ticket::READMY, \Ticket::READASSIGN])) {
                        // show mine + assign to me
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_tickets_users", "tickets_users_id", 0, 0, $searchopt[5]['joinparams']['beforejoin']['joinparams']);
                        if (count($_SESSION['glpigroups'])) {
                            $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_groups_tickets", "groups_tickets_id", 0, 0, $searchopt[8]['joinparams']['beforejoin']['joinparams']);
                        }
                    }
                    if (\Session::haveRightsOr('ticketvalidation', [\TicketValidation::VALIDATEINCIDENT, \TicketValidation::VALIDATEREQUEST])) {
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_ticketvalidations", "ticketvalidations_id", 0, 0, $searchopt[58]['joinparams']['beforejoin']['joinparams']);
                    }
                }
                return $out;
            case 'Change':
            case 'Problem':
                if ($itemtype == 'Change') {
                    $right = 'change';
                    $table = 'changes';
                    $groupetable = "glpi_changes_groups";
                    $linkfield = "changes_groups_id";
                } elseif ($itemtype == 'Problem') {
                    $right = 'problem';
                    $table = 'problems';
                    $groupetable = "glpi_groups_problems";
                    $linkfield = "groups_problems_id";
                }
                // Same structure in addDefaultWhere
                $out = '';
                if (!\Session::haveRight("{$right}", $itemtype::READALL)) {
                    $searchopt = & SearchOption::getOptions($itemtype);
                    if (\Session::haveRight("{$right}", $itemtype::READMY)) {
                        // show mine : requester
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_" . $table . "_users", $table . "_users_id", 0, 0, $searchopt[4]['joinparams']['beforejoin']['joinparams']);
                        if (count($_SESSION['glpigroups'])) {
                            $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, $groupetable, $linkfield, 0, 0, $searchopt[71]['joinparams']['beforejoin']['joinparams']);
                        }
                        // show mine : observer
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_" . $table . "_users", $table . "_users_id", 0, 0, $searchopt[66]['joinparams']['beforejoin']['joinparams']);
                        if (count($_SESSION['glpigroups'])) {
                            $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, $groupetable, $linkfield, 0, 0, $searchopt[65]['joinparams']['beforejoin']['joinparams']);
                        }
                        // show mine : assign
                        $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, "glpi_" . $table . "_users", $table . "_users_id", 0, 0, $searchopt[5]['joinparams']['beforejoin']['joinparams']);
                        if (count($_SESSION['glpigroups'])) {
                            $out .= JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, $groupetable, $linkfield, 0, 0, $searchopt[8]['joinparams']['beforejoin']['joinparams']);
                        }
                    }
                }
                return $out;
            default:
                // Plugin can override core definition for its type
                if ($plug = \isPluginItemType($itemtype)) {
                    $plugin_name = $plug['plugin'];
                    $hook_function = 'plugin_' . strtolower((string) $plugin_name) . '_addDefaultJoin';
                    $hook_closure = function () use ($hook_function, $itemtype, $ref_table, &$already_link_tables) {
                        if (is_callable($hook_function)) {
                            return $hook_function($itemtype, $ref_table, $already_link_tables);
                        }
                    };
                    $out = \Plugin::doOneHook($plugin_name, $hook_closure);
                    if (!empty($out)) {
                        return $out;
                    }
                }
                return "";
        }
    }
    /**
     * Generic Function to add left join to a request
     *
     * @param string  $itemtype             Item type
     * @param string  $ref_table            Reference table
     * @param array   $already_link_tables  Array of tables already joined
     * @param string  $new_table            New table to join
     * @param string  $linkfield            Linkfield for LeftJoin
     * @param boolean $meta                 Is it a meta item ? (default 0)
     * @param integer $meta_type            Meta type table (default 0)
     * @param array   $joinparams           Array join parameters (condition / joinbefore...)
     * @param string  $field                Field to display (needed for translation join) (default '')
     *
     * @return string Left join string
     **/
    public static function addLeftJoin($itemtype, $ref_table, array &$already_link_tables, $new_table, $linkfield, $meta = 0, $meta_type = 0, $joinparams = [], $field = '')
    {
        // Rename table for meta left join
        $AS = "";
        $nt = $new_table;
        $cleannt = $nt;
        // Virtual field no link
        if (strpos($linkfield, '_virtual') === 0) {
            return false;
        }
        $complexjoin = JoinBuilder::computeComplexJoinID($joinparams);
        $is_fkey_composite_on_self = \getTableNameForForeignKeyField($linkfield) == $ref_table && $linkfield != \getForeignKeyFieldForTable($ref_table);
        // Auto link
        if ($ref_table == $new_table && empty($complexjoin) && !$is_fkey_composite_on_self) {
            $transitemtype = \getItemTypeForTable($new_table);
            if (\Session::haveTranslations($transitemtype, $field)) {
                $transAS = $nt . '_trans_' . $field;
                return JoinBuilder::joinDropdownTranslations($transAS, $nt, $transitemtype, $field);
            }
            return "";
        }
        // Multiple link possibilies case
        if (!empty($linkfield) && $linkfield != \getForeignKeyFieldForTable($new_table)) {
            $nt .= "_" . $linkfield;
            $AS = " AS `{$nt}`";
        }
        if (!empty($complexjoin)) {
            $nt .= "_" . $complexjoin;
            $AS = " AS `{$nt}`";
        }
        $addmetanum = "";
        $rt = $ref_table;
        $cleanrt = $rt;
        if ($meta && $meta_type::getTable() != $new_table) {
            $addmetanum = "_" . $meta_type;
            $AS = " AS `{$nt}{$addmetanum}`";
            $nt = $nt . $addmetanum;
        }
        // Do not take into account standard linkfield
        $tocheck = $nt . "." . $linkfield;
        if ($linkfield == \getForeignKeyFieldForTable($new_table)) {
            $tocheck = $nt;
        }
        if (in_array($tocheck, $already_link_tables)) {
            return "";
        }
        array_push($already_link_tables, $tocheck);
        $specific_leftjoin = '';
        // Plugin can override core definition for its type
        if ($plug = \isPluginItemType($itemtype)) {
            $plugin_name = $plug['plugin'];
            $hook_function = 'plugin_' . strtolower((string) $plugin_name) . '_addLeftJoin';
            $hook_closure = function () use ($hook_function, $itemtype, $ref_table, $new_table, $linkfield, &$already_link_tables) {
                if (is_callable($hook_function)) {
                    return $hook_function($itemtype, $ref_table, $new_table, $linkfield, $already_link_tables);
                }
            };
            $specific_leftjoin = \Plugin::doOneHook($plugin_name, $hook_closure);
        }
        // Link with plugin tables : need to know left join structure
        if (empty($specific_leftjoin) && preg_match("/^glpi_plugin_([a-z0-9]+)/", $new_table, $matches)) {
            if (count($matches) == 2) {
                $plugin_name = $matches[1];
                $hook_function = 'plugin_' . strtolower($plugin_name) . '_addLeftJoin';
                $hook_closure = function () use ($hook_function, $itemtype, $ref_table, $new_table, $linkfield, &$already_link_tables) {
                    if (is_callable($hook_function)) {
                        return $hook_function($itemtype, $ref_table, $new_table, $linkfield, $already_link_tables);
                    }
                };
                $specific_leftjoin = \Plugin::doOneHook($plugin_name, $hook_closure);
            }
        }
        if (!empty($linkfield)) {
            $before = '';
            if (isset($joinparams['beforejoin']) && is_array($joinparams['beforejoin'])) {
                if (isset($joinparams['beforejoin']['table'])) {
                    $joinparams['beforejoin'] = [$joinparams['beforejoin']];
                }
                foreach ($joinparams['beforejoin'] as $tab) {
                    if (isset($tab['table'])) {
                        $intertable = $tab['table'];
                        if (isset($tab['linkfield'])) {
                            $interlinkfield = $tab['linkfield'];
                        } else {
                            $interlinkfield = \getForeignKeyFieldForTable($intertable);
                        }
                        $interjoinparams = [];
                        if (isset($tab['joinparams'])) {
                            $interjoinparams = $tab['joinparams'];
                        }
                        $before .= JoinBuilder::addLeftJoin($itemtype, $rt, $already_link_tables, $intertable, $interlinkfield, $meta, $meta_type, $interjoinparams);
                    }
                    // No direct link with the previous joins
                    if (!isset($tab['joinparams']['nolink']) || !$tab['joinparams']['nolink']) {
                        $cleanrt = $intertable;
                        $complexjoin = JoinBuilder::computeComplexJoinID($interjoinparams);
                        if (!empty($complexjoin)) {
                            $intertable .= "_" . $complexjoin;
                        }
                        if ($meta && $meta_type::getTable() != $cleanrt) {
                            $intertable .= "_" . $meta_type;
                        }
                        $rt = $intertable;
                    }
                }
            }
            $addcondition = '';
            if (isset($joinparams['condition'])) {
                $condition = $joinparams['condition'];
                if (is_array($condition)) {
                    $it = new \DBmysqlIterator(null);
                    $condition = $it->analyseCrit($condition);
                }
                $from = ["`REFTABLE`", "REFTABLE", "`NEWTABLE`", "NEWTABLE"];
                $to = ["`{$rt}`", "`{$rt}`", "`{$nt}`", "`{$nt}`"];
                $addcondition = str_replace($from, $to, $condition);
                $addcondition = $addcondition . " ";
            }
            if (!isset($joinparams['jointype'])) {
                $joinparams['jointype'] = 'standard';
            }
            if (empty($specific_leftjoin)) {
                switch ($new_table) {
                    // No link
                    case "glpi_auth_tables":
                        $user_searchopt = SearchOption::getOptions('User');
                        $specific_leftjoin = JoinBuilder::addLeftJoin($itemtype, $rt, $already_link_tables, "glpi_authldaps", 'auths_id', 0, 0, $user_searchopt[30]['joinparams']);
                        $specific_leftjoin .= JoinBuilder::addLeftJoin($itemtype, $rt, $already_link_tables, "glpi_authmails", 'auths_id', 0, 0, $user_searchopt[31]['joinparams']);
                        break;
                }
            }
            if (empty($specific_leftjoin)) {
                switch ($joinparams['jointype']) {
                    case 'child':
                        $linkfield = \getForeignKeyFieldForTable($cleanrt);
                        if (isset($joinparams['linkfield'])) {
                            $linkfield = $joinparams['linkfield'];
                        }
                        // Child join
                        $specific_leftjoin = " LEFT JOIN `{$new_table}` {$AS}
                                             ON (`{$rt}`.`id` = `{$nt}`.`{$linkfield}`
                                                 {$addcondition})";
                        break;
                    case 'item_item':
                        // Item_Item join
                        $specific_leftjoin = " LEFT JOIN `{$new_table}` {$AS}
                                          ON ((`{$rt}`.`id`
                                                = `{$nt}`.`" . \getForeignKeyFieldForTable($cleanrt) . "_1`
                                               OR `{$rt}`.`id`
                                                 = `{$nt}`.`" . \getForeignKeyFieldForTable($cleanrt) . "_2`)
                                              {$addcondition})";
                        break;
                    case 'item_item_revert':
                        // Item_Item join reverting previous item_item
                        $specific_leftjoin = " LEFT JOIN `{$new_table}` {$AS}
                                          ON ((`{$nt}`.`id`
                                                = `{$rt}`.`" . \getForeignKeyFieldForTable($cleannt) . "_1`
                                               OR `{$nt}`.`id`
                                                 = `{$rt}`.`" . \getForeignKeyFieldForTable($cleannt) . "_2`)
                                              {$addcondition})";
                        break;
                    case "mainitemtype_mainitem":
                        $addmain = 'main';
                        // no break
                    case "itemtype_item":
                        if (!isset($addmain)) {
                            $addmain = '';
                        }
                        $used_itemtype = $itemtype;
                        if (isset($joinparams['specific_itemtype']) && !empty($joinparams['specific_itemtype'])) {
                            $used_itemtype = $joinparams['specific_itemtype'];
                        }
                        // Itemtype join
                        $specific_leftjoin = " LEFT JOIN `{$new_table}` {$AS}
                                          ON (`{$rt}`.`id` = `{$nt}`.`" . $addmain . "items_id`
                                              AND `{$nt}`.`" . $addmain . "itemtype` = '{$used_itemtype}'
                                              {$addcondition}) ";
                        break;
                    case "itemtype_item_revert":
                        if (!isset($addmain)) {
                            $addmain = '';
                        }
                        $used_itemtype = $itemtype;
                        if (isset($joinparams['specific_itemtype']) && !empty($joinparams['specific_itemtype'])) {
                            $used_itemtype = $joinparams['specific_itemtype'];
                        }
                        // Itemtype join
                        $specific_leftjoin = " LEFT JOIN `{$new_table}` {$AS}
                                          ON (`{$nt}`.`id` = `{$rt}`.`" . $addmain . "items_id`
                                              AND `{$rt}`.`" . $addmain . "itemtype` = '{$used_itemtype}'
                                              {$addcondition}) ";
                        break;
                    case "itemtypeonly":
                        $used_itemtype = $itemtype;
                        if (isset($joinparams['specific_itemtype']) && !empty($joinparams['specific_itemtype'])) {
                            $used_itemtype = $joinparams['specific_itemtype'];
                        }
                        // Itemtype join
                        $specific_leftjoin = " LEFT JOIN `{$new_table}` {$AS}
                                          ON (`{$nt}`.`itemtype` = '{$used_itemtype}'
                                              {$addcondition}) ";
                        break;
                    default:
                        // Standard join
                        $specific_leftjoin = "LEFT JOIN `{$new_table}` {$AS}
                                          ON (`{$rt}`.`{$linkfield}` = `{$nt}`.`id`
                                              {$addcondition})";
                        $transitemtype = \getItemTypeForTable($new_table);
                        if (\Session::haveTranslations($transitemtype, $field)) {
                            $transAS = $nt . '_trans_' . $field;
                            $specific_leftjoin .= JoinBuilder::joinDropdownTranslations($transAS, $nt, $transitemtype, $field);
                        }
                        break;
                }
            }
            return $before . $specific_leftjoin;
        }
    }
    /**
     * Generic Function to add left join for meta items
     *
     * @param string $from_type             Reference item type ID
     * @param string $to_type               Item type to add
     * @param array  $already_link_tables2  Array of tables already joined
     *
     * @return string Meta Left join string
     **/
    public static function addMetaLeftJoin($from_type, $to_type, array &$already_link_tables2, $joinparams = [])
    {
        global $CFG_GLPI;
        $from_referencetype = SearchOption::getMetaReferenceItemtype($from_type);
        $LINK = " LEFT JOIN ";
        $from_table = $from_type::getTable();
        $from_fk = \getForeignKeyFieldForTable($from_table);
        $to_table = $to_type::getTable();
        $to_fk = \getForeignKeyFieldForTable($to_table);
        $to_obj = \getItemForItemtype($to_type);
        $to_entity_restrict = $to_obj->isField('entities_id') ? \getEntitiesRestrictRequest('AND', $to_table) : '';
        $complexjoin = JoinBuilder::computeComplexJoinID($joinparams);
        $alias_suffix = ($complexjoin != '' ? '_' . $complexjoin : '') . '_' . $to_type;
        $JOIN = "";
        // Specific JOIN
        if ($from_referencetype === 'Software' && in_array($to_type, $CFG_GLPI['software_types'])) {
            // From Software to software_types
            $softwareversions_table = "glpi_softwareversions{$alias_suffix}";
            if (!in_array($softwareversions_table, $already_link_tables2)) {
                array_push($already_link_tables2, $softwareversions_table);
                $JOIN .= "{$LINK} `glpi_softwareversions` AS `{$softwareversions_table}`
                         ON (`{$softwareversions_table}`.`softwares_id` = `{$from_table}`.`id`) ";
            }
            $items_softwareversions_table = "glpi_items_softwareversions_{$alias_suffix}";
            if (!in_array($items_softwareversions_table, $already_link_tables2)) {
                array_push($already_link_tables2, $items_softwareversions_table);
                $JOIN .= "{$LINK} `glpi_items_softwareversions` AS `{$items_softwareversions_table}`
                         ON (`{$items_softwareversions_table}`.`softwareversions_id` = `{$softwareversions_table}`.`id`
                             AND `{$items_softwareversions_table}`.`itemtype` = '{$to_type}'
                             AND `{$items_softwareversions_table}`.`is_deleted` = '0') ";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$items_softwareversions_table}`.`items_id` = `{$to_table}`.`id`
                             AND `{$items_softwareversions_table}`.`itemtype` = '{$to_type}'
                             {$to_entity_restrict}) ";
            }
            return $JOIN;
        }
        if ($to_type === 'Software' && in_array($from_referencetype, $CFG_GLPI['software_types'])) {
            // From software_types to Software
            $items_softwareversions_table = "glpi_items_softwareversions{$alias_suffix}";
            if (!in_array($items_softwareversions_table, $already_link_tables2)) {
                array_push($already_link_tables2, $items_softwareversions_table);
                $JOIN .= "{$LINK} `glpi_items_softwareversions` AS `{$items_softwareversions_table}`
                         ON (`{$items_softwareversions_table}`.`items_id` = `{$from_table}`.`id`
                             AND `{$items_softwareversions_table}`.`itemtype` = '{$from_type}'
                             AND `{$items_softwareversions_table}`.`is_deleted` = '0') ";
            }
            $softwareversions_table = "glpi_softwareversions{$alias_suffix}";
            if (!in_array($softwareversions_table, $already_link_tables2)) {
                array_push($already_link_tables2, $softwareversions_table);
                $JOIN .= "{$LINK} `glpi_softwareversions` AS `{$softwareversions_table}`
                         ON (`{$items_softwareversions_table}`.`softwareversions_id` = `{$softwareversions_table}`.`id`) ";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$softwareversions_table}`.`softwares_id` = `{$to_table}`.`id`) ";
            }
            $softwarelicenses_table = "glpi_softwarelicenses{$alias_suffix}";
            if (!in_array($softwarelicenses_table, $already_link_tables2)) {
                array_push($already_link_tables2, $softwarelicenses_table);
                $JOIN .= "{$LINK} `glpi_softwarelicenses` AS `{$softwarelicenses_table}`
                        ON ({$to_table}.`id` = `{$softwarelicenses_table}`.`softwares_id`" . \getEntitiesRestrictRequest(' AND', $softwarelicenses_table, '', '', true) . ") ";
            }
            return $JOIN;
        }
        if ($from_referencetype === 'Budget' && in_array($to_type, $CFG_GLPI['infocom_types'])) {
            // From Budget to infocom_types
            $infocom_alias = "glpi_infocoms{$alias_suffix}";
            if (!in_array($infocom_alias, $already_link_tables2)) {
                array_push($already_link_tables2, $infocom_alias);
                $JOIN .= "{$LINK} `glpi_infocoms` AS `{$infocom_alias}`
                         ON (`{$from_table}`.`id` = `{$infocom_alias}`.`budgets_id`) ";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$to_table}`.`id` = `{$infocom_alias}`.`items_id`
                             AND `{$infocom_alias}`.`itemtype` = '{$to_type}'
                             {$to_entity_restrict}) ";
            }
            return $JOIN;
        }
        if ($to_type === 'Budget' && in_array($from_referencetype, $CFG_GLPI['infocom_types'])) {
            // From infocom_types to Budget
            $infocom_alias = "glpi_infocoms{$alias_suffix}";
            if (!in_array($infocom_alias, $already_link_tables2)) {
                array_push($already_link_tables2, $infocom_alias);
                $JOIN .= "{$LINK} `glpi_infocoms` AS `{$infocom_alias}`
                         ON (`{$from_table}`.`id` = `{$infocom_alias}`.`items_id`
                             AND `{$infocom_alias}`.`itemtype` = '{$from_type}') ";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$infocom_alias}`.`{$to_fk}` = `{$to_table}`.`id`
                             {$to_entity_restrict}) ";
            }
            return $JOIN;
        }
        if ($from_referencetype === 'Reservation' && in_array($to_type, $CFG_GLPI['reservation_types'])) {
            // From Reservation to reservation_types
            $reservationitems_alias = "glpi_reservationitems{$alias_suffix}";
            if (!in_array($reservationitems_alias, $already_link_tables2)) {
                array_push($already_link_tables2, $reservationitems_alias);
                $JOIN .= "{$LINK} `glpi_reservationitems` AS `{$reservationitems_alias}`
                         ON (`{$from_table}`.`reservationitems_id` = `{$reservationitems_alias}`.`id`) ";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$to_table}`.`id` = `{$reservationitems_alias}`.`items_id`
                             AND `{$reservationitems_alias}`.`itemtype` = '{$to_type}'
                             {$to_entity_restrict}) ";
            }
            return $JOIN;
        }
        if ($to_type === 'Reservation' && in_array($from_referencetype, $CFG_GLPI['reservation_types'])) {
            // From reservation_types to Reservation
            $reservationitems_alias = "glpi_reservationitems{$alias_suffix}";
            if (!in_array($infocom_alias, $already_link_tables2)) {
                array_push($already_link_tables2, $infocom_alias);
                $JOIN .= "{$LINK} `glpi_reservationitems` AS `{$reservationitems_alias}`
                         ON (`{$from_table}`.`id` = `{$reservationitems_alias}`.`items_id`
                             AND `{$reservationitems_alias}`.`itemtype` = '{$from_type}') ";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$reservationitems_alias}`.`id` = `{$to_table}`.`reservationitems_id`
                             {$to_entity_restrict}) ";
            }
            return $JOIN;
        }
        // Generic JOIN
        $from_obj = \getItemForItemtype($from_referencetype);
        $from_item_obj = null;
        $to_obj = \getItemForItemtype($to_type);
        $to_item_obj = null;
        if (SearchOption::isPossibleMetaSubitemOf($from_referencetype, $to_type)) {
            $from_item_obj = \getItemForItemtype($from_referencetype . '_Item');
            if (!$from_item_obj) {
                $from_item_obj = \getItemForItemtype('Item_' . $from_referencetype);
            }
        }
        if (SearchOption::isPossibleMetaSubitemOf($to_type, $from_referencetype)) {
            $to_item_obj = \getItemForItemtype($to_type . '_Item');
            if (!$to_item_obj) {
                $to_item_obj = \getItemForItemtype('Item_' . $to_type);
            }
        }
        if ($from_obj && $from_obj->isField($to_fk)) {
            // $from_table has a foreign key corresponding to $to_table
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$from_table}`.`{$to_fk}` = `{$to_table}`.`id`
                             {$to_entity_restrict}) ";
            }
        } elseif ($to_obj && $to_obj->isField($from_fk)) {
            // $to_table has a foreign key corresponding to $from_table
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$from_table}`.`id` = `{$to_table}`.`{$from_fk}`
                             {$to_entity_restrict}) ";
            }
        } elseif ($from_obj && $from_obj->isField('itemtype') && $from_obj->isField('items_id')) {
            // $from_table has items_id/itemtype fields
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$from_table}`.`items_id` = `{$to_table}`.`id`
                             AND `{$from_table}`.`itemtype` = '{$to_type}'
                             {$to_entity_restrict}) ";
            }
        } elseif ($to_obj && $to_obj->isField('itemtype') && $to_obj->isField('items_id')) {
            // $to_table has items_id/itemtype fields
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$from_table}`.`id` = `{$to_table}`.`items_id`
                             AND `{$to_table}`.`itemtype` = '{$from_type}'
                             {$to_entity_restrict}) ";
            }
        } elseif ($from_item_obj && $from_item_obj->isField($from_fk)) {
            // glpi_$from_items table exists and has a foreign key corresponding to $to_table
            $items_table = $from_item_obj::getTable();
            $items_table_alias = $items_table . $alias_suffix;
            if (!in_array($items_table_alias, $already_link_tables2)) {
                array_push($already_link_tables2, $items_table_alias);
                $deleted = $from_item_obj->isField('is_deleted') ? "AND `{$items_table_alias}`.`is_deleted` = '0'" : "";
                $JOIN .= "{$LINK} `{$items_table}` AS `{$items_table_alias}`
                         ON (`{$items_table_alias}`.`{$from_fk}` = `{$from_table}`.`id`
                             AND `{$items_table_alias}`.`itemtype` = '{$to_type}'
                             {$deleted})";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$items_table_alias}`.`items_id` = `{$to_table}`.`id`
                             {$to_entity_restrict}) ";
            }
        } elseif ($to_item_obj && $to_item_obj->isField($to_fk)) {
            // glpi_$to_items table exists and has a foreign key corresponding to $from_table
            $items_table = $to_item_obj::getTable();
            $items_table_alias = $items_table . $alias_suffix;
            if (!in_array($items_table_alias, $already_link_tables2)) {
                array_push($already_link_tables2, $items_table_alias);
                $deleted = $to_item_obj->isField('is_deleted') ? "AND `{$items_table_alias}`.`is_deleted` = '0'" : "";
                $JOIN .= "{$LINK} `{$items_table}` AS `{$items_table_alias}`
                         ON (`{$items_table_alias}`.`items_id` = `{$from_table}`.`id`
                             AND `{$items_table_alias}`.`itemtype` = '{$from_type}'
                             {$deleted})";
            }
            if (!in_array($to_table, $already_link_tables2)) {
                array_push($already_link_tables2, $to_table);
                $JOIN .= "{$LINK} `{$to_table}`
                         ON (`{$items_table_alias}`.`{$to_fk}` = `{$to_table}`.`id`
                             {$to_entity_restrict}) ";
            }
        }
        return $JOIN;
    }
    /**
     * @param array $joinparams
     */
    public static function computeComplexJoinID(array $joinparams)
    {
        $complexjoin = '';
        if (isset($joinparams['condition'])) {
            if (is_array($joinparams['condition'])) {
                $complexjoin .= print_r($joinparams['condition'], true);
            } else {
                $complexjoin .= $joinparams['condition'];
            }
        }
        // For jointype == child
        if (isset($joinparams['jointype']) && $joinparams['jointype'] == 'child' && isset($joinparams['linkfield'])) {
            $complexjoin .= $joinparams['linkfield'];
        }
        if (isset($joinparams['beforejoin'])) {
            if (isset($joinparams['beforejoin']['table'])) {
                $joinparams['beforejoin'] = [$joinparams['beforejoin']];
            }
            foreach ($joinparams['beforejoin'] as $tab) {
                if (isset($tab['table'])) {
                    $complexjoin .= $tab['table'];
                }
                if (isset($tab['joinparams']) && isset($tab['joinparams']['condition'])) {
                    if (is_array($tab['joinparams']['condition'])) {
                        $complexjoin .= print_r($tab['joinparams']['condition'], true);
                    } else {
                        $complexjoin .= $tab['joinparams']['condition'];
                    }
                }
            }
        }
        if (!empty($complexjoin)) {
            $complexjoin = md5($complexjoin);
        }
        return $complexjoin;
    }
    /**
     * Add join for dropdown translations
     *
     * @param string $alias    Alias for translation table
     * @param string $table    Table to join on
     * @param string $itemtype Item type
     * @param string $field    Field name
     *
     * @return string
     */
    public static function joinDropdownTranslations($alias, $table, $itemtype, $field)
    {
        return "LEFT JOIN `glpi_dropdowntranslations` AS `{$alias}`
                  ON (`{$alias}`.`itemtype` = '{$itemtype}'
                        AND `{$alias}`.`items_id` = `{$table}`.`id`
                        AND `{$alias}`.`language` = '" . $_SESSION['glpilanguage'] . "'
                        AND `{$alias}`.`field` = '{$field}')";
    }
    /**
     * Get table name for item type
     *
     * @param string $itemtype
     *
     * @return string
     */
    public static function getOrigTableName(string $itemtype): string
    {
        return is_a($itemtype, \CommonDBTM::class, true) ? $itemtype::getTable() : \getTableForItemType($itemtype);
    }
}
