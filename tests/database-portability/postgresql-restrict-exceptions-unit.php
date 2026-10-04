<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Driver\API\ExceptionConverter as Converter;
use Doctrine\DBAL\Driver\Exception as NativeException;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query;
use itsmng\Database\Driver\Postgres\Driver;
use itsmng\Database\Driver\Postgres\ExceptionConverter;

// Public driver construction and exception conversion require no physical connection.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/Database/Driver/Postgres/ExceptionConverter.php';
require_once dirname(__DIR__, 2) . '/src/Database/Driver/Postgres/Driver.php';
require __DIR__ . '/fixtures/ComponentNativeAdmission.php';

$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function nativeError(?string $state, string $message = 'Native diagnostic'): PdoException
{
    $pdo = new PDOException($message);
    $pdo->errorInfo = [$state, 7, $message];
    return PdoException::new($pdo);
}

$driver = new Driver();
$converter = $driver->getExceptionConverter();
verify($converter instanceof ExceptionConverter, 'Actual application PostgreSQL driver owns the additional conversion');
$query = new Query('DELETE FROM owned_parent WHERE id = ?', [1], [ParameterType::INTEGER]);
foreach ([null, $query] as $context) {
    $native = nativeError('23001');
    $error = $converter->convert($native, $context);
    verify($error instanceof ForeignKeyConstraintViolationException, 'RESTRICT refusal retains the FK exception abstraction');
    verify($error->getSQLState() === '23001' && $error->getCode() === 7, 'Native RESTRICT state and code are unchanged');
    verify($error->getPrevious() === $native && $error->getQuery() === $context, 'Original native exception and optional query are retained');
    foreach (['23503', '23502', '23505', '40001', '40P01', '42601', '23514', '23000', null] as $state) {
        $native = nativeError($state, 'RESTRICT text must not classify an unrelated state');
        $stock = (new Doctrine\DBAL\Driver\PDO\PgSQL\Driver())->getExceptionConverter();
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
        $actual = (new ExceptionConverter($spy))->convert($native, $context);
        verify($actual === $expected && $spy->calls === 1, 'Every other state delegates exactly once and retains its original result');
        $actual = $converter->convert($native, $context);
        verify($actual::class === $expected::class && $actual->getSQLState() === $state
            && $actual->getCode() === 7 && $actual->getPrevious() === $native && $actual->getQuery() === $context,
            'Actual driver preserves stock classification and provenance for unrelated states');
    }
}
foreach (['23503' => 'violates foreign key constraint', '23001' => 'violates RESTRICT setting of foreign key constraint'] as $state => $phrase) {
    $primary = 'update or delete on table "parent" ' . $phrase . ' "selected_fk" on table "child"';
    $error = $converter->convert(nativeError($state, $primary), $query);
    verify(ComponentNativeAdmission::matchesPostgresParentForeign($error, $primary, 'child', 'selected_fk', 'parent'),
        'Selected native parent FK cause recognizes this exact server form');
    foreach ([
        [$primary, 'other_child', 'selected_fk', 'parent'],
        [$primary, 'child', 'other_fk', 'parent'],
        [$primary, 'child', 'selected_fk', 'other_parent'],
        [$primary, 'child', null, 'parent'],
        [$primary, 'child', 'selected_fk', null],
        ['query contains ' . $primary, 'child', 'selected_fk', 'parent'],
        [str_replace($phrase, $state === '23001' ? 'violates foreign key constraint' : 'violates RESTRICT setting of foreign key constraint', $primary), 'child', 'selected_fk', 'parent'],
    ] as [$message, $table, $constraint, $parent]) {
        verify(!ComponentNativeAdmission::matchesPostgresParentForeign($error, $message, $table, $constraint, $parent),
            'Selected native matcher rejects other objects, absent names, query text and mismatched state/message');
    }
    $generic = new DriverException(nativeError($state, $primary), $query);
    verify(!ComponentNativeAdmission::matchesPostgresParentForeign($generic, $primary, 'child', 'selected_fk', 'parent'),
        'Native state alone cannot replace the FK exception class');
}
echo "PostgreSQL exception policy: $assertions assertions passed without connecting.\n";
