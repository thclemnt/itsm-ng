<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

/** Separate eligibility/counting from the joins needed only for display. */
final class SearchPlan
{
    public function __construct(
        public readonly string $countSql,
        private string $pageSelect,
        private string $pageFrom,
        private string $where,
        private string $group,
        private string $pageOrder,
        private string $hydrateSelect,
        private string $table,
        private string $hydrateJoins,
        private string $hydrateOrder,
    ) {
    }

    public function pageSql(int $offset, int $limit): string
    {
        $sql = 'SELECT * FROM (' . $this->pageSelect . $this->pageFrom . $this->where . $this->group
            . ') AS `__eligible`' . $this->pageOrder;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset);
        }
        return $sql;
    }

    public function sql(int $offset, int $limit): string
    {
        return $this->hydrateSelect . ' FROM (' . $this->pageSql($offset, $limit) . ') AS `__search_page`'
            . ' INNER JOIN ' . $this->table . ' ON (' . $this->table . '.`id` = `__search_page`.`id`)'
            . $this->hydrateJoins . ' GROUP BY ' . $this->table . '.`id`' . $this->hydrateOrder;
    }
}
