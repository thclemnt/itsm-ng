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


use itsmng\Search\Input\QueryBuilder;
use itsmng\Search\Output\LegacyOutput;
use itsmng\Search\Provider\CriteriaBuilder;
use itsmng\Search\Provider\JoinBuilder;
use itsmng\Search\Provider\ProjectionBuilder;
use itsmng\Search\Provider\SQLProvider;
use itsmng\Search\SearchEngine;
use itsmng\Search\SearchOption;

/** Compatibility facade. Implementation lives in itsmng\Search. */
/**
 * Search Class
 *
 * Generic class for Search Engine
**/
class Search
{
    // Default number of items displayed in global search
    public const GLOBAL_DISPLAY_COUNT = 10;
    // EXPORT TYPE
    public const GLOBAL_SEARCH = -1;
    public const HTML_OUTPUT = 0;
    public const SYLK_OUTPUT = 1;
    public const PDF_OUTPUT_LANDSCAPE = 2;
    public const CSV_OUTPUT = 3;
    public const PDF_OUTPUT_PORTRAIT = 4;
    public const LBBR = '#LBBR#';
    public const LBHR = '#LBHR#';
    public const SHORTSEP = '$#$';
    public const LONGSEP = '$$##$$';
    public const NULLVALUE = '__NULL__';
    public static $output_type = self::HTML_OUTPUT;
    public static $search = [];
    /**
     * Display search engine for an type
     *
     * @param string  $itemtype Item type to manage
     *
     * @return void
     **/
    public static function show($itemtype)
    {
        return LegacyOutput::show($itemtype);
    }
    /**
     * Display result table for search engine for an type
     *
     * @param string $itemtype Item type to manage
     * @param array  $params   Search params passed to prepareDatasForSearch function
     *
     * @return void
     **/
    public static function showList($itemtype, $params)
    {
        return LegacyOutput::showList($itemtype, $params);
    }
    /**
     * Display result table for search engine for an type as a map
     *
     * @param string $itemtype Item type to manage
     * @param array  $params   Search params passed to prepareDatasForSearch function
     *
     * @return void
     **/
    public static function showMap($itemtype, $params)
    {
        return LegacyOutput::showMap($itemtype, $params);
    }
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
        return SearchEngine::getDatas($itemtype, $params, $forcedisplay);
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
        return SearchEngine::prepareDatasForSearch($itemtype, $params, $forcedisplay);
    }
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
        return SQLProvider::constructSQL($data);
    }
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
        return CriteriaBuilder::constructCriteriaSQL($criteria, $data, $searchopt, $is_having);
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
        return CriteriaBuilder::constructAdditionalSqlForMetacriteria($criteria, $SELECT, $FROM, $already_link_tables, $data);
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
        return SQLProvider::constructData($data, $onlycount);
    }
    /**
     * Display datas extracted from DB
     *
     * @param array $data Array of search datas prepared to get datas
     *
     * @return void
     **/
    public static function displayData(array $data)
    {
        return LegacyOutput::displayData($data);
    }
    /**
     * @since 0.90
     *
     * @param boolean $is_deleted
     * @param string  $itemtype
     *
     * @return string
     */
    public static function isDeletedSwitch($is_deleted, $itemtype = "")
    {
        return LegacyOutput::isDeletedSwitch($is_deleted, $itemtype);
    }
    /**
     * Compute title (use case of PDF OUTPUT)
     *
     * @param array $data Array data of search
     *
     * @return string Title
     **/
    public static function computeTitle($data)
    {
        return LegacyOutput::computeTitle($data);
    }
    /**
     * Get meta types available for search engine
     *
     * @param string $itemtype Type to display the form
     *
     * @return array Array of available itemtype
     **/
    public static function getMetaItemtypeAvailable($itemtype)
    {
        return SearchOption::getMetaItemtypeAvailable($itemtype);
    }
    /**
     * @since 0.85
     *
     * @param $itemtype
     **/
    public static function getMetaReferenceItemtype($itemtype)
    {
        return SearchOption::getMetaReferenceItemtype($itemtype);
    }
    /**
     * @since 0.85
     **/
    public static function getLogicalOperators($only_not = false)
    {
        return QueryBuilder::getLogicalOperators($only_not);
    }
    /**
     * Print generic search form
     *
     * Params need to parsed before using Search::manageParams function
     *
     * @param string $itemtype  Type to display the form
     * @param array  $params    Array of parameters may include sort, is_deleted, criteria, metacriteria
     *
     * @return void
     **/
    public static function showGenericSearch($itemtype, array $params)
    {
        return QueryBuilder::showGenericSearch($itemtype, $params);
    }
    /**
     * Display a criteria field set, this function should be called by ajax/search.php
     *
     * @since 9.4
     *
     * @param  array  $request we should have these keys of parameters:
     *                            - itemtype: main itemtype for criteria, sub one for metacriteria
     *                            - num: index of the criteria
     *                            - p: params of showGenericSearch method
     *
     * @return void
     */
    public static function displayCriteria($request = [])
    {
        return QueryBuilder::displayCriteria($request);
    }
    /**
     * Display a meta-criteria field set, this function should be called by ajax/search.php
     * Call displayCriteria method after displaying its itemtype field
     *
     * @since 9.4
     *
     * @param  array  $request @see displayCriteria method
     *
     * @return void
     */
    public static function displayMetaCriteria($request = [])
    {
        return QueryBuilder::displayMetaCriteria($request);
    }
    /**
     * Display a group of nested criteria.
     * A group (parent) criteria  can contains children criteria (who also cantains children, etc)
     *
     * @since 9.4
     *
     * @param  array  $request @see displayCriteria method
     *
     * @return void
     */
    public static function displayCriteriaGroup($request = [])
    {
        return QueryBuilder::displayCriteriaGroup($request);
    }
    /**
     * Retrieve a single criteria in Session by its index
     *
     * @since 9.4
     *
     * @param  string  $itemtype    which glpi type we must search in session
     * @param  integer $num         index of the criteria
     * @param  array   $parents_num node indexes of the parents (@see displayCriteriaGroup)
     *
     * @return mixed   the found criteria array of false of nothing found
     */
    public static function findCriteriaInSession($itemtype = '', $num = 0, $parents_num = [])
    {
        return QueryBuilder::findCriteriaInSession($itemtype, $num, $parents_num);
    }
    /**
     * construct the default criteria for an itemtype
     *
     * @since 9.4
     *
     * @param  string $itemtype
     *
     * @return array  criteria
     */
    public static function getDefaultCriteria($itemtype = '')
    {
        return QueryBuilder::getDefaultCriteria($itemtype);
    }
    /**
     * Display first part of criteria (field + searchtype, just after link)
     * will call displaySearchoptionValue for the next part (value)
     *
     * @since 9.4
     *
     * @param  array  $request we should have these keys of parameters:
     *                            - itemtype: main itemtype for criteria, sub one for metacriteria
     *                            - num: index of the criteria
     *                            - field: field key of the criteria
     *                            - p: params of showGenericSearch method
     *
     * @return void
     */
    public static function displaySearchoption($request = [])
    {
        return QueryBuilder::displaySearchoption($request);
    }
    /**
     * Display last part of criteria (value, just after searchtype)
     * called by displaySearchoptionValue
     *
     * @since 9.4
     *
     * @param  array  $request we should have these keys of parameters:
     *                            - searchtype: (contains, equals) passed by displaySearchoption
     *
     * @return void
     */
    public static function displaySearchoptionValue($request = [])
    {
        return QueryBuilder::displaySearchoptionValue($request);
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
        return CriteriaBuilder::addHaving($LINK, $NOT, $itemtype, $ID, $searchtype, $val);
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
        return CriteriaBuilder::addOrderBy($itemtype, $ID, $order);
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
        return SearchOption::addDefaultToView($itemtype, $params);
    }
    /**
     * Generic Function to add default select to a request
     *
     * @param string $itemtype device type
     *
     * @return string Select string
     **/
    public static function addDefaultSelect($itemtype)
    {
        return ProjectionBuilder::addDefaultSelect($itemtype);
    }
    /**
     * Generic Function to add select to a request
     *
     * @since 9.4: $num param has been dropped
     *
     * @param string  $itemtype     item type
     * @param integer $ID           ID of the item to add
     * @param boolean $meta         boolean is a meta
     * @param integer $meta_type    meta type table ID (default 0)
     *
     * @return string Select string
     **/
    public static function addSelect($itemtype, $ID, $meta = 0, $meta_type = 0)
    {
        return ProjectionBuilder::addSelect($itemtype, $ID, $meta, $meta_type);
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
        return CriteriaBuilder::addDefaultWhere($itemtype);
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
        return CriteriaBuilder::addWhere($link, $nott, $itemtype, $ID, $searchtype, $val, $meta);
    }
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
        return JoinBuilder::addDefaultJoin($itemtype, $ref_table, $already_link_tables);
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
        return JoinBuilder::addLeftJoin($itemtype, $ref_table, $already_link_tables, $new_table, $linkfield, $meta, $meta_type, $joinparams, $field);
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
        return JoinBuilder::addMetaLeftJoin($from_type, $to_type, $already_link_tables2, $joinparams);
    }
    /**
     * Generic Function to display Items
     *
     * @since 9.4: $num param has been dropped
     *
     * @param string  $itemtype item type
     * @param integer $ID       ID of the SEARCH_OPTION item
     * @param array   $data     array retrieved data array
     *
     * @return string String to print
     **/
    public static function displayConfigItem($itemtype, $ID, $data = [])
    {
        return LegacyOutput::displayConfigItem($itemtype, $ID, $data);
    }
    /**
     * Generic Function to display Items
     *
     * @since 9.4: $num param has been dropped
     *
     * @param string  $itemtype        item type
     * @param integer $ID              ID of the SEARCH_OPTION item
     * @param array   $data            array containing data results
     * @param boolean $meta            is a meta item ? (default 0)
     * @param array   $addobjectparams array added parameters for union search
     * @param string  $orig_itemtype   Original itemtype, used for union_search_type
     *
     * @return string String to print
     **/
    public static function giveItem($itemtype, $ID, array $data, $meta = 0, array $addobjectparams = [], $orig_itemtype = null)
    {
        return LegacyOutput::giveItem($itemtype, $ID, $data, $meta, $addobjectparams, $orig_itemtype);
    }
    /**
     * Reset save searches
     *
     * @return void
     **/
    public static function resetSaveSearch()
    {
        return QueryBuilder::resetSaveSearch();
    }
    /**
     * Completion of the URL $_GET values with the $_SESSION values or define default values
     *
     * @param string  $itemtype        Item type to manage
     * @param array   $params          Params to parse
     * @param boolean $usesession      Use datas save in session (true by default)
     * @param boolean $forcebookmark   Force trying to load parameters from default bookmark:
     *                                  used for global search (false by default)
     *
     * @return array parsed params
     **/
    public static function manageParams($itemtype, $params = [], $usesession = true, $forcebookmark = false)
    {
        return QueryBuilder::manageParams($itemtype, $params, $usesession, $forcebookmark);
    }
    public static function cleanParams(array $params): array
    {
        return QueryBuilder::cleanParams($params);
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
    public static function getCleanedOptions($itemtype, $action = \READ, $withplugins = true)
    {
        return SearchOption::getCleanedOptions($itemtype, $action, $withplugins);
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
        return SearchOption::getOptionNumber($itemtype, $field);
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
        return SearchOption::getOptions($itemtype, $withplugins);
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
        return SearchOption::isInfocomOption($itemtype, $searchID);
    }
    /**
     * @param string  $itemtype
     * @param integer $field_num
     **/
    public static function getActionsFor($itemtype, $field_num)
    {
        return SearchOption::getActionsFor($itemtype, $field_num);
    }
    /**
     * Print generic Header Column
     *
     * @param integer          $type     Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     * @param string           $value    Value to display
     * @param integer          &$num     Column number
     * @param string           $linkto   Link display element (HTML specific) (default '')
     * @param boolean|integer  $issort   Is the sort column ? (default 0)
     * @param string           $order    Order type ASC or DESC (defaut '')
     * @param string           $options  Options to add (default '')
     *
     * @return string HTML to display
     **/
    public static function showHeaderItem($type, $value, &$num, $linkto = "", $issort = 0, $order = "", $options = "")
    {
        return LegacyOutput::showHeaderItem($type, $value, $num, $linkto, $issort, $order, $options);
    }
    /**
     * Print generic normal Item Cell
     *
     * @param integer $type        Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     * @param string  $value       Value to display
     * @param integer &$num        Column number
     * @param integer $row         Row number
     * @param string  $extraparam  Extra parameters for display (default '')
     *
     * @return string HTML to display
     **/
    public static function showItem($type, $value, &$num, $row, $extraparam = '')
    {
        return LegacyOutput::showItem($type, $value, $num, $row, $extraparam);
    }
    /**
     * Print generic error
     *
     * @param integer $type     Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     * @param string  $message  Message to display, if empty "no item found" will be displayed
     *
     * @return string HTML to display
     **/
    public static function showError($type, $message = "")
    {
        return LegacyOutput::showError($type, $message);
    }
    /**
     * Print generic footer
     *
     * @param integer $type  Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     * @param string  $title title of file : used for PDF (default '')
     * @param integer $count Total number of results
     *
     * @return string HTML to display
     **/
    public static function showFooter($type, $title = "", $count = null)
    {
        return LegacyOutput::showFooter($type, $title, $count);
    }
    /**
     * Print generic footer
     *
     * @param integer         $type   Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     * @param integer         $rows   Number of rows
     * @param integer         $cols   Number of columns
     * @param boolean|integer $fixed  Used tab_cadre_fixe table for HTML export ? (default 0)
     *
     * @return string HTML to display
     **/
    public static function showHeader($type, $rows, $cols, $fixed = 0)
    {
        return LegacyOutput::showHeader($type, $rows, $cols, $fixed);
    }
    /**
     * Print begin of header part
     *
     * @param integer $type   Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     *
     * @since 0.85
     *
     * @return string HTML to display
     **/
    public static function showBeginHeader($type)
    {
        return LegacyOutput::showBeginHeader($type);
    }
    /**
     * Print end of header part
     *
     * @param integer $type   Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     *
     * @since 0.85
     *
     * @return string to display
     **/
    public static function showEndHeader($type)
    {
        return LegacyOutput::showEndHeader($type);
    }
    /**
     * Print generic new line
     *
     * @param integer $type        Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     * @param boolean $odd         Is it a new odd line ? (false by default)
     * @param boolean $is_deleted  Is it a deleted search ? (false by default)
     *
     * @return string HTML to display
     **/
    public static function showNewLine($type, $odd = false, $is_deleted = false)
    {
        return LegacyOutput::showNewLine($type, $odd, $is_deleted);
    }
    /**
     * Print generic end line
     *
     * @param integer $type  Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
     *
     * @return string HTML to display
     **/
    public static function showEndLine($type)
    {
        return LegacyOutput::showEndLine($type);
    }
    /**
     * @param array $joinparams
     */
    public static function computeComplexJoinID(array $joinparams)
    {
        return JoinBuilder::computeComplexJoinID($joinparams);
    }
    /**
     * Clean display value for csv export
     *
     * @param string $value value
     *
     * @return string Clean value
     **/
    public static function csv_clean($value)
    {
        return LegacyOutput::csv_clean($value);
    }
    /**
     * Clean display value for sylk export
     *
     * @param string $value value
     *
     * @return string Clean value
     **/
    public static function sylk_clean($value)
    {
        return LegacyOutput::sylk_clean($value);
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
        return CriteriaBuilder::makeTextCriteria($field, $val, $not, $link);
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
        return CriteriaBuilder::makeTextSearchValue($val);
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
        return CriteriaBuilder::makeTextSearch($val, $not);
    }
    /**
     * @since 0.84
     *
     * @param string $pattern
     * @param string $subject
     **/
    public static function explodeWithID($pattern, $subject)
    {
        return LegacyOutput::explodeWithID($pattern, $subject);
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
        return JoinBuilder::joinDropdownTranslations($alias, $table, $itemtype, $field);
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
        return JoinBuilder::getOrigTableName($itemtype);
    }
}
