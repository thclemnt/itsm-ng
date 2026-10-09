<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Search\Provider\SelectList;

/** Ordinary Search fixture: plugin expressions use a real core table through their own join. */
final class SearchSortPluginFixture
{
    public static bool $rawProjection = false;
    public static array $orders = [];
    public static array $joins = [];
}

function plugin_searchsort_addSelect($itemtype, $id, $name)
{
    global $DB;

    $column = $DB->quoteName('glpi_plugin_searchsort_values_computers_id.serial');
    $alias = 'ITEM_' . $name;
    return SearchSortPluginFixture::$rawProjection
        ? $column . ' AS ' . $DB->quoteName($alias) . ', '
        : (new SelectList())->add($column, $alias);
}

function plugin_searchsort_addOrderBy($itemtype, $id, $order, $name): string
{
    global $DB;

    SearchSortPluginFixture::$orders[] = [$itemtype, $id, $order, $name];
    // The plugin owns sorting independently of its display expression.
    $direction = $order === 'ASC' ? 'DESC' : 'ASC';
    return ' ORDER BY ' . $DB->quoteName('ITEM_' . $name) . ' ' . $direction
        . ', ' . $DB->quoteName('glpi_computers.id') . ' ASC';
}

function plugin_searchsort_addLeftJoin($itemtype, $reference, $table, $linkfield, &$linked): string
{
    global $DB;

    SearchSortPluginFixture::$joins[] = [$itemtype, $reference, $table, $linkfield];
    $alias = 'glpi_plugin_searchsort_values_computers_id';
    return ' LEFT JOIN ' . $DB->quoteName('glpi_computers') . ' AS ' . $DB->quoteName($alias)
        . ' ON (' . $DB->quoteName($reference . '.id') . ' = ' . $DB->quoteName($alias . '.id') . ')';
}
