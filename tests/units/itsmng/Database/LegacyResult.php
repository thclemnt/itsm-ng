<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use atoum\atoum\test;
use Doctrine\DBAL\Driver\PDO\Result as DriverResult;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use itsmng\Database\LegacyResult as BufferedResult;
use PDO;
use PDOStatement;
use RuntimeException;

class LegacyResult extends test
{
    public function testSeekableResultsPreserveColumnNamesAndScalarTypes(): void
    {
        foreach ([0, 3, 2048] as $count) {
            $statement = new GeneratedResultStatement($count);
            $connection = DriverManager::getConnection(['driver' => 'pdo_mysql']);
            $result = new BufferedResult(new Result(new DriverResult($statement), $connection), static function (array $row): array {
                $row[0] += 10;
                return $row;
            });
            $this->integer($result->num_rows)->isIdenticalTo($count);
            $this->integer($result->field_count)->isIdenticalTo(6);
            $this->integer($statement->freeCalls)->isIdenticalTo(1);
            $this->string($result->fieldName(1))->isIdenticalTo('duplicate');
            $this->boolean($result->data_seek(-1))->isFalse();
            $this->boolean($result->data_seek($count))->isFalse();
            if ($count > 0) {
                foreach ([$count - 1, 0, (int)floor($count / 2), 0] as $position) {
                    $expected = GeneratedResultStatement::row($position);
                    $expected[0] += 10;
                    $this->boolean($result->data_seek($position))->isTrue();
                    $this->array($result->fetch_row())->isIdenticalTo($expected);
                    $this->boolean($result->data_seek($position))->isTrue();
                    $assoc = array_combine(GeneratedResultStatement::NAMES, $expected);
                    $this->array($result->fetch_assoc())->isIdenticalTo($assoc);
                    $this->boolean($result->data_seek($position))->isTrue();
                    $this->array($result->fetch_array())->isIdenticalTo($expected + $assoc);
                    $this->boolean($result->data_seek($position))->isTrue();
                    $this->object($result->fetch_object())->isEqualTo((object)$assoc);
                }
                $this->boolean($result->data_seek($count - 1))->isTrue();
                $result->fetch_row();
            }
            $this->variable($result->fetch_row())->isNull();
            $this->boolean($result->free())->isTrue();
            $this->boolean($result->free())->isTrue();
            $this->variable($result->fetch_row())->isNull();
            $this->string($result->fieldName(1))->isIdenticalTo('duplicate');
            $this->boolean($connection->isConnected())->isFalse();
        }
    }

    public function testNormalizationFailureReleasesTheDriver(): void
    {
        $statement = new GeneratedResultStatement(2048);
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql']);
        $failure = new RuntimeException('Invalid row');
        $this->exception(static fn () => new BufferedResult(new Result(new DriverResult($statement), $connection), static function (array $row) use ($failure): array {
            if ($row[0] === 1000) {
                throw $failure;
            }
            return $row;
        }))->isIdenticalTo($failure);
        $this->integer($statement->freeCalls)->isIdenticalTo(1);
    }
}

/** Generated rows exercise disk spilling without an eager fixture array or a live database. */
class GeneratedResultStatement extends PDOStatement
{
    public const NAMES = ['duplicate', 'duplicate', 'nullable', 'fraction', 'flag', 'bytes'];
    public int $freeCalls = 0;
    private int $position = 0;

    public function __construct(private int $count)
    {
    }

    public static function row(int $id): array
    {
        return [$id, 'row ' . $id, null, 1.25, false, str_repeat("binary\0\n", 128)];
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->position < $this->count ? self::row($this->position++) : false;
    }

    public function columnCount(): int
    {
        return count(self::NAMES);
    }

    public function getColumnMeta(int $column): array|false
    {
        return ['name' => self::NAMES[$column]];
    }

    public function closeCursor(): bool
    {
        $this->freeCalls++;
        return true;
    }
}
