<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** Database provisioning and catalogue access happen before an ORM schema exists. */
final class InstallationConnection
{
    public static function mysqlServer(string $endpoint, string $user, string $password): Connection
    {
        if (preg_match('/^\[([^\]]+)\](?::(.+))?$/D', $endpoint, $parts)) {
            $host = $parts[1];
            $port = $parts[2] ?? null;
        } else {
            [$host, $port] = array_pad(explode(':', $endpoint, 2), 2, null);
        }
        $parameters = ['driver' => 'mysqli', 'charset' => 'utf8mb4', 'host' => $host, 'user' => $user, 'password' => $password];
        if ($port !== null && $port !== '') {
            $parameters[(int)$port > 0 ? 'port' : 'unix_socket'] = (int)$port > 0 ? (int)$port : $port;
        }
        return DriverManager::getConnection($parameters);
    }

    /** Existing databases do not require the install role to have CREATE privileges. */
    public static function ensureMysqlDatabase(Connection $server, string $name): bool
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Database name cannot be empty');
        }
        $manager = $server->createSchemaManager();
        if (!in_array($name, $manager->listDatabases(), true)) {
            $manager->createDatabase($server->getDatabasePlatform()->quoteIdentifier($name));
            return true;
        }
        return false;
    }

    public static function mysqlDatabase(string $endpoint, string $user, string $password, string $name): Connection
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Database name cannot be empty');
        }
        $parameters = self::mysqlServer($endpoint, $user, $password)->getParams();
        $parameters['dbname'] = $name;
        return DriverManager::getConnection($parameters);
    }

    /** Visible server databases and their table dates, before application mappings exist. */
    public static function mysqlDatabases(Connection $server): array
    {
        return $server->fetchAllAssociative(
            'SELECT s.schema_name AS name, COUNT(t.table_name) AS table_count,'
            . ' DATE(MIN(t.create_time)) AS creation_date, DATE(MAX(t.update_time)) AS last_update'
            . ' FROM information_schema.schemata s LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name'
            . ' WHERE s.schema_name NOT IN (?, ?, ?, ?) GROUP BY s.schema_name ORDER BY s.schema_name',
            ['information_schema', 'mysql', 'performance_schema', 'sys']
        );
    }

    public static function hasApplicationTables(Connection $database): bool
    {
        foreach ($database->createSchemaManager()->listTableNames() as $table) {
            if (str_starts_with($table, 'glpi_')) {
                return true;
            }
        }
        return false;
    }
}
