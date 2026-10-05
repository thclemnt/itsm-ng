<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;

/** Frozen export reader and fingerprint, never delegated to the current importer. */
final class DomainsPluginSnapshot
{
    public const FORMAT = 'infotel-domains-2.1.0-completed-v1';
    public const KINDS = ['PluginDomainsDomain', 'PluginDomainsDomainType', 'PluginDomainsDomaintype'];

    public static function definition(): array
    {
        static $definition;
        return $definition ??= json_decode(file_get_contents(__DIR__ . '/history/20261006-domains-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function read(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        if ($manager->tablesExist(['glpi_plugin_domains_profiles'])) {
            throw new \RuntimeException('Complete the pinned historical Domains 2.1.0 plugin upgrade before adoption: glpi_plugin_domains_profiles remains.');
        }
        $result = [];
        foreach (self::definition()['source'] as $table => $fields) {
            if (!$manager->tablesExist([$table])) {
                throw new \RuntimeException('Missing frozen Domains source table: ' . $table);
            }
            $actual = array_keys($manager->listTableColumns($table));
            if (array_diff($fields, $actual) || array_diff($actual, $fields)) {
                throw new \RuntimeException('Unsupported frozen Domains source layout: ' . $table . '; missing=' . implode(',', array_diff($fields, $actual)) . '; unexpected=' . implode(',', array_diff($actual, $fields)));
            }
            $quote = $connection->quoteIdentifier(...);
            $rows = $connection->fetchAllAssociative('SELECT ' . implode(', ', array_map($quote, $fields)) . ' FROM ' . $quote($table) . ' ORDER BY id');
            $result[$table] = array_map(self::rawRow(...), $rows);
        }
        return $result;
    }

    public static function rawRow(array $row): array
    {
        return array_map(static fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string)$value), $row);
    }

    public static function fingerprint(array $tables): string
    {
        return hash('sha256', json_encode([self::FORMAT, array_values($tables)], JSON_THROW_ON_ERROR));
    }

    public static function integer(mixed $value, string $field, int $minimum = 0): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string)$value)) {
            throw new \RuntimeException('Invalid frozen Domains integer: ' . $field);
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < $minimum) {
            throw new \RuntimeException('Frozen Domains integer outside supported range: ' . $field);
        }
        return $integer;
    }

    public static function flag(mixed $value, string $field): bool
    {
        $integer = self::integer($value, $field);
        if ($integer > 1) {
            throw new \RuntimeException('Invalid frozen Domains boolean: ' . $field);
        }
        return $integer === 1;
    }
}
