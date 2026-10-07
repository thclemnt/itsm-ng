<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result as PdoResult;
use Doctrine\DBAL\Exception\InvalidColumnIndex;
use PDOException;
use PDOStatement;
use RuntimeException;
use ValueError;

/** Native PDO values remain unchanged for ORM and ordinary DBAL consumers. */
final class Result extends AbstractResultMiddleware
{
    private array $metadata = [];

    public function __construct(private readonly PDOStatement $statement)
    {
        parent::__construct(new PdoResult($statement));
    }

    /** Only explicit column inspection and the legacy row boundary need metadata. */
    private function columnMetadata(int $column): array
    {
        if (isset($this->metadata[$column])) {
            return $this->metadata[$column];
        }
        try {
            $metadata = $this->statement->getColumnMeta($column);
        } catch (ValueError $error) {
            throw InvalidColumnIndex::new($column, $error);
        } catch (PDOException $error) {
            throw Exception::new($error);
        }
        if ($metadata === false) {
            throw InvalidColumnIndex::new($column);
        }
        return $this->metadata[$column] = $metadata;
    }

    public function getColumnName(int $index): string
    {
        return $this->columnMetadata($index)['name'];
    }

    public function free(): void
    {
        $this->metadata = [];
        parent::free();
    }

    /** Normalize only the legacy row API, using this result's actual wire types. */
    public function legacyRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }
            $metadata = $this->columnMetadata($column);
            if (!isset($metadata['native_type'])) {
                throw new RuntimeException('PostgreSQL driver did not supply native column type metadata.');
            }
            $type = $metadata['native_type'];
            if (in_array($type, ['int2', 'int4', 'int8'], true) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $row[$column] = (int)$value;
            } elseif (in_array($type, ['float4', 'float8'], true)) {
                $row[$column] = (float)$value;
            } elseif ($type === 'bool') {
                $row[$column] = in_array($value, [true, 1, '1', 't'], true) ? 1 : 0;
            } elseif ($type === 'timestamptz') {
                $row[$column] = (new DateTimeImmutable((string)$value))->format('Y-m-d H:i:s');
            } elseif ($type === 'bytea' && is_resource($value)) {
                $bytes = stream_get_contents($value);
                if ($bytes === false) {
                    throw new RuntimeException('Cannot read PostgreSQL binary result.');
                }
                $row[$column] = $bytes;
            }
        }
        return $row;
    }
}
