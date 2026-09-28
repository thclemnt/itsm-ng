<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

use itsmng\Search\SearchOption;

/** A criteria tree is a tree of matching ID sets, independent of display joins. */
final class TwoPhasePlanner
{
    public function __construct(private \DBAdapter $db)
    {
    }

    public function supports(array $data): bool
    {
        global $CFG_GLPI;
        if (!empty($data['search']['disable_two_phase_search']) || !empty($CFG_GLPI['disable_two_phase_search'])) {
            return false;
        }
        if (isset($CFG_GLPI['union_search_type'][$data['itemtype']]) || isPluginItemType($data['itemtype']) || !empty($data['search']['as_map'])) {
            return false;
        }
        return !$data['search']['all_search'] && !$data['search']['view_search'];
    }

    public function plan(array &$data): SearchPlan
    {
        $d = new Dialect($this->db);
        $type = $data['itemtype'];
        $table = JoinBuilder::getOrigTableName($type);
        $quoted = $d->quote($table);
        $options = SearchOption::getOptions($type);
        $sort = isset($options[$data['search']['sort']]['field']) ? (int)$data['search']['sort'] : null;
        $direction = $data['search']['order'] === 'DESC' ? 'DESC' : 'ASC';
        $linked = [$table];
        $baseJoins = JoinBuilder::addDefaultJoin($type, $table, $linked);
        $system = CriteriaBuilder::addDefaultWhere($type);
        $conditions = $system === '' ? [] : ['(' . $system . ')'];
        if ($data['item']->maybeDeleted()) {
            $conditions[] = $d->quote($table . '.is_deleted') . ' = ' . $this->db->quoteValue((int)$data['search']['is_deleted']);
        }
        if ($data['item']->maybeTemplate()) {
            $conditions[] = $d->quote($table . '.is_template') . " = '0'";
        }
        if ($data['item']->isEntityAssign() && $data['item']->isField('entities_id')) {
            $entities = getEntitiesRestrictRequest('', $table, '', '', $data['item']->maybeRecursive() && $data['item']->isField('is_recursive'));
            if (trim($entities) !== '') {
                $conditions[] = $entities;
            }
        }
        $predicate = $this->criteria($data['search']['criteria'], $data, $table);
        if ($predicate !== '') {
            $conditions[] = $predicate;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $from = ' FROM ' . $quoted . $baseJoins;
        $group = ' GROUP BY ' . $d->quote($table . '.id');
        $count = 'SELECT COUNT(*) FROM (SELECT ' . $d->quote($table . '.id') . $from . $where . $group . ') AS matched_ids';

        $sortJoins = $sort === null ? '' : $this->join($type, $table, $sort, $options, $linked);
        $sortExpressions = $sort === null
            ? [new SelectExpression($d->quote($table . '.id'), '__sort')]
            : SortBuilder::fields($type, $sort, $d);
        $page = new SelectList();
        $page->add($d->quote($table . '.id'), 'id');
        $pageKeys = $hydrateKeys = [];
        foreach ($sortExpressions as $i => $expression) {
            $alias = '__sort' . ($i ? '_' . $i : '');
            $page->add($expression->sql, $alias, $expression->aggregate, $expression->boolean);
            $pageKeys[] = $d->quote($alias);
            $hydrateKeys[] = 'MIN(' . $d->quote('__search_page.' . $alias) . ')';
        }
        $pageOrder = $this->order($pageKeys, $d->quote('id'), $direction);

        $display = ProjectionBuilder::defaults($type);
        $display->add($d->quote($table . '.id'), 'id')->add($d->literal($_SESSION['glpiname']), 'currentuser');
        $displayLinked = [$table];
        $displayJoins = JoinBuilder::addDefaultJoin($type, $table, $displayLinked);
        foreach ($data['toview'] as $id) {
            $display->merge(ProjectionBuilder::fields($type, (int)$id));
            $displayJoins .= $this->join($type, $table, (int)$id, $options, $displayLinked);
        }
        $data['meta_toview'] = [];
        CriteriaBuilder::constructAdditionalSqlForMetacriteria($data['search']['criteria'], $display, $displayJoins, $displayLinked, $data);
        $hydrateOrder = $this->order($hydrateKeys, $d->quote($table . '.id'), $direction);
        return new SearchPlan($count, 'SELECT ' . $page->sql($d, true), $from . $sortJoins, $where, $group, $pageOrder, 'SELECT ' . $display->sql($d, true), $quoted, $displayJoins, $hydrateOrder);
    }

    private function order(array $keys, string $id, string $direction): string
    {
        // Total ordering makes adjacent pages stable even when names are equal.
        $parts = [];
        foreach ($keys as $sort) {
            $parts[] = '(' . $sort . ' IS NULL) ' . ($direction === 'ASC' ? 'DESC' : 'ASC');
            $parts[] = $sort . ' ' . $direction;
        }
        $parts[] = $id . ' ASC';
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function join(string $type, string $table, int $id, array $options, array &$linked): string
    {
        $o = $options[$id];
        return JoinBuilder::addLeftJoin($type, $table, $linked, $o['table'], $o['linkfield'], 0, 0, $o['joinparams'] ?? [], $o['field']) ?? '';
    }

    private function criteria(array $criteria, array $data, string $root): string
    {
        $groups = [];
        $conjunction = [];
        foreach ($criteria as $criterion) {
            $link = $criterion['link'] ?? 'AND';
            if (!in_array($link, ['AND', 'OR', 'AND NOT', 'OR NOT'], true)) {
                $link = 'AND';
            }
            $negative = str_contains($link, 'NOT');
            if (isset($criterion['criteria'])) {
                $sql = $this->criteria($criterion['criteria'], $data, $root);
            } else {
                if (!isset($criterion['value']) || strlen((string)$criterion['value']) === 0) {
                    continue;
                }
                $search = $criterion['searchtype'];
                if (in_array($search, ['notequals', 'notcontains'], true)) {
                    $negative = !$negative;
                    $criterion['searchtype'] = $search === 'notequals' ? 'equals' : 'contains';
                }
                $sql = $this->matchingIds($criterion, $data, $root);
            }
            if ($sql === '') {
                continue;
            }
            $sql = ($negative ? 'NOT ' : '') . '(' . $sql . ')';
            // Preserve saved-search SQL precedence: AND binds before OR.
            // Explicitly nested UI groups recurse above.
            if (str_starts_with($link, 'OR') && $conjunction) {
                $groups[] = '(' . implode(' AND ', $conjunction) . ')';
                $conjunction = [];
            }
            $conjunction[] = $sql;
        }
        if ($conjunction) {
            $groups[] = '(' . implode(' AND ', $conjunction) . ')';
        }
        return $groups ? '(' . implode(' OR ', $groups) . ')' : '';
    }

    private function matchingIds(array $criterion, array $data, string $root): string
    {
        $d = new Dialect($this->db);
        $meta = !empty($criterion['meta']);
        $type = $meta ? $criterion['itemtype'] : $data['itemtype'];
        $options = SearchOption::getOptions($type);
        $id = (int)$criterion['field'];
        if (!isset($options[$id]['table'])) {
            throw new \InvalidArgumentException('Unknown search criterion');
        }
        $o = $options[$id];
        if (!$meta && (new FieldReference($type, $o))->alias === $root && empty($o['joinparams']) && empty($o['usehaving'])) {
            // A scalar on the root row needs no join or ID subquery. Keep NULL
            // outside the matching set so negation still includes missing values.
            $filter = CriteriaBuilder::addWhere('', false, $type, $id, $criterion['searchtype'], $criterion['value']);
            if ($filter === false || trim($filter) === '') {
                throw new \InvalidArgumentException('Unsupported search criterion');
            }
            return 'COALESCE((' . $filter . '), FALSE)';
        }
        $linked = [$root];
        $from = ' FROM ' . $d->quote($root) . JoinBuilder::addDefaultJoin($data['itemtype'], $root, $linked);
        if ($meta) {
            $from .= JoinBuilder::addMetaLeftJoin($data['itemtype'], $type, $linked, $o['joinparams'] ?? []);
            $from .= JoinBuilder::addLeftJoin($type, $type::getTable(), $linked, $o['table'], $o['linkfield'], 1, $type, $o['joinparams'] ?? [], $o['field']);
        } else {
            $from .= $this->join($type, $root, $id, $options, $linked);
        }
        $key = $d->quote($root . '.id');
        if (isset($o['usehaving'])) {
            $projection = ProjectionBuilder::fields($type, $id, $meta, $meta ? $type : 0);
            $projection->add($key, '__id');
            $filter = CriteriaBuilder::addHaving('', false, $type, $id, $criterion['searchtype'], $criterion['value']);
            $query = 'SELECT `__id` FROM (SELECT ' . $projection->sql($d, true) . $from . ' GROUP BY ' . $key . ') AS criterion_values WHERE ' . $filter;
        } else {
            $filter = CriteriaBuilder::addWhere('', false, $type, $id, $criterion['searchtype'], $criterion['value'], $meta);
            if ($filter === false || trim($filter) === '') {
                throw new \InvalidArgumentException('Unsupported search criterion');
            }
            // An explicit aggregate keeps each multivalue relation as its own
            // ID set. Otherwise MySQL can flatten dozens of IN subqueries into
            // one enormous join and spend minutes enumerating join orders.
            $query = 'SELECT MAX(' . $key . ')' . $from . ' WHERE ' . $filter . ' GROUP BY ' . $key;
        }
        return $key . ' IN (' . $query . ')';
    }
}
