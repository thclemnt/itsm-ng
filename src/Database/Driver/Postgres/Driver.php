<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\API\ExceptionConverter as DriverExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PdoPgsqlDriver;
use Doctrine\DBAL\ParameterType;
use PDO;
use SensitiveParameter;

/** DBAL owns PDO creation, TLS, timeouts and physical connection lifetime. */
final class Driver extends AbstractDriverMiddleware
{
    public function __construct()
    {
        parent::__construct(new PdoPgsqlDriver());
    }

    public function getExceptionConverter(): DriverExceptionConverter
    {
        return new ExceptionConverter(parent::getExceptionConverter());
    }

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        // libpq's connect_timeout is supplied by PDO's driver option. Keep
        // nonpersistent connections distinct, including equal credentials.
        $params['persistent'] = false;
        $params['driverOptions'][PDO::ATTR_PERSISTENT] = false;
        $params['driverOptions'][PDO::ATTR_TIMEOUT] = $params['connect_timeout'] ?? 5;
        $connection = new Connection(parent::connect($params));
        foreach ([
            'search_path' => $params['search_path'] ?? 'public',
            'standard_conforming_strings' => 'on',
        ] as $setting => $value) {
            $statement = $connection->prepare('SELECT set_config(?, ?, false)');
            $statement->bindValue(1, $setting, ParameterType::STRING);
            $statement->bindValue(2, $value, ParameterType::STRING);
            $statement->execute()->free();
        }
        return $connection;
    }
}
