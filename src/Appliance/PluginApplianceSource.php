<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Appliance;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** DBAL reads the unmodeled historical plugin export; core persistence belongs to the domain. */
final class PluginApplianceSource
{
    public const ITEMTYPE = 'PluginAppliancesAppliance';

    public function __construct(private Connection $connection)
    {
    }

    public function read(): PluginApplianceSnapshot
    {
        $types = $this->rows('glpi_plugin_appliances_appliancetypes', ['id', 'entities_id', 'is_recursive', 'name', 'comment'], ['externalid' => null]);
        foreach ($types as &$row) {
            $row['externalidentifier'] = self::externalIdentifier($row['externalid']);
            unset($row['externalid']);
        }
        unset($row);
        $environments = $this->rows('glpi_plugin_appliances_environments', ['id', 'name', 'comment']);
        $appliances = $this->rows('glpi_plugin_appliances_appliances', [
            'id', 'entities_id', 'is_recursive', 'name', 'is_deleted', 'plugin_appliances_appliancetypes_id', 'comment', 'locations_id',
            'plugin_appliances_environments_id', 'users_id', 'users_id_tech', 'groups_id', 'groups_id_tech', 'relationtype', 'date_mod',
            'states_id', 'externalid', 'serial', 'otherserial',
        ], ['is_helpdesk_visible' => true]);
        $relationTypes = [];
        foreach ($appliances as &$row) {
            $relationTypes[(int)$row['id']] = (int)$row['relationtype'];
            $row['appliancetypes_id'] = $row['plugin_appliances_appliancetypes_id'];
            $row['applianceenvironments_id'] = $row['plugin_appliances_environments_id'];
            $row['manufacturers_id'] = null;
            $row['externalidentifier'] = self::externalIdentifier($row['externalid']);
            if (in_array($row['date_mod'], ['', '0000-00-00 00:00:00'], true)) {
                $row['date_mod'] = null;
            }
            unset($row['plugin_appliances_appliancetypes_id'], $row['plugin_appliances_environments_id'], $row['relationtype'], $row['externalid']);
        }
        unset($row);
        $items = $this->rows('glpi_plugin_appliances_appliances_items', ['id', 'plugin_appliances_appliances_id', 'items_id', 'itemtype']);
        $owners = [];
        foreach ($items as &$row) {
            $row['appliances_id'] = $row['plugin_appliances_appliances_id'];
            $owners[(int)$row['id']] = (int)$row['appliances_id'];
            unset($row['plugin_appliances_appliances_id']);
        }
        unset($row);
        $relations = $this->rows('glpi_plugin_appliances_relations', ['id', 'plugin_appliances_appliances_items_id', 'relations_id']);
        foreach ($relations as &$row) {
            $owner = $owners[(int)$row['plugin_appliances_appliances_items_id']] ?? null;
            // These integers are the historical plugin format, not a core relationship catalogue.
            $row['itemtype'] = match ($relationTypes[$owner] ?? null) {
                1 => 'Location', 2 => 'Network', 3 => 'Domain',
                default => throw new RuntimeException('Unknown appliance plugin relation kind or owner at relation ' . $row['id']),
            };
            $row['appliances_items_id'] = $row['plugin_appliances_appliances_items_id'];
            $row['items_id'] = $row['relations_id'];
            unset($row['plugin_appliances_appliances_items_id'], $row['relations_id']);
        }
        unset($row);
        return new PluginApplianceSnapshot($types, $environments, $appliances, $items, $relations);
    }

    private static function externalIdentifier(mixed $value): ?string
    {
        // The old plugin's empty externalid denotes no external system identity.
        return $value === null || $value === '' ? null : (string)$value;
    }

    private function rows(string $table, array $required, array $optional = []): array
    {
        $manager = $this->connection->createSchemaManager();
        if (!$manager->tablesExist([$table])) {
            throw new RuntimeException('Missing appliance plugin source table: ' . $table);
        }
        $columns = $manager->listTableColumns($table);
        $missing = array_diff($required, array_keys($columns));
        if ($missing) {
            throw new RuntimeException('Missing appliance plugin source columns: ' . $table . '.' . implode(', ' . $table . '.', $missing));
        }
        $fields = [...$required, ...array_keys(array_intersect_key($optional, $columns))];
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $rows = $this->connection->fetchAllAssociative('SELECT ' . implode(', ', array_map($quote, $fields)) . ' FROM ' . $quote($table) . ' ORDER BY ' . $quote('id'));
        foreach ($rows as &$row) {
            $row += $optional;
        }
        return $rows;
    }
}
