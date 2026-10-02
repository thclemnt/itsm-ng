<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Schema\Table;

/** Frozen supported plugin tables; do not construct these from current application metadata. */
final class DomainsPlugin210Export
{
    public static function tables(bool $wideIdentifiers = false): array
    {
        $identifier = $wideIdentifiers ? 'bigint' : 'integer';
        $types = new Table('glpi_plugin_domains_domaintypes');
        $types->addColumn('id', $identifier);
        $types->addColumn('entities_id', $identifier, ['default' => 0]);
        $types->addColumn('name', 'string', ['length' => 255, 'notnull' => false]);
        $types->addColumn('comment', 'text', ['notnull' => false]);
        $types->addColumn('is_recursive', 'integer', ['default' => 0]);
        $types->setPrimaryKey(['id']);
        $domains = new Table('glpi_plugin_domains_domains');
        foreach (['id', 'entities_id', 'plugin_domains_domaintypes_id', 'users_id_tech', 'groups_id_tech', 'suppliers_id'] as $column) {
            $domains->addColumn($column, $identifier, ['default' => 0]);
        }
        foreach (['is_recursive', 'is_helpdesk_visible', 'is_deleted'] as $column) {
            $domains->addColumn($column, 'integer', ['default' => $column === 'is_helpdesk_visible' ? 1 : 0]);
        }
        foreach (['name', 'others'] as $column) {
            $domains->addColumn($column, 'string', ['length' => 255, 'notnull' => false]);
        }
        $domains->addColumn('comment', 'text', ['notnull' => false]);
        foreach (['date_creation', 'date_expiration'] as $column) {
            $domains->addColumn($column, 'date', ['notnull' => false]);
        }
        $domains->addColumn('date_mod', 'datetime', ['notnull' => false]);
        $domains->setPrimaryKey(['id']);
        $items = new Table('glpi_plugin_domains_domains_items');
        foreach (['id', 'plugin_domains_domains_id', 'items_id'] as $column) {
            $items->addColumn($column, $identifier, ['default' => 0]);
        }
        $items->addColumn('itemtype', 'string', ['length' => 100]);
        $items->setPrimaryKey(['id']);
        $items->addUniqueIndex(['plugin_domains_domains_id', 'itemtype', 'items_id'], 'unicity');
        $configs = new Table('glpi_plugin_domains_configs');
        $configs->addColumn('id', $identifier);
        $configs->addColumn('delay_expired', 'string', ['length' => 50, 'default' => '30']);
        $configs->addColumn('delay_whichexpire', 'string', ['length' => 50, 'default' => '30']);
        $configs->setPrimaryKey(['id']);
        foreach ([$types, $domains, $items, $configs] as $table) {
            $table->addOption('engine', 'InnoDB');
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
        }
        return [$types, $domains, $items, $configs];
    }

    /** Curated rows with explicit fixture-owned parent IDs; never infer a graph from core schema. */
    public static function rows(array $context, int $base = 4294972000): array
    {
        $types = [
            ['id' => $base, 'entities_id' => $context['entity_a'], 'name' => "Duplicate 日本語", 'comment' => null, 'is_recursive' => 1],
            ['id' => $base + 1, 'entities_id' => $context['entity_b'], 'name' => "Duplicate 日本語", 'comment' => 'NULL', 'is_recursive' => 0],
        ];
        $domains = [];
        foreach ([0, 1, 2] as $offset) {
            $domains[] = ['id' => $base + 10 + $offset, 'entities_id' => $context[$offset === 1 ? 'entity_b' : 'entity_a'],
                'is_recursive' => $offset === 0 ? 1 : 0, 'name' => $offset === 2 ? 'NULL' : "same.example 日本語",
                'plugin_domains_domaintypes_id' => $offset === 2 ? 0 : $base + ($offset === 1 ? 1 : 0),
                'date_creation' => $offset === 2 ? null : '2026-01-01', 'date_expiration' => $offset === 2 ? null : '2027-12-31',
                'users_id_tech' => $offset === 0 ? $context['user'] : 0, 'groups_id_tech' => $offset === 0 ? $context['group'] : 0,
                'suppliers_id' => $offset === 0 ? $context['supplier'] : 0, 'comment' => $offset === 2 ? null : "O'Reilly C:\\new 日本語\nLiteral null",
                'others' => $offset === 2 ? null : '', 'is_helpdesk_visible' => $offset === 1 ? 0 : 1,
                'date_mod' => $offset === 2 ? null : '2026-10-01 12:34:56', 'is_deleted' => 0];
        }
        $items = [];
        foreach ($context['assets'] as $kind => $id) {
            $offset = count($items);
            $items[] = ['id' => $base + 100 + $offset, 'plugin_domains_domains_id' => $base + 10 + ($offset % 3), 'items_id' => $id, 'itemtype' => $kind];
        }
        return ['glpi_plugin_domains_domaintypes' => $types, 'glpi_plugin_domains_domains' => $domains,
            'glpi_plugin_domains_domains_items' => $items, 'glpi_plugin_domains_configs' => [['id' => 1, 'delay_expired' => '30', 'delay_whichexpire' => '45']]];
    }

}
