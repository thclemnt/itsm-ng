<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Exception\InvalidColumnIndex;
use Doctrine\DBAL\Result as DBALResult;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;
use ValueError;
use atoum\atoum\test;
use itsmng\Database\Driver\Postgres\Result as DriverResult;
use itsmng\Database\LegacyResult;

class Result extends test
{
    private function statement(): MetadataStatement
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "binary\0value");
        rewind($stream);
        return new MetadataStatement([
            ['name' => 'wide', 'native_type' => 'int8'],
            ['name' => 'number', 'native_type' => 'float8'],
            ['name' => 'enabled', 'native_type' => 'bool'],
            ['name' => 'instant', 'native_type' => 'timestamptz'],
            ['name' => 'binary', 'native_type' => 'bytea'],
            ['name' => 'optional', 'native_type' => 'text'],
        ], [[5000000100, 1.25, true, '2026-10-07 12:13:14+02', $stream, null]]);
    }

    public function testNativeFetchesDoNotInspectColumnMetadata(): void
    {
        foreach (['fetchNumeric', 'fetchAssociative', 'fetchOne', 'fetchAllNumeric', 'fetchAllAssociative', 'fetchFirstColumn'] as $fetch) {
            $statement = $this->statement();
            $result = new DriverResult($statement);
            $row = $statement->rows[0];
            $associative = array_combine(array_column($statement->columns, 'name'), $row);
            $expected = match ($fetch) {
                'fetchNumeric' => $row,
                'fetchAssociative' => $associative,
                'fetchOne' => $row[0],
                'fetchAllNumeric' => [$row],
                'fetchAllAssociative' => [$associative],
                'fetchFirstColumn' => [$row[0]],
            };
            try {
                $this->variable($result->$fetch())->isIdenticalTo($expected);
                $this->integer($result->columnCount())->isIdenticalTo(6);
                $this->integer($result->rowCount())->isIdenticalTo(1);
                $this->array($statement->metadataCalls)->isEmpty();
            } finally {
                $result->free();
                fclose($row[4]);
            }
            $this->integer($statement->freeCalls)->isIdenticalTo(1);
            $this->array($statement->metadataCalls)->isEmpty();
        }
    }

    public function testLegacyNamesAndNormalizationShareResultMetadata(): void
    {
        $statement = $this->statement();
        $driver = new DriverResult($statement);
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'dbname' => 'not-opened']);
        $this->boolean($connection->isConnected())->isFalse();
        try {
            $this->string($driver->getColumnName(0))->isIdenticalTo('wide');
            $this->string($driver->getColumnName(0))->isIdenticalTo('wide');
            $legacy = new LegacyResult(new DBALResult($driver, $connection), $driver->legacyRow(...));
            $expected = [5000000100, 1.25, 1, '2026-10-07 12:13:14', "binary\0value", null];
            $this->array($legacy->fetch_row())->isIdenticalTo($expected);
            $this->integer($legacy->field_count)->isIdenticalTo(6);
            $this->integer($legacy->num_rows)->isIdenticalTo(1);
            $this->array($statement->metadataCalls)->isIdenticalTo([0, 1, 2, 3, 4, 5]);
            $this->integer($statement->freeCalls)->isIdenticalTo(1);
            $this->string($legacy->fieldName(5))->isIdenticalTo('optional');
            $this->boolean($legacy->data_seek(0))->isTrue();
            $this->array($legacy->fetch_assoc())->isIdenticalTo(array_combine(array_column($statement->columns, 'name'), $expected));
            $this->boolean($legacy->data_seek(-1))->isFalse();
            $this->boolean($legacy->data_seek(1))->isFalse();
            $this->boolean($legacy->data_seek(0))->isTrue();
            $this->array($legacy->fetch_array())->isIdenticalTo($expected + array_combine(array_column($statement->columns, 'name'), $expected));
            $this->boolean($legacy->data_seek(0))->isTrue();
            $this->object($legacy->fetch_object())->isEqualTo((object)array_combine(array_column($statement->columns, 'name'), $expected));
            $this->variable($legacy->fetch_row())->isNull();
            $this->boolean($legacy->free())->isTrue();
            $this->variable($legacy->fetch_row())->isNull();
            $this->string($legacy->fieldName(0))->isIdenticalTo('wide');
            $this->array($statement->metadataCalls)->isIdenticalTo([0, 1, 2, 3, 4, 5]);
            $this->boolean($connection->isConnected())->isFalse();
        } finally {
            fclose($statement->rows[0][4]);
        }
        $empty = new MetadataStatement([['name' => 'none', 'native_type' => 'bool']], []);
        $emptyDriver = new DriverResult($empty);
        $legacy = new LegacyResult(new DBALResult($emptyDriver, $connection), $emptyDriver->legacyRow(...));
        $this->integer($legacy->num_rows)->isIdenticalTo(0);
        $this->string($legacy->fieldName(0))->isIdenticalTo('none');
        $this->variable($legacy->fetch_row())->isNull();
        $this->array($empty->metadataCalls)->isIdenticalTo([0]);
        $this->integer($empty->freeCalls)->isIdenticalTo(1);
    }

    public function testMetadataErrorsRemainAtTheExplicitBoundary(): void
    {
        $statement = new MetadataStatement([['name' => 'value', 'native_type' => 'bool']], [[false]]);
        $result = new DriverResult($statement);
        $this->array($result->legacyRow([false]))->isIdenticalTo([0]);
        $this->array($result->legacyRow([null]))->isIdenticalTo([null]);
        $this->array($statement->metadataCalls)->isIdenticalTo([0]);
        $this->exception(fn () => $result->getColumnName(9))->isInstanceOf(InvalidColumnIndex::class);
        $statement->failure = new ValueError('Invalid native index');
        $this->exception(fn () => $result->getColumnName(-1))->isInstanceOf(InvalidColumnIndex::class);
        $error = new PDOException('Native metadata failure');
        $error->errorInfo = ['XX000', 7, 'Native metadata failure'];
        $statement->failure = $error;
        $this->exception(fn () => $result->getColumnName(1))->isInstanceOf(Exception::class);
        $statement->failure = null;
        $statement->columns[1] = ['name' => 'untyped'];
        $this->string($result->getColumnName(1))->isIdenticalTo('untyped');
        $this->exception(fn () => $result->legacyRow([1 => 'value']))
            ->isInstanceOf(RuntimeException::class)->hasMessage('PostgreSQL driver did not supply native column type metadata.');
        $result->free();
        $this->integer($statement->freeCalls)->isIdenticalTo(1);
    }
}

/** A PDO boundary spy: no connection, SQL executor or replacement portability runner. */
final class MetadataStatement extends PDOStatement
{
    public array $metadataCalls = [];
    public int $freeCalls = 0;
    public ?Throwable $failure = null;
    private int $position = 0;

    public function __construct(public array $columns, public array $rows)
    {
    }

    public function getColumnMeta(int $column): array|false
    {
        $this->metadataCalls[] = $column;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->columns[$column] ?? false;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = $this->rows[$this->position++] ?? null;
        if ($row === null) {
            return false;
        }
        return match ($mode) {
            PDO::FETCH_NUM => $row,
            PDO::FETCH_ASSOC => array_combine(array_column($this->columns, 'name'), $row),
            PDO::FETCH_COLUMN => $row[0],
            default => throw new LogicException('Unexpected PDO fetch mode'),
        };
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = [];
        while ($this->position < count($this->rows)) {
            $rows[] = $this->fetch($mode);
        }
        return $rows;
    }

    public function closeCursor(): bool
    {
        ++$this->freeCalls;
        return true;
    }
}
