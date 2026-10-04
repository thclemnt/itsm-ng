<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Real policy and middleware, deterministic native driver doubles; no connection.
define('GLPI_ROOT', dirname(__DIR__, 2));
require GLPI_ROOT . '/vendor/autoload.php';

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\MySQLConnection;
use itsmng\Database\MySQLManagedConnection;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CurrentReadNativeState extends PDO
{
    public bool $active = false;

    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }
}

final class CurrentReadResult implements Result
{
    public bool $freed = false;

    public function __construct(private array $rows)
    {
    }

    public function fetchNumeric(): array|false
    {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAssociative(): array|false
    {
        throw new LogicException('Only numeric native policy results are expected.');
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();
        return $row === false ? false : $row[0];
    }

    public function fetchAllNumeric(): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    public function fetchAllAssociative(): array
    {
        throw new LogicException('No associative policy projection.');
    }

    public function fetchFirstColumn(): array
    {
        return array_column($this->fetchAllNumeric(), 0);
    }

    public function rowCount(): int|string
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return 2;
    }

    public function free(): void
    {
        $this->freed = true;
    }
}

final class CurrentReadDriverSession implements DriverConnection
{
    public string $modes = 'ANSI_QUOTES,STRICT_TRANS_TABLES';
    public array $queries = [];
    public array $results = [];
    public array $sets = [];
    public bool $retainEnabled = false;
    public bool $disappear = false;
    public ?Throwable $refusal = null;
    public CurrentReadNativeState $native;

    public function __construct(public array $capability)
    {
        $this->native = new CurrentReadNativeState();
    }

    public function prepare(string $sql): Statement
    {
        return new class ($this, $sql) implements Statement {
            private mixed $value = null;

            public function __construct(private CurrentReadDriverSession $session, private string $sql)
            {
            }

            public function bindValue(int|string $param, mixed $value, ParameterType $type): void
            {
                verify($param === 1, 'Exactly one native policy parameter');
                verify($type === ($this->sql === 'SET SESSION sql_mode = ?' ? ParameterType::STRING : ParameterType::INTEGER), 'Policy binds its actual native value type');
                $this->value = $value;
            }

            public function execute(): Result
            {
                if ($this->sql === 'SET SESSION sql_mode = ?') {
                    $this->session->modes = $this->value;
                } else {
                    verify($this->sql === 'SET SESSION innodb_snapshot_isolation = ?' && $this->value === 0, 'Only the exact supported SESSION capability is initialized');
                    $this->session->sets[] = $this->value;
                    if ($this->session->refusal !== null) {
                        throw $this->session->refusal;
                    }
                    if (!$this->session->retainEnabled) {
                        $this->session->capability = $this->session->disappear ? [] : [['innodb_snapshot_isolation', 'OFF']];
                    }
                }
                $result = new CurrentReadResult([]);
                $this->session->results[] = $result;
                return $result;
            }
        };
    }

    public function query(string $sql): Result
    {
        $this->queries[] = $sql;
        $rows = match ($sql) {
            'SELECT @@SESSION.sql_mode' => [[$this->modes]],
            "SHOW SESSION VARIABLES WHERE Variable_name = 'innodb_snapshot_isolation'" => $this->capability,
            "SHOW SESSION STATUS LIKE 'Ssl_cipher'" => [],
            default => throw new LogicException('Unexpected policy query: ' . $sql),
        };
        $result = new CurrentReadResult($rows);
        $this->results[] = $result;
        return $result;
    }

    public function quote(string $value): string
    {
        throw new LogicException('The policy never quotes data.');
    }

    public function exec(string $sql): int|string
    {
        throw new LogicException('The policy never executes application writes.');
    }

    public function lastInsertId(): int|string
    {
        throw new LogicException('No application identity is allocated.');
    }

    public function beginTransaction(): void
    {
        $this->native->active = true;
    }

    public function commit(): void
    {
        $this->native->active = false;
    }

    public function rollBack(): void
    {
        $this->native->active = false;
    }

    public function getNativeConnection()
    {
        return $this->native;
    }

    public function getServerVersion(): string
    {
        return '11.8.0-MariaDB-fixture';
    }
}

final class CurrentReadDriver implements Driver
{
    public function __construct(private CurrentReadDriverSession $session)
    {
    }

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return $this->session;
    }

    public function getDatabasePlatform(\Doctrine\DBAL\ServerVersionProvider $versionProvider): \Doctrine\DBAL\Platforms\AbstractPlatform
    {
        return new \Doctrine\DBAL\Platforms\MySQLPlatform();
    }

    public function getExceptionConverter(): \Doctrine\DBAL\Driver\API\ExceptionConverter
    {
        return new \Doctrine\DBAL\Driver\API\MySQL\ExceptionConverter();
    }
}

$open = static fn (CurrentReadDriverSession $session): DriverConnection => (new MySQLConnection())->wrap(new CurrentReadDriver($session))->connect([]);
foreach ([[], [['innodb_snapshot_isolation', 'OFF']], [['innodb_snapshot_isolation', 'ON']]] as $capability) {
    $session = new CurrentReadDriverSession($capability);
    $open($session);
    verify($session->sets === ($capability === [['innodb_snapshot_isolation', 'ON']] ? [0] : []), 'Absent and already-disabled capabilities need no SET; enabled capability is established once');
    verify($session->modes === 'ANSI_QUOTES,STRICT_TRANS_TABLES,STRICT_ALL_TABLES' && !$session->native->active, 'Configured modes and actual idle transaction state survive admission');
    verify(!array_filter($session->results, static fn ($result): bool => !$result->freed), 'Every native policy result is freed');
}
foreach ([[['INNODB_SNAPSHOT_ISOLATION', 'ON']], [['innodb_snapshot_isolation', '2']], [['innodb_snapshot_isolation', 'on']], [['innodb_snapshot_isolation', 'OFF'], ['innodb_snapshot_isolation', 'ON']]] as $malformed) {
    $session = new CurrentReadDriverSession($malformed);
    try {
        $open($session);
        throw new LogicException('Malformed native capability accepted');
    } catch (CurrentReadUnavailable) {
        verify($session->sets === [] && !$session->native->active, 'Malformed identity/value refuses before a capability write or transaction');
    }
}
foreach (['retainEnabled', 'disappear'] as $failure) {
    $session = new CurrentReadDriverSession([['innodb_snapshot_isolation', 'ON']]);
    $session->$failure = true;
    try {
        $open($session);
        throw new LogicException('Unproven capability readback accepted');
    } catch (CurrentReadUnavailable) {
        verify($session->sets === [0], 'Factory cannot admit an unchanged or disappeared capability');
    }
}
$session = new CurrentReadDriverSession([['innodb_snapshot_isolation', 'ON']]);
$session->refusal = $primary = new RuntimeException('Owned SET refused');
try {
    $open($session);
    throw new LogicException('Refused native SET accepted');
} catch (RuntimeException $error) {
    verify($error === $primary, 'The actual native initialization error is preserved');
}
$session = new CurrentReadDriverSession([['innodb_snapshot_isolation', 'ON']]);
$session->native->active = true;
try {
    MySQLConnection::initializeCurrentReads($session);
    throw new LogicException('Existing caller transaction initialized');
} catch (CurrentReadUnavailable) {
    verify($session->queries === $session->sets && $session->sets === [] && $session->native->active, 'Initialization refuses an existing physical transaction without reads or repair');
}
$session = new CurrentReadDriverSession([['innodb_snapshot_isolation', 'OFF']]);
$driver = (new MySQLConnection())->wrap(new CurrentReadDriver($session));
$owner = new MySQLManagedConnection([], $driver);
$owner->getNativeConnection();
$session->capability = [['innodb_snapshot_isolation', 'ON']];
try {
    $owner->beginTransaction();
    throw new LogicException('Changed caller session admitted');
} catch (CurrentReadUnavailable) {
    verify($owner->getTransactionNestingLevel() === 0 && !$session->native->active && $session->sets === [], 'Managed admission does not mint a frame or repair a caller-changed capability');
}
echo "MySQL current-read policy: native capability, readback, refusal, idle admission and strict-mode preservation passed without a database.\n";
