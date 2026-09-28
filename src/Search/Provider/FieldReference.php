<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

/** Identifiers for an option, including composite foreign keys and meta joins. */
final class FieldReference
{
    public readonly string $table;
    public readonly string $field;
    public readonly string $suffix;
    public readonly string $relationSuffix;
    public readonly string $metaSuffix;
    public readonly string $alias;

    public function __construct(string $itemtype, array $option, bool $meta = false, $meta_type = 0)
    {
        global $CFG_GLPI;
        $table = $option["table"];
        $field = $option["field"];
        $addtable = "";
        $addtable2 = "";
        $complexjoin = '';
        if (isset($option['joinparams'])) {
            $complexjoin = JoinBuilder::computeComplexJoinID($option['joinparams']);
        }
        $is_fkey_composite_on_self = \getTableNameForForeignKeyField($option["linkfield"]) == $table && $option["linkfield"] != \getForeignKeyFieldForTable($table);
        $orig_table = JoinBuilder::getOrigTableName($itemtype);
        if ((($is_fkey_composite_on_self || $table != $orig_table) && (!isset($CFG_GLPI["union_search_type"][$itemtype]) || $CFG_GLPI["union_search_type"][$itemtype] != $table) || !empty($complexjoin)) && $option["linkfield"] != \getForeignKeyFieldForTable($table)) {
            $addtable .= "_" . $option["linkfield"];
        }
        if (!empty($complexjoin)) {
            $addtable .= "_" . $complexjoin;
            $addtable2 .= "_" . $complexjoin;
        }
        $addmeta = "";
        if ($meta) {
            if ($meta_type::getTable() != $table) {
                $addmeta = "_" . $meta_type;
                $addtable .= $addmeta;
                $addtable2 .= $addmeta;
            }
        }
        $this->table = $table;
        $this->field = $field;
        $this->suffix = $addtable;
        $this->relationSuffix = $addtable2;
        $this->metaSuffix = $addmeta;
        $this->alias = $table . $addtable;
    }
}
