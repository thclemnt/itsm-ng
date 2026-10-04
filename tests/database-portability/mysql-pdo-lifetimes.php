<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\MySQLManagedConnection;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/mysql-pdo-lifetimes.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function closedCommand(callable $operation, string $message): void
{
    try {
        $operation();
        throw new RuntimeException($message);
    } catch (LogicException) {
        verify(true, $message);
    }
}
/** Exercise the actual pinned DBAL disconnect callback, without logging SQL or credentials. */
final class DisconnectFailureLogger extends \Psr\Log\AbstractLogger
{
    public int $disconnects = 0;

    public function __construct(public readonly RuntimeException $failure)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((string)$message === 'Disconnecting') {
            ++$this->disconnects;
            throw $this->failure;
        }
    }
}

/** Controlled cleanup failure surrounds a real query result, not a fabricated native SQL error. */
final class CursorFailureMiddleware implements \Doctrine\DBAL\Driver\Middleware
{
    public function __construct(private readonly RuntimeException $failure)
    {
    }

    public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
    {
        return new class ($driver, $this->failure) extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
            public function __construct(\Doctrine\DBAL\Driver $driver, private readonly RuntimeException $failure)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): \Doctrine\DBAL\Driver\Connection
            {
                return new class (parent::connect($params), $this->failure) extends \Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware {
                    public function __construct(\Doctrine\DBAL\Driver\Connection $connection, private readonly RuntimeException $failure)
                    {
                        parent::__construct($connection);
                    }

                    public function query(string $sql): \Doctrine\DBAL\Driver\Result
                    {
                        $result = parent::query($sql);
                        if ($sql !== 'SELECT 1 AS close_probe') {
                            return $result;
                        }
                        return new class ($result, $this->failure) extends \Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware {
                            public function __construct(\Doctrine\DBAL\Driver\Result $result, private readonly RuntimeException $failure)
                            {
                                parent::__construct($result);
                            }

                            public function free(): void
                            {
                                throw $this->failure;
                            }
                        };
                    }
                };
            }
        };
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable physical lifetime database required');
if ($DB->getProvider() === 'pgsql') {
    echo "pgsql: MySQL physical command ownership does not apply; original PostgreSQL driver ownership contract remains authoritative.\n";
    exit(0);
}
$writer = $DB->getDoctrineConnection();
verify($writer instanceof MySQLManagedConnection && !$writer->isTransactionActive(), 'Actual canonical MySQL writer starts idle');
$DB->assertManagedTransaction();
$secondary = null;
$seed = null;
$legacy = null;
$bufferedStatement = null;
$ordinary = null;
$ordinaryResult = null;
$fresh = null;
$cleanupProbe = null;
$probeResult = null;
$lock = 'pdo-owner-' . bin2hex(random_bytes(8));
$applicationLock = false;
$primary = null;
$cleanup = [];
try {
    $prefix = 'PDO lifetime ' . bin2hex(random_bytes(5));
    $seed = (new FixtureRecords($DB))->create('glpi_suppliers', ['name' => $prefix]);
    $secondary = DBConnection::createConnection('mysql', $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $DB->dbdefault);
    verify($secondary->connected, 'Actual public connection factory opens an exclusive configured owner');
    $owner = $secondary->getDoctrineConnection();
    $physical = WeakReference::create($owner->getNativeConnection());
    $sessionId = (int)$owner->fetchOne('SELECT CONNECTION_ID()');
    verify($sessionId !== (int)$writer->fetchOne('SELECT CONNECTION_ID()'), 'Observer and caller are genuinely distinct physical sessions');
    $legacy = $secondary->prepare('UPDATE glpi_suppliers SET name=? WHERE id=?');
    $marker = $prefix . ' uncommitted';
    $legacy->bind_param('si', $marker, $seed);
    $bufferedStatement = $secondary->prepare('SELECT ? AS marker');
    $bufferedStatement->bind_param('s', $marker);
    verify($bufferedStatement->execute(), 'Real legacy prepared result buffers its native rows');
    $buffered = $bufferedStatement->get_result();
    $ordinary = $owner->prepare('SELECT name FROM glpi_suppliers WHERE id=?');
    $ordinary->bindValue(1, $seed);
    $ordinaryResult = $ordinary->executeQuery(); // Deliberately retain actual DBAL result and statement.
    verify($ordinaryResult->fetchOne() === $prefix, 'Retained ordinary DBAL statement/result are real');
    $ordinaryResult->free(); // Retain even a freed cursor object: PDO's result still holds its statement/session.
    verify($secondary->getLock($lock) && !$DB->getLock($lock), 'Caller owns a real contended advisory session lock');
    verify(
        $secondary->query('BEGIN') === true && $owner->getTransactionNestingLevel() === 0,
        'Actual raw caller transaction is physically active outside DBAL nesting'
    );
    verify(
        $legacy->execute() && $writer->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $prefix,
        'Retained legacy statement writes a genuinely uncommitted caller marker'
    );
    $owner->close();
    verify(!$owner->isConnected() && $physical->get() === null, 'Direct owner close releases the physical PDO despite all retained legacy/DBAL/result objects');
    $applicationLock = $DB->getLock($lock);
    verify($applicationLock, 'Independent observer acquires the released real advisory session lock');
    verify($DB->releaseLock($lock), 'Observer releases only its acquired owned fixture lock');
    $applicationLock = false;
    verify(
        $writer->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $prefix,
        'Physical closure rolls back original raw caller data instead of leaving a retained PDO session alive'
    );
    closedCommand($legacy->execute(...), 'Retained legacy command refuses after direct owner close');
    closedCommand($ordinary->executeQuery(...), 'Retained ordinary DBAL command refuses after direct owner close');
    closedCommand($ordinaryResult->fetchOne(...), 'Retained native result cannot operate on the closed physical owner');
    verify(!$owner->isConnected(), 'Stale retained commands/results never reconnect the closed owner');
    verify($DB->fetchAssoc($buffered) === ['marker' => $marker], 'Already buffered legacy rows survive physical owner close');
    $newSessionId = (int)$owner->fetchOne('SELECT CONNECTION_ID()');
    verify(
        $newSessionId !== $sessionId && $secondary->getDoctrineConnection() === $owner,
        'Real same-DBAL reconnect replaces the actual native session'
    );
    closedCommand($legacy->execute(...), 'Old legacy statement cannot revive after same-DBAL reconnect');
    closedCommand($ordinary->executeQuery(...), 'Old DBAL statement cannot revive after same-DBAL reconnect');
    $fresh = $secondary->prepare('SELECT ? AS marker');
    $fresh->bind_param('s', $marker);
    verify($fresh->execute(), 'Fresh command on reconnected supplied owner executes normally');
    verify($secondary->close() === true && $secondary->connect() === true, 'Actual public adapter close/reconnect obtains a new owner');
    verify($secondary->getDoctrineConnection() !== $owner, 'Adapter rebind replaces the exact DBAL identity');
    closedCommand($fresh->execute(...), 'Retained legacy facade refuses execution against a newly supplied DBAL owner');
    verify(
        $writer->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$seed]) === $prefix,
        'Stale commands never mutate data through the closed or replacement writer'
    );

    foreach ([false, true] as $cursorFails) {
        $disconnectFailure = new RuntimeException('Owned fixture disconnect callback failure');
        $cursorFailure = new RuntimeException('Owned fixture cursor callback failure');
        $logger = new DisconnectFailureLogger($disconnectFailure);
        $configuration = new \Doctrine\DBAL\Configuration();
        $middlewares = [new \Doctrine\DBAL\Logging\Middleware($logger)];
        if ($cursorFails) {
            $middlewares[] = new CursorFailureMiddleware($cursorFailure);
        }
        $configuration->setMiddlewares($middlewares);
        $cleanupProbe = \itsmng\Database\MySQLConnection::create($writer->getParams(), $configuration);
        $probeResult = $cleanupProbe->executeQuery('SELECT 1 AS close_probe');
        $probeStatement = $cleanupProbe->prepare('SELECT ? AS close_stmt');
        $probeStatement->bindValue(1, 'owned retained command');
        $probePhysical = WeakReference::create($cleanupProbe->getNativeConnection());
        $caught = null;
        try {
            $cleanupProbe->close();
        } catch (Throwable $error) {
            $caught = $error;
        }
        verify(
            $caught === ($cursorFails ? $cursorFailure : $disconnectFailure),
            'Actual supplied logging disconnect cannot replace the earlier cursor cleanup failure'
        );
        verify(
            $logger->disconnects === 1 && !$cleanupProbe->isConnected() && $probePhysical->get() === null,
            'All actual physical handles close despite cursor/disconnect callback exceptions'
        );
        closedCommand($probeStatement->executeQuery(...), 'Cleanup exception cannot leave a retained command executable');
        closedCommand($probeResult->fetchOne(...), 'Cleanup exception cannot leave a retained result executable');
        $cleanupProbe->close();
        $probeResult->free();
        $cleanupProbe = $probeResult = null;
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        $cleanupProbe?->close();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        $probeResult?->free();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        $secondary?->close(); // This fixture owns its exclusive caller, frames and session lock.
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    foreach ([$legacy, $bufferedStatement, $fresh] as $statement) {
        try {
            $statement?->close();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    try {
        $ordinaryResult?->free();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        if ($applicationLock) {
            $DB->releaseLock($lock);
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        if ($seed !== null) {
            $writer->delete('glpi_suppliers', ['id' => $seed]);
        }
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    fwrite(STDERR, (string)$primary . "\n");
    foreach ($cleanup as $error) {
        fwrite(STDERR, 'Additional owned PDO lifetime cleanup failure: ' . (string)$error . "\n");
    }
    exit(1);
}
if ($cleanup) {
    throw new RuntimeException('Owned PDO lifetime cleanup failed.', previous: $cleanup[0]);
}
echo "mysql: retained legacy/DBAL/result lifetime, physical rollback, session lock release, same-owner reconnect and adapter rebind passed.\n";
