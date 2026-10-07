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
    public static function create(#[\SensitiveParameter] array $parameters, ?Configuration $configuration = null): Connection
    {
        return DriverManager::getConnection(self::parameters($parameters), self::configuration($configuration));
    }

    /** Validate the transport policy before a lazy driver can connect. */
    public static function parameters(#[\SensitiveParameter] array $parameters): array
    {
        if (($parameters['driver'] ?? 'pdo_mysql') !== 'pdo_mysql' || isset($parameters['driverClass'])
            || (isset($parameters['wrapperClass']) && $parameters['wrapperClass'] !== MySQLManagedConnection::class)) {
            throw new \InvalidArgumentException('MySQL ownership requires the canonical PDO driver and DBAL owner.');
        }
        if (($parameters['persistent'] ?? false) !== false) {
            throw new \InvalidArgumentException('MySQL ownership requires a distinct nonpersistent physical connection.');
        }
        $parameters['driver'] = 'pdo_mysql';
        $parameters['wrapperClass'] = MySQLManagedConnection::class;
        $parameters['persistent'] = false;
        $ssl = $parameters['ssl'] ?? false;
        $verify = $parameters['ssl_verify_server_cert'] ?? true;
        if (!is_bool($ssl) || !is_bool($verify)) {
            throw new \InvalidArgumentException('MySQL TLS and certificate verification options must be boolean.');
        }
        $options = $parameters['driverOptions'] ?? [];
        if (!is_array($options)) {
            throw new \InvalidArgumentException('MySQL PDO driver options must be an array.');
        }
        $required = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::ATTR_PERSISTENT => false,
        ];
        $allowed = [...array_keys($required), \PDO::ATTR_TIMEOUT];
        $tlsPrefix = class_exists(\Pdo\Mysql::class, false) ? 'Pdo\\Mysql::ATTR_SSL_' : 'PDO::MYSQL_ATTR_SSL_';
        $tls = [
            'ssl_key' => $tlsPrefix . 'KEY',
            'ssl_cert' => $tlsPrefix . 'CERT',
            'ssl_ca' => $tlsPrefix . 'CA',
            'ssl_capath' => $tlsPrefix . 'CAPATH',
            'ssl_cipher' => $tlsPrefix . 'CIPHER',
        ];
        foreach ($parameters as $name => $value) {
            if (str_starts_with((string)$name, 'ssl_') && $name !== 'ssl_verify_server_cert' && !array_key_exists($name, $tls)) {
                throw new \InvalidArgumentException('Unknown MySQL TLS option.');
            }
        }
        foreach ($tls as $name => $constant) {
            $value = $parameters[$name] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException('MySQL TLS material and cipher options must be strings or null.');
            }
            if (!$ssl && $value !== null && $value !== '') {
                throw new \InvalidArgumentException('MySQL TLS material requires explicit TLS enablement.');
            }
            if (defined($constant)) {
                $allowed[] = constant($constant);
            }
            if ($ssl && $value !== null && $value !== '') {
                if (!defined($constant)) {
                    throw new \InvalidArgumentException('Configured MySQL TLS option requires pdo_mysql support.');
                }
                $required[constant($constant)] = $value;
            }
        }
        $verifyConstant = $tlsPrefix . 'VERIFY_SERVER_CERT';
        if (defined($verifyConstant)) {
            $allowed[] = constant($verifyConstant);
        }
        if ($ssl) {
            if (!defined($verifyConstant)) {
                throw new \InvalidArgumentException('MySQL TLS certificate verification requires pdo_mysql support.');
            }
            $required[constant($verifyConstant)] = $verify;
        }
        foreach ($options as $name => $value) {
            if (!is_int($name) || !in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported MySQL PDO option; transport policy must be explicit.');
            }
            if ($ssl && $name !== \PDO::ATTR_TIMEOUT && !array_key_exists($name, $required)) {
                throw new \InvalidArgumentException('MySQL TLS options must use the explicit named material policy.');
            }
            if (array_key_exists($name, $required) && $value !== $required[$name]) {
                throw new \InvalidArgumentException('MySQL PDO option contradicts the canonical connection policy.');
            }
            if ($name === \PDO::ATTR_TIMEOUT && (!is_int($value) || $value < 0)) {
                throw new \InvalidArgumentException('MySQL PDO timeout must be a nonnegative integer.');
            }
            if (!$ssl && !in_array($name, [...array_keys($required), \PDO::ATTR_TIMEOUT], true)) {
                throw new \InvalidArgumentException('MySQL PDO TLS options require explicit TLS enablement.');
            }
        }
        $parameters['ssl'] = $ssl;
        $parameters['ssl_verify_server_cert'] = $verify;
        $parameters['driverOptions'] = array_replace($options, $required);
        return $parameters;
    }

    /** Preserve supplied logging/control middleware and use the same native inspection policy. */
    public static function configuration(?Configuration $configuration = null): Configuration
    {
        $configuration = $configuration === null ? new Configuration() : clone $configuration;
        $middlewares = array_values(array_filter($configuration->getMiddlewares(), static fn (Middleware $middleware): bool => !$middleware instanceof self));
        $configuration->setMiddlewares([...$middlewares, new self()]);
        $configuration->setSchemaManagerFactory(new MySQLSchemaManagerFactory());
        return $configuration;
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

    /** Inspect the actual capability; MySQL and older MariaDB may not expose it. */
    public static function snapshotIsolation(Connection|DriverConnection $connection): ?bool
    {
        $sql = "SHOW SESSION VARIABLES WHERE Variable_name = 'innodb_snapshot_isolation'";
        $result = $connection instanceof Connection ? $connection->executeQuery($sql) : $connection->query($sql);
        try {
            $rows = $result->fetchAllNumeric();
        } finally {
            $result->free();
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || count($rows[0]) !== 2 || $rows[0][0] !== 'innodb_snapshot_isolation'
            || !in_array($rows[0][1], ['ON', 'OFF'], true)) {
            throw new CurrentReadUnavailable('Unexpected native innodb_snapshot_isolation capability; current locking reads cannot be admitted.');
        }
        return $rows[0][1] === 'ON';
    }

    /** @internal Called only while a new physical driver session is being admitted. */
    public static function initializeCurrentReads(DriverConnection $connection): void
    {
        $native = $connection->getNativeConnection();
        if ($native instanceof \PDO && $native->inTransaction()) {
            throw new CurrentReadUnavailable('Current locking-read policy cannot initialize an existing caller transaction.');
        }
        if (self::snapshotIsolation($connection) === true) {
            // The application deliberately retains traditional InnoDB current
            // locking reads under RR, rather than MariaDB's newer snapshot mode.
            $statement = $connection->prepare('SET SESSION innodb_snapshot_isolation = ?');
            $statement->bindValue(1, 0, ParameterType::INTEGER);
            $statement->execute()->free();
            if (self::snapshotIsolation($connection) !== false) {
                throw new CurrentReadUnavailable('The new physical session did not establish traditional InnoDB current locking reads.');
            }
        }
    }

    /** Read-only admission: never repair isolation inside a supplied caller frame. */
    public static function assertCurrentReads(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return;
        }
        if ($connection->isTransactionActive()) {
            TransactionOwnership::assertManaged($connection);
        }
        if (self::snapshotIsolation($connection) === true) {
            throw new CurrentReadUnavailable('This caller enabled innodb_snapshot_isolation; finish its transaction and open an application session before performing a current locking read.');
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                if ($params['ssl'] ?? false) {
                    $tls = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
                    try {
                        $row = $tls->fetchNumeric();
                        if (!is_array($row) || !is_string($row[1] ?? null) || $row[1] === '') {
                            throw new \Doctrine\DBAL\Exception('Required MySQL TLS did not establish an encrypted session.');
                        }
                    } finally {
                        $tls->free();
                    }
                }
                $result = $connection->query('SELECT @@SESSION.sql_mode');
                $configured = (string)$result->fetchOne();
                $result->free();
                $statement = $connection->prepare('SET SESSION sql_mode = ?');
                $statement->bindValue(1, MySQLConnection::strictModes($configured), ParameterType::STRING);
                $statement->execute()->free();
                MySQLConnection::initializeCurrentReads($connection);
                return new \itsmng\Database\Driver\OwnedConnection($connection);
            }
        };
    }
}
