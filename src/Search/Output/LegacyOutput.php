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

namespace itsmng\Search\Output;

use itsmng\Search\Input\QueryBuilder;
use itsmng\Search\SearchEngine;
use itsmng\Search\SearchOption;

final class LegacyOutput
{
    /**
     * Display search engine for an type
     *
     * @param string  $itemtype Item type to manage
     *
     * @return void
     **/
    public static function show($itemtype)
    {
        $params = QueryBuilder::manageParams($itemtype, $_GET);
        echo "<div class='search_page'>";
        QueryBuilder::showGenericSearch($itemtype, $params);
        if ($params['as_map'] == 1) {
            LegacyOutput::showMap($itemtype, $params);
        } else {
            LegacyOutput::showList($itemtype, $params);
        }
        echo "</div>";
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
        LegacyOutput::displayData(SearchEngine::getDatas($itemtype, $params));
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
        global $CFG_GLPI;
        if ($itemtype == 'Location') {
            $latitude = 21;
            $longitude = 20;
        } elseif ($itemtype == 'Entity') {
            $latitude = 67;
            $longitude = 68;
        } else {
            $latitude = 998;
            $longitude = 999;
        }
        $params['criteria'][] = ['link' => 'AND NOT', 'field' => $latitude, 'searchtype' => 'contains', 'value' => 'NULL'];
        $params['criteria'][] = ['link' => 'AND NOT', 'field' => $longitude, 'searchtype' => 'contains', 'value' => 'NULL'];
        $data = SearchEngine::getDatas($itemtype, $params);
        LegacyOutput::displayData($data);
        if ($data['data']['totalcount'] > 0) {
            $target = \Glpi\Toolbox\URL::sanitizeURL($data['search']['target']);
            $criteria = $data['search']['criteria'];
            array_pop($criteria);
            array_pop($criteria);
            $criteria[] = ['link' => 'AND', 'field' => $itemtype == 'Location' || $itemtype == 'Entity' ? 1 : ($itemtype == 'Ticket' ? 83 : 3), 'searchtype' => 'equals', 'value' => 'CURLOCATION'];
            $globallinkto = \Toolbox::append_params(['criteria' => \Toolbox::stripslashes_deep($criteria), 'metacriteria' => \Toolbox::stripslashes_deep($data['search']['metacriteria'])], '&amp;');
            $parameters = "as_map=0&amp;sort=" . $data['search']['sort'] . "&amp;order=" . $data['search']['order'] . '&amp;' . $globallinkto;
            if (strpos($target, '?') == false) {
                $fulltarget = $target . "?" . $parameters;
            } else {
                $fulltarget = $target . "&" . $parameters;
            }
            $fulltarget = \Glpi\Toolbox\URL::sanitizeURL($fulltarget);
            $typename = class_exists($itemtype) ? $itemtype::getTypeName($data['data']['totalcount']) : ($itemtype == 'AllAssets' ? __('assets') : $itemtype);
            echo "<div class='center'><p>" . __('Search results for localized items only') . "</p>";
            $js = "\$(function() {
               var map = initMap(\$('#page'), 'map', 'full');
               _loadMap(map, '{$itemtype}');
            });

         var _loadMap = function(map_elt, itemtype) {
            L.AwesomeMarkers.Icon.prototype.options.prefix = 'far';
            var _micon = 'circle';

            var stdMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'blue'
            });

            var aMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'cadetblue'
            });

            var bMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'purple'
            });

            var cMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'darkpurple'
            });

            var dMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'red'
            });

            var eMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'darkred'
            });


            //retrieve geojson data
            map_elt.spin(true);
            \$.ajax({
               dataType: 'json',
               method: 'POST',
               url: '{$CFG_GLPI['root_doc']}/ajax/map.php',
               data: {
                  itemtype: itemtype,
                  params: " . json_encode($params) . "
               }
            }).done(function(data) {
               var _points = data.points;
               var _markers = L.markerClusterGroup({
                  iconCreateFunction: function(cluster) {
                     var childCount = cluster.getChildCount();

                     var markers = cluster.getAllChildMarkers();
                     var n = 0;
                     for (var i = 0; i < markers.length; i++) {
                        n += markers[i].count;
                     }

                     var c = ' marker-cluster-';
                     if (n < 10) {
                        c += 'small';
                     } else if (n < 100) {
                        c += 'medium';
                     } else {
                        c += 'large';
                     }

                     return new L.DivIcon({ html: '<div><span>' + n + '</span></div>', className: 'marker-cluster' + c, iconSize: new L.Point(40, 40) });
                  }
               });

               \$.each(_points, function(index, point) {
                  var _title = '<strong>' + point.title + '</strong><br/><a href=\\''+'{$fulltarget}'.replace(/CURLOCATION/, point.loc_id)+'\\'>" . sprintf(__('%1$s %2$s'), 'COUNT', $typename) . "'.replace(/COUNT/, point.count)+'</a>';
                  if (point.types) {
                     \$.each(point.types, function(tindex, type) {
                        _title += '<br/>" . sprintf(__('%1$s %2$s'), 'COUNT', 'TYPE') . "'.replace(/COUNT/, type.count).replace(/TYPE/, type.name);
                     });
                  }
                  var _icon = stdMarker;
                  if (point.count < 10) {
                     _icon = stdMarker;
                  } else if (point.count < 100) {
                     _icon = aMarker;
                  } else if (point.count < 1000) {
                     _icon = bMarker;
                  } else if (point.count < 5000) {
                     _icon = cMarker;
                  } else if (point.count < 10000) {
                     _icon = dMarker;
                  } else {
                     _icon = eMarker;
                  }
                  var _marker = L.marker([point.lat, point.lng], { icon: _icon, title: point.title });
                  _marker.count = point.count;
                  _marker.bindPopup(_title);
                  _markers.addLayer(_marker);
               });

               map_elt.addLayer(_markers);
               map_elt.fitBounds(
                  _markers.getBounds(), {
                     padding: [50, 50],
                     maxZoom: 12
                  }
               );
            }).fail(function (response) {
               var _data = response.responseJSON;
               var _message = '" . __s('An error occured loading data :(') . "';
               if (_data.message) {
                  _message = _data.message;
               }
               var fail_info = L.control();
               fail_info.onAdd = function (map) {
                  this._div = L.DomUtil.create('div', 'fail_info');
                  this._div.innerHTML = _message + '<br/><span id=\\'reload_data\\'><i class=\\'fa fa-sync\\' aria-hidden='true'></i> " . __s('Reload') . "</span>';
                  return this._div;
               };
               fail_info.addTo(map_elt);
               \$('#reload_data').on('click', function() {
                  \$('.fail_info').remove();
                  _loadMap(map_elt);
               });
            }).always(function() {
               //hide spinner
               map_elt.spin(false);
            });
         }

         ";
            echo \Html::scriptBlock($js);
            echo "</div>";
        }
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
        global $CFG_GLPI;
        $display_type = (int) $data['display_type'];
        if (in_array($display_type, [\Search::SYLK_OUTPUT, \Search::PDF_OUTPUT_LANDSCAPE, \Search::CSV_OUTPUT, \Search::PDF_OUTPUT_PORTRAIT], true)) {
            if ($data['data']['count'] > 0) {
                $begin_display = $data['data']['begin'];
                $end_display = $data['data']['end'];
                $nbcols = count($data['data']['cols']);
                if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                    $nbcols++;
                }
                echo LegacyOutput::showHeader($display_type, $end_display - $begin_display + 1, $nbcols);
                $headers_line_top = '';
                $headers_line_top .= LegacyOutput::showBeginHeader($display_type);
                $headers_line_top .= LegacyOutput::showNewLine($display_type);
                $header_num = 1;
                $metanames = [];
                foreach ($data['data']['cols'] as $val) {
                    $name = $val["name"];
                    if (isset($val['groupname'])) {
                        $groupname = $val['groupname'];
                        if (is_array($groupname)) {
                            $groupname = $groupname['name'];
                        }
                        $name = "{$groupname} - {$name}";
                    }
                    if ($data['itemtype'] != $val['itemtype']) {
                        if (!isset($metanames[$val['itemtype']])) {
                            if ($metaitem = \getItemForItemtype($val['itemtype'])) {
                                $metanames[$val['itemtype']] = $metaitem->getTypeName();
                            }
                        }
                        $name = sprintf(__('%1$s - %2$s'), $metanames[$val['itemtype']], $val["name"]);
                    }
                    $headers_line_top .= LegacyOutput::showHeaderItem($display_type, $name, $header_num);
                }
                if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                    $headers_line_top .= LegacyOutput::showHeaderItem($display_type, __('Item type'), $header_num);
                }
                $headers_line_top .= LegacyOutput::showEndLine($display_type);
                $headers_line_top .= LegacyOutput::showEndHeader($display_type);
                echo $headers_line_top;
                $row_num = 1;
                $typenames = [];
                foreach ($data['data']['rows'] as $row) {
                    $item_num = 1;
                    $row_num++;
                    echo LegacyOutput::showNewLine($display_type, $row_num % 2, $data['search']['is_deleted']);
                    foreach ($data['data']['cols'] as $col) {
                        $colkey = "{$col['itemtype']}_{$col['id']}";
                        echo LegacyOutput::showItem($display_type, $row[$colkey]['displayname'], $item_num, $row_num);
                    }
                    if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                        if (!isset($typenames[$row["TYPE"]])) {
                            if ($itemtmp = \getItemForItemtype($row["TYPE"])) {
                                $typenames[$row["TYPE"]] = $itemtmp->getTypeName();
                            }
                        }
                        echo LegacyOutput::showItem($display_type, $typenames[$row["TYPE"]], $item_num, $row_num);
                    }
                    echo LegacyOutput::showEndLine($display_type);
                }
                $title = '';
                if ($display_type == \Search::PDF_OUTPUT_LANDSCAPE || $display_type == \Search::PDF_OUTPUT_PORTRAIT) {
                    $title = LegacyOutput::computeTitle($data);
                }
                echo LegacyOutput::showFooter($display_type, $title, $data['data']['count']);
            } else {
                echo LegacyOutput::showError($display_type);
            }
            return;
        }
        // Init list of items displayed
        if ($data['display_type'] == \Search::HTML_OUTPUT) {
            \Session::initNavigateListItems($data['itemtype']);
        }
        $fields = array_combine(array_column($data['data']['cols'], 'id'), array_column($data['data']['cols'], 'name'));
        $values = [];
        $row_num = 0;
        $massiveActionValues = [];
        foreach ($data['data']['rows'] as $row) {
            \Session::addToNavigateListItems($data['itemtype'], $row["id"]);
            $row_num++;
            $col_num = 0;
            $value[$row_num] = [];
            if (!isset($row['entities_id']) || in_array($row['entities_id'], $_SESSION['glpiactiveentities'])) {
                $massiveActionValues[$row_num] = 'item[' . $data['itemtype'] . '][' . $row['id'] . ']';
            } else {
                $massiveActionValues[$row_num] = null;
            }
            foreach ($data['data']['cols'] as $col) {
                $colkey = "{$col['itemtype']}_{$col['id']}";
                if (isset($row[$colkey]['displayname']) && $row[$colkey]['displayname']) {
                    $values[$row_num][$col_num] = $row[$colkey]['displayname'];
                }
                $col_num++;
            }
        }
        $massiveactionparams = $data['search']['massiveactionparams'] + ['container' => 'SearchTableFor' . $data['itemtype'], 'display_arrow' => false, 'is_deleted' => $data['search']['is_deleted'], 'itemtype' => $data['itemtype']];
        $can_trash = isset($data['item']->fields['is_deleted']);
        $url = $CFG_GLPI['root_doc'] . "/src/search/search.ajax.php?itemtype={$data['itemtype']}&deleted={$data['search']['is_deleted']}";
        if ($data['search']['criteria']) {
            $url .= "&criteria=" . urlencode(json_encode($data['search']['criteria']));
        }
        if (isset($data['itemtype']) && class_exists($data['itemtype'])) {
            $item = new $data['itemtype']();
            if (method_exists($item, 'title')) {
                $item->title();
            }
        }
        \Html::showMassiveActions($massiveactionparams);
        $can_edit_columns = \Session::haveRight(\DisplayPreference::$rightname, \DisplayPreference::PERSONAL) || \Session::haveRight(\DisplayPreference::$rightname, \DisplayPreference::GENERAL);
        if ($can_edit_columns) {
            \Html::requireJs('displaypreferences');
        }
        $export_params = ['item_type' => $data['itemtype'], 'criteria' => $data['search']['criteria'], 'sort' => $data['search']['sort'], 'order' => $data['search']['order'], 'is_deleted' => $data['search']['is_deleted']];
        if (!empty($data['search']['metacriteria'])) {
            $export_params['metacriteria'] = $data['search']['metacriteria'];
        }
        \renderTwigTemplate('table.twig', ['id' => 'SearchTableFor' . $data['itemtype'], 'fields' => $fields, 'url' => $url, 'can_trash' => $can_trash, 'is_trash' => $data['search']['is_deleted'], 'massive_action' => $massiveActionValues, 'itemtype' => $data['itemtype'], 'column_edit' => $can_edit_columns, 'export_target' => $CFG_GLPI['root_doc'] . '/front/report.dynamic.php', 'export_params' => $export_params]);
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
        $rand = mt_rand();
        return "<div class='switch grey_border pager_controls'>" . "<label for='is_deletedswitch{$rand}' title='" . __s('Show the trashbin') . "' >" . "<span class='sr-only'>" . __s('Show the trashbin') . "</span>" . "<input type='hidden' name='is_deleted' value='0' /> " . "<input type='checkbox' id='is_deletedswitch{$rand}' name='is_deleted' value='1' " . ($is_deleted ? "checked='checked'" : "") . " onClick = \"toogle('is_deleted','','','');
                              document.forms['searchform{$itemtype}'].submit();\" />" . "<span class='fa fa-trash-alt pointer'></span>" . "<span class='lever'></span>" . "</label>" . "</div>";
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
        $title = "";
        if (count($data['search']['criteria'])) {
            //Drop the first link as it is not needed, or convert to clean link (AND NOT -> NOT)
            if (isset($data['search']['criteria']['0']['link'])) {
                $notpos = strpos($data['search']['criteria']['0']['link'], 'NOT');
                //If link was like '%NOT%' just use NOT. Otherwise remove the link
                if ($notpos > 0) {
                    $data['search']['criteria']['0']['link'] = 'NOT';
                } elseif (!$notpos) {
                    unset($data['search']['criteria']['0']['link']);
                }
            }
            foreach ($data['search']['criteria'] as $criteria) {
                if (isset($criteria['itemtype'])) {
                    $searchopt = & SearchOption::getOptions($criteria['itemtype']);
                } else {
                    $searchopt = & SearchOption::getOptions($data['itemtype']);
                }
                $titlecontain = '';
                if (isset($criteria['criteria'])) {
                    //This is a group criteria, call computeTitle again and concat
                    $newdata = $data;
                    $oldlink = $criteria['link'];
                    $newdata['search'] = $criteria;
                    $titlecontain = sprintf(__('%1$s %2$s (%3$s)'), $titlecontain, $oldlink, LegacyOutput::computeTitle($newdata));
                } else {
                    if (strlen((string) $criteria['value']) > 0) {
                        if (isset($criteria['link'])) {
                            $titlecontain = " " . $criteria['link'] . " ";
                        }
                        $gdname = '';
                        $valuename = '';
                        switch ($criteria['field']) {
                            case "all":
                                $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, __('All'));
                                break;
                            case "view":
                                $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, __('Items seen'));
                                break;
                            default:
                                if (isset($criteria['meta']) && $criteria['meta']) {
                                    $searchoptname = sprintf(__('%1$s / %2$s'), $criteria['itemtype'], $searchopt[$criteria['field']]["name"]);
                                } else {
                                    $searchoptname = $searchopt[$criteria['field']]["name"];
                                }
                                $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, $searchoptname);
                                $itemtype = \getItemTypeForTable($searchopt[$criteria['field']]["table"]);
                                $valuename = '';
                                if ($item = \getItemForItemtype($itemtype)) {
                                    $valuename = $item->getValueToDisplay($searchopt[$criteria['field']], $criteria['value']);
                                }
                                $gdname = \Dropdown::getDropdownName($searchopt[$criteria['field']]["table"], $criteria['value']);
                        }
                        if (empty($valuename)) {
                            $valuename = $criteria['value'];
                        }
                        switch ($criteria['searchtype']) {
                            case "equals":
                                if (in_array($searchopt[$criteria['field']]["field"], ['name', 'completename'])) {
                                    $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, $gdname);
                                } else {
                                    $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, $valuename);
                                }
                                break;
                            case "notequals":
                                if (in_array($searchopt[$criteria['field']]["field"], ['name', 'completename'])) {
                                    $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain, $gdname);
                                } else {
                                    $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain, $valuename);
                                }
                                break;
                            case "lessthan":
                                $titlecontain = sprintf(__('%1$s < %2$s'), $titlecontain, $valuename);
                                break;
                            case "morethan":
                                $titlecontain = sprintf(__('%1$s > %2$s'), $titlecontain, $valuename);
                                break;
                            case "contains":
                                $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, '%' . $valuename . '%');
                                break;
                            case "notcontains":
                                $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain, '%' . $valuename . '%');
                                break;
                            case "under":
                                $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, sprintf(__('%1$s %2$s'), __('under'), $gdname));
                                break;
                            case "notunder":
                                $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, sprintf(__('%1$s %2$s'), __('not under'), $gdname));
                                break;
                            default:
                                $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, $valuename);
                                break;
                        }
                    }
                }
                $title .= $titlecontain;
            }
        }
        if (isset($data['search']['metacriteria']) && count($data['search']['metacriteria'])) {
            $metanames = [];
            foreach ($data['search']['metacriteria'] as $metacriteria) {
                $searchopt = & SearchOption::getOptions($metacriteria['itemtype']);
                if (!isset($metanames[$metacriteria['itemtype']])) {
                    if ($metaitem = \getItemForItemtype($metacriteria['itemtype'])) {
                        $metanames[$metacriteria['itemtype']] = $metaitem->getTypeName();
                    }
                }
                $titlecontain2 = '';
                if (strlen((string) $metacriteria['value']) > 0) {
                    if (isset($metacriteria['link'])) {
                        $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2, $metacriteria['link']);
                    }
                    $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2, sprintf(__('%1$s / %2$s'), $metanames[$metacriteria['itemtype']], $searchopt[$metacriteria['field']]["name"]));
                    $gdname2 = \Dropdown::getDropdownName($searchopt[$metacriteria['field']]["table"], $metacriteria['value']);
                    switch ($metacriteria['searchtype']) {
                        case "equals":
                            if (in_array($searchopt[$metacriteria['link']]["field"], ['name', 'completename'])) {
                                $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2, $gdname2);
                            } else {
                                $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2, $metacriteria['value']);
                            }
                            break;
                        case "notequals":
                            if (in_array($searchopt[$metacriteria['link']]["field"], ['name', 'completename'])) {
                                $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2, $gdname2);
                            } else {
                                $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2, $metacriteria['value']);
                            }
                            break;
                        case "lessthan":
                            $titlecontain2 = sprintf(__('%1$s < %2$s'), $titlecontain2, $metacriteria['value']);
                            break;
                        case "morethan":
                            $titlecontain2 = sprintf(__('%1$s > %2$s'), $titlecontain2, $metacriteria['value']);
                            break;
                        case "contains":
                            $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2, '%' . $metacriteria['value'] . '%');
                            break;
                        case "notcontains":
                            $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2, '%' . $metacriteria['value'] . '%');
                            break;
                        case "under":
                            $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2, sprintf(__('%1$s %2$s'), __('under'), $gdname2));
                            break;
                        case "notunder":
                            $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2, sprintf(__('%1$s %2$s'), __('not under'), $gdname2));
                            break;
                        default:
                            $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2, $metacriteria['value']);
                            break;
                    }
                }
                $title .= $titlecontain2;
            }
        }
        return $title;
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
        $searchopt = & SearchOption::getOptions($itemtype);
        $table = $searchopt[$ID]["table"];
        $field = $searchopt[$ID]["field"];
        // Plugin can override core definition for its type
        if ($plug = \isPluginItemType($itemtype)) {
            $out = \Plugin::doOneHook($plug['plugin'], 'displayConfigItem', $itemtype, $ID, $data, "{$itemtype}_{$ID}");
            if (!empty($out)) {
                return $out;
            }
        }
        $out = "";
        $NAME = "{$itemtype}_{$ID}";
        switch ($table . "." . $field) {
            case "glpi_tickets.time_to_resolve":
            case "glpi_tickets.internal_time_to_resolve":
            case "glpi_problems.time_to_resolve":
            case "glpi_changes.time_to_resolve":
            case "glpi_tickets.time_to_own":
            case "glpi_tickets.internal_time_to_own":
                if (!in_array($ID, [151, 158, 181, 186]) && !empty($data[$NAME][0]['name']) && $data[$NAME][0]['status'] != \CommonITILObject::WAITING && $data[$NAME][0]['name'] < $_SESSION['glpi_currenttime']) {
                    $out = " style=\"background-color: #cf9b9b\" ";
                }
                break;
            case "glpi_projectstates.color":
                $out = " style=\"background-color:" . $data[$NAME][0]['name'] . ";\" ";
                break;
            case "glpi_projectstates.name":
                if (array_key_exists('color', $data[$NAME][0])) {
                    $out = " style=\"background-color:" . $data[$NAME][0]['color'] . ";\" ";
                }
                break;
        }
        return $out;
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
        global $CFG_GLPI;
        $searchopt = & SearchOption::getOptions($itemtype);
        if ($itemtype == 'AllAssets' || isset($CFG_GLPI["union_search_type"][$itemtype]) && $CFG_GLPI["union_search_type"][$itemtype] == $searchopt[$ID]["table"]) {
            $oparams = [];
            if (isset($searchopt[$ID]['addobjectparams']) && $searchopt[$ID]['addobjectparams']) {
                $oparams = $searchopt[$ID]['addobjectparams'];
            }
            // Search option may not exists in subtype
            // This is the case for "Inventory number" for a Software listed from ReservationItem search
            $subtype_so = & SearchOption::getOptions($data["TYPE"]);
            if (!array_key_exists($ID, $subtype_so)) {
                return '';
            }
            return LegacyOutput::giveItem($data["TYPE"], $ID, $data, $meta, $oparams, $itemtype);
        }
        $so = $searchopt[$ID];
        $orig_id = $ID;
        $ID = ($orig_itemtype !== null ? $orig_itemtype : $itemtype) . '_' . $ID;
        if (count($addobjectparams)) {
            $so = array_merge($so, $addobjectparams);
        }
        // Plugin can override core definition for its type
        if ($plug = \isPluginItemType($itemtype)) {
            $out = \Plugin::doOneHook($plug['plugin'], 'giveItem', $itemtype, $orig_id, $data, $ID);
            if (!empty($out)) {
                return $out;
            }
        }
        if (isset($so["table"])) {
            $table = $so["table"];
            $field = $so["field"];
            $linkfield = $so["linkfield"];
            /// TODO try to clean all specific cases using SpecificToDisplay
            switch ($table . '.' . $field) {
                case "glpi_users.name":
                    if ($itemtype == 'Ticket' && \Session::getCurrentInterface() == 'helpdesk' && $orig_id == 5 && \Entity::getUsedConfig('anonymize_support_agents', $itemtype::getById($data['id'])->getEntityId())) {
                        return __("Helpdesk");
                    }
                    // USER search case
                    if ($itemtype != 'User' && isset($so["forcegroupby"]) && $so["forcegroupby"]) {
                        $out = "";
                        $count_display = 0;
                        $added = [];
                        $showuserlink = 0;
                        if (\Session::haveRight('user', \READ)) {
                            $showuserlink = 1;
                        }
                        for ($k = 0; $k < $data[$ID]['count']; $k++) {
                            if (isset($data[$ID][$k]['name']) && $data[$ID][$k]['name'] > 0 || isset($data[$ID][$k][2]) && $data[$ID][$k][2] != '') {
                                if ($count_display) {
                                    $out .= \Search::LBBR;
                                }
                                if ($itemtype == 'Ticket') {
                                    if (isset($data[$ID][$k]['name']) && $data[$ID][$k]['name'] > 0) {
                                        $userdata = \getUserName($data[$ID][$k]['name'], 2);
                                        $tooltip = "";
                                        if (\Session::haveRight('user', \READ)) {
                                            $tooltip = \Html::showToolTip($userdata["comment"], ['link' => $userdata["link"], 'display' => false]);
                                        }
                                        $out .= sprintf(__('%1$s %2$s'), $userdata['name'], $tooltip);
                                        $count_display++;
                                    }
                                } else {
                                    $out .= \getUserName($data[$ID][$k]['name'], $showuserlink);
                                    $count_display++;
                                }
                                // Manage alternative_email for tickets_users
                                if ($itemtype == 'Ticket' && isset($data[$ID][$k][2])) {
                                    $split = explode(\Search::LONGSEP, $data[$ID][$k][2]);
                                    for ($l = 0; $l < count($split); $l++) {
                                        $split2 = explode(" ", $split[$l]);
                                        if (count($split2) == 2 && $split2[0] == 0 && !empty($split2[1])) {
                                            if ($count_display) {
                                                $out .= \Search::LBBR;
                                            }
                                            $count_display++;
                                            $out .= "<a href='mailto:" . $split2[1] . "'>" . $split2[1] . "</a>";
                                        }
                                    }
                                }
                            }
                        }
                        return $out;
                    }
                    if ($itemtype != 'User') {
                        $toadd = '';
                        if ($itemtype == 'Ticket' && $data[$ID][0]['id'] > 0) {
                            $userdata = \getUserName($data[$ID][0]['id'], 2);
                            $toadd = \Html::showToolTip($userdata["comment"], ['link' => $userdata["link"], 'display' => false]);
                        }
                        $usernameformat = \formatUserName($data[$ID][0]['id'], $data[$ID][0]['name'], $data[$ID][0]['realname'], $data[$ID][0]['firstname'], 1);
                        return sprintf(__('%1$s %2$s'), $usernameformat, $toadd);
                    }
                    break;
                case "glpi_profiles.name":
                    if ($itemtype == 'User' && $orig_id == 20) {
                        $out = "";
                        $count_display = 0;
                        $added = [];
                        for ($k = 0; $k < $data[$ID]['count']; $k++) {
                            if (strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0 && !in_array($data[$ID][$k]['name'] . "-" . $data[$ID][$k]['entities_id'], $added)) {
                                $text = sprintf(__('%1$s - %2$s'), $data[$ID][$k]['name'], \Dropdown::getDropdownName('glpi_entities', $data[$ID][$k]['entities_id']));
                                $comp = '';
                                if ($data[$ID][$k]['is_recursive']) {
                                    $comp = __('R');
                                    if ($data[$ID][$k]['is_dynamic']) {
                                        $comp = sprintf(__('%1$s%2$s'), $comp, ", ");
                                    }
                                }
                                if ($data[$ID][$k]['is_dynamic']) {
                                    $comp = sprintf(__('%1$s%2$s'), $comp, __('D'));
                                }
                                if (!empty($comp)) {
                                    $text = sprintf(__('%1$s %2$s'), $text, "(" . $comp . ")");
                                }
                                if ($count_display) {
                                    $out .= \Search::LBBR;
                                }
                                $count_display++;
                                $out .= $text;
                                $added[] = $data[$ID][$k]['name'] . "-" . $data[$ID][$k]['entities_id'];
                            }
                        }
                        return $out;
                    }
                    break;
                case "glpi_entities.completename":
                    if ($itemtype == 'User') {
                        $out = "";
                        $added = [];
                        $count_display = 0;
                        for ($k = 0; $k < $data[$ID]['count']; $k++) {
                            if (isset($data[$ID][$k]['name']) && strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0 && !in_array($data[$ID][$k]['name'] . "-" . $data[$ID][$k]['profiles_id'], $added)) {
                                $text = sprintf(__('%1$s - %2$s'), $data[$ID][$k]['name'], \Dropdown::getDropdownName('glpi_profiles', $data[$ID][$k]['profiles_id']));
                                $comp = '';
                                if ($data[$ID][$k]['is_recursive']) {
                                    $comp = __('R');
                                    if ($data[$ID][$k]['is_dynamic']) {
                                        $comp = sprintf(__('%1$s%2$s'), $comp, ", ");
                                    }
                                }
                                if ($data[$ID][$k]['is_dynamic']) {
                                    $comp = sprintf(__('%1$s%2$s'), $comp, __('D'));
                                }
                                if (!empty($comp)) {
                                    $text = sprintf(__('%1$s %2$s'), $text, "(" . $comp . ")");
                                }
                                if ($count_display) {
                                    $out .= \Search::LBBR;
                                }
                                $count_display++;
                                $out .= $text;
                                $added[] = $data[$ID][$k]['name'] . "-" . $data[$ID][$k]['profiles_id'];
                            }
                        }
                        return $out;
                    }
                    break;
                case "glpi_documenttypes.icon":
                    if (!empty($data[$ID][0]['name'])) {
                        return "<img class='middle' alt='' src='" . $CFG_GLPI["typedoc_icon_dir"] . "/" . $data[$ID][0]['name'] . "'>";
                    }
                    return "&nbsp;";
                case "glpi_documents.filename":
                    $doc = new \Document();
                    if ($doc->getFromDB($data['id'])) {
                        return $doc->getDownloadLink();
                    }
                    return \NOT_AVAILABLE;
                case "glpi_tickets_tickets.tickets_id_1":
                    $out = "";
                    $displayed = [];
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        $linkid = $data[$ID][$k]['tickets_id_2'] == $data['id'] ? $data[$ID][$k]['name'] : $data[$ID][$k]['tickets_id_2'];
                        if ($linkid > 0 && !isset($displayed[$linkid])) {
                            $text = "<a ";
                            $text .= "href=\"" . \Ticket::getFormURLWithID($linkid) . "\">";
                            $text .= \Dropdown::getDropdownName('glpi_tickets', $linkid) . "</a>";
                            if (count($displayed)) {
                                $out .= \Search::LBBR;
                            }
                            $displayed[$linkid] = $linkid;
                            $out .= $text;
                        }
                    }
                    return $out;
                case "glpi_problems.id":
                    if ($so["datatype"] == 'count') {
                        if ($data[$ID][0]['name'] > 0 && \Session::haveRight("problem", \Problem::READALL)) {
                            if ($itemtype == 'ITILCategory') {
                                $options['criteria'][0]['field'] = 7;
                                $options['criteria'][0]['searchtype'] = 'equals';
                                $options['criteria'][0]['value'] = $data['id'];
                                $options['criteria'][0]['link'] = 'AND';
                            } else {
                                $options['criteria'][0]['field'] = 12;
                                $options['criteria'][0]['searchtype'] = 'equals';
                                $options['criteria'][0]['value'] = 'all';
                                $options['criteria'][0]['link'] = 'AND';
                                $options['metacriteria'][0]['itemtype'] = $itemtype;
                                $options['metacriteria'][0]['field'] = SearchOption::getOptionNumber($itemtype, 'name');
                                $options['metacriteria'][0]['searchtype'] = 'equals';
                                $options['metacriteria'][0]['value'] = $data['id'];
                                $options['metacriteria'][0]['link'] = 'AND';
                            }
                            $options['reset'] = 'reset';
                            $out = "<a id='problem{$itemtype}" . $data['id'] . "' ";
                            $out .= "href=\"" . $CFG_GLPI["root_doc"] . "/front/problem.php?" . \Toolbox::append_params($options, '&amp;') . "\">";
                            $out .= $data[$ID][0]['name'] . "</a>";
                            return $out;
                        }
                    }
                    break;
                case "glpi_tickets.id":
                    if ($so["datatype"] == 'count') {
                        if ($data[$ID][0]['name'] > 0 && \Session::haveRight("ticket", \Ticket::READALL)) {
                            if ($itemtype == 'User') {
                                // Requester
                                if ($ID == 'User_60') {
                                    $options['criteria'][0]['field'] = 4;
                                    $options['criteria'][0]['searchtype'] = 'equals';
                                    $options['criteria'][0]['value'] = $data['id'];
                                    $options['criteria'][0]['link'] = 'AND';
                                }
                                // Writer
                                if ($ID == 'User_61') {
                                    $options['criteria'][0]['field'] = 22;
                                    $options['criteria'][0]['searchtype'] = 'equals';
                                    $options['criteria'][0]['value'] = $data['id'];
                                    $options['criteria'][0]['link'] = 'AND';
                                }
                                // Assign
                                if ($ID == 'User_64') {
                                    $options['criteria'][0]['field'] = 5;
                                    $options['criteria'][0]['searchtype'] = 'equals';
                                    $options['criteria'][0]['value'] = $data['id'];
                                    $options['criteria'][0]['link'] = 'AND';
                                }
                            } elseif ($itemtype == 'ITILCategory') {
                                $options['criteria'][0]['field'] = 7;
                                $options['criteria'][0]['searchtype'] = 'equals';
                                $options['criteria'][0]['value'] = $data['id'];
                                $options['criteria'][0]['link'] = 'AND';
                            } else {
                                $options['criteria'][0]['field'] = 12;
                                $options['criteria'][0]['searchtype'] = 'equals';
                                $options['criteria'][0]['value'] = 'all';
                                $options['criteria'][0]['link'] = 'AND';
                                $options['metacriteria'][0]['itemtype'] = $itemtype;
                                $options['metacriteria'][0]['field'] = SearchOption::getOptionNumber($itemtype, 'name');
                                $options['metacriteria'][0]['searchtype'] = 'equals';
                                $options['metacriteria'][0]['value'] = $data['id'];
                                $options['metacriteria'][0]['link'] = 'AND';
                            }
                            $options['reset'] = 'reset';
                            $out = "<a id='ticket{$itemtype}" . $data['id'] . "' ";
                            $out .= "href=\"" . $CFG_GLPI["root_doc"] . "/front/ticket.php?" . \Toolbox::append_params($options, '&amp;') . "\">";
                            $out .= $data[$ID][0]['name'] . "</a>";
                            return $out;
                        }
                    }
                    break;
                case "glpi_tickets.time_to_resolve":
                case "glpi_problems.time_to_resolve":
                case "glpi_changes.time_to_resolve":
                case "glpi_tickets.time_to_own":
                case "glpi_tickets.internal_time_to_own":
                case "glpi_tickets.internal_time_to_resolve":
                    // Due date + progress
                    if (in_array($orig_id, [151, 158, 181, 186])) {
                        $out = \Html::convDateTime($data[$ID][0]['name']);
                        // No due date in waiting status
                        if ($data[$ID][0]['status'] == \CommonITILObject::WAITING) {
                            return '';
                        }
                        if (empty($data[$ID][0]['name'])) {
                            return '';
                        }
                        if ($data[$ID][0]['status'] == \Ticket::SOLVED || $data[$ID][0]['status'] == \Ticket::CLOSED) {
                            return $out;
                        }
                        $itemtype = \getItemTypeForTable($table);
                        $item = new $itemtype();
                        $item->getFromDB($data['id']);
                        $percentage = 0;
                        $totaltime = 0;
                        $currenttime = 0;
                        $slaField = 'slas_id';
                        // define correct sla field
                        switch ($table . '.' . $field) {
                            case "glpi_tickets.time_to_resolve":
                                $slaField = 'slas_id_ttr';
                                $sla_class = 'SLA';
                                break;
                            case "glpi_tickets.time_to_own":
                                $slaField = 'slas_id_tto';
                                $sla_class = 'SLA';
                                break;
                            case "glpi_tickets.internal_time_to_own":
                                $slaField = 'olas_id_tto';
                                $sla_class = 'OLA';
                                break;
                            case "glpi_tickets.internal_time_to_resolve":
                                $slaField = 'olas_id_ttr';
                                $sla_class = 'OLA';
                                break;
                        }
                        switch ($table . '.' . $field) {
                            // If ticket has been taken into account : no progression display
                            case "glpi_tickets.time_to_own":
                            case "glpi_tickets.internal_time_to_own":
                                if ($item->fields['takeintoaccount_delay_stat'] > 0) {
                                    return $out;
                                }
                                break;
                        }
                        if ($item->isField($slaField) && $item->fields[$slaField] != 0) {
                            // Have SLA
                            $sla = new $sla_class();
                            $sla->getFromDB($item->fields[$slaField]);
                            $currenttime = $sla->getActiveTimeBetween($item->fields['date'], date('Y-m-d H:i:s'));
                            $totaltime = $sla->getActiveTimeBetween($item->fields['date'], $data[$ID][0]['name']);
                        } else {
                            $calendars_id = \Entity::getUsedConfig('calendars_id', $item->fields['entities_id']);
                            if ($calendars_id != 0) {
                                // Ticket entity have calendar
                                $calendar = new \Calendar();
                                $calendar->getFromDB($calendars_id);
                                $currenttime = $calendar->getActiveTimeBetween($item->fields['date'], date('Y-m-d H:i:s'));
                                $totaltime = $calendar->getActiveTimeBetween($item->fields['date'], $data[$ID][0]['name']);
                            } else {
                                // No calendar
                                $currenttime = strtotime(date('Y-m-d H:i:s')) - strtotime((string) $item->fields['date']);
                                $totaltime = strtotime((string) $data[$ID][0]['name']) - strtotime((string) $item->fields['date']);
                            }
                        }
                        if ($totaltime != 0) {
                            $percentage = round(100 * $currenttime / $totaltime);
                        } else {
                            // Total time is null : no active time
                            $percentage = 100;
                        }
                        if ($percentage > 100) {
                            $percentage = 100;
                        }
                        $percentage_text = $percentage;
                        if ($_SESSION['glpiduedatewarning_unit'] == '%') {
                            $less_warn_limit = $_SESSION['glpiduedatewarning_less'];
                            $less_warn = 100 - $percentage;
                        } elseif ($_SESSION['glpiduedatewarning_unit'] == 'hour') {
                            $less_warn_limit = $_SESSION['glpiduedatewarning_less'] * \HOUR_TIMESTAMP;
                            $less_warn = $totaltime - $currenttime;
                        } elseif ($_SESSION['glpiduedatewarning_unit'] == 'day') {
                            $less_warn_limit = $_SESSION['glpiduedatewarning_less'] * \DAY_TIMESTAMP;
                            $less_warn = $totaltime - $currenttime;
                        }
                        if ($_SESSION['glpiduedatecritical_unit'] == '%') {
                            $less_crit_limit = $_SESSION['glpiduedatecritical_less'];
                            $less_crit = 100 - $percentage;
                        } elseif ($_SESSION['glpiduedatecritical_unit'] == 'hour') {
                            $less_crit_limit = $_SESSION['glpiduedatecritical_less'] * \HOUR_TIMESTAMP;
                            $less_crit = $totaltime - $currenttime;
                        } elseif ($_SESSION['glpiduedatecritical_unit'] == 'day') {
                            $less_crit_limit = $_SESSION['glpiduedatecritical_less'] * \DAY_TIMESTAMP;
                            $less_crit = $totaltime - $currenttime;
                        }
                        $color = $_SESSION['glpiduedateok_color'];
                        if ($less_crit < $less_crit_limit) {
                            $color = $_SESSION['glpiduedatecritical_color'];
                        } elseif ($less_warn < $less_warn_limit) {
                            $color = $_SESSION['glpiduedatewarning_color'];
                        }
                        if (!isset($so['datatype'])) {
                            $so['datatype'] = 'progressbar';
                        }
                        $progressbar_data = ['text' => \Html::convDateTime($data[$ID][0]['name']), 'percent' => $percentage, 'percent_text' => $percentage_text, 'color' => $color];
                    }
                    break;
                case "glpi_softwarelicenses.number":
                    if ($data[$ID][0]['min'] == -1) {
                        return __('Unlimited');
                    }
                    if (empty($data[$ID][0]['name'])) {
                        return 0;
                    }
                    return $data[$ID][0]['name'];
                case "glpi_auth_tables.name":
                    return \Auth::getMethodName($data[$ID][0]['name'], $data[$ID][0]['auths_id'], 1, $data[$ID][0]['ldapname'] . $data[$ID][0]['mailname']);
                case "glpi_reservationitems.comment":
                    if (empty($data[$ID][0]['name'])) {
                        $text = __('None');
                    } else {
                        $text = \Html::resume_text($data[$ID][0]['name']);
                    }
                    if (\Session::haveRight('reservation', \UPDATE)) {
                        return "<a title=\"" . __s('Modify the comment') . "\"
                           href='" . \ReservationItem::getFormURLWithID($data['refID']) . "' >" . $text . "</a>";
                    }
                    return $text;
                case 'glpi_crontasks.description':
                    $tmp = new \CronTask();
                    return $tmp->getDescription($data[$ID][0]['name']);
                case 'glpi_changes.status':
                    $status = \Change::getStatus($data[$ID][0]['name']);
                    return "<span class='no-wrap'>" . \Change::getStatusIcon($data[$ID][0]['name']) . "&nbsp;{$status}" . "</span>";
                case 'glpi_problems.status':
                    $status = \Problem::getStatus($data[$ID][0]['name']);
                    return "<span class='no-wrap'>" . \Problem::getStatusIcon($data[$ID][0]['name']) . "&nbsp;{$status}" . "</span>";
                case 'glpi_tickets.status':
                    $status = \Ticket::getStatus($data[$ID][0]['name']);
                    return "<span class='no-wrap'>" . \Ticket::getStatusIcon($data[$ID][0]['name']) . "&nbsp;{$status}" . "</span>";
                case 'glpi_projectstates.name':
                    $out = '';
                    $name = $data[$ID][0]['name'];
                    if (isset($data[$ID][0]['trans'])) {
                        $name = $data[$ID][0]['trans'];
                    }
                    if ($itemtype == 'ProjectState') {
                        $out = "<a href='" . \ProjectState::getFormURLWithID($data[$ID][0]["id"]) . "'>" . $name . "</a></div>";
                    } else {
                        $out = $name;
                    }
                    return $out;
                case 'glpi_items_tickets.items_id':
                case 'glpi_items_problems.items_id':
                case 'glpi_changes_items.items_id':
                case 'glpi_certificates_items.items_id':
                case 'glpi_appliances_items.items_id':
                    if (!empty($data[$ID])) {
                        $items = [];
                        foreach ($data[$ID] as $key => $val) {
                            if (is_numeric($key)) {
                                if (!empty($val['itemtype']) && ($item = \getItemForItemtype($val['itemtype']))) {
                                    if ($item->getFromDB($val['name'])) {
                                        $items[] = $item->getLink(['comments' => true]);
                                    }
                                }
                            }
                        }
                        if (!empty($items)) {
                            return implode("<br>", $items);
                        }
                    }
                    return '&nbsp;';
                case 'glpi_items_tickets.itemtype':
                case 'glpi_items_problems.itemtype':
                    if (!empty($data[$ID])) {
                        $itemtypes = [];
                        foreach ($data[$ID] as $key => $val) {
                            if (is_numeric($key)) {
                                if (!empty($val['name']) && ($item = \getItemForItemtype($val['name']))) {
                                    $item = new $val['name']();
                                    $name = $item->getTypeName();
                                    $itemtypes[] = __($name);
                                }
                            }
                        }
                        if (!empty($itemtypes)) {
                            return implode("<br>", $itemtypes);
                        }
                    }
                    return '&nbsp;';
                case 'glpi_tickets.name':
                case 'glpi_problems.name':
                case 'glpi_changes.name':
                    if (isset($data[$ID][0]['content']) && isset($data[$ID][0]['id']) && isset($data[$ID][0]['status'])) {
                        $link = $itemtype::getFormURLWithID($data[$ID][0]['id']);
                        $out = "<a id='{$itemtype}" . $data[$ID][0]['id'] . "' href=\"" . $link;
                        // Force solution tab if solved
                        if ($item = \getItemForItemtype($itemtype)) {
                            if (in_array($data[$ID][0]['status'], $item->getSolvedStatusArray())) {
                                $out .= "&amp;forcetab={$itemtype}\$2";
                            }
                        }
                        $out .= "\">";
                        $name = $data[$ID][0]['name'];
                        if ($_SESSION["glpiis_ids_visible"] || empty($data[$ID][0]['name'])) {
                            $name = sprintf(__('%1$s (%2$s)'), $name, $data[$ID][0]['id']);
                        }
                        $out .= $name . "</a>";
                        $hdecode = \Html::entity_decode_deep($data[$ID][0]['content']);
                        $content = \Toolbox::unclean_cross_side_scripting_deep($hdecode);
                        $out = sprintf(__('%1$s %2$s'), $out, \Html::showToolTip(nl2br(\Html::Clean($content)), ['applyto' => $itemtype . $data[$ID][0]['id'], 'display' => false]));
                        return $out;
                    }
                    // no break
                case 'glpi_ticketvalidations.status':
                    $out = '';
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if ($data[$ID][$k]['name']) {
                            $status = \TicketValidation::getStatus($data[$ID][$k]['name']);
                            $bgcolor = \TicketValidation::getStatusColor($data[$ID][$k]['name']);
                            $out .= (empty($out) ? '' : \Search::LBBR) . "<div style=\"background-color:" . $bgcolor . ";\">" . $status . '</div>';
                        }
                    }
                    return $out;
                case 'glpi_ticketsatisfactions.satisfaction':
                    if (\Search::$output_type == \Search::HTML_OUTPUT) {
                        return \TicketSatisfaction::displaySatisfaction($data[$ID][0]['name']);
                    }
                    break;
                case 'glpi_projects._virtual_planned_duration':
                    return \Html::timestampToString(\ProjectTask::getTotalPlannedDurationForProject($data["id"]), false);
                case 'glpi_projects._virtual_effective_duration':
                    return \Html::timestampToString(\ProjectTask::getTotalEffectiveDurationForProject($data["id"]), false);
                case 'glpi_cartridgeitems._virtual':
                    return \Cartridge::getCount($data["id"], $data[$ID][0]['alarm_threshold'], true);
                case 'glpi_printers._virtual':
                    return \Cartridge::getCountForPrinter($data["id"], \Search::$output_type != \Search::HTML_OUTPUT);
                case 'glpi_consumableitems._virtual':
                    return \Consumable::getCount($data["id"], $data[$ID][0]['alarm_threshold'], \Search::$output_type != \Search::HTML_OUTPUT);
                case 'glpi_links._virtual':
                    $out = '';
                    $link = new \Link();
                    if (($item = \getItemForItemtype($itemtype)) && $item->getFromDB($data['id'])) {
                        $data = \Link::getLinksDataForItem($item);
                        $count_display = 0;
                        foreach ($data as $val) {
                            $links = \Link::getAllLinksFor($item, $val);
                            foreach ($links as $link) {
                                if ($count_display) {
                                    $out .= \Search::LBBR;
                                }
                                $out .= $link;
                                $count_display++;
                            }
                        }
                    }
                    return $out;
                case 'glpi_reservationitems._virtual':
                    if ($data[$ID][0]['is_active']) {
                        return "<a href='reservation.php?reservationitems_id=" . $data["refID"] . "' title=\"" . __s('See planning') . "\">" . "<i class='far fa-calendar-alt' aria-hidden='true'></i><span class='sr-only'>" . __('See planning') . "</span></a>";
                    } else {
                        return "&nbsp;";
                    }
                    // no break
                case "glpi_tickets.priority":
                case "glpi_problems.priority":
                case "glpi_changes.priority":
                case "glpi_projects.priority":
                    $index = $data[$ID][0]['name'];
                    $color = $_SESSION["glpipriority_{$index}"];
                    $name = \CommonITILObject::getPriorityName($index);
                    return "<div class='priority_block' style='border-color: {$color}'>
                        <span style='background: {$color}'></span>&nbsp;{$name}
                       </div>";
            }
        }
        //// Default case
        if ($itemtype == 'Ticket' && \Session::getCurrentInterface() == 'helpdesk' && $orig_id == 8 && \Entity::getUsedConfig('anonymize_support_agents', $itemtype::getById($data['id'])->getEntityId())) {
            // Assigned groups
            return __("Helpdesk group");
        }
        // Link with plugin tables : need to know left join structure
        if (isset($table)) {
            if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table . '.' . $field, $matches)) {
                if (count($matches) == 2) {
                    $plug = $matches[1];
                    $out = \Plugin::doOneHook($plug, 'giveItem', $itemtype, $orig_id, $data, $ID);
                    if (!empty($out)) {
                        return $out;
                    }
                }
            }
        }
        $unit = '';
        if (isset($so['unit'])) {
            $unit = $so['unit'];
        }
        // Preformat items
        if (isset($so["datatype"])) {
            switch ($so["datatype"]) {
                case "itemlink":
                    $linkitemtype = \getItemTypeForTable($so["table"]);
                    $out = "";
                    $count_display = 0;
                    $separate = \Search::LBBR;
                    if (isset($so['splititems']) && $so['splititems']) {
                        $separate = \Search::LBHR;
                    }
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (isset($data[$ID][$k]['id'])) {
                            if ($count_display) {
                                $out .= $separate;
                            }
                            $count_display++;
                            $page = $linkitemtype::getFormURLWithID($data[$ID][$k]['id']);
                            $name = $data[$ID][$k]['name'];
                            if ($_SESSION["glpiis_ids_visible"] || empty($data[$ID][$k]['name'])) {
                                $name = sprintf(__('%1$s (%2$s)'), $name, $data[$ID][$k]['id']);
                            }
                            $out .= "<a id='" . $linkitemtype . "_" . $data['id'] . "_" . $data[$ID][$k]['id'] . "' href='{$page}'>" . $name . "</a>";
                        }
                    }
                    return $out;
                case "text":
                    $separate = \Search::LBBR;
                    if (isset($so['splititems']) && $so['splititems']) {
                        $separate = \Search::LBHR;
                    }
                    $out = '';
                    $count_display = 0;
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0) {
                            if ($count_display) {
                                $out .= $separate;
                            }
                            $count_display++;
                            $text = "";
                            if (isset($so['htmltext']) && $so['htmltext']) {
                                $text = \Html::clean(\Toolbox::unclean_cross_side_scripting_deep(nl2br((string) $data[$ID][$k]['name'])));
                            } else {
                                $text = nl2br((string) $data[$ID][$k]['name']);
                            }
                            if (\Search::$output_type == \Search::HTML_OUTPUT && \Toolbox::strlen($text) > $CFG_GLPI['cut']) {
                                $rand = mt_rand();
                                $popup_params = ['display' => false];
                                if (\Toolbox::strlen($text) > $CFG_GLPI['cut']) {
                                    $popup_params += ['awesome-class' => 'fa-comments', 'autoclose' => false, 'onclick' => true];
                                } else {
                                    $popup_params += ['applyto' => "text{$rand}"];
                                }
                                $out .= sprintf(__('%1$s %2$s'), "<span id='text{$rand}'>" . \Html::resume_text($text, $CFG_GLPI['cut']) . '</span>', \Html::showToolTip('<div class="fup-popup">' . $text . '</div>', $popup_params));
                            } else {
                                $out .= $text;
                            }
                        }
                    }
                    return $out;
                case "date":
                case "date_delay":
                    $out = '';
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (is_null($data[$ID][$k]['name']) && isset($so['emptylabel']) && $so['emptylabel']) {
                            $out .= (empty($out) ? '' : \Search::LBBR) . $so['emptylabel'];
                        } else {
                            $out .= (empty($out) ? '' : \Search::LBBR) . \Html::convDate($data[$ID][$k]['name']);
                        }
                    }
                    return $out;
                case "datetime":
                    $out = '';
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (is_null($data[$ID][$k]['name']) && isset($so['emptylabel']) && $so['emptylabel']) {
                            $out .= (empty($out) ? '' : \Search::LBBR) . $so['emptylabel'];
                        } else {
                            $out .= (empty($out) ? '' : \Search::LBBR) . \Html::convDateTime($data[$ID][$k]['name']);
                        }
                    }
                    return $out;
                case "timestamp":
                    $withseconds = false;
                    if (isset($so['withseconds'])) {
                        $withseconds = $so['withseconds'];
                    }
                    $withdays = true;
                    if (isset($so['withdays'])) {
                        $withdays = $so['withdays'];
                    }
                    $out = '';
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        $out .= (empty($out) ? '' : '<br>') . \Html::timestampToString($data[$ID][$k]['name'], $withseconds, $withdays);
                    }
                    return $out;
                case "email":
                    $out = '';
                    $count_display = 0;
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if ($count_display) {
                            $out .= \Search::LBBR;
                        }
                        $count_display++;
                        if (!empty($data[$ID][$k]['name'])) {
                            $out .= empty($out) ? '' : \Search::LBBR;
                            $out .= "<a href='mailto:" . \Html::entities_deep($data[$ID][$k]['name']) . "'>" . $data[$ID][$k]['name'];
                            $out .= "</a>";
                        }
                    }
                    return empty($out) ? "&nbsp;" : $out;
                case "weblink":
                    $orig_link = trim($data[$ID][0]['name'] ?? '');
                    if (!empty($orig_link) && \Toolbox::isValidWebUrl($orig_link)) {
                        // strip begin of link
                        $link = preg_replace('/https?:\\/\\/(www[^\\.]*\\.)?/', '', $orig_link);
                        $link = preg_replace('/\\/$/', '', (string) $link);
                        if (\Toolbox::strlen($link) > $CFG_GLPI["url_maxlength"]) {
                            $link = \Toolbox::substr($link, 0, $CFG_GLPI["url_maxlength"]) . "...";
                        }
                        return "<a href=\"" . \Toolbox::formatOutputWebLink($orig_link) . "\" target='_blank'>{$link}</a>";
                    }
                    return "&nbsp;";
                case "count":
                case "number":
                    $out = "";
                    $count_display = 0;
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0) {
                            if ($count_display) {
                                $out .= \Search::LBBR;
                            }
                            $count_display++;
                            if (isset($so['toadd']) && isset($so['toadd'][$data[$ID][$k]['name']])) {
                                $out .= $so['toadd'][$data[$ID][$k]['name']];
                            } else {
                                $out .= \Dropdown::getValueWithUnit($data[$ID][$k]['name'], $unit);
                            }
                        }
                    }
                    return $out;
                case "decimal":
                    $out = "";
                    $count_display = 0;
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0) {
                            if ($count_display) {
                                $out .= \Search::LBBR;
                            }
                            $count_display++;
                            if (isset($so['toadd']) && isset($so['toadd'][$data[$ID][$k]['name']])) {
                                $out .= $so['toadd'][$data[$ID][$k]['name']];
                            } else {
                                $out .= \Dropdown::getValueWithUnit($data[$ID][$k]['name'], $unit, $CFG_GLPI["decimal_number"]);
                            }
                        }
                    }
                    return $out;
                case "bool":
                    $out = "";
                    $count_display = 0;
                    for ($k = 0; $k < $data[$ID]['count']; $k++) {
                        if (strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0) {
                            if ($count_display) {
                                $out .= \Search::LBBR;
                            }
                            $count_display++;
                            $out .= \Dropdown::getYesNo($data[$ID][$k]['name']);
                        }
                    }
                    return $out;
                case "itemtypename":
                    if ($obj = \getItemForItemtype($data[$ID][0]['name'])) {
                        return $obj->getTypeName();
                    }
                    return "";
                case "language":
                    if (isset($CFG_GLPI['languages'][$data[$ID][0]['name']])) {
                        return $CFG_GLPI['languages'][$data[$ID][0]['name']][0];
                    }
                    return __('Default value');
                case 'progressbar':
                    if (!isset($progressbar_data)) {
                        $bar_color = 'green';
                        $progressbar_data = ['percent' => $data[$ID][0]['name'], 'percent_text' => $data[$ID][0]['name'], 'color' => $bar_color, 'text' => ''];
                    }
                    $out = "{$progressbar_data['text']}<div class='center' style='background-color: #ffffff; width: 100%;
                        border: 1px solid #9BA563; position: relative;' >";
                    $out .= "<div style='position:absolute;'>&nbsp;{$progressbar_data['percent_text']}%</div>";
                    $out .= "<div class='center' style='background-color: {$progressbar_data['color']};
                        width: {$progressbar_data['percent']}%; height: 12px' ></div>";
                    $out .= "</div>";
                    return $out;
                    break;
            }
        }
        // Manage items with need group by / group_concat
        $out = "";
        $count_display = 0;
        $separate = \Search::LBBR;
        if (isset($so['splititems']) && $so['splititems']) {
            $separate = \Search::LBHR;
        }
        for ($k = 0; $k < $data[$ID]['count']; $k++) {
            if (strlen(trim((string) ($data[$ID][$k]['name'] ?? ''))) > 0) {
                if ($count_display) {
                    $out .= $separate;
                }
                $count_display++;
                // Get specific display if available
                if (isset($table)) {
                    $itemtype = \getItemTypeForTable($table);
                    if ($item = \getItemForItemtype($itemtype)) {
                        $tmpdata = $data[$ID][$k];
                        // Copy name to real field
                        $tmpdata[$field] = $data[$ID][$k]['name'];
                        $specific = $item->getSpecificValueToDisplay($field, $tmpdata, ['html' => true, 'searchopt' => $so, 'raw_data' => $data]);
                    }
                }
                if (!empty($specific)) {
                    $out .= $specific;
                } else {
                    if (isset($so['toadd']) && isset($so['toadd'][$data[$ID][$k]['name']])) {
                        $out .= $so['toadd'][$data[$ID][$k]['name']];
                    } else {
                        // Empty is 0 or empty
                        if (empty($split[0]) && isset($so['emptylabel'])) {
                            $out .= $so['emptylabel'];
                        } else {
                            // Trans field exists
                            if (isset($data[$ID][$k]['trans']) && !empty($data[$ID][$k]['trans'])) {
                                $out .= $data[$ID][$k]['trans'];
                            } else {
                                $out .= $data[$ID][$k]['name'];
                            }
                        }
                    }
                }
            }
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $PDF_TABLE .= "<th {$options}>";
                $PDF_TABLE .= \Html::clean($value);
                $PDF_TABLE .= "</th>
";
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
                global $SYLK_HEADER, $SYLK_SIZE;
                $SYLK_HEADER[$num] = LegacyOutput::sylk_clean($value);
                $SYLK_SIZE[$num] = \Toolbox::strlen($SYLK_HEADER[$num]);
                break;
            case \Search::CSV_OUTPUT:
                //CSV
                $out = "\"" . LegacyOutput::csv_clean($value) . "\"" . $_SESSION["glpicsv_delimiter"];
                break;
            default:
                $class = "";
                if ($issort) {
                    $class = "order_{$order}";
                }
                $out = "<th {$options} class='{$class}'>";
                if (!empty($linkto)) {
                    $out .= "<a href=\"{$linkto}\">";
                }
                $out .= $value;
                if (!empty($linkto)) {
                    $out .= "</a>";
                }
                $out .= "</th>
";
        }
        $num++;
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $value = preg_replace('/' . \Search::LBBR . '/', '<br>', $value);
                $value = preg_replace('/' . \Search::LBHR . '/', '<hr>', (string) $value);
                $PDF_TABLE .= "<td {$extraparam} valign='top'>";
                $PDF_TABLE .= \Html::weblink_extract(\Html::clean($value));
                $PDF_TABLE .= "</td>
";
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
                global $SYLK_ARRAY, $SYLK_SIZE;
                $value = \Html::weblink_extract(\Html::clean($value));
                $value = preg_replace('/' . \Search::LBBR . '/', '<br>', $value);
                $value = preg_replace('/' . \Search::LBHR . '/', '<hr>', (string) $value);
                $SYLK_ARRAY[$row][$num] = LegacyOutput::sylk_clean($value);
                $SYLK_SIZE[$num] = max($SYLK_SIZE[$num], \Toolbox::strlen($SYLK_ARRAY[$row][$num]));
                break;
            case \Search::CSV_OUTPUT:
                //csv
                $value = preg_replace('/' . \Search::LBBR . '/', '<br>', $value);
                $value = preg_replace('/' . \Search::LBHR . '/', '<hr>', (string) $value);
                $value = \Html::weblink_extract(\Html::clean($value));
                $out = "\"" . LegacyOutput::csv_clean($value) . "\"" . $_SESSION["glpicsv_delimiter"];
                break;
            default:
                global $CFG_GLPI;
                $out = "<td {$extraparam} valign='top'>";
                if (!preg_match('/' . \Search::LBHR . '/', $value)) {
                    $values = preg_split('/' . \Search::LBBR . '/i', $value);
                    $line_delimiter = '<br>';
                } else {
                    $values = preg_split('/' . \Search::LBHR . '/i', $value);
                    $line_delimiter = '<hr>';
                }
                if (count($values) > 1 && \Toolbox::strlen($value) > $CFG_GLPI['cut']) {
                    $value = '';
                    foreach ($values as $v) {
                        $value .= $v . $line_delimiter;
                    }
                    $value = preg_replace('/' . \Search::LBBR . '/', '<br>', $value);
                    $value = preg_replace('/' . \Search::LBHR . '/', '<hr>', (string) $value);
                    $value = '<div class="fup-popup">' . $value . '</div>';
                    $valTip = "&nbsp;" . \Html::showToolTip($value, ['awesome-class' => 'fa-comments', 'display' => false, 'autoclose' => false, 'onclick' => true]);
                    $out .= $values[0] . $valTip;
                } else {
                    $value = preg_replace('/' . \Search::LBBR . '/', '<br>', $value);
                    $value = preg_replace('/' . \Search::LBHR . '/', '<hr>', (string) $value);
                    $out .= $value;
                }
                $out .= "</td>
";
        }
        $num++;
        return $out;
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
        if (strlen($message) == 0) {
            $message = __('No item found');
        }
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
            case \Search::SYLK_OUTPUT:
                //sylk
            case \Search::CSV_OUTPUT:
                //csv
                break;
            default:
                $out = "<div class='center b'>{$message}</div>
";
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                if ($type == \Search::PDF_OUTPUT_LANDSCAPE) {
                    $pdf = new \GLPIPDF('L', 'mm', 'A4', true, 'UTF-8', false);
                } else {
                    $pdf = new \GLPIPDF('P', 'mm', 'A4', true, 'UTF-8', false);
                }
                if ($count !== null) {
                    $pdf->setTotalCount($count);
                }
                $pdf->SetCreator('ITSM-NG');
                $pdf->SetAuthor('ITSM-NG');
                $pdf->SetTitle($title);
                $pdf->SetHeaderData('', '', $title, '');
                $font = 'helvetica';
                //$subsetting = true;
                $fontsize = 8;
                if (isset($_SESSION['glpipdffont']) && $_SESSION['glpipdffont']) {
                    $font = $_SESSION['glpipdffont'];
                    //$subsetting = false;
                }
                $pdf->setHeaderFont([$font, 'B', $fontsize]);
                $pdf->setFooterFont([$font, 'B', $fontsize]);
                //set margins
                $pdf->SetMargins(10, 15, 10);
                $pdf->SetHeaderMargin(10);
                $pdf->SetFooterMargin(10);
                //set auto page breaks
                $pdf->SetAutoPageBreak(true, 15);
                // For standard language
                //$pdf->setFontSubsetting($subsetting);
                // set font
                $pdf->SetFont($font, '', $fontsize);
                $pdf->AddPage();
                $PDF_TABLE .= '</table>';
                $pdf->writeHTML($PDF_TABLE, true, false, true, false, '');
                $pdf->Output('glpi.pdf', 'I');
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
                global $SYLK_HEADER, $SYLK_ARRAY, $SYLK_SIZE;
                // largeurs des colonnes
                foreach ($SYLK_SIZE as $num => $val) {
                    $out .= "F;W" . $num . " " . $num . " " . min(50, $val) . "
";
                }
                $out .= "
";
                // Header
                foreach ($SYLK_HEADER as $num => $val) {
                    $out .= "F;SDM4;FG0C;" . ($num == 1 ? "Y1;" : "") . "X{$num}
";
                    $out .= "C;N;K\"{$val}\"
";
                    $out .= "
";
                }
                // Datas
                foreach ($SYLK_ARRAY as $row => $tab) {
                    foreach ($tab as $num => $val) {
                        $out .= "F;P3;FG0L;" . ($num == 1 ? "Y" . $row . ";" : "") . "X{$num}
";
                        $out .= "C;N;K\"{$val}\"
";
                    }
                }
                $out .= "E
";
                break;
            case \Search::CSV_OUTPUT:
                //csv
                break;
            default:
                $out = "</table></div>
";
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $PDF_TABLE = "<table cellspacing=\"0\" cellpadding=\"1\" border=\"1\" aria-label='Exported PDF Table'>";
                break;
            case \Search::SYLK_OUTPUT:
                // Sylk
                global $SYLK_ARRAY, $SYLK_HEADER, $SYLK_SIZE;
                $SYLK_ARRAY = [];
                $SYLK_HEADER = [];
                $SYLK_SIZE = [];
                // entetes HTTP
                header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
                header('Pragma: private');
                /// IE BUG + SSL
                header('Cache-control: private, must-revalidate');
                /// IE BUG + SSL
                header("Content-disposition: filename=glpi.slk");
                header('Content-type: application/octetstream');
                // entete du fichier
                echo "ID;PGLPI_EXPORT
";
                // ID;Pappli
                echo "
";
                // formats
                echo "P;PGeneral
";
                echo "P;P#,##0.00
";
                // P;Pformat_1 (reels)
                echo "P;P#,##0
";
                // P;Pformat_2 (entiers)
                echo "P;P@
";
                // P;Pformat_3 (textes)
                echo "
";
                // polices
                echo "P;EArial;M200
";
                echo "P;EArial;M200
";
                echo "P;EArial;M200
";
                echo "P;FArial;M200;SB
";
                echo "
";
                // nb lignes * nb colonnes
                echo "B;Y" . $rows;
                echo ";X" . $cols . "
";
                // B;Yligmax;Xcolmax
                echo "
";
                break;
            case \Search::CSV_OUTPUT:
                // csv
                header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
                header('Pragma: private');
                /// IE BUG + SSL
                header('Cache-control: private, must-revalidate');
                /// IE BUG + SSL
                header("Content-disposition: filename=glpi.csv");
                header('Content-type: text/csv');
                // zero width no break space (for excel)
                echo "﻿";
                break;
            default:
                if ($fixed) {
                    $out = "<div class='center'><table border='0' class='tab_cadre_fixehov' aria-label='default'>
";
                } else {
                    $out = "<div class='center'><table border='0' class='tab_cadrehov' aria-label='default'>
";
                }
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $PDF_TABLE .= "<thead>";
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
            case \Search::CSV_OUTPUT:
                //csv
                break;
            default:
                $out = "<thead>";
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $PDF_TABLE .= "</thead>";
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
            case \Search::CSV_OUTPUT:
                //csv
                break;
            default:
                $out = "</thead>";
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $style = "";
                if ($odd) {
                    $style = " style=\"background-color:#DDDDDD;\" ";
                }
                $PDF_TABLE .= "<tr {$style} nobr=\"true\">";
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
            case \Search::CSV_OUTPUT:
                //csv
                break;
            default:
                $class = " class='tab_bg_2" . ($is_deleted ? '_2' : '') . "' ";
                if ($odd) {
                    $class = " class='tab_bg_1" . ($is_deleted ? '_2' : '') . "' ";
                }
                $out = "<tr {$class}>";
        }
        return $out;
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
        $out = "";
        switch ($type) {
            case \Search::PDF_OUTPUT_LANDSCAPE:
                //pdf
            case \Search::PDF_OUTPUT_PORTRAIT:
                global $PDF_TABLE;
                $PDF_TABLE .= '</tr>';
                break;
            case \Search::SYLK_OUTPUT:
                //sylk
                break;
            case \Search::CSV_OUTPUT:
                //csv
                $out = "
";
                break;
            default:
                $out = "</tr>";
        }
        return $out;
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
        $value = str_replace("\"", "''", $value);
        $value = \Html::clean($value, true, 2, false);
        $value = str_replace("&gt;", ">", $value);
        $value = str_replace("&lt;", "<", $value);
        return $value;
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
        $value = preg_replace('/\\x0A/', ' ', $value);
        $value = preg_replace('/\\x0D/', '', (string) $value);
        $value = str_replace("\"", "''", $value);
        $value = \Html::clean($value);
        $value = str_replace("
", " | ", $value);
        $value = str_replace("&gt;", ">", $value);
        $value = str_replace("&lt;", "<", $value);
        return $value;
    }
    /**
     * @since 0.84
     *
     * @param string $pattern
     * @param string $subject
     **/
    public static function explodeWithID($pattern, $subject)
    {
        $tab = explode($pattern, $subject);
        if (isset($tab[1]) && !is_numeric($tab[1])) {
            // Report $ to tab[0]
            if (preg_match('/^(\\$*)(.*)/', $tab[1], $matchs)) {
                if (isset($matchs[2]) && is_numeric($matchs[2])) {
                    $tab[1] = $matchs[2];
                    $tab[0] .= $matchs[1];
                }
            }
        }
        // Manage NULL value
        if ($tab[0] == \Search::NULLVALUE) {
            $tab[0] = null;
        }
        return $tab;
    }
}
