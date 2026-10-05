<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\API\ExceptionConverter as Converter;
use Doctrine\DBAL\Driver\Exception as NativeException;
use Doctrine\DBAL\Driver\PDO\Exception as DbalPdoException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query;
use itsmng\Database\Driver\Postgres\Driver;
use itsmng\Database\Driver\Postgres\ExceptionConverter as Policy;
use PDOException;
use RuntimeException;

class ExceptionConverter extends \atoum\atoum\test
{
    private function nativeError(?string $state, string $message = 'Native diagnostic'): DbalPdoException
    {
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$state, 7, $message];
        return DbalPdoException::new($pdo);
    }

    public function testRestrictPreservesNativeProvenanceAndOtherStatesDelegateExactlyOnce(): void
    {
        $driver = new Driver();
        $converter = $driver->getExceptionConverter();
        $this->boolean($converter instanceof Policy)->isTrue('Actual application PostgreSQL driver owns the additional conversion');
        $query = new Query('DELETE FROM owned_parent WHERE id = ?', [1], [ParameterType::INTEGER]);
        foreach ([null, $query] as $context) {
            $native = $this->nativeError('23001');
            $error = $converter->convert($native, $context);
            $this->boolean($error instanceof ForeignKeyConstraintViolationException)->isTrue('RESTRICT refusal retains the FK exception abstraction');
            $this->boolean($error->getSQLState() === '23001' && $error->getCode() === 7)->isTrue('Native RESTRICT state and code are unchanged');
            $this->boolean($error->getPrevious() === $native && $error->getQuery() === $context)->isTrue('Original native exception and optional query are retained');
            foreach (['23503', '23502', '23505', '40001', '40P01', '42601', '23514', '23000', null] as $state) {
                $native = $this->nativeError($state, 'RESTRICT text must not classify an unrelated state');
                $stock = (new \Doctrine\DBAL\Driver\PDO\PgSQL\Driver())->getExceptionConverter();
                $expected = $stock->convert($native, $context);
                $spy = new class ($native, $context, $expected) implements Converter {
                    public int $calls = 0;

                    public function __construct(private NativeException $native, private ?Query $query, private DriverException $result)
                    {
                    }

                    public function convert(NativeException $exception, ?Query $query): DriverException
                    {
                        if ($exception !== $this->native || $query !== $this->query) {
                            throw new RuntimeException('Delegation must retain the actual native error and query');
                        }
                        ++$this->calls;
                        return $this->result;
                    }
                };
                $actual = (new Policy($spy))->convert($native, $context);
                $this->boolean($actual === $expected && $spy->calls === 1)->isTrue('Every other state delegates exactly once and retains its original result');
                $actual = $converter->convert($native, $context);
                $this->boolean($actual::class === $expected::class && $actual->getSQLState() === $state
                    && $actual->getCode() === 7 && $actual->getPrevious() === $native && $actual->getQuery() === $context)->isTrue('Actual driver preserves stock classification and provenance for unrelated states');
            }
        }
    }
}
