<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/** MySQL session integrity belongs to every physical connection, including reconnects. */
final class MySQLConnection implements Middleware
{
    public static function create(#[\SensitiveParameter] array $parameters): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new self()]);
        return DriverManager::getConnection($parameters, $configuration);
    }

    /** Keep the configured modes; strict native writes cannot be an optional setting. */
    public static function strictModes(string $configured): string
    {
        $modes = array_values(array_filter(array_map('trim', explode(',', $configured)), static fn (string $mode): bool => $mode !== ''));
        if (!in_array('STRICT_ALL_TABLES', array_map('strtoupper', $modes), true)) {
            $modes[] = 'STRICT_ALL_TABLES';
        }
        return implode(',', $modes);
    }

    /** Diagnostics never change the supplied connection's session state. */
    public static function assertStrict(Connection $connection): void
    {
        $mode = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
        $modes = array_map('strtoupper', array_map('trim', explode(',', $mode)));
        // Existing externally supplied strict transactional connections remain
        // valid for InnoDB core storage. Our own factory uses the stronger ALL.
        if (!array_intersect(['STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES'], $modes)) {
            throw new \RuntimeException('MySQL native value enforcement requires strict SESSION sql_mode (STRICT_ALL_TABLES or STRICT_TRANS_TABLES). Open application and installation connections through MySQLConnection; do not disable strict mode.');
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $result = $connection->query('SELECT @@SESSION.sql_mode');
                $configured = (string)$result->fetchOne();
                $result->free();
                $statement = $connection->prepare('SET SESSION sql_mode = ?');
                $statement->bindValue(1, MySQLConnection::strictModes($configured), ParameterType::STRING);
                $statement->execute()->free();
                return $connection;
            }
        };
    }
}
