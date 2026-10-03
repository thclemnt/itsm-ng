<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use itsmng\Database\CheckConstraintSupport;
use itsmng\Database\InstallationConnection;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/mysql-session-modes.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
if ($DB->getProvider() === 'pgsql') {
    echo "pgsql: MySQL session initialization does not apply; PostgreSQL native domain contracts remain authoritative.\n";
    exit(0);
}

/** Control only the newly created driver session; never alter the shared server GLOBAL mode. */
final class ControlledMySQLModes implements Middleware
{
    public function __construct(private readonly string $modes)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->modes) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $modes)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $statement = $connection->prepare('SET SESSION sql_mode = ?');
                $statement->bindValue(1, $this->modes, ParameterType::STRING);
                $statement->execute()->free();
                return $connection;
            }
        };
    }
}

$writer = $DB->getDoctrineConnection();
verify(!$writer->isTransactionActive(), 'DDL fixture owns an idle connection');
$platform = $writer->getDatabasePlatform();
$name = 'itsm_port_sql_mode_' . bin2hex(random_bytes(5));
$table = $platform->quoteIdentifier($DB->dbdefault) . '.' . $platform->quoteIdentifier($name);
$connections = [];
$adapters = [];
$created = false;
verify(!$writer->createSchemaManager()->tablesExist([$name]), 'Probe name must not already identify an existing table');
$globalModes = (string)$writer->fetchOne('SELECT @@GLOBAL.sql_mode');
$mode = static fn (Connection $connection): string => (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
$strict = static function (Connection $connection) use ($mode): void {
    verify(in_array('STRICT_ALL_TABLES', explode(',', $mode($connection)), true), 'Every owned physical connection initializes STRICT_ALL_TABLES');
};
$reject = static function (Connection $connection) use ($table): void {
    $before = $connection->fetchAssociative("SELECT * FROM $table WHERE id = 1");
    foreach ([['flag' => 2], ['flag' => -1], ['flag' => null], ['label' => 'truncated-value']] as $writes) {
        $refused = false;
        try {
            $connection->update($table, $writes, ['id' => 1]);
        } catch (\Doctrine\DBAL\Exception) {
            $refused = true;
        }
        verify($refused && $connection->fetchAssociative("SELECT * FROM $table WHERE id = 1") === $before, 'Native invalid flag, required NULL or truncation is refused without coercion');
    }
};
$controlled = static function (string $modes, bool $initialize = true) use ($writer): Connection {
    $configuration = new Configuration();
    $configuration->setMiddlewares([new ControlledMySQLModes($modes)]);
    if ($initialize) {
        return MySQLConnection::create($writer->getParams(), $configuration);
    }
    $configuration->setSchemaManagerFactory(new \itsmng\Database\MySQLSchemaManagerFactory());
    $external = $writer->getParams();
    unset($external['wrapperClass']); // Deliberately external sessions do not claim managed transport ownership.
    return DriverManager::getConnection($external, $configuration);
};
try {
    $writer->executeStatement("CREATE TABLE $table (id INTEGER PRIMARY KEY, flag TINYINT NOT NULL, label VARCHAR(3) NOT NULL, CHECK (flag IS NOT NULL AND flag IN (0, 1))) ENGINE=InnoDB");
    $created = true;
    $writer->insert($table, ['id' => 1, 'flag' => 1, 'label' => 'ok']);
    $strict($writer);
    $reject($writer);

    $adapter = DBConnection::createConnection('mysql', $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $DB->dbdefault);
    $adapters[] = $adapter;
    verify($adapter->connected, 'Separate configured application adapter connects');
    $strict($adapter->getDoctrineConnection());
    $reject($adapter->getDoctrineConnection());
    $adapter->close();
    verify($adapter->connect() === true, 'Explicit application adapter reconnect succeeds');
    $connection = $adapter->getDoctrineConnection();
    $strict($connection);
    $reject($connection);
    $connection->close();
    $strict($connection);
    $reject($connection);
    verify($adapter->getDoctrineConnection() === $connection, 'Direct DBAL reconnect preserves its owning adapter facade');

    $readAdapter = (new ReflectionClass($DB))->newInstanceWithoutConstructor();
    $readAdapter->slave = true;
    $adapters[] = $readAdapter;
    verify($readAdapter->connect() === true && $readAdapter->isSlave(), 'Configured read adapter shares the same connection policy');
    $readConnection = $readAdapter->getDoctrineConnection();
    $strict($readConnection);
    verify(Orm::create($readAdapter)->getConnection() === $readConnection && $readConnection !== $writer, 'ORM retains the supplied read endpoint; this owned endpoint is not live replica validation');

    foreach ([
        InstallationConnection::mysqlServer($DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword)),
        InstallationConnection::mysqlDatabase($DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $DB->dbdefault),
    ] as $installation) {
        $connections[] = $installation;
        verify(!$installation->isConnected(), 'Installation factories remain lazy');
        $strict($installation);
        $reject($installation);
        $installation->close();
        $strict($installation);
        $reject($installation);
    }

    foreach (['', 'STRICT_TRANS_TABLES,ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'] as $configured) {
        $connection = $controlled($configured);
        $connections[] = $connection;
        foreach ([false, true] as $reconnect) {
            $reconnect && $connection->close();
            $expected = array_filter(explode(',', MySQLConnection::strictModes($configured)));
            $actual = explode(',', $mode($connection));
            sort($expected);
            sort($actual);
            verify($actual === $expected, 'Configured ANSI, grouping/date and transactional modes survive physical initialization and reconnect');
            $strict($connection);
            $reject($connection);
        }
        verify((new SchemaCheck())->differences($connection) === [], 'Native constraint inspection retains canonical schema under configured SQL modes');
    }
    $permissive = $controlled('', false);
    $connections[] = $permissive;
    $before = $mode($permissive);
    $differences = (new SchemaCheck())->differences($permissive);
    verify((bool)array_filter($differences, static fn (string $difference): bool => str_contains($difference, 'strict SESSION sql_mode')), 'SchemaCheck diagnoses an externally supplied permissive session');
    verify($mode($permissive) === $before && $before === '', 'Read-only schema diagnosis never repairs SQL mode');
    $externalStrict = $controlled('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', false);
    $connections[] = $externalStrict;
    $before = $mode($externalStrict);
    CheckConstraintSupport::assertSupported($externalStrict);
    verify($mode($externalStrict) === $before, 'Externally supplied transactional strict mode remains accepted and unchanged');
} finally {
    foreach ($connections as $connection) {
        $connection->close();
    }
    foreach ($adapters as $adapter) {
        $adapter->close();
    }
    if ($created) {
        $writer->executeStatement("DROP TABLE $table");
    }
}
verify((string)$writer->fetchOne('SELECT @@GLOBAL.sql_mode') === $globalModes, 'The contract never changes shared server modes');
verify((new SchemaCheck())->differences($writer) === [], 'Canonical schema remains unchanged');
echo "mysql: native session integrity, reconnect, configured modes, installation and read routing: $assertions assertions passed.\n";
