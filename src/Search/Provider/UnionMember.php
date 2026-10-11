<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

use CommonDBTM;
use itsmng\Database\EntityRegistry;

/** Resolve a virtual asset field before rendering a concrete union member. */
final class UnionMember
{
    private ?array $columns;

    public function __construct(private string $virtualTable, private CommonDBTM $item)
    {
        $table = $item::getTable();
        $this->columns = isset(EntityRegistry::tables()[$table])
            ? EntityRegistry::columnNames($table) : null;
    }

    public function applies(string $table): bool
    {
        return $table === $this->virtualTable;
    }

    public function column(string $table, string $field, string $alias, Dialect $dialect): string
    {
        if (!$this->applies($table)) {
            return $dialect->column($table, $field, $alias);
        }
        $field = $field === 'name' ? $this->item::getNameField() : $field;
        $present = $this->columns === null
            ? $this->item->isField($field) : in_array($field, $this->columns, true);
        // Virtual inventory fields have historically displayed empty text when
        // the concrete asset has no such property (for example Software).
        return $present ? $dialect->column($this->item::getTable(), $field, $alias) : $dialect->literal('');
    }
}
