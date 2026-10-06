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

namespace itsmng\Search\Provider;

use itsmng\Search\SearchOption;

final class ProjectionBuilder
{
    public static function addDefaultSelect($itemtype)
    {
        global $DB;
        $sql = self::defaults($itemtype)->sql(new Dialect($DB));
        return $sql === '' ? '' : $sql . ', ';
    }

    public static function defaults(string $itemtype): SelectList
    {
        global $DB;
        $d = new Dialect($DB);
        $fields = new SelectList();
        $table = JoinBuilder::getOrigTableName($itemtype);
        if ($itemtype === 'FieldUnicity') {
            $fields->add($d->quote('glpi_fieldunicities.itemtype'), 'ITEMTYPE');
        }
        if ($plug = isPluginItemType($itemtype)) {
            $fields->addHookResult(\Plugin::doOneHook($plug['plugin'], 'addDefaultSelect', $itemtype), $d);
        }
        if ($table === 'glpi_entities') {
            return $fields->add($d->quote($table . '.id'), 'entities_id')->add('1', 'is_recursive');
        }
        $item = $itemtype === 'AllAssets' ? null : getItemForItemtype($itemtype);
        if ($item && $item->maybeRecursive()) {
            foreach (['entities_id', 'is_recursive'] as $column) {
                if ($item->isField($column)) {
                    $fields->add($d->column($table, $column), $column);
                }
            }
        }
        return $fields;
    }

    public static function addSelect($itemtype, $ID, $meta = 0, $meta_type = 0)
    {
        global $DB;
        $sql = self::fields($itemtype, (int)$ID, (bool)$meta, $meta_type)->sql(new Dialect($DB));
        return $sql === '' ? '' : $sql . ', ';
    }

    public static function fields(string $itemtype, int $ID, bool $meta = false, $meta_type = 0, ?UnionMember $member = null, ?string $subjectType = null): SelectList
    {
        global $DB, $CFG_GLPI;
        $searchopt = & SearchOption::getOptions($itemtype);
        $ref = new FieldReference($itemtype, $searchopt[$ID], $meta, $meta_type);
        $table = $ref->table;
        $field = $ref->field;
        $addtable = $ref->suffix;
        $addtable2 = $ref->relationSuffix;
        $addmeta = $ref->metaSuffix;
        $NAME = "ITEM_{$itemtype}_{$ID}";
        $d = new Dialect($DB);
        $option = $searchopt[$ID];
        $alias = $table . $addtable;
        $column = fn (string $name) => $member
            ? $member->column($table, $name, $alias, $d) : $d->column($table, $name, $alias);
        $value = $column($field);
        $id = $column('id');
        $fields = new SelectList();
        $many = $meta || !empty($option['forcegroupby']);
        $add = function (string $expression, string $suffix = '', bool $aggregate = false) use ($fields, $NAME): void {
            $fields->add($expression, $NAME . $suffix, $aggregate);
        };
        $packed = fn (string $expression, bool $nullable = true) => $d->concat(
            $nullable ? $d->coalesceText($expression, \Search::NULLVALUE) : $expression,
            $d->literal(\Search::SHORTSEP),
            $id
        );
        $translation = $d->coalesceText($d->quote($alias . '_trans_' . $field . '.value'), \Search::NULLVALUE);
        $translated = \Session::haveTranslations(getItemTypeForTable($table), $field);
        foreach ($option['additionalfields'] ?? [] as $key) {
            $expression = $column($key);
            if ($many) {
                $expression = $d->coalesceText($d->aggregate($packed($expression), true, [$id => 'ASC']), \Search::NULLVALUE . \Search::SHORTSEP);
            }
            $add($expression, '_' . $key, $many);
        }
        if (str_starts_with($field, '_virtual')) {
            return $fields;
        }
        if ($plug = isPluginItemType($itemtype)) {
            $raw = \Plugin::doOneHook($plug['plugin'], 'addSelect', $itemtype, $ID, "{$itemtype}_{$ID}");
            if ($raw) {
                return $fields->addHookResult($raw, $d);
            }
        }
        if (preg_match('/^glpi_plugin_([a-z0-9]+)/', $table, $matches)) {
            $raw = \Plugin::doOneHook($matches[1], 'addSelect', $itemtype, $ID, "{$itemtype}_{$ID}");
            if ($raw) {
                return $fields->addHookResult($raw, $d);
            }
        }
        switch ($table . '.' . $field) {
            case 'glpi_users.name':
                if ($itemtype === 'User') {
                    break;
                }
                if (!empty($option['forcegroupby'])) {
                    $add($d->aggregate($id), '', true);
                    $before = $option['joinparams']['beforejoin'] ?? [];
                    if (in_array($itemtype, ['Ticket', 'Problem', 'Change'], true) && in_array($before['table'] ?? '', ['glpi_tickets_users', 'glpi_problems_users', 'glpi_changes_users'], true)) {
                        $actor = $before['table'] . '_' . JoinBuilder::computeComplexJoinID($before['joinparams']) . $addmeta;
                        $add($d->aggregate($d->concat($d->quote($actor . '.users_id'), $d->literal(' '), $d->quote($actor . '.alternative_email'))), '_2', true);
                    }
                } else {
                    $add($value);
                    foreach (['realname', 'id', 'firstname'] as $key) {
                        $add($column($key), '_' . $key);
                    }
                }
                return $fields;
            case 'glpi_softwarelicenses.number':
                $a = $meta ? $table . $addtable2 : $alias;
                $v = $d->quote($a . '.' . $field);
                $key = $d->quote($a . '.id');
                $add("FLOOR(SUM($v) * COUNT(DISTINCT $key) / NULLIF(COUNT($key), 0))", '', true);
                $add("MIN($v)", '_min', true);
                return $fields;
            case 'glpi_profiles.name':
            case 'glpi_entities.completename':
                if ($itemtype === 'User' && in_array($ID, [20, 80], true)) {
                    $relation = 'glpi_profiles_users' . ($meta ? '_' . $meta_type : '');
                    $order = [$d->quote($relation . '.id') => 'ASC'];
                    $add($d->aggregate($value, false, $order), '', true);
                    foreach ([$ID === 20 ? 'entities_id' : 'profiles_id', 'is_recursive', 'is_dynamic'] as $key) {
                        $add($d->aggregate($d->column('glpi_profiles_users', $key, $relation), false, $order), '_' . $key, true);
                    }
                    return $fields;
                }
                break;
            case 'glpi_auth_tables.name':
                $userOptions = SearchOption::getOptions('User');
                $add($d->quote('glpi_users.authtype'));
                $add($d->quote('glpi_users.auths_id'), '_auths_id');
                $ldap = 'glpi_authldaps' . $addtable . '_' . JoinBuilder::computeComplexJoinID($userOptions[30]['joinparams']) . $addmeta;
                $mail = 'glpi_authmails' . $addtable . '_' . JoinBuilder::computeComplexJoinID($userOptions[31]['joinparams']) . $addmeta;
                $add($d->quote($ldap . '.' . $field), '_' . $ID . '_ldapname');
                $add($d->quote($mail . '.' . $field), '_mailname');
                return $fields;
            case 'glpi_softwareversions.name':
            case 'glpi_softwareversions.comment':
                if ($meta && $meta_type === 'Software') {
                    $v = $d->concat($d->quote('glpi_softwares.name'), $d->literal(' - '), $d->quote($table . $addtable2 . '.' . $field), $d->literal(\Search::SHORTSEP), $d->quote($table . $addtable2 . '.id'));
                    $add($d->aggregate($v), '', true);
                    return $fields;
                }
                if ($field === 'comment') {
                    $add($d->aggregate($d->concat($column('name'), $d->literal(' - '), $value, $d->literal(\Search::SHORTSEP), $id)), '', true);
                    return $fields;
                }
                break;
            case 'glpi_states.name':
                if (($meta && $meta_type === 'Software') || $itemtype === 'Software') {
                    $parts = [];
                    if ($meta) {
                        $parts = [$d->quote('glpi_softwares.name'), $d->literal(' - ')];
                    }
                    $parts[] = $d->quote('glpi_softwareversions' . ($meta ? $addtable : '') . '.name');
                    $parts[] = $d->literal(' - ');
                    $a = $meta ? $table . $addtable2 : $alias;
                    array_push($parts, $d->quote($a . '.' . $field), $d->literal(\Search::SHORTSEP), $d->quote($a . '.id'));
                    $add($d->aggregate($d->concat(...$parts)), '', true);
                    return $fields;
                }
                break;
            case 'glpi_itilfollowups.content':
            case 'glpi_tickettasks.content':
            case 'glpi_changetasks.content':
                if (is_subclass_of($itemtype, 'CommonITILObject')) {
                    $add($d->aggregate($packed($value), true, [$column('date') => 'DESC', $id => 'DESC']), '', true);
                    return $fields;
                }
                break;
        }
        // Duration is a total of owning cost rows. Joining actors and several
        // parents for display must never multiply those identities.
        if ($field === 'actiontime' && isset($option['computation']) && $member === null
            && empty($option['additionalfields']) && ($option['joinparams'] ?? []) === ['jointype' => 'child']) {
            $costType = \getItemTypeForTable($table);
            if (\itsmng\Database\Repository\CostRepository::supports($costType)
                && is_subclass_of($costType, \CommonITILCost::class)
                && $option['computation'] === $costType::durationSearchComputation($DB)
                && (!$meta || $subjectType !== null)) {
                $subject = $subjectType ?? $itemtype;
                $em = \itsmng\Database\Orm::create($DB);
                try {
                    $total = (new \itsmng\Database\Repository\CostRepository($em))->searchActionTime(
                        $costType, $subject, JoinBuilder::getOrigTableName($subject),
                        static fn (string $parentAlias) => \getEntitiesRestrictRequest('', $parentAlias)
                    );
                } finally {
                    $em->clear();
                }
                if ($total !== null) {
                    // Each group represents one root identity; MAX only makes
                    // the correlated scalar legal in grouped SELECT/HAVING.
                    $add('MAX((' . $total . '))', '', true);
                    return $fields->withoutFieldJoin();
                }
            }
        }
        if (isset($option['computation'])) {
            $value = str_replace($DB->quoteName('TABLE'), 'TABLE', $option['computation']);
            $value = str_replace('TABLE', $d->quote($alias), $value);
        }
        $datatype = $option['datatype'] ?? '';
        if ($datatype === 'count') {
            $add('COUNT(DISTINCT ' . $column($field) . ')', '', true);
            return $fields;
        }
        if ($datatype === 'date_delay') {
            $value = self::dateDelay($option, $alias, $DB);
            $add($many ? $d->aggregate($value) : $value, '', $many);
            return $fields;
        }
        $aggregate = $many && (!isset($option['computation']) || !empty($option['computationgroupby']));
        if ($aggregate) {
            $add($d->aggregate($packed($value, $datatype !== 'itemlink'), true, [$id => 'ASC']), '', true);
            if ($translated) {
                $add($d->aggregate($packed($translation), true, [$id => 'ASC']), '_trans_' . $field, true);
            }
        } else {
            $add($value, '', isset($option['computation']) && ($option['computationaggregate'] ?? !empty($option['usehaving'])));
            if ($datatype === 'itemlink') {
                $add($id, '_id');
            }
            if ($translated) {
                $add($translation, '_trans_' . $field);
            }
        }
        return $fields;
    }

    public static function dateDelay(array $option, string $alias, \DBAdapter $db): string
    {
        $amount = $db->quoteName($alias . '.' . $option['datafields'][2]);
        if (isset($option['datafields'][3])) {
            $amount .= ' - ' . $db->quoteName($alias . '.' . $option['datafields'][3]);
        }
        return $db->expressions()->dateAdd($db->quoteName($alias . '.' . $option['datafields'][1]), '(' . $amount . ')', trim($option['delayunit'] ?? 'MONTH'));
    }
}
