<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** Read only the supported completed Infotel Domains 2.1.0 export through DBAL. */
final class DomainPluginSource
{
    public const COMMIT = 'e628ee87b84a87867365dbc77d06738e9e74a246';
    public const ITEMTYPE = 'PluginDomainsDomain';
    public const TYPE = 'PluginDomainsDomainType';

    public function __construct(private Connection $connection)
    {
    }

    public function read(): DomainPluginSnapshot
    {
        if ($this->connection->createSchemaManager()->tablesExist(['glpi_plugin_domains_profiles'])) {
            throw new RuntimeException('Unsupported incomplete Domains plugin layout: glpi_plugin_domains_profiles remains; complete the pinned plugin upgrade before export.');
        }
        return new DomainPluginSnapshot(
            $this->rows('glpi_plugin_domains_domaintypes', ['id', 'entities_id', 'name', 'comment', 'is_recursive']),
            $this->rows('glpi_plugin_domains_domains', ['id', 'entities_id', 'is_recursive', 'name', 'plugin_domains_domaintypes_id',
                'date_creation', 'date_expiration', 'users_id_tech', 'groups_id_tech', 'suppliers_id', 'comment', 'others',
                'is_helpdesk_visible', 'date_mod', 'is_deleted']),
            $this->rows('glpi_plugin_domains_domains_items', ['id', 'plugin_domains_domains_id', 'items_id', 'itemtype']),
            $this->rows('glpi_plugin_domains_configs', ['id', 'delay_expired', 'delay_whichexpire'])
        );
    }

    private function rows(string $table, array $fields): array
    {
        $manager = $this->connection->createSchemaManager();
        if (!$manager->tablesExist([$table])) {
            throw new RuntimeException('Missing Domains plugin source table: ' . $table);
        }
        $columns = array_keys($manager->listTableColumns($table));
        $missing = array_diff($fields, $columns);
        $unknown = array_diff($columns, $fields);
        if ($missing || $unknown) {
            throw new RuntimeException('Unsupported Domains plugin source columns: ' . $table
                . '; missing=' . implode(',', $missing) . '; unexpected=' . implode(',', $unknown));
        }
        $quote = $this->connection->quoteIdentifier(...);
        return $this->connection->fetchAllAssociative('SELECT ' . implode(', ', array_map($quote, $fields))
            . ' FROM ' . $quote($table) . ' ORDER BY ' . $quote('id'));
    }
}
