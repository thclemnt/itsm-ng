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
    public static function ensureMysqlDatabase(Connection $server, string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Database name cannot be empty');
        }
        $manager = $server->createSchemaManager();
        if (!in_array($name, $manager->listDatabases(), true)) {
            $manager->createDatabase($server->getDatabasePlatform()->quoteIdentifier($name));
        }
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
