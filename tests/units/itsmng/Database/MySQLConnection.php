<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use itsmng\Database\MySQLConnection as Policy;
use itsmng\Database\MySQLManagedConnection;
use PDO;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use itsmng\Database\CurrentReadUnavailable;
use LogicException;
use RuntimeException;
use Throwable;

/** Session and lazy transport policy are independent of application configuration and servers. */
class MySQLConnection extends \atoum\atoum\test
{
    private function parameters(): array
    {
        return ['driver' => 'pdo_mysql', 'host' => 'example.invalid', 'user' => 'fixture', 'password' => 'not-used'];
    }

    public function testStrictModesPreserveConfiguredPolicyAndAreIdempotent(): void
    {
        foreach ([
            '' => 'STRICT_ALL_TABLES',
            'NO_ENGINE_SUBSTITUTION' => 'NO_ENGINE_SUBSTITUTION,STRICT_ALL_TABLES',
            'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER' => 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,STRICT_ALL_TABLES',
            'ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE' => 'ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,STRICT_ALL_TABLES',
            'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES' => 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES',
            'strict_all_tables' => 'strict_all_tables',
            ' ,STRICT_TRANS_TABLES, ' => 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES',
        ] as $configured => $expected) {
            $this->string(Policy::strictModes($configured))->isIdenticalTo($expected);
            $this->string(Policy::strictModes($expected))->isIdenticalTo($expected);
        }
    }

    public function testCanonicalPdoOwnershipAndNativeValues(): void
    {
        $parameters = Policy::parameters($this->parameters());
        $this->string($parameters['driver'])->isIdenticalTo('pdo_mysql');
        $this->string($parameters['wrapperClass'])->isIdenticalTo(MySQLManagedConnection::class);
        $this->boolean($parameters['driverOptions'][PDO::ATTR_EMULATE_PREPARES])->isFalse();
        $this->boolean($parameters['driverOptions'][PDO::ATTR_STRINGIFY_FETCHES])->isFalse();
        $this->boolean($parameters['driverOptions'][PDO::ATTR_PERSISTENT])->isFalse();
        $this->array(Policy::parameters($parameters))->isIdenticalTo($parameters);
    }

    public function testInvalidTransportPolicyIsDiagnosedBeforeLazyCreation(): void
    {
        $base = $this->parameters();
        foreach ([
            ['driver' => 'mysqli'], ['driverClass' => \stdClass::class], ['wrapperClass' => \stdClass::class], ['persistent' => true],
            ['ssl' => 'yes'], ['ssl_verify_server_cert' => 0], ['ssl_unknown' => 'unhandled'],
            ['ssl_key' => ['invalid']], ['ssl_ca' => '/configured/ca.pem'],
            ['driverOptions' => 'invalid'], ['driverOptions' => [123456789 => true]],
            ['driverOptions' => [PDO::ATTR_EMULATE_PREPARES => true]],
            ['driverOptions' => [PDO::ATTR_STRINGIFY_FETCHES => true]],
            ['driverOptions' => [PDO::ATTR_PERSISTENT => true]],
            ['driverOptions' => [PDO::ATTR_TIMEOUT => -1]],
        ] as $invalid) {
            $this->exception(static fn () => Policy::create(array_replace($base, $invalid)))
                ->isInstanceOf(\InvalidArgumentException::class);
        }
    }

    public function testExplicitTlsMaterialAndVerificationReachPdo(): void
    {
        $this->boolean(defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'))->isTrue('The supported runtime must provide PDO MySQL TLS verification');
        $tls = $this->parameters() + ['ssl' => true, 'ssl_verify_server_cert' => true, 'ssl_key' => '/configured/key.pem',
            'ssl_cert' => '/configured/cert.pem', 'ssl_ca' => '/configured/ca.pem', 'ssl_capath' => '/configured/cas', 'ssl_cipher' => 'fixture-cipher'];
        $translated = Policy::parameters($tls);
        foreach (['ssl_key' => PDO::MYSQL_ATTR_SSL_KEY, 'ssl_cert' => PDO::MYSQL_ATTR_SSL_CERT,
            'ssl_ca' => PDO::MYSQL_ATTR_SSL_CA, 'ssl_capath' => PDO::MYSQL_ATTR_SSL_CAPATH, 'ssl_cipher' => PDO::MYSQL_ATTR_SSL_CIPHER] as $field => $option) {
            $this->string($translated['driverOptions'][$option])->isIdenticalTo($tls[$field]);
        }
        $this->boolean($translated['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT])->isTrue();
        $this->array(Policy::parameters($translated))->isIdenticalTo($translated);
        $unverified = Policy::parameters(array_replace($tls, ['ssl_verify_server_cert' => false]));
        $this->boolean($unverified['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT])->isFalse();
        $this->exception(static fn () => Policy::parameters($tls + ['driverOptions' => [PDO::MYSQL_ATTR_SSL_CA => '/different/ca.pem']]))
            ->isInstanceOf(\InvalidArgumentException::class);
        $this->boolean(Policy::create($tls)->isConnected())->isFalse('TLS mapping and ownership remain lazy without accessing configured files');
    }

    private function session(array $capability): CurrentReadDriverSession
    {
        return new CurrentReadDriverSession($capability, function (bool $condition, string $message): void {
            $this->boolean($condition)->isTrue($message);
        });
    }

    private function open(CurrentReadDriverSession $session): DriverConnection
    {
        return (new Policy())->wrap(new CurrentReadDriver($session))->connect([]);
    }

    public function testCurrentReadCapabilitiesRetainModesAndReleaseResults(): void
    {
        foreach ([[], [['innodb_snapshot_isolation', 'OFF']], [['innodb_snapshot_isolation', 'ON']]] as $capability) {
            $session = $this->session($capability);
            $this->open($session);
            $this->boolean($session->sets === ($capability === [['innodb_snapshot_isolation', 'ON']] ? [0] : []))->isTrue('Absent and already-disabled capabilities need no SET; enabled capability is established once');
            $this->boolean($session->modes === 'ANSI_QUOTES,STRICT_TRANS_TABLES,STRICT_ALL_TABLES' && !$session->native->active)->isTrue('Configured modes and actual idle transaction state survive admission');
            $this->boolean(!array_filter($session->results, static fn ($result): bool => !$result->freed))->isTrue('Every native policy result is freed');
        }
    }

    public function testMalformedCurrentReadCapabilitiesRefuseBeforeWriting(): void
    {
        foreach ([[['INNODB_SNAPSHOT_ISOLATION', 'ON']], [['innodb_snapshot_isolation', '2']], [['innodb_snapshot_isolation', 'on']], [['innodb_snapshot_isolation', 'OFF'], ['innodb_snapshot_isolation', 'ON']]] as $malformed) {
            $session = $this->session($malformed);
            try {
                $this->open($session);
                throw new LogicException('Malformed native capability accepted');
            } catch (CurrentReadUnavailable) {
                $this->boolean($session->sets === [] && !$session->native->active)->isTrue('Malformed identity/value refuses before a capability write or transaction');
            }
        }
    }

    public function testCurrentReadInitializationRequiresConfirmedReadback(): void
    {
        foreach (['retainEnabled', 'disappear'] as $failure) {
            $session = $this->session([['innodb_snapshot_isolation', 'ON']]);
            $session->$failure = true;
            try {
                $this->open($session);
                throw new LogicException('Unproven capability readback accepted');
            } catch (CurrentReadUnavailable) {
                $this->boolean($session->sets === [0])->isTrue('Factory cannot admit an unchanged or disappeared capability');
            }
        }
    }

    public function testCurrentReadInitializationPreservesNativeFailure(): void
    {
        $session = $this->session([['innodb_snapshot_isolation', 'ON']]);
        $session->refusal = $primary = new RuntimeException('Owned SET refused');
        try {
            $this->open($session);
            throw new LogicException('Refused native SET accepted');
        } catch (RuntimeException $error) {
            $this->boolean($error === $primary)->isTrue('The actual native initialization error is preserved');
        }
    }

    public function testCurrentReadInitializationDoesNotTouchCallerTransaction(): void
    {
        $session = $this->session([['innodb_snapshot_isolation', 'ON']]);
        $session->native->active = true;
        try {
            Policy::initializeCurrentReads($session);
            throw new LogicException('Existing caller transaction initialized');
        } catch (CurrentReadUnavailable) {
            $this->boolean($session->queries === $session->sets && $session->sets === [] && $session->native->active)->isTrue('Initialization refuses an existing physical transaction without reads or repair');
        }
    }

    public function testManagedAdmissionRefusesCallerChangedCapabilityWithoutFrameOrRepair(): void
    {
        $session = $this->session([['innodb_snapshot_isolation', 'OFF']]);
        $driver = (new Policy())->wrap(new CurrentReadDriver($session));
        $owner = new MySQLManagedConnection([], $driver);
        $owner->getNativeConnection();
        $session->capability = [['innodb_snapshot_isolation', 'ON']];
        try {
            $owner->beginTransaction();
            throw new LogicException('Changed caller session admitted');
        } catch (CurrentReadUnavailable) {
            $this->boolean($owner->getTransactionNestingLevel() === 0 && !$session->native->active && $session->sets === [])->isTrue('Managed admission does not mint a frame or repair a caller-changed capability');
        }
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

    public function __construct(public array $capability, public \Closure $verify)
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
                ($this->session->verify)($param === 1, 'Exactly one native policy parameter');
                ($this->session->verify)($type === ($this->sql === 'SET SESSION sql_mode = ?' ? ParameterType::STRING : ParameterType::INTEGER), 'Policy binds its actual native value type');
                $this->value = $value;
            }

            public function execute(): Result
            {
                if ($this->sql === 'SET SESSION sql_mode = ?') {
                    $this->session->modes = $this->value;
                } else {
                    ($this->session->verify)($this->sql === 'SET SESSION innodb_snapshot_isolation = ?' && $this->value === 0, 'Only the exact supported SESSION capability is initialized');
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

    public function connect(#[\SensitiveParameter] array $params): DriverConnection
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
