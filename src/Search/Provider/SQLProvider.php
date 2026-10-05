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

use itsmng\Search\Output\LegacyOutput;
use itsmng\Search\SearchOption;

final class SQLProvider implements SearchProviderInterface
{
    /**
     * Construct SQL request depending of search parameters
     *
     * Add to data array a field sql containing an array of requests :
     *      search : request to get items limited to wanted ones
     *      count : to count all items based on search criterias
     *                    may be an array a request : need to add counts
     *                    maybe empty : use search one to count
     *
     * @since 0.85
     *
     * @param array $data  Array of search datas prepared to generate SQL
     *
     * @return void
     **/
    public static function constructSQL(array &$data)
    {
        global $CFG_GLPI, $DB;
        if (!isset($data['itemtype'])) {
            return false;
        }
        $planner = new TwoPhasePlanner($DB);
        if ($planner->supports($data)) {
            $plan = $planner->plan($data);
            $data['sql'] = [
                'plan' => $plan, 'two_phase' => true,
                'count' => [$plan->countSql],
                'search' => $plan->sql((int)$data['search']['start'], $data['search']['export_all'] ? 0 : (int)$data['search']['list_limit']),
            ];
            return;
        }
        $data['sql']['count'] = [];
        $data['sql']['search'] = '';
        $searchopt = & SearchOption::getOptions($data['itemtype']);
        $blacklist_tables = [];
        $orig_table = JoinBuilder::getOrigTableName($data['itemtype']);
        if (isset($CFG_GLPI['union_search_type'][$data['itemtype']])) {
            $itemtable = $CFG_GLPI['union_search_type'][$data['itemtype']];
            $blacklist_tables[] = $orig_table;
        } else {
            $itemtable = $orig_table;
        }
        // hack for AllAssets
        if (isset($CFG_GLPI['union_search_type'][$data['itemtype']])) {
            $entity_restrict = true;
        } else {
            $entity_restrict = $data['item']->isEntityAssign() && $data['item']->isField('entities_id');
        }
        // Construct the request
        //// 1 - SELECT
        // request currentuser for SQL supervision, not displayed
        $dialect = new Dialect($DB);
        $SELECT = ProjectionBuilder::defaults($data['itemtype']);
        $SELECT->add($dialect->quote($itemtable . '.id'), 'id')->add($dialect->literal($_SESSION['glpiname']), 'currentuser');
        foreach ($data['toview'] as $val) {
            $SELECT->merge(ProjectionBuilder::fields($data['itemtype'], (int)$val));
        }
        if (!empty($data['search']['as_map']) && $data['itemtype'] !== 'Entity') {
            $SELECT->add($dialect->quote('glpi_locations.id'), 'loc_id');
        }
        //// 2 - FROM AND LEFT JOIN
        // Set reference table
        $FROM = " FROM `{$itemtable}`";
        // Init already linked tables array in order not to link a table several times
        $already_link_tables = [];
        // Put reference table
        array_push($already_link_tables, $itemtable);
        // Add default join
        $COMMONLEFTJOIN = JoinBuilder::addDefaultJoin($data['itemtype'], $itemtable, $already_link_tables);
        $FROM .= $COMMONLEFTJOIN;
        // Add all table for toview items
        foreach ($data['tocompute'] as $val) {
            if (!in_array($searchopt[$val]["table"], $blacklist_tables)) {
                $FROM .= JoinBuilder::addLeftJoin($data['itemtype'], $itemtable, $already_link_tables, $searchopt[$val]["table"], $searchopt[$val]["linkfield"], 0, 0, $searchopt[$val]["joinparams"], $searchopt[$val]["field"]);
            }
        }
        // Search all case :
        if ($data['search']['all_search']) {
            foreach ($searchopt as $key => $val) {
                // Do not search on Group Name
                if (is_array($val) && isset($val['table'])) {
                    if (!in_array($searchopt[$key]["table"], $blacklist_tables)) {
                        $FROM .= JoinBuilder::addLeftJoin($data['itemtype'], $itemtable, $already_link_tables, $searchopt[$key]["table"], $searchopt[$key]["linkfield"], 0, 0, $searchopt[$key]["joinparams"], $searchopt[$key]["field"]);
                    }
                }
            }
        }
        //// 3 - WHERE
        // default string
        $COMMONWHERE = CriteriaBuilder::addDefaultWhere($data['itemtype']);
        $first = empty($COMMONWHERE);
        // Add deleted if item have it
        if ($data['item'] && $data['item']->maybeDeleted()) {
            $LINK = " AND ";
            if ($first) {
                $LINK = " ";
                $first = false;
            }
            $COMMONWHERE .= $LINK . "`{$itemtable}`.`is_deleted` = " . $DB->quoteValue((int)$data['search']['is_deleted']) . " ";
        }
        // Remove template items
        if ($data['item'] && $data['item']->maybeTemplate()) {
            $LINK = " AND ";
            if ($first) {
                $LINK = " ";
                $first = false;
            }
            $COMMONWHERE .= $LINK . "`{$itemtable}`.`is_template` = '0' ";
        }
        // Add Restrict to current entities
        if ($entity_restrict) {
            $LINK = " AND ";
            if ($first) {
                $LINK = " ";
                $first = false;
            }
            if ($data['itemtype'] == 'Entity') {
                $COMMONWHERE .= \getEntitiesRestrictRequest($LINK, $itemtable);
            } elseif (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                // Will be replace below in Union/Recursivity Hack
                $COMMONWHERE .= $LINK . " ENTITYRESTRICT ";
            } else {
                $COMMONWHERE .= \getEntitiesRestrictRequest($LINK, $itemtable, '', '', $data['item']->maybeRecursive() && $data['item']->isField('is_recursive'));
            }
        }
        $WHERE = "";
        $HAVING = "";
        // Add search conditions
        // If there is search items
        if (count($data['search']['criteria'])) {
            $WHERE = CriteriaBuilder::constructCriteriaSQL($data['search']['criteria'], $data, $searchopt);
            $HAVING = CriteriaBuilder::constructCriteriaSQL($data['search']['criteria'], $data, $searchopt, true);
            // if criteria (with meta flag) need additional join/from sql
            CriteriaBuilder::constructAdditionalSqlForMetacriteria($data['search']['criteria'], $SELECT, $FROM, $already_link_tables, $data);
        }
        //// 4 - ORDER
        $ORDER = " ORDER BY `id` ";
        foreach ($data['tocompute'] as $val) {
            if ($data['search']['sort'] == $val) {
                $ORDER = CriteriaBuilder::addOrderBy($data['itemtype'], $data['search']['sort'], $data['search']['order']);
            }
        }
        //// 7 - Manage GROUP BY
        $GROUPBY = "";
        // Meta Search / Search All / Count tickets
        $criteria_with_meta = array_filter($data['search']['criteria'], function ($criterion) {
            return isset($criterion['meta']) && $criterion['meta'];
        });
        if (count($data['search']['metacriteria']) || count($criteria_with_meta) || !empty($HAVING) || $data['search']['all_search']) {
            $GROUPBY = " GROUP BY `{$itemtable}`.`id`";
        }
        if (empty($GROUPBY)) {
            foreach ($data['toview'] as $val2) {
                if (!empty($GROUPBY)) {
                    break;
                }
                if (isset($searchopt[$val2]["forcegroupby"])) {
                    $GROUPBY = " GROUP BY `{$itemtable}`.`id`";
                }
            }
        }
        $SELECT = 'SELECT DISTINCT ' . $SELECT->sql($dialect, $GROUPBY !== '');
        $LIMIT = "";
        $numrows = 0;
        //No search : count number of items using a simple count(ID) request and LIMIT search
        if ($data['search']['no_search']) {
            if ($data['search']['list_limit'] == 0) {
                $data['search']['list_limit'] = '18446744073709551615';
            }
            $LIMIT = " LIMIT " . (int) $data['search']['list_limit'] . " OFFSET " . (int) $data['search']['start'];
            $count = "count(DISTINCT `{$itemtable}`.`id`)";
            // request currentuser for SQL supervision, not displayed
            $query_num = "SELECT {$count},
                              '" . \Toolbox::addslashes_deep($_SESSION['glpiname']) . "' AS currentuser
                       FROM `{$itemtable}`" . $COMMONLEFTJOIN;
            $first = true;
            if (!empty($COMMONWHERE)) {
                $LINK = " AND ";
                if ($first) {
                    $LINK = " WHERE ";
                    $first = false;
                }
                $query_num .= $LINK . $COMMONWHERE;
            }
            // Union Search :
            if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                $tmpquery = $query_num;
                foreach ($CFG_GLPI[$CFG_GLPI["union_search_type"][$data['itemtype']]] as $ctype) {
                    $ctable = $ctype::getTable();
                    if (($citem = \getItemForItemtype($ctype)) && $citem->canView()) {
                        // State case
                        if ($data['itemtype'] == 'AllAssets') {
                            $query_num = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']], $ctable, $tmpquery);
                            $query_num = str_replace($data['itemtype'], $ctype, $query_num);
                            $query_num .= " AND `{$ctable}`.`id` IS NOT NULL ";
                            // Add deleted if item have it
                            if ($citem && $citem->maybeDeleted()) {
                                $query_num .= " AND `{$ctable}`.`is_deleted` = '0' ";
                            }
                            // Remove template items
                            if ($citem && $citem->maybeTemplate()) {
                                $query_num .= " AND `{$ctable}`.`is_template` = '0' ";
                            }
                        } else {
                            // Ref table case
                            $reftable = $data['itemtype']::getTable();
                            if ($data['item'] && $data['item']->maybeDeleted()) {
                                $tmpquery = str_replace("`" . $CFG_GLPI["union_search_type"][$data['itemtype']] . "`.
                                                   `is_deleted`", "`{$reftable}`.`is_deleted`", $tmpquery);
                            }
                            $replace = "FROM `{$reftable}`
                                  INNER JOIN `{$ctable}`
                                       ON (`{$reftable}`.`items_id` =`{$ctable}`.`id`
                                           AND `{$reftable}`.`itemtype` = '{$ctype}')";
                            $query_num = str_replace("FROM `" . $CFG_GLPI["union_search_type"][$data['itemtype']] . "`", $replace, $tmpquery);
                            $query_num = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']], $ctable, $query_num);
                        }
                        $query_num = str_replace("ENTITYRESTRICT", \getEntitiesRestrictRequest('', $ctable, '', '', $citem->maybeRecursive()), $query_num);
                        $data['sql']['count'][] = $query_num;
                    }
                }
            } else {
                $data['sql']['count'][] = $query_num;
            }
        }
        // If export_all reset LIMIT condition
        if ($data['search']['export_all']) {
            $LIMIT = "";
        }
        if (!empty($WHERE) || !empty($COMMONWHERE)) {
            if (!empty($COMMONWHERE)) {
                $WHERE = ' WHERE ' . $COMMONWHERE . (!empty($WHERE) ? ' AND ( ' . $WHERE . ' )' : '');
            } else {
                $WHERE = ' WHERE ' . $WHERE . ' ';
            }
            $first = false;
        }
        if (!empty($HAVING)) {
            $HAVING = ' HAVING ' . $HAVING;
        }
        // Create QUERY
        if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
            $first = true;
            $QUERY = "";
            foreach ($CFG_GLPI[$CFG_GLPI["union_search_type"][$data['itemtype']]] as $ctype) {
                $ctable = $ctype::getTable();
                if (($citem = \getItemForItemtype($ctype)) && $citem->canView()) {
                    if ($first) {
                        $first = false;
                    } else {
                        $QUERY .= " UNION ";
                    }
                    $tmpquery = "";
                    // AllAssets case
                    if ($data['itemtype'] == 'AllAssets') {
                        $tmpquery = $SELECT . ', ' . $dialect->literal($ctype)
                            . ' AS ' . $dialect->quote('TYPE') . ' ' . $FROM . $WHERE;
                        $tmpquery .= " AND `{$ctable}`.`id` IS NOT NULL ";
                        // Add deleted if item have it
                        if ($citem && $citem->maybeDeleted()) {
                            $tmpquery .= " AND `{$ctable}`.`is_deleted` = '0' ";
                        }
                        // Remove template items
                        if ($citem && $citem->maybeTemplate()) {
                            $tmpquery .= " AND `{$ctable}`.`is_template` = '0' ";
                        }
                        $tmpquery .= $GROUPBY . $HAVING;
                        // Replace 'asset_types' by itemtype table name
                        $tmpquery = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']], $ctable, $tmpquery);
                        // Replace 'AllAssets' by itemtype
                        // Use quoted value to prevent replacement of AllAssets in column identifiers
                        $tmpquery = str_replace($DB->quoteValue('AllAssets'), $DB->quoteValue($ctype), $tmpquery);
                    } else {
                        // Ref table case
                        $reftable = $data['itemtype']::getTable();
                        $tmpquery = $SELECT . ', ' . $dialect->literal($ctype)
                            . ' AS ' . $dialect->quote('TYPE') . ', '
                            . $dialect->quote($reftable . '.id') . ' AS ' . $dialect->quote('refID') . ', '
                            . $dialect->quote($ctable . '.entities_id') . ' AS ' . $dialect->quote('ENTITY')
                            . ' ' . $FROM . $WHERE;
                        if ($data['item']->maybeDeleted()) {
                            $tmpquery = str_replace("`" . $CFG_GLPI["union_search_type"][$data['itemtype']] . "`.
                                                `is_deleted`", "`{$reftable}`.`is_deleted`", $tmpquery);
                        }
                        $replace = "FROM `{$reftable}`" . "
                              INNER JOIN `{$ctable}`" . "
                                 ON (`{$reftable}`.`items_id`=`{$ctable}`.`id`" . "
                                     AND `{$reftable}`.`itemtype` = '{$ctype}')";
                        $tmpquery = str_replace("FROM `" . $CFG_GLPI["union_search_type"][$data['itemtype']] . "`", $replace, $tmpquery);
                        $tmpquery = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']], $ctable, $tmpquery);
                        $name_field = $ctype::getNameField();
                        $tmpquery = str_replace("`{$ctable}`.`name`", "`{$ctable}`.`{$name_field}`", $tmpquery);
                    }
                    $tmpquery = str_replace("ENTITYRESTRICT", \getEntitiesRestrictRequest('', $ctable, '', '', $citem->maybeRecursive()), $tmpquery);
                    // SOFTWARE HACK
                    if ($ctype == 'Software') {
                        $tmpquery = str_replace("`glpi_softwares`.`serial`", "''", $tmpquery);
                        $tmpquery = str_replace("`glpi_softwares`.`otherserial`", "''", $tmpquery);
                    }
                    $QUERY .= $tmpquery;
                }
            }
            if (empty($QUERY)) {
                echo LegacyOutput::showError($data['display_type']);
                return;
            }
            $QUERY .= str_replace($CFG_GLPI["union_search_type"][$data['itemtype']] . ".", "", $ORDER) . $LIMIT;
        } else {
            $QUERY = $SELECT . $FROM . $WHERE . $GROUPBY . $HAVING . $ORDER . $LIMIT;
        }
        $data['sql']['search'] = $QUERY;
    }
    /**
     * Retrieve datas from DB : construct data array containing columns definitions and rows datas
     *
     * add to data array a field data containing :
     *      cols : columns definition
     *      rows : rows data
     *
     * @since 0.85
     *
     * @param array   $data      array of search data prepared to get data
     * @param boolean $onlycount If we just want to count results
     *
     * @return void
     **/
    public static function constructData(array &$data, $onlycount = false)
    {
        if (!isset($data['sql']) || !isset($data['sql']['search'])) {
            return false;
        }
        $data['data'] = [];
        // Use a ReadOnly connection if available and configured to be used
        $DBread = \DBConnection::getReadConnection();
        if ($DBread->getProvider() === 'mysql') {
            $DBread->query("SET SESSION group_concat_max_len = 16384;");
        }
        // directly increase group_concat_max_len to avoid double query
        if ($DBread->getProvider() === 'mysql' && count($data['search']['metacriteria'])) {
            foreach ($data['search']['metacriteria'] as $metacriterion) {
                if ($metacriterion['link'] == 'AND NOT' || $metacriterion['link'] == 'OR NOT') {
                    $DBread->query("SET SESSION group_concat_max_len = 4194304;");
                    break;
                }
            }
        }
        $plannedCount = null;
        if (($data['sql']['plan'] ?? null) instanceof SearchPlan) {
            $plan = $data['sql']['plan'];
            $countResult = $DBread->query($plan->countSql);
            if (!$countResult) {
                throw new \RuntimeException($DBread->error());
            }
            $plannedCount = (int)$DBread->result($countResult, 0, 0);
            $limit = $data['search']['export_all'] ? 0 : (int)$data['search']['list_limit'];
            $start = max(0, (int)$data['search']['start']);
            if ($limit === 0 || $plannedCount === 0) {
                $start = 0;
            } elseif ($start >= $plannedCount) {
                $start = (int)(floor(($plannedCount - 1) / $limit) * $limit);
            }
            $data['search']['start'] = $start;
            $data['sql']['page'] = $plan->pageSql($start, $limit);
            $data['sql']['search'] = $plan->sql($start, $limit);
            unset($data['sql']['plan']);
            if ($onlycount) {
                $data['data']['totalcount'] = $plannedCount;
                return;
            }
        }
        $DBread->execution_time = true;
        $result = $DBread->query($data['sql']['search']);
        /// Check group concat limit : if warning : increase limit
        if ($DBread->getProvider() === 'mysql' && ($result2 = $DBread->query('SHOW WARNINGS'))) {
            if ($DBread->numrows($result2) > 0) {
                $res = $DBread->fetchAssoc($result2);
                if ($res['Code'] == 1260) {
                    $DBread->query("SET SESSION group_concat_max_len = 8194304;");
                    $DBread->execution_time = true;
                    $result = $DBread->query($data['sql']['search']);
                }
                if ($res['Code'] == 1116) {
                    // too many tables
                    echo LegacyOutput::showError($data['search']['display_type'], __("'All' criterion is not usable with this object list, " . "sql query fails (too many tables). " . "Please use 'Items seen' criterion instead"));
                    return false;
                }
            }
        }
        if ($result) {
            $data['data']['execution_time'] = $DBread->execution_time;
            if (isset($data['search']['savedsearches_id'])) {
                \SavedSearch::updateExecutionTime((int) $data['search']['savedsearches_id'], $DBread->execution_time);
            }
            $data['data']['totalcount'] = 0;
            // if real search or complete export : get numrows from request
            if ($plannedCount !== null) {
                $data['data']['totalcount'] = $plannedCount;
            } elseif (!$data['search']['no_search'] || $data['search']['export_all']) {
                $data['data']['totalcount'] = $DBread->numrows($result);
            } else {
                if (!isset($data['sql']['count']) || count($data['sql']['count']) == 0) {
                    $data['data']['totalcount'] = $DBread->numrows($result);
                } else {
                    foreach ($data['sql']['count'] as $sqlcount) {
                        $result_num = $DBread->query($sqlcount);
                        $data['data']['totalcount'] += $DBread->result($result_num, 0, 0);
                    }
                }
            }
            if ($onlycount) {
                //we just want to coutn results; no need to continue process
                return;
            }
            // Clamp pagination when the requested offset is outside the result set (useful for AllAssets search)
            $totalcount = (int) $data['data']['totalcount'];
            $listlimit = (int) $data['search']['list_limit'];
            if ($totalcount <= 0) {
                $data['search']['start'] = 0;
            } elseif ($data['search']['start'] >= $totalcount) {
                if ($listlimit > 0) {
                    $lastpage = (int) floor(($totalcount - 1) / $listlimit);
                    $data['search']['start'] = $lastpage * $listlimit;
                } else {
                    $data['search']['start'] = 0;
                }
            }
            // Search case
            $data['data']['begin'] = $data['search']['start'];
            $data['data']['end'] = min($data['data']['totalcount'], $data['search']['start'] + $data['search']['list_limit']);
            //map case
            if (isset($data['search']['as_map']) && $data['search']['as_map'] == 1) {
                $data['data']['end'] = $data['data']['totalcount'] - 1;
            }
            // No search Case
            if ($data['search']['no_search']) {
                $data['data']['begin'] = 0;
                $data['data']['end'] = min($data['data']['totalcount'] - $data['search']['start'], $data['search']['list_limit']);
            }
            // Export All case
            if ($data['search']['export_all']) {
                $data['data']['begin'] = 0;
                $data['data']['end'] = $data['data']['totalcount'];
            }
            if ((int)$data['search']['list_limit'] === 0) {
                $data['data']['begin'] = 0;
                $data['data']['end'] = $data['data']['totalcount'];
            }
            // Get columns
            $data['data']['cols'] = [];
            $searchopt = & SearchOption::getOptions($data['itemtype']);
            foreach ($data['toview'] as $opt_id) {
                $data['data']['cols'][] = ['itemtype' => $data['itemtype'], 'id' => $opt_id, 'name' => $searchopt[$opt_id]["name"], 'meta' => 0, 'searchopt' => $searchopt[$opt_id]];
            }
            // manage toview column for criteria with meta flag
            foreach ($data['meta_toview'] as $m_itemtype => $toview) {
                $searchopt = & SearchOption::getOptions($m_itemtype);
                foreach ($toview as $opt_id) {
                    $data['data']['cols'][] = ['itemtype' => $m_itemtype, 'id' => $opt_id, 'name' => $searchopt[$opt_id]["name"], 'meta' => 1, 'searchopt' => $searchopt[$opt_id]];
                }
            }
            // Display columns Headers for meta items
            $already_printed = [];
            if (count($data['search']['metacriteria'])) {
                foreach ($data['search']['metacriteria'] as $metacriteria) {
                    if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype']) && isset($metacriteria['value']) && strlen($metacriteria['value']) > 0) {
                        if (!isset($already_printed[$metacriteria['itemtype'] . $metacriteria['field']])) {
                            $searchopt = & SearchOption::getOptions($metacriteria['itemtype']);
                            $data['data']['cols'][] = ['itemtype' => $metacriteria['itemtype'], 'id' => $metacriteria['field'], 'name' => $searchopt[$metacriteria['field']]["name"], 'meta' => 1, 'searchopt' => $searchopt[$metacriteria['field']]];
                            $already_printed[$metacriteria['itemtype'] . $metacriteria['field']] = 1;
                        }
                    }
                }
            }
            // search group (corresponding of dropdown optgroup) of current col
            foreach ($data['data']['cols'] as $num => $col) {
                // search current col in searchoptions ()
                while (key($searchopt) !== null && key($searchopt) != $col['id']) {
                    next($searchopt);
                }
                if (key($searchopt) !== null) {
                    //search optgroup (non array option)
                    while (key($searchopt) !== null && is_numeric(key($searchopt)) && is_array(current($searchopt))) {
                        prev($searchopt);
                    }
                    if (key($searchopt) !== null && key($searchopt) !== "common") {
                        $data['data']['cols'][$num]['groupname'] = current($searchopt);
                    }
                }
                //reset
                reset($searchopt);
            }
            // Get rows
            // if real search seek to begin of items to display (because of complete search)
            if (!$data['search']['no_search'] && empty($data['sql']['two_phase'])) {
                $DBread->dataSeek($result, $data['search']['start']);
            }
            $i = $data['data']['begin'];
            $data['data']['warning'] = "For compatibility keep raw data  (ITEM_X, META_X) at the top for the moment. Will be drop in next version";
            $data['data']['rows'] = [];
            $data['data']['items'] = [];
            \Search::$output_type = $data['display_type'];
            // This snapshot lives only for this result's formatting pass and is
            // first read by a core ticket-status cell, after its plugin hook.
            $ticketStatusCatalogue = null;
            $ticketStatuses = static function () use (&$ticketStatusCatalogue): array {
                return $ticketStatusCatalogue ??= \Ticket::getAllStatusArray(true, true);
            };
            while ($i < $data['data']['end']) {
                $row = $DBread->fetchAssoc($result);
                $newrow = [];
                $newrow['raw'] = $row;
                // Parse datas
                foreach ($newrow['raw'] as $key => $val) {
                    if (preg_match('/ITEM(_(\\w[^\\d]+))?_(\\d+)(_(.+))?/', (string) $key, $matches)) {
                        $j = $matches[3];
                        if (isset($matches[2]) && !empty($matches[2])) {
                            $j = $matches[2] . '_' . $matches[3];
                        }
                        $fieldname = 'name';
                        if (isset($matches[5])) {
                            $fieldname = $matches[5];
                        }
                        // No Group_concat case
                        if ($fieldname == 'content' || strpos($val ?? '', \Search::LONGSEP) === false) {
                            $newrow[$j]['count'] = 1;
                            $handled = false;
                            if ($fieldname != 'content' && strpos($val ?? '', \Search::SHORTSEP) !== false) {
                                $split2 = LegacyOutput::explodeWithID(\Search::SHORTSEP, $val);
                                if (is_numeric($split2[1])) {
                                    $newrow[$j][0][$fieldname] = $split2[0];
                                    $newrow[$j][0]['id'] = $split2[1];
                                    $handled = true;
                                }
                            }
                            if (!$handled) {
                                if ($val === \Search::NULLVALUE) {
                                    $newrow[$j][0][$fieldname] = null;
                                } else {
                                    $newrow[$j][0][$fieldname] = $val;
                                }
                            }
                        } else {
                            if (!isset($newrow[$j])) {
                                $newrow[$j] = [];
                            }
                            $split = explode(\Search::LONGSEP, $val);
                            $newrow[$j]['count'] = count($split);
                            foreach ($split as $key2 => $val2) {
                                $handled = false;
                                if (strpos($val2, \Search::SHORTSEP) !== false) {
                                    $split2 = LegacyOutput::explodeWithID(\Search::SHORTSEP, $val2);
                                    if (is_numeric($split2[1])) {
                                        $newrow[$j][$key2]['id'] = $split2[1];
                                        if ($split2[0] == \Search::NULLVALUE) {
                                            $newrow[$j][$key2][$fieldname] = null;
                                        } else {
                                            $newrow[$j][$key2][$fieldname] = $split2[0];
                                        }
                                        $handled = true;
                                    }
                                }
                                if (!$handled) {
                                    $newrow[$j][$key2][$fieldname] = $val2;
                                }
                            }
                        }
                    } else {
                        if ($key == 'currentuser') {
                            if (!isset($data['data']['currentuser'])) {
                                $data['data']['currentuser'] = $val;
                            }
                        } else {
                            $newrow[$key] = $val;
                            // Add id to items list
                            if ($key == 'id') {
                                $data['data']['items'][$val] = $i;
                            }
                        }
                    }
                }
                foreach ($data['data']['cols'] as $val) {
                    $newrow[$val['itemtype'] . '_' . $val['id']]['displayname'] = LegacyOutput::giveItem($val['itemtype'], $val['id'], $newrow, ticketStatuses: $ticketStatuses);
                }
                $data['data']['rows'][$i] = $newrow;
                $i++;
            }
            $data['data']['count'] = count($data['data']['rows']);
        } else {
            echo $DBread->error();
        }
    }
}
