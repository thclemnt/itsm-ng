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

namespace itsmng\Search\Input;

use Ajax;
use Dropdown;
use Glpi\Toolbox\URL;
use Html;
use Plugin;
use RuntimeException;
use SavedSearch;
use SavedSearch_User;
use Session;
use Toolbox;
use itsmng\Search\SearchOption;

use function getItemForItemtype;
use function getItemTypeForTable;
use function isPluginItemType;
use function renderTwigTemplate;

final class QueryBuilder
{
    /**
     * @since 0.85
     **/
    public static function getLogicalOperators($only_not = false)
    {
        if ($only_not) {
            return ['AND' => Dropdown::EMPTY_VALUE, 'AND NOT' => __("NOT")];
        }
        return ['AND' => __('AND'), 'OR' => __('OR'), 'AND NOT' => __('AND NOT'), 'OR NOT' => __('OR NOT')];
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
        global $CFG_GLPI;
        // Default values of parameters
        $p['sort'] = '';
        $p['is_deleted'] = 0;
        $p['as_map'] = 0;
        $p['criteria'] = [];
        $p['metacriteria'] = [];
        $p['hide'] = false;
        if (class_exists($itemtype)) {
            $p['target'] = $itemtype::getSearchURL();
        } else {
            $p['target'] = Toolbox::getItemTypeSearchURL($itemtype);
        }
        $p['showreset'] = true;
        $p['showbookmark'] = true;
        $p['showfolding'] = true;
        $p['mainform'] = true;
        $p['prefix_crit'] = '';
        $p['addhidden'] = [];
        $p['actionname'] = 'search';
        $p['actionvalue'] = _sx('button', 'Search');
        foreach ($params as $key => $val) {
            $p[$key] = $val;
        }
        $p['target'] = URL::sanitizeURL($p['target']);
        $main_block_class = '';
        $main_block_width_class = 'w-100 w-md-50 mx-auto';
        if ($p['mainform']) {
            echo "<form aria-label='Search Form {$itemtype}' name='searchform{$itemtype}' method='get' action='" . $p['target'] . "'>";
        } else {
            $main_block_class = "sub_criteria";
            $main_block_width_class = 'w-100';
        }
        echo "<div id='searchcriteria' class='{$main_block_class} {$main_block_width_class}'" . ($p['hide'] ? "style='display: none;'" : "") . ">";
        $nbsearchcountvar = 'nbcriteria' . strtolower($itemtype) . mt_rand();
        $searchcriteriatableid = 'criteriatable' . strtolower($itemtype) . mt_rand();
        // init criteria count
        echo Html::scriptBlock("
         var {$nbsearchcountvar} = " . count($p['criteria']) . ";
      ");
        echo "<ul id='{$searchcriteriatableid}'>";
        // Display normal search parameters
        $i = 0;
        foreach (array_keys($p['criteria']) as $i) {
            QueryBuilder::displayCriteria(['itemtype' => $itemtype, 'num' => $i, 'p' => $p]);
        }
        $rand_criteria = mt_rand();
        echo "<li id='more-criteria{$rand_criteria}'
            class='normalcriteria headerRow'
            style='display: none;'>...</li>";
        echo "</ul>";
        echo "<div class='search_actions'>";
        $linked = SearchOption::getMetaItemtypeAvailable($itemtype);
        echo "<span id='addsearchcriteria{$rand_criteria}' class='secondary'>
               <i class='fas fa-plus-square'></i>
               " . __s('rule') . "
            </span>";
        if (count($linked)) {
            echo "<span id='addmetasearchcriteria{$rand_criteria}' class='secondary'>
                  <i class='far fa-plus-square' aria-hidden='true'></i>
                  " . __s('global rule') . "
               </span>";
        }
        echo "<span id='addcriteriagroup{$rand_criteria}' class='secondary'>
               <i class='fas fa-plus-circle' aria-hidden='true'></i>
               " . __s('group') . "
            </span>";
        $json_p = json_encode($p);
        if ($p['mainform']) {
            // Display submit button
            echo '<input type="submit" name="' . htmlspecialchars((string) $p['actionname']) . '" value="' . htmlspecialchars((string) $p['actionvalue']) . '" class="submit">';
            if ($p['showbookmark'] || $p['showreset']) {
                if ($p['showbookmark']) {
                    //TODO: change that!
                    Ajax::createIframeModalWindow('loadbookmark', SavedSearch::getSearchURL() . "?action=load&type=" . SavedSearch::SEARCH, ['title' => __('Load a saved search')]);
                    SavedSearch::showSaveButton(SavedSearch::SEARCH, $itemtype);
                }
                if ($p['showreset']) {
                    echo "<a class='fa fa-undo reset-search' href='" . $p['target'] . (strpos($p['target'], '?') ? '&amp;' : '?') . "reset=reset' title=\"" . __s('Blank') . "\"
                  ><span class='sr-only'>" . __s('Blank') . "</span></a>";
                }
                if ($p['showfolding']) {
                    echo "<a class='fa fa-angle-double-up fa-fw fold-search'
                        href='#'
                        title=\"" . __("Fold search") . "\"></a>";
                }
            }
        }
        echo "</div>";
        //.search_actions
        // idor checks
        $idor_display_criteria = Session::getNewIDORToken($itemtype);
        $idor_display_meta_criteria = Session::getNewIDORToken($itemtype);
        $idor_display_criteria_group = Session::getNewIDORToken($itemtype);
        $JS = <<<JAVASCRIPT
         \$('#addsearchcriteria{$rand_criteria}').on('click', function(event) {
            event.preventDefault();
            \$.post('{$CFG_GLPI['root_doc']}/ajax/search.php', {
               'action': 'display_criteria',
               'itemtype': '{$itemtype}',
               'num': {$nbsearchcountvar},
               'p': {$json_p},
               '_idor_token': '{$idor_display_criteria}'
            })
            .done(function(data) {
               \$(data).insertBefore('#more-criteria{$rand_criteria}');
               {$nbsearchcountvar}++;
            });
         });

         \$('#addmetasearchcriteria{$rand_criteria}').on('click', function(event) {
            event.preventDefault();
            \$.post('{$CFG_GLPI['root_doc']}/ajax/search.php', {
               'action': 'display_meta_criteria',
               'itemtype': '{$itemtype}',
               'meta': true,
               'num': {$nbsearchcountvar},
               'p': {$json_p},
               '_idor_token': '{$idor_display_meta_criteria}'
            })
            .done(function(data) {
               \$(data).insertBefore('#more-criteria{$rand_criteria}');
               {$nbsearchcountvar}++;
            });
         });

         \$('#addcriteriagroup{$rand_criteria}').on('click', function(event) {
            event.preventDefault();
            \$.post('{$CFG_GLPI['root_doc']}/ajax/search.php', {
               'action': 'display_criteria_group',
               'itemtype': '{$itemtype}',
               'meta': true,
               'num': {$nbsearchcountvar},
               'p': {$json_p},
               '_idor_token': '{$idor_display_criteria_group}'
            })
            .done(function(data) {
               \$(data).insertBefore('#more-criteria{$rand_criteria}');
               {$nbsearchcountvar}++;
            });
         });
JAVASCRIPT;
        if ($p['mainform']) {
            $JS .= <<<JAVASCRIPT
         \$('.fold-search').on('click', function(event) {
            var search_criteria =  \$('#searchcriteria ul li:not(:first-child)');
            event.preventDefault();
            \$(this)
               .toggleClass('fa-angle-double-up')
               .toggleClass('fa-angle-double-down');
            search_criteria.toggle();
            window.localStorage.setItem(
               'show_full_searchcriteria',
               search_criteria.first().is(':visible')
            );
         });

         // Init search_criteria state
         var search_criteria_visibility = window.localStorage.getItem('show_full_searchcriteria');
         if (search_criteria_visibility !== undefined && search_criteria_visibility == 'false') {
            \$('.fold-search').click();
         }

         \$(document).on("click", ".remove-search-criteria", function() {
            var rowID = \$(this).data('rowid');
            console.log(rowID)
            \$('#' + rowID).remove();
            \$('#searchcriteria ul li:first-child').addClass('headerRow').show();
         });
JAVASCRIPT;
        }
        echo Html::scriptBlock($JS);
        if (count($p['addhidden'])) {
            foreach ($p['addhidden'] as $key => $val) {
                echo Html::hidden($key, ['value' => $val]);
            }
        }
        if ($p['mainform']) {
            // For dropdown
            echo Html::hidden('itemtype', ['value' => $itemtype]);
            // Reset to start when submit new search
            echo Html::hidden('start', ['value' => 0]);
        }
        echo "</div>";
        if ($p['mainform']) {
            Html::closeForm();
        }
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
        global $CFG_GLPI;
        if (!isset($request["itemtype"]) || !isset($request["num"])) {
            return "";
        }
        $num = (int) $request['num'];
        $p = $request['p'];
        $options = SearchOption::getCleanedOptions($request["itemtype"]);
        $randrow = mt_rand();
        $rowid = 'searchrow' . $request['itemtype'] . $randrow;
        $addclass = $num == 0 ? ' headerRow' : '';
        $prefix = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
        $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];
        $criteria = [];
        $from_meta = isset($request['from_meta']) && $request['from_meta'];
        $sess_itemtype = $request["itemtype"];
        if ($from_meta) {
            $sess_itemtype = $request["parent_itemtype"];
        }
        if (!($criteria = QueryBuilder::findCriteriaInSession($sess_itemtype, $num, $parents_num))) {
            $criteria = QueryBuilder::getDefaultCriteria($request["itemtype"]);
        }
        if (isset($criteria['meta']) && $criteria['meta'] && !$from_meta) {
            return QueryBuilder::displayMetaCriteria($request);
        }
        if (isset($criteria['criteria']) && is_array($criteria['criteria'])) {
            return QueryBuilder::displayCriteriaGroup($request);
        }
        $values = [];
        // display select box to define search item
        if ($CFG_GLPI['allow_search_view'] == 2 && !isset($request['from_meta'])) {
            $values['view'] = __('Items seen');
        }
        reset($options);
        $group = '';
        foreach ($options as $key => $val) {
            // print groups
            if (!is_array($val)) {
                $group = $val;
            } elseif (count($val) == 1) {
                $group = $val['name'];
            } else {
                if ((!isset($val['nosearch']) || $val['nosearch'] == false) && (!$from_meta || !array_key_exists('nometa', $val) || $val['nometa'] !== true)) {
                    $values[$group][$key] = $val["name"];
                }
            }
        }
        if ($CFG_GLPI['allow_search_view'] == 1 && !isset($request['from_meta'])) {
            $values['view'] = __('Items seen');
        }
        if ($CFG_GLPI['allow_search_all'] && !isset($request['from_meta'])) {
            $values['all'] = __('All');
        }
        $value = '';
        if (isset($criteria['field'])) {
            $value = $criteria['field'];
        }
        $subValue = $criteria['value'] ?? '';
        $spanid = Html::cleanId('SearchSpan' . $request["itemtype"] . $prefix . $num);
        $json_p = json_encode($p);
        $idor_display_criteria = Session::getNewIDORToken($request["itemtype"]);
        $searchtype = isset($criteria['searchtype']) ? $criteria['searchtype'] : '';
        $field_id = Html::cleanId("dropdown_criteria{$prefix}_{$num}_field_{$randrow}");
        renderTwigTemplate('search/searchCriteria.twig', ['is_deleted' => $p['is_deleted'], 'as_map' => $p['as_map'], 'rowid' => $rowid, 'addclass' => $addclass, 'spanid' => $spanid, 'from_meta' => $from_meta, 'inputs' => [$from_meta ? [] : ['type' => 'select', 'name' => "criteria{$prefix}[{$num}][link]", 'values' => QueryBuilder::getLogicalOperators($num == 0), 'value' => isset($criteria["link"]) ? $criteria["link"] : '', 'noLib' => true], ['type' => 'select', 'id' => $field_id, 'name' => "criteria{$prefix}[{$num}][field]", 'values' => $values, 'value' => $value, 'noLib' => true, 'hooks' => ['change' => <<<JS
           const \$select = \$(this);
           const \$container = \$select.closest('[data-search-container]');
           const containerKey = \$container.data('search-container');
           const dataKey = 'searchOption_' + containerKey;
           const expectedField = \$select.val();

           \$.ajax({
               url: '{$CFG_GLPI['root_doc']}/ajax/search.php',
               type: 'POST',
               data: {
                   action: 'display_searchoption',
                   field: expectedField,
                   itemtype: '{$request['itemtype']}',
                   num: {$num},
                   p: {$json_p},
                   _idor_token: '{$idor_display_criteria}',
                   value: '',
                   searchtype: ''
               },
               success: function(data) {
                   if (\$select.val() !== expectedField) {
                       return;
                   }
                   const \$existing = \$container.data(dataKey);
                   if (\$existing && \$existing.remove) {
                       \$existing.remove();
                       \$container.removeData(dataKey);
                   }
                   const \$content = \$(\$.parseHTML(data, document, true));
                   \$container.data(dataKey, \$content);
                   \$container.append(\$content);
               }
           });
JS
], 'init' => <<<JS
         const \$field = \$('#{$field_id}');
         const \$container = \$('[data-search-container="{$spanid}"]');
         const dataKey = 'searchOption_{$spanid}';
         const expectedField = \$field.val();

         \$.ajax({
             url: '{$CFG_GLPI['root_doc']}/ajax/search.php',
             type: 'POST',
             data: {
                 action: 'display_searchoption',
                 field: expectedField,
                 itemtype: '{$request['itemtype']}',
                 num: {$num},
                 p: {$json_p},
                 _idor_token: '{$idor_display_criteria}',
                 value: '{$subValue}',
                 searchtype: '{$searchtype}'
             },
             success: function(data) {
                 if (\$field.val() !== expectedField) {
                     return;
                 }
                 const \$existing = \$container.data(dataKey);
                 if (\$existing && \$existing.remove) {
                     \$existing.remove();
                     \$container.removeData(dataKey);
                 }
                 const \$content = \$(\$.parseHTML(data, document, true));
                 \$container.data(dataKey, \$content);
                 \$container.append(\$content);
             }
         });
JS
]]]);
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
        global $CFG_GLPI;
        if (!isset($request["itemtype"]) || !isset($request["num"])) {
            return "";
        }
        $p = $request['p'];
        $num = (int) $request['num'];
        $prefix = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
        $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];
        $itemtype = $request["itemtype"];
        $metacriteria = [];
        if (!($metacriteria = QueryBuilder::findCriteriaInSession($itemtype, $num, $parents_num))) {
            // Set default field
            $options = SearchOption::getCleanedOptions($itemtype);
            foreach ($options as $key => $val) {
                if (is_array($val) && isset($val['table'])) {
                    if ($metacriteria) {
                        $metacriteria['field'] = $key;
                    }
                    break;
                }
            }
        }
        $linked = SearchOption::getMetaItemtypeAvailable($itemtype);
        $rand = mt_rand();
        $values = [];
        if (count($linked)) {
            foreach ($linked as $type) {
                if ($item = getItemForItemtype($type)) {
                    $values[$type] = $item->getTypeName(1);
                }
            }
        }
        asort($values);
        $value = isset($metacriteria['itemtype']) ? $metacriteria['itemtype'] : '';
        $randrow = mt_rand();
        $spanid = Html::cleanId("show_" . $request["itemtype"] . "_" . $prefix . $num . "_{$rand}");
        $rowid = 'metasearchrow' . $request['itemtype'] . $rand;
        $json_p = json_encode($request["p"]);
        $used_itemtype = $request["itemtype"];
        // Force Computer itemtype for AllAssets to permit to show specific items
        if ($request["itemtype"] == 'AllAssets') {
            $used_itemtype = 'Computer';
        }
        $idor_display_criteria = Session::getNewIDORToken("", ['parent_itemtype' => $request['itemtype']]);
        // $params = [
        //    'action'          => 'display_criteria',
        //    'itemtype'        => '__VALUE__',
        //    'parent_itemtype' => $request['itemtype'],
        //    'from_meta'       => true,
        //    'num'             => $num,
        //    'p'               => $request["p"],
        //    '_idor_token'     => Session::getNewIDORToken("", [
        //       'parent_itemtype' => $request['itemtype']
        //    ])
        // ];
        $field_id = Html::cleanId("dropdown_criteria{$prefix}_{$num}_field_{$randrow}");
        renderTwigTemplate('search/searchCriteria.twig', ['is_deleted' => $p['is_deleted'], 'as_map' => $p['as_map'], 'rowid' => $rowid, 'spanid' => $spanid, 'noLib' => true, 'meta' => true, 'inputs' => [['type' => 'hidden', 'name' => "criteria{$prefix}[{$num}][meta]", 'value' => true], ['type' => 'select', 'name' => "criteria{$prefix}[{$num}][link]", 'values' => QueryBuilder::getLogicalOperators($num == 0), 'value' => isset($criteria["link"]) ? $criteria["link"] : '', 'noLib' => true], ['type' => 'select', 'id' => $field_id, 'name' => "criteria{$prefix}[{$num}][itemtype]", 'values' => $values, 'value' => $value, 'noLib' => true, 'hooks' => ['change' => <<<JS
   \$.ajax({
      url: '{$CFG_GLPI['root_doc']}/ajax/search.php',
      type: 'POST',
      data: {
         action: 'display_criteria',
         itemtype: \$(this).val(),
         parent_itemtype: '{$used_itemtype}',
         from_meta: true,
         num: {$num},
         p: {$json_p},
         _idor_token: '{$idor_display_criteria}',
      },
      success: function(data) {
         \$('#{$spanid}').html(data);
      }
   });
JS
], 'init' => <<<JS
   \$.ajax({
      url: '{$CFG_GLPI['root_doc']}/ajax/search.php',
      type: 'POST',
      data: {
         action: 'display_criteria',
         itemtype: \$('#{$field_id}').val(),
         parent_itemtype: '{$used_itemtype}',
         from_meta: true,
         num: {$num},
         p: {$json_p},
         _idor_token: '{$idor_display_criteria}',
      },
      success: function(data) {
         \$('#{$spanid}').html(data);
      }
   });
JS
]]]);
        echo "</li>";
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
        $num = (int) $request['num'];
        $p = $request['p'];
        $randrow = mt_rand();
        $rowid = 'searchrow' . $request['itemtype'] . $randrow;
        $addclass = $num == 0 ? ' headerRow' : '';
        $prefix = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
        $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];
        if (!($criteria = QueryBuilder::findCriteriaInSession($request['itemtype'], $num, $parents_num))) {
            $criteria = ['criteria' => QueryBuilder::getDefaultCriteria($request['itemtype'])];
        }
        echo "<li class='normalcriteria{$addclass} mb-3 mx-auto' style='max-width: 1100px;' id='{$rowid}'>";
        echo "<div class='input-container d-flex flex-row align-items-start gap-3'>";
        echo "<div class='input-group flex-nowrap align-items-stretch w-auto'>";
        echo "<i class='far fa-minus-square remove-search-criteria input-group-text' role='button' alt='-' title=\"" . __s('Delete a rule') . "\" data-rowid='{$rowid}'></i>";
        Dropdown::showFromArray("criteria{$prefix}[{$num}][link]", QueryBuilder::getLogicalOperators(), ['value' => isset($criteria["link"]) ? $criteria["link"] : '', 'width' => '80px']);
        echo "</div>";
        $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];
        array_push($parents_num, $num);
        $params = ['mainform' => false, 'prefix_crit' => "{$prefix}[{$num}][criteria]", 'parents_num' => $parents_num, 'criteria' => $criteria['criteria']];
        echo "<div class='flex-grow-1'>";
        echo QueryBuilder::showGenericSearch($request['itemtype'], $params);
        echo "</div>";
        echo "</div>";
        echo "</li>";
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
        if (!isset($_SESSION['glpisearch'][$itemtype]['criteria'])) {
            return false;
        }
        $criteria = & $_SESSION['glpisearch'][$itemtype]['criteria'];
        if (count($parents_num)) {
            foreach ($parents_num as $parent) {
                if (!isset($criteria[$parent]['criteria'])) {
                    return false;
                }
                $criteria = & $criteria[$parent]['criteria'];
            }
        }
        if (isset($criteria[$num]) && is_array($criteria[$num])) {
            return $criteria[$num];
        }
        return false;
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
        global $CFG_GLPI;
        $field = '';
        if ($CFG_GLPI['allow_search_view'] == 2) {
            $field = 'view';
        } else {
            $options = SearchOption::getCleanedOptions($itemtype);
            foreach ($options as $key => $val) {
                if (is_array($val) && isset($val['table'])) {
                    $field = $key;
                    break;
                }
            }
        }
        return [['field' => $field, 'link' => 'contains', 'value' => '']];
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
        global $CFG_GLPI;
        if (!isset($request["itemtype"]) || !isset($request["field"]) || !isset($request["num"])) {
            return "";
        }
        $p = $request['p'];
        $num = (int) $request['num'];
        $prefix = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
        $itemtype = $request['itemtype'];
        if (!is_subclass_of($itemtype, 'CommonDBTM') && !isset($CFG_GLPI['union_search_type'][$itemtype])) {
            throw new RuntimeException('Invalid itemtype provided!');
        }
        if (isset($request['meta']) && $request['meta']) {
            $fieldname = 'metacriteria';
        } else {
            $fieldname = 'criteria';
            $request['meta'] = 0;
        }
        $actions = SearchOption::getActionsFor($request["itemtype"], $request["field"]);
        // is it a valid action for type ?
        if (count($actions) && (empty($request['searchtype']) || !isset($actions[$request['searchtype']]))) {
            $tmp = $actions;
            unset($tmp['searchopt']);
            $request['searchtype'] = key($tmp);
            unset($tmp);
        }
        $rands = -1;
        $dropdownname = Html::cleanId("spansearchtype{$fieldname}" . $request["itemtype"] . $prefix . $num);
        $searchopt = [];
        if (count($actions) > 0) {
            // get already get search options
            if (isset($actions['searchopt'])) {
                $searchopt = $actions['searchopt'];
                // No name for clean array with quotes
                unset($searchopt['name']);
                unset($actions['searchopt']);
            }
            $searchtype_name = "{$fieldname}{$prefix}[{$num}][searchtype]";
            $rands = Dropdown::showFromArray($searchtype_name, $actions, ['value' => $request["searchtype"]]);
            $fieldsearch_id = Html::cleanId("dropdown_{$searchtype_name}{$rands}");
        }
        $params = ['value' => rawurlencode(stripslashes((string) $request['value'])), 'searchopt' => $searchopt, 'searchtype' => $request["searchtype"], 'num' => $num, 'itemtype' => $request["itemtype"], '_idor_token' => Session::getNewIDORToken($request["itemtype"]), 'from_meta' => isset($request['from_meta']) ? $request['from_meta'] : false, 'field' => $request["field"], 'p' => $p];
        Ajax::updateItemOnSelectEvent($fieldsearch_id, $dropdownname, $CFG_GLPI["root_doc"] . "/ajax/search.php", ['action' => 'display_searchoption_value', 'searchtype' => '__VALUE__'] + $params);
        echo "<span id=\"{$dropdownname}\">";
        QueryBuilder::displaySearchoptionValue($params);
        echo "</span>";
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
        if (!isset($request['searchtype'])) {
            return "";
        }
        $p = $request['p'];
        $prefix = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
        $searchopt = isset($request['searchopt']) ? $request['searchopt'] : [];
        $request['value'] = rawurldecode((string) $request['value']);
        $fieldname = isset($request['meta']) && $request['meta'] ? 'metacriteria' : 'criteria';
        $inputname = $fieldname . $prefix . '[' . $request['num'] . '][value]';
        $display = false;
        $item = getItemForItemtype($request['itemtype']);
        $options2 = [];
        $options2['value'] = $request['value'];
        //$options2['width'] = '100%';
        // For tree dropdpowns
        $options2['permit_select_parent'] = true;
        switch ($request['searchtype']) {
            case "equals":
            case "notequals":
            case "morethan":
            case "lessthan":
            case "under":
            case "notunder":
                if (!$display && isset($searchopt['field'])) {
                    // Specific cases
                    switch ($searchopt['table'] . "." . $searchopt['field']) {
                        // Add mygroups choice to searchopt
                        case "glpi_groups.completename":
                            $searchopt['toadd'] = ['mygroups' => __('My groups')];
                            break;
                        case "glpi_changes.status":
                        case "glpi_changes.impact":
                        case "glpi_changes.urgency":
                        case "glpi_problems.status":
                        case "glpi_problems.impact":
                        case "glpi_problems.urgency":
                        case "glpi_tickets.status":
                        case "glpi_tickets.impact":
                        case "glpi_tickets.urgency":
                            $options2['showtype'] = 'search';
                            break;
                        case "glpi_changes.priority":
                        case "glpi_problems.priority":
                        case "glpi_tickets.priority":
                            $options2['showtype'] = 'search';
                            $options2['withmajor'] = true;
                            break;
                        case "glpi_tickets.global_validation":
                            $options2['all'] = true;
                            break;
                        case "glpi_ticketvalidations.status":
                            $options2['all'] = true;
                            break;
                        case "glpi_users.name":
                            $options2['right'] = isset($searchopt['right']) ? $searchopt['right'] : 'all';
                            $options2['inactive_deleted'] = 1;
                            break;
                    }
                    // Standard datatype usage
                    if (!$display && isset($searchopt['datatype'])) {
                        switch ($searchopt['datatype']) {
                            case "date":
                            case "date_delay":
                            case "datetime":
                                $options2['relative_dates'] = true;
                                break;
                        }
                    }
                    $out = $item->getValueToSelect($searchopt, $inputname, $request['value'], $options2);
                    if (strlen($out ?? '')) {
                        echo $out;
                        $display = true;
                    }
                    //Could display be handled by a plugin ?
                    if (!$display && ($plug = isPluginItemType(getItemTypeForTable($searchopt['table'])))) {
                        $display = Plugin::doOneHook($plug['plugin'], 'searchOptionsValues', ['name' => $inputname, 'searchtype' => $request['searchtype'], 'searchoption' => $searchopt, 'value' => $request['value']]);
                    }
                }
                break;
            default:
                if (!$display) {
                    echo "<input type='text' size='13' name='{$inputname}' class='form-control' value=\"" . Html::cleanInputText($request['value']) . "\">";
                }
                break;
        }
    }
    /**
     * Reset save searches
     *
     * @return void
     **/
    public static function resetSaveSearch()
    {
        unset($_SESSION['glpisearch']);
        $_SESSION['glpisearch'] = [];
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
        $default_values = [];
        $default_values["start"] = 0;
        $default_values["order"] = "ASC";
        $default_values["sort"] = 1;
        $default_values["is_deleted"] = 0;
        $default_values["as_map"] = 0;
        if (isset($params['start'])) {
            $params['start'] = (int) $params['start'];
        }
        $default_values["criteria"] = QueryBuilder::getDefaultCriteria($itemtype);
        $default_values["metacriteria"] = [];
        // Reorg search array
        // start
        // order
        // sort
        // is_deleted
        // itemtype
        // criteria : array (0 => array (link =>
        //                               field =>
        //                               searchtype =>
        //                               value =>   (contains)
        // metacriteria : array (0 => array (itemtype =>
        //                                  link =>
        //                                  field =>
        //                                  searchtype =>
        //                                  value =>   (contains)
        if ($itemtype != 'AllAssets' && class_exists($itemtype)) {
            // retrieve default values for current itemtype
            $itemtype_default_values = [];
            if (method_exists($itemtype, 'getDefaultSearchRequest')) {
                $itemtype_default_values = call_user_func([$itemtype, 'getDefaultSearchRequest']);
            }
            // retrieve default values for the current user
            $user_default_values = SavedSearch_User::getDefault(Session::getLoginUserID(), $itemtype);
            if ($user_default_values === false) {
                $user_default_values = [];
            }
            // we construct default values in this order:
            // - general default
            // - itemtype default
            // - user default
            //
            // The last ones erase values or previous
            // So, we can combine each part (order from itemtype, criteria from user, etc)
            $default_values = array_merge($default_values, $itemtype_default_values, $user_default_values);
        }
        // First view of the page or force bookmark : try to load a bookmark
        if ($forcebookmark || $usesession && !isset($params["reset"]) && !isset($_SESSION['glpisearch'][$itemtype])) {
            $user_default_values = SavedSearch_User::getDefault(Session::getLoginUserID(), $itemtype);
            if ($user_default_values) {
                $_SESSION['glpisearch'][$itemtype] = [];
                // Only get datas for bookmarks
                if ($forcebookmark) {
                    $params = $user_default_values;
                } else {
                    $bookmark = new SavedSearch();
                    $bookmark->load($user_default_values['savedsearches_id'], false);
                }
            }
        }
        // Force reorder criterias
        if (isset($params["criteria"]) && is_array($params["criteria"]) && count($params["criteria"])) {
            $tmp = $params["criteria"];
            $params["criteria"] = [];
            foreach ($tmp as $val) {
                $params["criteria"][] = $val;
            }
        }
        // transform legacy meta-criteria in criteria (with flag meta=true)
        // at the end of the array, as before there was only at the end of the query
        if (isset($params["metacriteria"]) && is_array($params["metacriteria"])) {
            // as we will append meta to criteria, check the key exists
            if (!isset($params["criteria"])) {
                $params["criteria"] = [];
            }
            foreach ($params["metacriteria"] as $val) {
                $params["criteria"][] = $val + ['meta' => 1];
            }
            $params["metacriteria"] = [];
        }
        if ($usesession && isset($params["reset"])) {
            if (isset($_SESSION['glpisearch'][$itemtype])) {
                unset($_SESSION['glpisearch'][$itemtype]);
            }
        }
        if (isset($params) && is_array($params) && $usesession) {
            foreach ($params as $key => $val) {
                $_SESSION['glpisearch'][$itemtype][$key] = $val;
            }
        }
        $saved_params = $params;
        foreach ($default_values as $key => $val) {
            if (!isset($params[$key])) {
                if ($usesession && ($key == 'is_deleted' || $key == 'as_map' || !isset($saved_params['criteria'])) && isset($_SESSION['glpisearch'][$itemtype][$key])) {
                    $params[$key] = $_SESSION['glpisearch'][$itemtype][$key];
                } else {
                    $params[$key] = $val;
                    $_SESSION['glpisearch'][$itemtype][$key] = $val;
                }
            }
        }
        return QueryBuilder::cleanParams($params);
    }
    public static function cleanParams(array $params): array
    {
        $int_params = ['sort'];
        foreach ($params as $key => &$val) {
            if (in_array($key, $int_params)) {
                if (is_array($val)) {
                    foreach ($val as &$subval) {
                        $subval = (int) $subval;
                    }
                } else {
                    $val = (int) $val;
                }
            }
        }
        return $params;
    }
}
