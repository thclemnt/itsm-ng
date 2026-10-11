<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

use itsmng\Search\SearchOption;
use User;

/** Sort on typed values rather than the packed strings used for display. */
final class SortBuilder
{
    /** @return list<SelectExpression> */
    public static function fields(string $type, int $id, Dialect $dialect): array
    {
        $options = SearchOption::getOptions($type);
        $ref = new FieldReference($type, $options[$id]);
        if ($ref->table === 'glpi_users' && $ref->field === 'name' && $type !== 'User') {
            $columns = $_SESSION['glpinames_format'] == User::FIRSTNAME_BEFORE
                ? ['firstname', 'realname', 'name'] : ['realname', 'firstname', 'name'];
            return array_map(
                fn ($column) => new SelectExpression($dialect->column($ref->table, $column, $ref->alias), '__sort_' . $column),
                $columns
            );
        }
        if ($ref->table === 'glpi_ipaddresses' && $ref->field === 'name') {
            // Numeric IPv4/IPv6 ordering without INET_ATON or text parsing.
            return array_map(
                fn ($column) => new SelectExpression($dialect->column($ref->table, $column, $ref->alias), '__sort_' . $column),
                ['version', 'binary_0', 'binary_1', 'binary_2', 'binary_3']
            );
        }
        $fields = ProjectionBuilder::fields($type, $id);
        if ($ref->table === 'glpi_auth_tables' && $ref->field === 'name') {
            return [
                $fields->get('ITEM_' . $type . '_' . $id),
                $fields->get('ITEM_' . $type . '_' . $id . '_' . $id . '_ldapname'),
                $fields->get('ITEM_' . $type . '_' . $id . '_mailname'),
            ];
        }
        return [$fields->get('ITEM_' . $type . '_' . $id)];
    }
}
