<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\PDO\Exception;

/** Native PDO values remain unchanged for ORM and ordinary DBAL consumers. */
final class Result extends AbstractResultMiddleware
{
    private array $types = [];

    public function __construct(\PDOStatement $statement)
    {
        parent::__construct(new \Doctrine\DBAL\Driver\PDO\Result($statement));
        try {
            for ($column = 0; $column < $statement->columnCount(); $column++) {
                $metadata = $statement->getColumnMeta($column);
                if ($metadata === false || !isset($metadata['native_type'])) {
                    throw new \RuntimeException('PostgreSQL driver did not supply native column type metadata.');
                }
                $this->types[] = $metadata['native_type'];
            }
        } catch (\PDOException $error) {
            throw Exception::new($error);
        }
    }

    /** Normalize only the legacy row API, using this result's actual wire types. */
    public function legacyRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }
            $type = $this->types[$column];
            if (in_array($type, ['int2', 'int4', 'int8'], true) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $row[$column] = (int)$value;
            } elseif (in_array($type, ['float4', 'float8'], true)) {
                $row[$column] = (float)$value;
            } elseif ($type === 'bool') {
                $row[$column] = in_array($value, [true, 1, '1', 't'], true) ? 1 : 0;
            } elseif ($type === 'timestamptz') {
                $row[$column] = (new \DateTimeImmutable((string)$value))->format('Y-m-d H:i:s');
            } elseif ($type === 'bytea' && is_resource($value)) {
                $bytes = stream_get_contents($value);
                if ($bytes === false) {
                    throw new \RuntimeException('Cannot read PostgreSQL binary result.');
                }
                $row[$column] = $bytes;
            }
        }
        return $row;
    }
}
