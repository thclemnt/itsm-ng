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

use DisplayPreference;
use Html;
use Search;
use Session;
use Toolbox;
use itsmng\Search\Input\QueryBuilder;
use itsmng\Search\Provider\SQLProvider;

use function getItemForItemtype;

final class SearchEngine
{
    /**
     * Get data based on search parameters
     *
     * @since 0.85
     *
     * @param string $itemtype      Item type to manage
     * @param array  $params        Search params passed to prepareDatasForSearch function
     * @param array  $forcedisplay  Array of columns to display (default empty = empty use display pref and search criteria)
     *
     * @return array The data
     **/
    public static function getDatas($itemtype, $params, array $forcedisplay = [])
    {
        $data = SearchEngine::prepareDatasForSearch($itemtype, $params, $forcedisplay);
        SQLProvider::constructSQL($data);
        SQLProvider::constructData($data);
        return $data;
    }
    /**
     * Prepare search criteria to be used for a search
     *
     * @since 0.85
     *
     * @param string $itemtype      Item type
     * @param array  $params        Array of parameters
     *                               may include sort, order, start, list_limit, deleted, criteria, metacriteria
     * @param array  $forcedisplay  Array of columns to display (default empty = empty use display pref and search criterias)
     *
     * @return array prepare to be used for a search (include criteria and others needed information)
     **/
    public static function prepareDatasForSearch($itemtype, array $params, array $forcedisplay = [])
    {
        global $CFG_GLPI;
        // Default values of parameters
        $p['criteria'] = [];
        $p['metacriteria'] = [];
        $p['sort'] = '1';
        //
        $p['order'] = 'ASC';
        //
        $p['start'] = 0;
        //
        $p['is_deleted'] = 0;
        $p['export_all'] = 0;
        if (class_exists($itemtype)) {
            $p['target'] = $itemtype::getSearchURL();
        } else {
            $p['target'] = Toolbox::getItemTypeSearchURL($itemtype);
        }
        $p['display_type'] = Search::HTML_OUTPUT;
        $p['showmassiveactions'] = true;
        $p['dont_flush'] = false;
        $p['show_pager'] = true;
        $p['show_footer'] = true;
        $p['no_sort'] = false;
        $p['list_limit'] = $_SESSION['glpilist_limit'];
        $p['massiveactionparams'] = [];
        foreach ($params as $key => $val) {
            switch ($key) {
                case 'order':
                    if (in_array($val, ['ASC', 'DESC'])) {
                        $p[$key] = $val;
                    }
                    break;
                case 'sort':
                    $p[$key] = intval($val);
                    if ($p[$key] < 0) {
                        $p[$key] = 1;
                    }
                    break;
                case 'is_deleted':
                    if ($val == 1) {
                        $p[$key] = '1';
                    }
                    break;
                default:
                    $p[$key] = $val;
                    break;
            }
        }
        // Set display type for export if define
        if (isset($p['display_type'])) {
            // Limit to 10 element
            if ($p['display_type'] == Search::GLOBAL_SEARCH) {
                $p['list_limit'] = Search::GLOBAL_DISPLAY_COUNT;
            }
        }
        if ($p['export_all']) {
            $p['start'] = 0;
        }
        foreach ($p['metacriteria'] as $criterion) {
            $p['criteria'][] = $criterion + ['meta' => true];
        }
        $p['metacriteria'] = [];
        $p = QueryBuilder::cleanParams($p);
        $data = [];
        $data['search'] = $p;
        $data['itemtype'] = $itemtype;
        // Instanciate an object to access method
        $data['item'] = null;
        if ($itemtype != 'AllAssets') {
            $data['item'] = getItemForItemtype($itemtype);
        }
        $data['display_type'] = $data['search']['display_type'];
        if (!$CFG_GLPI['allow_search_all']) {
            foreach ($p['criteria'] as $val) {
                if (isset($val['field']) && $val['field'] == 'all') {
                    Html::displayRightError();
                }
            }
        }
        if (!$CFG_GLPI['allow_search_view']) {
            foreach ($p['criteria'] as $val) {
                if (isset($val['field']) && $val['field'] == 'view') {
                    Html::displayRightError();
                }
            }
        }
        /// Get the items to display
        // Add searched items
        $forcetoview = false;
        if (is_array($forcedisplay) && count($forcedisplay)) {
            $forcetoview = true;
        }
        $data['search']['all_search'] = false;
        $data['search']['view_search'] = false;
        // If no research limit research to display item and compute number of item using simple request
        $data['search']['no_search'] = true;
        $data['toview'] = SearchOption::addDefaultToView($itemtype, $params);
        $data['meta_toview'] = [];
        if (!$forcetoview) {
            // Add items to display depending of personal prefs
            $displaypref = DisplayPreference::getForTypeUser($itemtype, Session::getLoginUserID());
            if (count($displaypref)) {
                foreach ($displaypref as $val) {
                    array_push($data['toview'], $val);
                }
            }
        } else {
            $data['toview'] = array_merge($data['toview'], $forcedisplay);
        }
        if (count($p['criteria']) > 0) {
            // use a recursive closure to push searchoption when using nested criteria
            $parse_criteria = function ($criteria) use (&$parse_criteria, &$data) {
                foreach ($criteria as $criterion) {
                    // recursive call
                    if (isset($criterion['criteria'])) {
                        $parse_criteria($criterion['criteria']);
                    } else {
                        // normal behavior
                        if (isset($criterion['field']) && !in_array($criterion['field'], $data['toview'])) {
                            if ($criterion['field'] != 'all' && $criterion['field'] != 'view' && (!isset($criterion['meta']) || !$criterion['meta'])) {
                                array_push($data['toview'], $criterion['field']);
                            } elseif (strlen((string)($criterion['value'] ?? '')) > 0 && $criterion['field'] == 'all') {
                                $data['search']['all_search'] = true;
                            } elseif (strlen((string)($criterion['value'] ?? '')) > 0 && $criterion['field'] == 'view') {
                                $data['search']['view_search'] = true;
                            }
                        }
                        if (isset($criterion['value']) && strlen((string) $criterion['value']) > 0) {
                            $data['search']['no_search'] = false;
                        }
                    }
                }
            };
            // call the closure
            $parse_criteria($p['criteria']);
        }
        if (count($p['metacriteria'])) {
            $data['search']['no_search'] = false;
        }
        // Add order item
        if (!in_array($p['sort'], $data['toview'])) {
            array_push($data['toview'], $p['sort']);
        }
        // Special case for Ticket : put ID in front
        if ($itemtype == 'Ticket') {
            array_unshift($data['toview'], 2);
        }
        $limitsearchopt = SearchOption::getCleanedOptions($itemtype);
        // Clean and reorder toview
        $tmpview = [];
        foreach ($data['toview'] as $val) {
            if (isset($limitsearchopt[$val]) && !in_array($val, $tmpview)) {
                $tmpview[] = $val;
            }
        }
        $data['toview'] = $tmpview;
        $data['tocompute'] = $data['toview'];
        // Force item to display
        if ($forcetoview) {
            foreach ($data['toview'] as $val) {
                if (!in_array($val, $data['tocompute'])) {
                    array_push($data['tocompute'], $val);
                }
            }
        }
        return $data;
    }
}
