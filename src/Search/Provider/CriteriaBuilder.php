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

use itsmng\Search\Input\QueryBuilder;
use itsmng\Search\SearchOption;

final class CriteriaBuilder
{
    /**
     * Construct WHERE (or HAVING) part of the sql based on passed criteria
     *
     * @since 9.4
     *
     * @param  array   $criteria  list of search criterion, we should have these keys:
     *                               - link (optionnal): AND, OR, NOT AND, NOT OR
     *                               - field: id of the searchoption
     *                               - searchtype: how to match value (contains, equals, etc)
     *                               - value
     * @param  array   $data      common array used by search engine,
     *                            contains all the search part (sql, criteria, params, itemtype etc)
     *                            TODO: should be a property of the class
     * @param  array   $searchopt Search options for the current itemtype
     * @param  boolean $is_having Do we construct sql WHERE or HAVING part
     *
     * @return string             the sql sub string
     */
    public static function constructCriteriaSQL($criteria = [], $data = [], $searchopt = [], $is_having = false)
    {
        $sql = "";
        foreach ($criteria as $criterion) {
            if (!isset($criterion['criteria']) && (!isset($criterion['value']) || strlen($criterion['value']) <= 0)) {
                continue;
            }
            $itemtype = $data['itemtype'];
            $meta = false;
            if (isset($criterion['meta']) && $criterion['meta'] && isset($criterion['itemtype'])) {
                $itemtype = $criterion['itemtype'];
                $meta = true;
                // These options belong to the meta item type. Keeping a reference
                // here would overwrite its shared cache on the next non-meta criterion.
                $meta_searchopt = SearchOption::getOptions($itemtype);
            } else {
                // Not a meta, use the same search option everywhere
                $meta_searchopt = $searchopt;
            }
            // common search
            if (!isset($criterion['field']) || $criterion['field'] != "all" && $criterion['field'] != "view") {
                $LINK = " ";
                $NOT = 0;
                $tmplink = "";
                if (isset($criterion['link']) && in_array($criterion['link'], array_keys(QueryBuilder::getLogicalOperators()))) {
                    if (strstr($criterion['link'], "NOT")) {
                        $tmplink = " " . str_replace(" NOT", "", $criterion['link']);
                        $NOT = 1;
                    } else {
                        $tmplink = " " . $criterion['link'];
                    }
                } else {
                    $tmplink = " AND ";
                }
                // Manage Link if not first item
                if (!empty($sql)) {
                    $LINK = $tmplink;
                }
                if (isset($criterion['criteria']) && count($criterion['criteria'])) {
                    $sub_sql = CriteriaBuilder::constructCriteriaSQL($criterion['criteria'], $data, $meta_searchopt, $is_having);
                    if (strlen($sub_sql)) {
                        if ($NOT) {
                            $sql .= "{$LINK} NOT({$sub_sql})";
                        } else {
                            $sql .= "{$LINK} ({$sub_sql})";
                        }
                    }
                } elseif (isset($meta_searchopt[$criterion['field']]["usehaving"]) || $meta && "AND NOT" === $criterion['link']) {
                    if (!$is_having) {
                        // the having part will be managed in a second pass
                        continue;
                    }
                    $new_having = CriteriaBuilder::addHaving($LINK, $NOT, $itemtype, $criterion['field'], $criterion['searchtype'], $criterion['value']);
                    if ($new_having !== false) {
                        $sql .= $new_having;
                    }
                } else {
                    if ($is_having) {
                        // the having part has been already managed in the first pass
                        continue;
                    }
                    $new_where = CriteriaBuilder::addWhere($LINK, $NOT, $itemtype, $criterion['field'], $criterion['searchtype'], $criterion['value'], $meta);
                    if ($new_where !== false) {
                        $sql .= $new_where;
                    }
                }
            } elseif (isset($criterion['value']) && strlen($criterion['value']) > 0) {
                // view and all search
                $LINK = " OR ";
                $NOT = 0;
                $globallink = " AND ";
                if (isset($criterion['link'])) {
                    switch ($criterion['link']) {
                        case "AND":
                            $LINK = " OR ";
                            $globallink = " AND ";
                            break;
                        case "AND NOT":
                            $LINK = " AND ";
                            $NOT = 1;
                            $globallink = " AND ";
                            break;
                        case "OR":
                            $LINK = " OR ";
                            $globallink = " OR ";
                            break;
                        case "OR NOT":
                            $LINK = " AND ";
                            $NOT = 1;
                            $globallink = " OR ";
                            break;
                    }
                } else {
                    $tmplink = " AND ";
                }
                // Manage Link if not first item
                if (!empty($sql)) {
                    $sql .= $globallink;
                }
                $first2 = true;
                $items = [];
                if (isset($criterion['field']) && $criterion['field'] == "all") {
                    $items = $searchopt;
                } else {
                    // toview case : populate toview
                    foreach ($data['toview'] as $key2 => $val2) {
                        $items[$val2] = $searchopt[$val2];
                    }
                }
                $view_sql = "";
                foreach ($items as $key2 => $val2) {
                    if (isset($val2['nosearch']) && $val2['nosearch']) {
                        continue;
                    }
                    if (is_array($val2)) {
                        // Add Where clause if not to be done in HAVING CLAUSE
                        if (!$is_having && !isset($val2["usehaving"])) {
                            $tmplink = $LINK;
                            if ($first2) {
                                $tmplink = " ";
                            }
                            $new_where = CriteriaBuilder::addWhere($tmplink, $NOT, $itemtype, $key2, $criterion['searchtype'], $criterion['value'], $meta);
                            if ($new_where !== false) {
                                $first2 = false;
                                $view_sql .= $new_where;
                            }
                        }
                    }
                }
                if (strlen($view_sql)) {
                    $sql .= " ({$view_sql}) ";
                }
            }
        }
        return $sql;
    }
    /**
     * Construct aditionnal SQL (select, joins, etc) for meta-criteria
     *
     * @since 9.4
     *
     * @param  array  $criteria             list of search criterion
     * @param  string &$SELECT              TODO: should be a class property (output parameter)
     * @param  string &$FROM                TODO: should be a class property (output parameter)
     * @param  array  &$already_link_tables TODO: should be a class property (output parameter)
     * @param  array  &$data                TODO: should be a class property (output parameter)
     *
     * @return void
     */
    public static function constructAdditionalSqlForMetacriteria($criteria = [], &$SELECT = "", &$FROM = "", &$already_link_tables = [], &$data = [])
    {
        $data['meta_toview'] ??= [];
        foreach ($criteria as $criterion) {
            // manage sub criteria
            if (isset($criterion['criteria'])) {
                CriteriaBuilder::constructAdditionalSqlForMetacriteria($criterion['criteria'], $SELECT, $FROM, $already_link_tables, $data);
                continue;
            }
            // parse only criterion with meta flag
            if (!isset($criterion['itemtype']) || empty($criterion['itemtype']) || !isset($criterion['meta']) || !$criterion['meta'] || !isset($criterion['value']) || strlen($criterion['value']) <= 0) {
                continue;
            }
            $m_itemtype = $criterion['itemtype'];
            $metaopt = & SearchOption::getOptions($m_itemtype);
            $sopt = $metaopt[$criterion['field']];
            //add toview for meta criterion
            $data['meta_toview'][$m_itemtype][] = $criterion['field'];
            if ($SELECT instanceof SelectList) {
                $SELECT->merge(ProjectionBuilder::fields($m_itemtype, (int)$criterion['field'], true, $m_itemtype));
            } else {
                $SELECT .= ProjectionBuilder::addSelect($m_itemtype, $criterion['field'], true, $m_itemtype);
            }
            $FROM .= JoinBuilder::addMetaLeftJoin($data['itemtype'], $m_itemtype, $already_link_tables, $sopt["joinparams"]);
            $FROM .= JoinBuilder::addLeftJoin($m_itemtype, $m_itemtype::getTable(), $already_link_tables, $sopt["table"], $sopt["linkfield"], 1, $m_itemtype, $sopt["joinparams"], $sopt["field"]);
        }
    }
    /**
     * Generic Function to add GROUP BY to a request
     *
     * @since 9.4: $num param has been dropped
     *
     * @param string  $LINK           link to use
     * @param string  $NOT            is is a negative search ?
     * @param string  $itemtype       item type
     * @param integer $ID             ID of the item to search
     * @param string  $searchtype     search type ('contains' or 'equals')
     * @param string  $val            value search
     *
     * @return select string
     **/
    public static function addHaving($LINK, $NOT, $itemtype, $ID, $searchtype, $val)
    {
        global $DB;
        $searchopt = & SearchOption::getOptions($itemtype);
        if (!isset($searchopt[$ID]['table'])) {
            return false;
        }
        $table = $searchopt[$ID]["table"];
        $NAME = "ITEM_{$itemtype}_{$ID}";
        // Plugin can override core definition for its type
        if ($plug = \isPluginItemType($itemtype)) {
            $out = \Plugin::doOneHook($plug['plugin'], 'addHaving', $LINK, $NOT, $itemtype, $ID, $val, "{$itemtype}_{$ID}");
            if (!empty($out)) {
                return $out;
            }
        }
        //// Default cases
        // Link with plugin tables
        if (preg_match("/^glpi_plugin_([a-z0-9]+)/", (string) $table, $matches)) {
            if (count($matches) == 2) {
                $plug = $matches[1];
                $out = \Plugin::doOneHook($plug, 'addHaving', $LINK, $NOT, $itemtype, $ID, $val, "{$itemtype}_{$ID}");
                if (!empty($out)) {
                    return $out;
                }
            }
        }
        if (in_array($searchtype, ["notequals", "notcontains"])) {
            $NOT = !$NOT;
        }
        // Preformat items
        if (isset($searchopt[$ID]["datatype"])) {
            switch ($searchopt[$ID]["datatype"]) {
                case "date":
                case "datetime":
                case "date_delay":
                    if (in_array($searchtype, ['contains', 'notcontains'])) {
                        break;
                    }
                    $force_day = $searchopt[$ID]["datatype"] !== 'datetime';
                    if ($searchopt[$ID]["datatype"] === 'datetime' && (strstr($val, 'BEGIN') || strstr($val, 'LAST') || strstr($val, 'DAY'))) {
                        $force_day = true;
                    }
                    $val = \Html::computeGenericDateTimeSearch($val, $force_day);
                    $operator = '';
                    switch ($searchtype) {
                        case 'equals':
                        case 'notequals':
                            $operator = !$NOT ? '=' : '!=';
                            break;
                        case 'lessthan':
                            $operator = !$NOT ? '<' : '>';
                            break;
                        case 'morethan':
                            $operator = !$NOT ? '>' : '<';
                            break;
                    }
                    if ($operator !== '') {
                        return " {$LINK} ({$DB->quoteName($NAME)} {$operator} {$DB->quoteValue($val)}) ";
                    }
                    break;
                case "count":
                case "number":
                case "decimal":
                case "timestamp":
                    $search = ["/\\&lt;/", "/\\&gt;/"];
                    $replace = ["<", ">"];
                    $val = preg_replace($search, $replace, $val);
                    if (preg_match("/([<>])([=]*)[[:space:]]*([0-9]+)/", (string) $val, $regs)) {
                        if ($NOT) {
                            if ($regs[1] == '<') {
                                $regs[1] = '>';
                            } else {
                                $regs[1] = '<';
                            }
                        }
                        $regs[1] .= $regs[2];
                        return " {$LINK} (`{$NAME}` " . $regs[1] . " " . $regs[3] . " ) ";
                    }
                    if (is_numeric($val)) {
                        if (isset($searchopt[$ID]["width"])) {
                            if (!$NOT) {
                                return " {$LINK} (`{$NAME}` < " . (intval($val) + $searchopt[$ID]["width"]) . "
                                        AND `{$NAME}` > " . (intval($val) - $searchopt[$ID]["width"]) . ") ";
                            }
                            return " {$LINK} (`{$NAME}` > " . (intval($val) + $searchopt[$ID]["width"]) . "
                                     OR `{$NAME}` < " . (intval($val) - $searchopt[$ID]["width"]) . " ) ";
                        }
                        // Exact search
                        if (!$NOT) {
                            return " {$LINK} (`{$NAME}` = " . intval($val) . ") ";
                        }
                        return " {$LINK} (`{$NAME}` <> " . intval($val) . ") ";
                    }
                    break;
            }
        }
        return CriteriaBuilder::makeTextCriteria("`{$NAME}`", $val, $NOT, $LINK);
    }
    /**
     * Generic Function to add ORDER BY to a request
     *
     * @since 9.4: $key param has been dropped
     *
     * @param string  $itemtype  ID of the device type
     * @param integer $ID        field to add
     * @param string  $order     order define
     *
     * @return select string
     *
     **/
    public static function addOrderBy($itemtype, $ID, $order)
    {
        global $CFG_GLPI;
        // Security test for order
        if ($order != "ASC") {
            $order = "DESC";
        }
        $searchopt = & SearchOption::getOptions($itemtype);
        $table = $searchopt[$ID]["table"];
        $field = $searchopt[$ID]["field"];
        $addtable = '';
        $is_fkey_composite_on_self = \getTableNameForForeignKeyField($searchopt[$ID]["linkfield"]) == $table && $searchopt[$ID]["linkfield"] != \getForeignKeyFieldForTable($table);
        $orig_table = JoinBuilder::getOrigTableName($itemtype);
        if (($is_fkey_composite_on_self || $table != $orig_table) && $searchopt[$ID]["linkfield"] != \getForeignKeyFieldForTable($table)) {
            $addtable .= "_" . $searchopt[$ID]["linkfield"];
        }
        if (isset($searchopt[$ID]['joinparams'])) {
            $complexjoin = JoinBuilder::computeComplexJoinID($searchopt[$ID]['joinparams']);
            if (!empty($complexjoin)) {
                $addtable .= "_" . $complexjoin;
            }
        }
        if (isset($CFG_GLPI["union_search_type"][$itemtype])) {
            return " ORDER BY `ITEM_{$itemtype}_{$ID}` {$order} ";
        }
        // Plugin can override core definition for its type
        if ($plug = \isPluginItemType($itemtype)) {
            $out = \Plugin::doOneHook($plug['plugin'], 'addOrderBy', $itemtype, $ID, $order, "{$itemtype}_{$ID}");
            if (!empty($out)) {
                return $out;
            }
        }
        switch ($table . "." . $field) {
            case "glpi_auth_tables.name":
                $user_searchopt = SearchOption::getOptions('User');
                return " ORDER BY `glpi_users`.`authtype` {$order},
                              `glpi_authldaps" . $addtable . "_" . JoinBuilder::computeComplexJoinID($user_searchopt[30]['joinparams']) . "`.
                                 `name` {$order},
                              `glpi_authmails" . $addtable . "_" . JoinBuilder::computeComplexJoinID($user_searchopt[31]['joinparams']) . "`.
                                 `name` {$order} ";
            case "glpi_users.name":
                if ($itemtype != 'User') {
                    if ($_SESSION["glpinames_format"] == \User::FIRSTNAME_BEFORE) {
                        $name1 = 'firstname';
                        $name2 = 'realname';
                    } else {
                        $name1 = 'realname';
                        $name2 = 'firstname';
                    }
                    return " ORDER BY `" . $table . $addtable . "`.`{$name1}` {$order},
                                 `" . $table . $addtable . "`.`{$name2}` {$order},
                                 `" . $table . $addtable . "`.`name` {$order}";
                }
                return " ORDER BY `" . $table . $addtable . "`.`name` {$order}";
            case "glpi_networkequipments.ip":
            case "glpi_ipaddresses.name":
                return " ORDER BY INET_ATON(`{$table}{$addtable}`.`{$field}`) {$order} ";
        }
        //// Default cases
        // Link with plugin tables
        if (preg_match("/^glpi_plugin_([a-z0-9]+)/", (string) $table, $matches)) {
            if (count($matches) == 2) {
                $plug = $matches[1];
                $out = \Plugin::doOneHook($plug, 'addOrderBy', $itemtype, $ID, $order, "{$itemtype}_{$ID}");
                if (!empty($out)) {
                    return $out;
                }
            }
        }
        // Preformat items
        if (isset($searchopt[$ID]["datatype"])) {
            switch ($searchopt[$ID]["datatype"]) {
                case "date_delay":
                    $interval = "MONTH";
                    if (isset($searchopt[$ID]['delayunit'])) {
                        $interval = $searchopt[$ID]['delayunit'];
                    }
                    $add_minus = '';
                    if (isset($searchopt[$ID]["datafields"][3])) {
                        $add_minus = "- `{$table}{$addtable}`.`" . $searchopt[$ID]["datafields"][3] . "`";
                    }
                    return " ORDER BY ADDDATE(`{$table}{$addtable}`.`" . $searchopt[$ID]["datafields"][1] . "`,
                                         INTERVAL (`{$table}{$addtable}`.`" . $searchopt[$ID]["datafields"][2] . "` {$add_minus})
                                         {$interval}) {$order} ";
            }
        }
        return " ORDER BY `ITEM_{$itemtype}_{$ID}` {$order} ";
    }
    /**
     * Generic Function to add default where to a request
     *
     * @param string $itemtype device type
     *
     * @return string Where string
     **/
    public static function addDefaultWhere($itemtype)
    {
        $condition = '';
        switch ($itemtype) {
            case 'Reminder':
                $condition = \Reminder::addVisibilityRestrict();
                break;
            case 'RSSFeed':
                $condition = \RSSFeed::addVisibilityRestrict();
                break;
            case 'Notification':
                if (!\Config::canView()) {
                    $condition = " `glpi_notifications`.`itemtype` NOT IN ('CronTask', 'DBConnection') ";
                }
                break;
                // No link
            case 'User':
                // View all entities
                if (!\Session::canViewAllEntities()) {
                    $condition = \getEntitiesRestrictRequest("", "glpi_profiles_users", '', '', true);
                }
                break;
            case 'ProjectTask':
                $condition = '';
                $teamtable = 'glpi_projecttaskteams';
                $condition .= "`glpi_projects`.`is_template` = '0'";
                $condition .= " AND ((`{$teamtable}`.`itemtype` = 'User'
                             AND `{$teamtable}`.`items_id` = '" . \Session::getLoginUserID() . "')";
                if (count($_SESSION['glpigroups'])) {
                    $condition .= " OR (`{$teamtable}`.`itemtype` = 'Group'
                                    AND `{$teamtable}`.`items_id`
                                       IN (" . implode(",", $_SESSION['glpigroups']) . "))";
                }
                $condition .= ") ";
                break;
            case 'Project':
                $condition = '';
                if (!\Session::haveRight("project", \Project::READALL)) {
                    $teamtable = 'glpi_projectteams';
                    $condition .= "(`glpi_projects`.users_id = '" . \Session::getLoginUserID() . "'
                               OR (`{$teamtable}`.`itemtype` = 'User'
                                   AND `{$teamtable}`.`items_id` = '" . \Session::getLoginUserID() . "')";
                    if (count($_SESSION['glpigroups'])) {
                        $condition .= " OR (`glpi_projects`.`groups_id`
                                       IN (" . implode(",", $_SESSION['glpigroups']) . "))";
                        $condition .= " OR (`{$teamtable}`.`itemtype` = 'Group'
                                      AND `{$teamtable}`.`items_id`
                                          IN (" . implode(",", $_SESSION['glpigroups']) . "))";
                    }
                    $condition .= ") ";
                }
                break;
            case 'Ticket':
                // Same structure in addDefaultJoin
                $condition = '';
                if (!\Session::haveRight("ticket", \Ticket::READALL)) {
                    $searchopt = & SearchOption::getOptions($itemtype);
                    $requester_table = '`glpi_tickets_users_' . JoinBuilder::computeComplexJoinID($searchopt[4]['joinparams']['beforejoin']['joinparams']) . '`';
                    $requestergroup_table = '`glpi_groups_tickets_' . JoinBuilder::computeComplexJoinID($searchopt[71]['joinparams']['beforejoin']['joinparams']) . '`';
                    $assign_table = '`glpi_tickets_users_' . JoinBuilder::computeComplexJoinID($searchopt[5]['joinparams']['beforejoin']['joinparams']) . '`';
                    $assigngroup_table = '`glpi_groups_tickets_' . JoinBuilder::computeComplexJoinID($searchopt[8]['joinparams']['beforejoin']['joinparams']) . '`';
                    $observer_table = '`glpi_tickets_users_' . JoinBuilder::computeComplexJoinID($searchopt[66]['joinparams']['beforejoin']['joinparams']) . '`';
                    $observergroup_table = '`glpi_groups_tickets_' . JoinBuilder::computeComplexJoinID($searchopt[65]['joinparams']['beforejoin']['joinparams']) . '`';
                    $condition = "(";
                    if (\Session::haveRight("ticket", \Ticket::READMY)) {
                        $condition .= " {$requester_table}.users_id = '" . \Session::getLoginUserID() . "'
                                    OR {$observer_table}.users_id = '" . \Session::getLoginUserID() . "'
                                    OR `glpi_tickets`.`users_id_recipient` = '" . \Session::getLoginUserID() . "'";
                    } else {
                        $condition .= "0=1";
                    }
                    if (\Session::haveRight("ticket", \Ticket::READGROUP)) {
                        if (count($_SESSION['glpigroups'])) {
                            $condition .= " OR {$requestergroup_table}.`groups_id`
                                             IN (" . implode(",", $_SESSION['glpigroups']) . ")";
                            $condition .= " OR {$observergroup_table}.`groups_id`
                                             IN (" . implode(",", $_SESSION['glpigroups']) . ")";
                        }
                    }
                    if (\Session::haveRight("ticket", \Ticket::OWN)) {
                        // Can own ticket : show assign to me
                        $condition .= " OR {$assign_table}.users_id = '" . \Session::getLoginUserID() . "' ";
                    }
                    if (\Session::haveRight("ticket", \Ticket::READASSIGN)) {
                        // assign to me
                        $condition .= " OR {$assign_table}.`users_id` = '" . \Session::getLoginUserID() . "'";
                        if (count($_SESSION['glpigroups'])) {
                            $condition .= " OR {$assigngroup_table}.`groups_id`
                                             IN (" . implode(",", $_SESSION['glpigroups']) . ")";
                        }
                        if (\Session::haveRight('ticket', \Ticket::ASSIGN)) {
                            $condition .= " OR `glpi_tickets`.`status`='" . \CommonITILObject::INCOMING . "'";
                        }
                    }
                    if (\Session::haveRightsOr('ticketvalidation', [\TicketValidation::VALIDATEINCIDENT, \TicketValidation::VALIDATEREQUEST])) {
                        $condition .= " OR `glpi_ticketvalidations`.`users_id_validate`
                                          = '" . \Session::getLoginUserID() . "'";
                    }
                    $condition .= ") ";
                }
                break;
            case 'Change':
            case 'Problem':
                if ($itemtype == 'Change') {
                    $right = 'change';
                    $table = 'changes';
                    $groupetable = "`glpi_changes_groups_";
                } elseif ($itemtype == 'Problem') {
                    $right = 'problem';
                    $table = 'problems';
                    $groupetable = "`glpi_groups_problems_";
                }
                // Same structure in addDefaultJoin
                $condition = '';
                if (!\Session::haveRight("{$right}", $itemtype::READALL)) {
                    $searchopt = & SearchOption::getOptions($itemtype);
                    if (\Session::haveRight("{$right}", $itemtype::READMY)) {
                        $requester_table = '`glpi_' . $table . '_users_' . JoinBuilder::computeComplexJoinID($searchopt[4]['joinparams']['beforejoin']['joinparams']) . '`';
                        $requestergroup_table = $groupetable . JoinBuilder::computeComplexJoinID($searchopt[71]['joinparams']['beforejoin']['joinparams']) . '`';
                        $observer_table = '`glpi_' . $table . '_users_' . JoinBuilder::computeComplexJoinID($searchopt[66]['joinparams']['beforejoin']['joinparams']) . '`';
                        $observergroup_table = $groupetable . JoinBuilder::computeComplexJoinID($searchopt[65]['joinparams']['beforejoin']['joinparams']) . '`';
                        $assign_table = '`glpi_' . $table . '_users_' . JoinBuilder::computeComplexJoinID($searchopt[5]['joinparams']['beforejoin']['joinparams']) . '`';
                        $assigngroup_table = $groupetable . JoinBuilder::computeComplexJoinID($searchopt[8]['joinparams']['beforejoin']['joinparams']) . '`';
                    }
                    $condition = "(";
                    if (\Session::haveRight("{$right}", $itemtype::READMY)) {
                        $condition .= " {$requester_table}.users_id = '" . \Session::getLoginUserID() . "'
                                 OR {$observer_table}.users_id = '" . \Session::getLoginUserID() . "'
                                 OR {$assign_table}.users_id = '" . \Session::getLoginUserID() . "'
                                 OR `glpi_" . $table . "`.`users_id_recipient` = '" . \Session::getLoginUserID() . "'";
                        if (count($_SESSION['glpigroups'])) {
                            $my_groups_keys = "'" . implode("','", $_SESSION['glpigroups']) . "'";
                            $condition .= " OR {$requestergroup_table}.groups_id IN ({$my_groups_keys})
                                 OR {$observergroup_table}.groups_id IN ({$my_groups_keys})
                                 OR {$assigngroup_table}.groups_id IN ({$my_groups_keys})";
                        }
                    } else {
                        $condition .= "0=1";
                    }
                    $condition .= ") ";
                }
                break;
            case 'Config':
                $availableContexts = ['core'] + \Plugin::getPlugins();
                $availableContexts = implode("', '", $availableContexts);
                $condition = "`context` IN ('{$availableContexts}')";
                break;
            case 'SavedSearch':
                $condition = \SavedSearch::addVisibilityRestrict();
                break;
            case 'TicketTask':
                // Filter on is_private
                $allowed_is_private = [];
                if (\Session::haveRight(\TicketTask::$rightname, \CommonITILTask::SEEPRIVATE)) {
                    $allowed_is_private[] = 1;
                }
                if (\Session::haveRight(\TicketTask::$rightname, \CommonITILTask::SEEPUBLIC)) {
                    $allowed_is_private[] = 0;
                }
                // If the user can't see public and private
                if (!count($allowed_is_private)) {
                    $condition = "0 = 1";
                    break;
                }
                $in = "IN ('" . implode("','", $allowed_is_private) . "')";
                $condition = "(`glpi_tickettasks`.`is_private` {$in} ";
                // Check for assigned or created tasks
                $condition .= "OR `glpi_tickettasks`.`users_id` = " . \Session::getLoginUserID() . " ";
                $condition .= "OR `glpi_tickettasks`.`users_id_tech` = " . \Session::getLoginUserID() . " ";
                // Check for parent item visibility unless the user can see all the
                // possible parents
                if (!\Session::haveRight('ticket', \Ticket::READALL)) {
                    $condition .= "AND " . \TicketTask::buildParentCondition();
                }
                $condition .= ")";
                break;
            case 'ITILFollowup':
                // Filter on is_private
                $allowed_is_private = [];
                if (\Session::haveRight(\ITILFollowup::$rightname, \ITILFollowup::SEEPRIVATE)) {
                    $allowed_is_private[] = 1;
                }
                if (\Session::haveRight(\ITILFollowup::$rightname, \ITILFollowup::SEEPUBLIC)) {
                    $allowed_is_private[] = 0;
                }
                // If the user can't see public and private
                if (!count($allowed_is_private)) {
                    $condition = "0 = 1";
                    break;
                }
                $in = "IN ('" . implode("','", $allowed_is_private) . "')";
                $condition = "(`glpi_itilfollowups`.`is_private` {$in} ";
                // Now filter on parent item visiblity
                $condition .= "AND (";
                // Filter for "ticket" parents
                $condition .= \ITILFollowup::buildParentCondition(\Ticket::getType());
                $condition .= "OR ";
                // Filter for "change" parents
                $condition .= \ITILFollowup::buildParentCondition(\Change::getType(), 'changes_id', "glpi_changes_users", "glpi_changes_groups");
                $condition .= "OR ";
                // Fitler for "problem" parents
                $condition .= \ITILFollowup::buildParentCondition(\Problem::getType(), 'problems_id', "glpi_problems_users", "glpi_groups_problems");
                $condition .= "))";
                break;
            default:
                // Plugin can override core definition for its type
                if ($plug = \isPluginItemType($itemtype)) {
                    $condition = \Plugin::doOneHook($plug['plugin'], 'addDefaultWhere', $itemtype);
                }
                break;
        }
        /* Hook to restrict user right on current itemtype */
        list($itemtype, $condition) = \Plugin::doHookFunction('add_default_where', [$itemtype, $condition]);
        return $condition;
    }
    /**
     * Generic Function to add where to a request
     *
     * @param string  $link         Link string
     * @param boolean $nott         Is it a negative search ?
     * @param string  $itemtype     Item type
     * @param integer $ID           ID of the item to search
     * @param string  $searchtype   Searchtype used (equals or contains)
     * @param string  $val          Item num in the request
     * @param integer $meta         Is a meta search (meta=2 in search.class.php) (default 0)
     *
     * @return string Where string
     **/
    public static function addWhere($link, $nott, $itemtype, $ID, $searchtype, $val, $meta = 0)
    {
        global $DB;
        $searchopt = & SearchOption::getOptions($itemtype);
        if (!isset($searchopt[$ID]['table'])) {
            return false;
        }
        $table = $searchopt[$ID]["table"];
        $field = $searchopt[$ID]["field"];
        $inittable = $table;
        $addtable = '';
        $is_fkey_composite_on_self = \getTableNameForForeignKeyField($searchopt[$ID]["linkfield"]) == $table && $searchopt[$ID]["linkfield"] != \getForeignKeyFieldForTable($table);
        $orig_table = JoinBuilder::getOrigTableName($itemtype);
        if ($table != 'asset_types' && ($is_fkey_composite_on_self || $table != $orig_table) && $searchopt[$ID]["linkfield"] != \getForeignKeyFieldForTable($table)) {
            $addtable = "_" . $searchopt[$ID]["linkfield"];
            $table .= $addtable;
        }
        if (isset($searchopt[$ID]['joinparams'])) {
            $complexjoin = JoinBuilder::computeComplexJoinID($searchopt[$ID]['joinparams']);
            if (!empty($complexjoin)) {
                $table .= "_" . $complexjoin;
            }
        }
        $addmeta = "";
        if ($meta && $itemtype::getTable() != $inittable) {
            $addmeta = "_" . $itemtype;
            $table .= $addmeta;
        }
        // Hack to allow search by ID on every sub-table
        if (preg_match('/^\\$\\$\\$\\$([0-9]+)$/', $val, $regs)) {
            return $link . " (`{$table}`.`id` " . ($nott ? "<>" : "=") . $regs[1] . " " . ($regs[1] == 0 ? " OR `{$table}`.`id` IS NULL" : '') . ") ";
        }
        // Preparse value
        if (isset($searchopt[$ID]["datatype"])) {
            switch ($searchopt[$ID]["datatype"]) {
                case "datetime":
                case "date":
                case "date_delay":
                    $force_day = true;
                    if ($searchopt[$ID]["datatype"] == 'datetime' && !(strstr($val, 'BEGIN') || strstr($val, 'LAST') || strstr($val, 'DAY'))) {
                        $force_day = false;
                    }
                    $val = \Html::computeGenericDateTimeSearch($val, $force_day);
                    break;
            }
        }
        switch ($searchtype) {
            case "notcontains":
                $nott = !$nott;
                // no break
            case "contains":
                $SEARCH = CriteriaBuilder::makeTextSearch($val, $nott);
                break;
            case "equals":
                if ($nott) {
                    $SEARCH = " <> '{$val}'";
                } else {
                    $SEARCH = " = '{$val}'";
                }
                break;
            case "notequals":
                if ($nott) {
                    $SEARCH = " = '{$val}'";
                } else {
                    $SEARCH = " <> '{$val}'";
                }
                break;
            case "under":
                if ($nott) {
                    $SEARCH = " NOT IN ('" . implode("','", \getSonsOf($inittable, $val)) . "')";
                } else {
                    $SEARCH = " IN ('" . implode("','", \getSonsOf($inittable, $val)) . "')";
                }
                break;
            case "notunder":
                if ($nott) {
                    $SEARCH = " IN ('" . implode("','", \getSonsOf($inittable, $val)) . "')";
                } else {
                    $SEARCH = " NOT IN ('" . implode("','", \getSonsOf($inittable, $val)) . "')";
                }
                break;
        }
        //Check in current item if a specific where is defined
        if (method_exists($itemtype, 'addWhere')) {
            $out = $itemtype::addWhere($link, $nott, $itemtype, $ID, $searchtype, $val);
            if (!empty($out)) {
                return $out;
            }
        }
        // Plugin can override core definition for its type
        if ($plug = \isPluginItemType($itemtype)) {
            $out = \Plugin::doOneHook($plug['plugin'], 'addWhere', $link, $nott, $itemtype, $ID, $val, $searchtype);
            if (!empty($out)) {
                return $out;
            }
        }
        switch ($inittable . "." . $field) {
            // case "glpi_users_validation.name" :
            case "glpi_users.name":
                if ($itemtype == 'User') {
                    // glpi_users case / not link table
                    if (in_array($searchtype, ['equals', 'notequals'])) {
                        $search_str = "`{$table}`.`id`" . $SEARCH;
                        if ($searchtype == 'notequals') {
                            $nott = !$nott;
                        }
                        // Add NULL if $val = 0 and not negative search
                        // Or negative search on real value
                        if (!$nott && $val == 0 || $nott && $val != 0) {
                            $search_str .= " OR `{$table}`.`id` IS NULL";
                        }
                        return " {$link} ({$search_str})";
                    }
                    return CriteriaBuilder::makeTextCriteria("`{$table}`.`{$field}`", $val, $nott, $link);
                }
                if ($_SESSION["glpinames_format"] == \User::FIRSTNAME_BEFORE) {
                    $name1 = 'firstname';
                    $name2 = 'realname';
                } else {
                    $name1 = 'realname';
                    $name2 = 'firstname';
                }
                if (in_array($searchtype, ['equals', 'notequals'])) {
                    return " {$link} (`{$table}`.`id`" . $SEARCH . ($val == 0 ? " OR `{$table}`.`id` IS" . ($searchtype == "notequals" ? " NOT" : "") . " NULL" : '') . ') ';
                }
                $toadd = '';
                $tmplink = 'OR';
                if ($nott) {
                    $tmplink = 'AND';
                }
                if (is_a($itemtype, \CommonITILObject::class, true)) {
                    if (isset($searchopt[$ID]["joinparams"]["beforejoin"]["table"]) && isset($searchopt[$ID]["joinparams"]["beforejoin"]["joinparams"]) && ($searchopt[$ID]["joinparams"]["beforejoin"]["table"] == 'glpi_tickets_users' || $searchopt[$ID]["joinparams"]["beforejoin"]["table"] == 'glpi_problems_users' || $searchopt[$ID]["joinparams"]["beforejoin"]["table"] == 'glpi_changes_users')) {
                        $bj = $searchopt[$ID]["joinparams"]["beforejoin"];
                        $linktable = $bj['table'] . '_' . JoinBuilder::computeComplexJoinID($bj['joinparams']) . $addmeta;
                        //$toadd     = "`$linktable`.`alternative_email` $SEARCH $tmplink ";
                        $toadd = CriteriaBuilder::makeTextCriteria("`{$linktable}`.`alternative_email`", $val, $nott, $tmplink);
                        if ($val == '^$') {
                            return $link . " ((`{$linktable}`.`users_id` IS NULL)
                            OR `{$linktable}`.`alternative_email` IS NULL)";
                        }
                    }
                }
                $toadd2 = '';
                if ($nott && $val != 'NULL' && $val != 'null') {
                    $toadd2 = " OR `{$table}`.`{$field}` IS NULL";
                }
                return $link . " (((`{$table}`.`{$name1}` {$SEARCH}
                            {$tmplink} `{$table}`.`{$name2}` {$SEARCH}
                            {$tmplink} `{$table}`.`{$field}` {$SEARCH}
                            {$tmplink} CONCAT(`{$table}`.`{$name1}`, ' ', `{$table}`.`{$name2}`) {$SEARCH} )
                            {$toadd2}) {$toadd})";
            case "glpi_groups.completename":
                if ($val == 'mygroups') {
                    switch ($searchtype) {
                        case 'equals':
                            return " {$link} (`{$table}`.`id` IN ('" . implode("','", $_SESSION['glpigroups']) . "')) ";
                        case 'notequals':
                            return " {$link} (`{$table}`.`id` NOT IN ('" . implode("','", $_SESSION['glpigroups']) . "')) ";
                        case 'under':
                            $groups = $_SESSION['glpigroups'];
                            foreach ($_SESSION['glpigroups'] as $g) {
                                $groups += \getSonsOf($inittable, $g);
                            }
                            $groups = array_unique($groups);
                            return " {$link} (`{$table}`.`id` IN ('" . implode("','", $groups) . "')) ";
                        case 'notunder':
                            $groups = $_SESSION['glpigroups'];
                            foreach ($_SESSION['glpigroups'] as $g) {
                                $groups += \getSonsOf($inittable, $g);
                            }
                            $groups = array_unique($groups);
                            return " {$link} (`{$table}`.`id` NOT IN ('" . implode("','", $groups) . "')) ";
                    }
                }
                break;
            case "glpi_auth_tables.name":
                $user_searchopt = SearchOption::getOptions('User');
                $tmplink = 'OR';
                if ($nott) {
                    $tmplink = 'AND';
                }
                return $link . " (`glpi_authmails" . $addtable . "_" . JoinBuilder::computeComplexJoinID($user_searchopt[31]['joinparams']) . $addmeta . "`.`name`
                           {$SEARCH}
                           {$tmplink} `glpi_authldaps" . $addtable . "_" . JoinBuilder::computeComplexJoinID($user_searchopt[30]['joinparams']) . $addmeta . "`.`name`
                           {$SEARCH} ) ";
            case "glpi_ipaddresses.name":
                $search = ["/\\&lt;/", "/\\&gt;/"];
                $replace = ["<", ">"];
                $val = preg_replace($search, $replace, $val);
                if (preg_match("/^\\s*([<>])([=]*)[[:space:]]*([0-9\\.]+)/", (string) $val, $regs)) {
                    if ($nott) {
                        if ($regs[1] == '<') {
                            $regs[1] = '>';
                        } else {
                            $regs[1] = '<';
                        }
                    }
                    $regs[1] .= $regs[2];
                    return $link . " (INET_ATON(`{$table}`.`{$field}`) " . $regs[1] . " INET_ATON('" . $regs[3] . "')) ";
                }
                break;
            case "glpi_tickets.status":
            case "glpi_problems.status":
            case "glpi_changes.status":
                $tocheck = [];
                if ($item = \getItemForItemtype($itemtype)) {
                    switch ($val) {
                        case 'process':
                            $tocheck = $item->getProcessStatusArray();
                            break;
                        case 'notclosed':
                            $tocheck = $item->getAllStatusArray();
                            foreach ($item->getClosedStatusArray() as $status) {
                                if (isset($tocheck[$status])) {
                                    unset($tocheck[$status]);
                                }
                            }
                            $tocheck = array_keys($tocheck);
                            break;
                        case 'old':
                            $tocheck = array_merge($item->getSolvedStatusArray(), $item->getClosedStatusArray());
                            break;
                        case 'notold':
                            $tocheck = $item::getNotSolvedStatusArray();
                            break;
                        case 'all':
                            $tocheck = array_keys($item->getAllStatusArray());
                            break;
                    }
                }
                if (count($tocheck) == 0) {
                    $statuses = $item->getAllStatusArray();
                    if (isset($statuses[$val])) {
                        $tocheck = [$val];
                    }
                }
                if (count($tocheck)) {
                    if ($nott) {
                        return $link . " `{$table}`.`{$field}` NOT IN ('" . implode("','", $tocheck) . "')";
                    }
                    return $link . " `{$table}`.`{$field}` IN ('" . implode("','", $tocheck) . "')";
                }
                break;
            case "glpi_tickets_tickets.tickets_id_1":
                $tmplink = 'OR';
                $compare = '=';
                if ($nott) {
                    $tmplink = 'AND';
                    $compare = '<>';
                }
                $toadd2 = '';
                if ($nott && $val != 'NULL' && $val != 'null') {
                    $toadd2 = " OR `{$table}`.`{$field}` IS NULL";
                }
                return $link . " (((`{$table}`.`tickets_id_1` {$compare} '{$val}'
                              {$tmplink} `{$table}`.`tickets_id_2` {$compare} '{$val}')
                             AND `glpi_tickets`.`id` <> '{$val}')
                            {$toadd2})";
            case "glpi_tickets.priority":
            case "glpi_tickets.impact":
            case "glpi_tickets.urgency":
            case "glpi_problems.priority":
            case "glpi_problems.impact":
            case "glpi_problems.urgency":
            case "glpi_changes.priority":
            case "glpi_changes.impact":
            case "glpi_changes.urgency":
            case "glpi_projects.priority":
                if (is_numeric($val)) {
                    if ($val > 0) {
                        $compare = $nott ? '<>' : '=';
                        return $link . " `{$table}`.`{$field}` {$compare} '{$val}'";
                    }
                    if ($val < 0) {
                        $compare = $nott ? '<' : '>=';
                        return $link . " `{$table}`.`{$field}` {$compare} '" . abs($val) . "'";
                    }
                    // Show all
                    $compare = $nott ? '<' : '>=';
                    return $link . " `{$table}`.`{$field}` {$compare} '0' ";
                }
                return "";
            case "glpi_tickets.global_validation":
            case "glpi_ticketvalidations.status":
            case "glpi_changes.global_validation":
            case "glpi_changevalidations.status":
                if ($val == 'all') {
                    return "";
                }
                $tocheck = [];
                switch ($val) {
                    case 'can':
                        $tocheck = \CommonITILValidation::getCanValidationStatusArray();
                        break;
                    case 'all':
                        $tocheck = \CommonITILValidation::getAllValidationStatusArray();
                        break;
                }
                if (count($tocheck) == 0) {
                    $tocheck = [$val];
                }
                if (count($tocheck)) {
                    if ($nott) {
                        return $link . " `{$table}`.`{$field}` NOT IN ('" . implode("','", $tocheck) . "')";
                    }
                    return $link . " `{$table}`.`{$field}` IN ('" . implode("','", $tocheck) . "')";
                }
                break;
            case "glpi_notifications.event":
                if (in_array($searchtype, ['equals', 'notequals']) && strpos($val, \Search::SHORTSEP)) {
                    $not = 'notequals' === $searchtype ? 'NOT' : '';
                    list($itemtype_val, $event_val) = explode(\Search::SHORTSEP, $val);
                    return " {$link} {$not}(`{$table}`.`event` = '{$event_val}'
                               AND `{$table}`.`itemtype` = '{$itemtype_val}')";
                }
                break;
        }
        //// Default cases
        // Link with plugin tables
        if (preg_match("/^glpi_plugin_([a-z0-9]+)/", (string) $inittable, $matches)) {
            if (count($matches) == 2) {
                $plug = $matches[1];
                $out = \Plugin::doOneHook($plug, 'addWhere', $link, $nott, $itemtype, $ID, $val, $searchtype);
                if (!empty($out)) {
                    return $out;
                }
            }
        }
        $tocompute = "`{$table}`.`{$field}`";
        $tocomputetrans = "`" . $table . "_trans_" . $field . "`.`value`";
        if (isset($searchopt[$ID]["computation"])) {
            $tocompute = $searchopt[$ID]["computation"];
            $tocompute = str_replace($DB->quoteName('TABLE'), 'TABLE', $tocompute);
            $tocompute = str_replace("TABLE", $DB->quoteName("{$table}"), $tocompute);
        }
        // Preformat items
        if (isset($searchopt[$ID]["datatype"])) {
            switch ($searchopt[$ID]["datatype"]) {
                case "itemtypename":
                    if (in_array($searchtype, ['equals', 'notequals'])) {
                        return " {$link} (`{$table}`.`{$field}`" . $SEARCH . ') ';
                    }
                    break;
                case "itemlink":
                    if (in_array($searchtype, ['equals', 'notequals', 'under', 'notunder'])) {
                        return " {$link} (`{$table}`.`id`" . $SEARCH . ') ';
                    }
                    break;
                case "datetime":
                case "date":
                case "date_delay":
                    if ($searchopt[$ID]["datatype"] == 'datetime' || $searchopt[$ID]["datatype"] == 'date' && $inittable == 'glpi_tickets') {
                        // Specific search for datetime
                        if (in_array($searchtype, ['equals', 'notequals'])) {
                            $val = preg_replace("/:00\$/", '', (string) $val);
                            $val = '^' . $val;
                            if ($searchtype == 'notequals') {
                                $nott = !$nott;
                            }
                            return CriteriaBuilder::makeTextCriteria("`{$table}`.`{$field}`", $val, $nott, $link);
                        }
                    }
                    if ($searchtype == 'lessthan') {
                        $val = '<' . $val;
                    }
                    if ($searchtype == 'morethan') {
                        $val = '>' . $val;
                    }
                    if ($searchtype) {
                        $date_computation = $tocompute;
                    }
                    $search_unit = trim($searchopt[$ID]['searchunit'] ?? 'MONTH');
                    if ($searchopt[$ID]['datatype'] === 'date_delay') {
                        $date_computation = ProjectionBuilder::dateDelay($searchopt[$ID], $table, $DB);
                    }
                    if (in_array($searchtype, ['equals', 'notequals'])) {
                        return " {$link} ({$date_computation} " . $SEARCH . ') ';
                    }
                    $search = ["/\\&lt;/", "/\\&gt;/"];
                    $replace = ["<", ">"];
                    $val = preg_replace($search, $replace, (string) $val);
                    if (preg_match("/^\\s*([<>=]+)(.*)/", (string) $val, $regs)) {
                        if (is_numeric($regs[2])) {
                            return $link . " {$date_computation} " . $regs[1] . " " . $DB->expressions()->dateAdd('CURRENT_TIMESTAMP', (string)(float)$regs[2], $search_unit);
                        }
                        // ELSE Reformat date if needed
                        $regs[2] = preg_replace('@(\\d{1,2})(-|/)(\\d{1,2})(-|/)(\\d{4})@', '\\5-\\3-\\1', $regs[2]);
                        if (preg_match('/[0-9]{2,4}-[0-9]{1,2}-[0-9]{1,2}/', (string) $regs[2])) {
                            $ret = $link;
                            if ($nott) {
                                $ret .= " NOT(";
                            }
                            $ret .= " {$date_computation} {$regs[1]} '{$regs[2]}'";
                            if ($nott) {
                                $ret .= ")";
                            }
                            return $ret;
                        }
                        return "";
                    }
                    // ELSE standard search
                    // Date format modification if needed
                    $val = preg_replace('@(\\d{1,2})(-|/)(\\d{1,2})(-|/)(\\d{4})@', '\\5-\\3-\\1', (string) $val);
                    if ($date_computation) {
                        return CriteriaBuilder::makeTextCriteria($date_computation, $val, $nott, $link);
                    }
                    return '';
                case "right":
                    if ($searchtype == 'notequals') {
                        $nott = !$nott;
                    }
                    return $link . ($nott ? ' NOT' : '') . " (({$tocompute} & '{$val}') <> 0) ";
                case "bool":
                    if ($DB->getProvider() === 'pgsql') {
                        $tocompute = 'CAST(' . $tocompute . ' AS integer)';
                    }
                    if (!is_numeric($val)) {
                        if (strcasecmp((string) $val, __('No')) == 0) {
                            $val = 0;
                        } elseif (strcasecmp((string) $val, __('Yes')) == 0) {
                            $val = 1;
                        }
                    }
                    // no break here : use number comparaison case
                case "count":
                case "number":
                case "decimal":
                case "timestamp":
                case "progressbar":
                    $search = ["/\\&lt;/", "/\\&gt;/"];
                    $replace = ["<", ">"];
                    $val = preg_replace($search, $replace, (string) $val);
                    if (preg_match("/([<>])([=]*)[[:space:]]*([0-9]+)/", (string) $val, $regs)) {
                        if (in_array($searchtype, ["notequals", "notcontains"])) {
                            $nott = !$nott;
                        }
                        if ($nott) {
                            if ($regs[1] == '<') {
                                $regs[1] = '>';
                            } else {
                                $regs[1] = '<';
                            }
                        }
                        $regs[1] .= $regs[2];
                        return $link . " ({$tocompute} " . $regs[1] . " " . $regs[3] . ") ";
                    }
                    if (is_numeric($val)) {
                        $numeric_val = floatval($val);
                        if (in_array($searchtype, ["notequals", "notcontains"])) {
                            $nott = !$nott;
                        }
                        if (isset($searchopt[$ID]["width"])) {
                            $ADD = "";
                            if ($nott && $val != 'NULL' && $val != 'null') {
                                $ADD = " OR {$tocompute} IS NULL";
                            }
                            if ($nott) {
                                return $link . " ({$tocompute} < " . ($numeric_val - $searchopt[$ID]["width"]) . "
                                        OR {$tocompute} > " . ($numeric_val + $searchopt[$ID]["width"]) . "
                                        {$ADD}) ";
                            }
                            return $link . " (({$tocompute} >= " . ($numeric_val - $searchopt[$ID]["width"]) . "
                                      AND {$tocompute} <= " . ($numeric_val + $searchopt[$ID]["width"]) . ")
                                     {$ADD}) ";
                        }
                        if (!$nott) {
                            return " {$link} ({$tocompute} = {$numeric_val}) ";
                        }
                        return " {$link} ({$tocompute} <> {$numeric_val}) ";
                    }
                    break;
            }
        }
        // Default case
        if (in_array($searchtype, ['equals', 'notequals', 'under', 'notunder'])) {
            if ((!isset($searchopt[$ID]['searchequalsonfield']) || !$searchopt[$ID]['searchequalsonfield']) && ($itemtype == 'AllAssets' || $table != $itemtype::getTable())) {
                $out = " {$link} (`{$table}`.`id`" . $SEARCH;
            } else {
                $out = " {$link} (`{$table}`.`{$field}`" . $SEARCH;
            }
            if ($searchtype == 'notequals') {
                $nott = !$nott;
            }
            // Add NULL if $val = 0 and not negative search
            // Or negative search on real value
            if (!$nott && $val == 0 || $nott && $val != 0) {
                $out .= " OR `{$table}`.`id` IS NULL";
            }
            $out .= ')';
            return $out;
        }
        $transitemtype = \getItemTypeForTable($inittable);
        if (\Session::haveTranslations($transitemtype, $field)) {
            return " {$link} (" . CriteriaBuilder::makeTextCriteria($tocompute, $val, $nott, '') . "
                          OR " . CriteriaBuilder::makeTextCriteria($tocomputetrans, $val, $nott, '') . ")";
        }
        return CriteriaBuilder::makeTextCriteria($tocompute, $val, $nott, $link);
    }
    /**
     * Create SQL search condition
     *
     * @param string  $field  Nname (should be ` protected)
     * @param string  $val    Value to search
     * @param boolean $not    Is a negative search ? (false by default)
     * @param string  $link   With previous criteria (default 'AND')
     *
     * @return search SQL string
     **/
    public static function makeTextCriteria($field, $val, $not = false, $link = 'AND')
    {
        global $DB;
        // PostgreSQL does not implicitly cast identifiers/dates for LIKE.
        if (isset($DB) && $DB->getProvider() === 'pgsql') {
            $field = 'CAST(' . $field . ' AS text)';
        }
        $sql = $field . CriteriaBuilder::makeTextSearch($val, $not);
        // mange empty field (string with length = 0)
        $sql_or = "";
        if (strtolower($val) == "null") {
            $sql_or = "OR {$field} = ''";
        }
        if ($not && $val != 'NULL' && $val != 'null' && $val != '^$' || !$not && $val == '^$') {
            // Empty
            $sql = "({$sql} OR {$field} IS NULL)";
        }
        return " {$link} ({$sql} {$sql_or})";
    }
    /**
     * Create SQL search value
     *
     * @since 9.4
     *
     * @param string  $val value to search
     *
     * @return string|null
     **/
    public static function makeTextSearchValue($val)
    {
        // Unclean to permit < and > search
        $val = \Toolbox::unclean_cross_side_scripting_deep($val);
        // escape _ char used as wildcard in mysql likes
        $val = str_replace('_', '\\_', $val);
        if ($val === 'NULL' || $val === 'null') {
            return null;
        }
        $val = trim($val);
        if ($val === '^') {
            // Special case, searching "^" means we are searching for a non empty/null field
            return '%';
        }
        if ($val === '' || $val === '^$' || $val === '$') {
            return '';
        }
        if (preg_match('/^\\^/', $val)) {
            // Remove leading `^`
            $val = ltrim((string) preg_replace('/^\\^/', '', $val));
        } else {
            // Add % wildcard before searched string if not begining by a `^`
            $val = '%' . $val;
        }
        if (preg_match('/\\$$/', $val)) {
            // Remove trailing `$`
            $val = rtrim((string) preg_replace('/\\$$/', '', $val));
        } else {
            // Add % wildcard after searched string if not ending by a `$`
            $val = $val . '%';
        }
        return $val;
    }
    /**
     * Create SQL search condition
     *
     * @param string  $val  Value to search
     * @param boolean $not  Is a negative search ? (false by default)
     *
     * @return string Search string
     **/
    public static function makeTextSearch($val, $not = false)
    {
        global $DB;
        $NOT = "";
        if ($not) {
            $NOT = "NOT";
        }
        $val = CriteriaBuilder::makeTextSearchValue($val);
        if ($val == null) {
            $SEARCH = " IS {$NOT} NULL ";
        } else {
            $operator = isset($DB) && $DB->getProvider() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $SEARCH = " {$NOT} {$operator} '{$val}' ";
        }
        return $SEARCH;
    }
}
